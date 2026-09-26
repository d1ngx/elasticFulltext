<?php

class elasticFulltextPlugin extends PluginBase {
	private $stateTable = 'plugin_elastic_fulltext_state';
	private $snippets = array();
	private $matchedFileIDs = array();
	private $cursorFile = '';

	public function __construct() {
		parent::__construct();
		$this->cursorFile = rtrim(TEMP_PATH, '/\\').'/elastic-fulltext-cursor.json';
	}

	public function regist() {
		$this->hookRegist(array(
			'globalRequest' => 'elasticFulltextPlugin.bindHooks',
			'user.commonJs.insert' => 'elasticFulltextPlugin.echoJs',
		));
	}

	public function bindHooks() {
		$this->bindSearchHooks();
		Hook::bind('explorer.list.path.before', 'elasticFulltextPlugin.bindSearchHooks');
	}

	public function bindSearchHooks() {
		Hook::unbind('explorer.listSearch.searchDataBefore', 'elasticFulltextPlugin.searchBefore');
		Hook::unbind('explorer.listSearch.searchDataAfter', 'elasticFulltextPlugin.searchAfter');
		Hook::unbind('explorer.listSearch.fileContentText', 'elasticFulltextPlugin.fileContentText');
		Hook::unbind('explorer.list.path.parse', 'elasticFulltextPlugin.listPathParse');
		Hook::bind('explorer.listSearch.searchDataBefore', 'elasticFulltextPlugin.searchBefore');
		Hook::bind('explorer.listSearch.searchDataAfter', 'elasticFulltextPlugin.searchAfter');
		Hook::bind('explorer.listSearch.fileContentText', 'elasticFulltextPlugin.fileContentText');
		Hook::bind('explorer.list.path.parse', 'elasticFulltextPlugin.listPathParse');
	}

	public function echoJs() {
		$this->echoFile('static/main.js');
		$this->echoFile('static/admin.js');
	}

	public function onChangeStatus($status) {
		if ($status) $this->initTable();
		$this->updateTask($status && $this->isOpen() ? 1 : 0);
		return true;
	}

	public function onUpdate() {
		$this->initTable();
		$this->updateTask($this->isOpen() ? 1 : 0);
	}

	public function onSetConfig($config) {
		$prev = $this->getConfig();
		$config['elasticUrl'] = rtrim(trim(_get($config, 'elasticUrl', 'http://elasticsearch:9200')), '/');
		if (!preg_match('#^https?://#i', $config['elasticUrl'])) throw new Exception('Elasticsearch URL must begin with http:// or https://');
		$config['indexName'] = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '-', trim(_get($config, 'indexName', 'kodbox-fulltext'))));
		if (!$config['indexName'] || $config['indexName'][0] === '_' || $config['indexName'][0] === '-') throw new Exception('Invalid Elasticsearch index name');
		$config['maxFileSizeMB'] = max(1, min(500, intval($this->keepConfig($config, $prev, 'maxFileSizeMB', 30))));
		$config['batchSize'] = max(1, min(500, intval($this->keepConfig($config, $prev, 'batchSize', 50))));
		$config['indexedChars'] = max(100000, min(5000000, intval($this->keepConfig($config, $prev, 'indexedChars', 1000000))));
		$config['serviceEnabled'] = _get($config, 'serviceEnabled', '1') == '1' ? 1 : 0;
		$config['extensionMode'] = $this->keepConfig($config, $prev, 'extensionMode', 'allow') === 'deny' ? 'deny' : 'allow';
		$config['allowExtensions'] = implode(',', $this->normalizeExtensions($this->keepConfig($config, $prev, 'allowExtensions', '')));
		$config['denyExtensions'] = implode(',', $this->normalizeExtensions($this->keepConfig($config, $prev, 'denyExtensions', '')));
		if (array_key_exists('debugMode', $config)) $config['debugMode'] = $config['debugMode'] == '1' ? 1 : 0;
		else $config['debugMode'] = _get($prev, 'debugMode', 0) == '1' ? 1 : 0;
		unset($config['pluginAuth'], $config['searchLimit'], $config['verifyTls'], $config['serviceCheck'], $config['runStatus'], $config['statusPanel'], $config['actions']);
		$this->initTable();
		$this->updateTask($config['serviceEnabled']);
		return $config;
	}

	public function onGetConfig($formData) {
		return $formData;
	}

	public function onUninstall() {
		$task = Model('SystemTask')->findByKey('event', $this->pluginName.'Plugin.task');
		if ($task) Model('SystemTask')->remove($task['id'], true);
	}

	public function searchBefore($param) {
		if (!$this->isOpen()) return;
		if (!is_array($param) || empty($param['words']) || !in_array('content', (array)_get($param, 'option', array()), true)) return;
		if (strlen($param['words']) <= 1 || empty($param['parentID'])) return;
		include_once($this->pluginPath.'lib/CorpusShare.class.php');
		$level = ob_get_level();
		ob_start();
		try {
			$result = $this->searchInFolder($param['words'], 1000, intval($param['parentID']));
			$fileIDs = array();
			$this->snippets = array();
			$this->matchedFileIDs = array();
			foreach ((array)$result as $hit) {
				$fileID = intval(_get($hit, 'fileID', 0));
				if (!$fileID) continue;
				$fileIDs[] = $fileID;
				$this->matchedFileIDs[$fileID] = true;
				if (!empty($hit['snippet'])) $this->snippets[$fileID] = $this->sanitizeSnippet($hit['snippet']);
			}
			// 与官方 docSearch 一致：保留 words 和 content，仅附加候选物理 fileID。
			// Kodbox Source::listSearch 会继续应用目录、类型、时间和用户权限过滤。
			$param = KodboxCorpusShare::takeContentHits($param, $fileIDs, $this->snippets, 'elasticFulltext');
		} catch (Throwable $e) {
			$this->log('search failed: '.$e->getMessage(), 'error');
			while (ob_get_level() > $level) @ob_end_clean();
			return;
		}
		while (ob_get_level() > $level) @ob_end_clean();
		return $param;
	}

	public function searchAfter($param, $listData) {
		include_once($this->pluginPath.'lib/CorpusShare.class.php');
		$applied = KodboxCorpusShare::applyContentHits($listData);
		if ($applied) $listData = $applied;
		elseif (!KodboxCorpusShare::contentHits()) return;
		$ids = array();
		foreach ((array)_get($listData, 'fileList', array()) as $item) {
			$id = intval(_get($item, 'fileID', _get($item, 'fileInfo.fileID', 0)));
			if ($id) $ids[] = $id;
		}
		$words = (string)_get($param, 'words', '');
		if ($ids && $words !== '') {
			try {
				$clean = array();
				foreach ($this->client()->snippets($words, $ids) as $id => $text) {
					$text = $this->sanitizeSnippet($text);
					if ($text !== '') $clean[intval($id)] = $text;
				}
				if ($clean) {
					KodboxCorpusShare::putSnippets($clean);
					$listData = KodboxCorpusShare::overlaySnippets($listData);
				}
			} catch (Throwable $e) {}
		}
		return $listData;
	}

	public function listPathParse($data) {
		include_once($this->pluginPath.'lib/CorpusShare.class.php');
		return KodboxCorpusShare::overlaySnippets($data);
	}

	// 兼容 Kodbox 对物理路径及其他插件发起的正文读取。
	public function fileContentText($file, $makeNow = false) {
		if (!$this->isOpen()) return;
		$fileID = intval(_get((array)$file, 'fileID', 0));
		if (!$fileID) return;
		try {
			$content = $this->client()->getContent($fileID);
			return $content !== '' ? $content : null;
		} catch (Throwable $e) {
			return;
		}
	}

	public function task() {
		return $this->runLocked(false);
	}

	private function runLocked($fillBatch) {
		$this->releaseSession();
		if (!$this->isOpen()) return 0;
		$this->recoverStaleRun();
		$lock = @fopen(rtrim(TEMP_PATH, '/\\').'/elastic-fulltext-task.lock', 'c');
		if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
			if ($lock) fclose($lock);
			return -1;
		}
		$heavy = @fopen(rtrim(TEMP_PATH, '/\\').'/kod-heavy-index.lock', 'c');
		if (!$heavy || !@flock($heavy, LOCK_EX | LOCK_NB)) {
			if ($heavy) fclose($heavy);
			flock($lock, LOCK_UN); fclose($lock);
			return -1;
		}
		try {
			require_once $this->pluginPath.'lib/Backpressure.class.php';
			$pressure = ElasticFulltextBackpressure::inspect(array());
			if (!$pressure['ok']) {
				$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => '', 'pauseReason' => (string)_get($pressure, 'reason', '系统负载较高')));
				return 0;
			}
			if (function_exists('ignore_timeout')) ignore_timeout();
			@ignore_user_abort(true);
			if (class_exists('KodLog')) KodLog::$checkClientAbort = false;
			return $this->runTask($fillBatch);
		} catch (ElasticFulltextPressureException $e) {
			$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => '', 'pauseReason' => $e->getMessage()));
			return 0;
		} catch (Throwable $e) {
			$this->log('task failed: '.$e->getMessage(), 'error');
			$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => ''));
			return 0;
		} finally {@flock($heavy, LOCK_UN); @fclose($heavy); @flock($lock, LOCK_UN); @fclose($lock);}
	}

	private function runTask($fillBatch = false) {
		$this->initTable();
		$client = $this->client();
		$client->ensureInfrastructure();
		$config = $this->getConfig();
		$batch = max(1, min(500, intval(_get($config, 'batchSize', 50))));
		$budget = $fillBatch ? min(600, max(180, $batch * 4)) : 50;
		if (function_exists('ignore_timeout')) ignore_timeout();
		else @set_time_limit($budget + 30);
		$extensions = $this->configuredExtensions($config);
		if (!$extensions) return 0;
		$cursorData = $this->readCursorData();
		$corpusHash = $this->corpusFingerprint($config);
		$scanHash = $this->scanFingerprint($config);
		$cursor = intval(_get($cursorData, 'fileID', 0));
		if (_get($cursorData, 'corpusHash', '') !== $corpusHash || _get($cursorData, 'scanHash', '') !== $scanHash) {
			$cursor = 0;
			$this->writeCursor(0, array('complete' => 0, 'completeMax' => 0, 'corpusHash' => $corpusHash, 'scanHash' => $scanHash));
		}
		$deadline = microtime(true) + $budget;
		$startedAt = intval(_get($this->readCursorData(), 'started', 0));
		if ($startedAt <= 0) $startedAt = time();
		$processed = 0;
		$skipped = 0;
		$failed = 0;
		$ignored = 0;
		$scanned = 0;
		$this->writeCursor($cursor, array(
			'running' => 1, 'current' => '准备中', 'started' => $startedAt,
			'indexed' => 0, 'skipped' => 0, 'failed' => 0, 'scanned' => 0, 'ignored' => 0, 'pauseReason' => '',
		));
		$failedRows = Model($this->stateTable)->where(array('status' => 3, 'indexTime' => array('lt', time() - 900)))->order('indexTime asc')->limit(min(5, $batch))->select();
		foreach ((array)$failedRows as $failedRow) {
			ElasticFulltextBackpressure::assertReady(array());
			if (microtime(true) >= $deadline) break;
			$fileID = intval($failedRow['fileID']);
			$file = Model('File')->where(array('fileID' => $fileID))->find();
			if (!$file) {
				try { $client->deleteFile($fileID); } catch (Throwable $e) {}
				Model($this->stateTable)->where(array('fileID' => $fileID))->delete();
				continue;
			}
			$ext = strtolower(get_path_ext(_get($file, 'name', '')));
			if (!$ext || !in_array($ext, $extensions, true)) {
				$this->saveState($file, 2, '扩展名已不在索引范围');
				continue;
			}
			$this->writeCursor($cursor, array(
				'running' => 1, 'current' => (string)_get($file, 'name', ''), 'started' => $startedAt,
				'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'scanned' => $scanned, 'ignored' => $ignored,
			));
			$result = $this->indexFileRecord($file, $ext, $client, $config, null);
			if ($result === 'ok') $processed++;
			else if ($result === 'skip') $skipped++;
			else if ($result === 'fail') $failed++;
		}
		// 官方 docSearch 以 io_file 为扫描源。物理 fileID 天然去重。
		// 图片等非目标格式不计入批次，一直扫到真正索引满 batch 或用完时间窗口。
		$page = min(100, max(20, $batch * 4));
		$wrapped = false;
		while ($processed < $batch && microtime(true) < $deadline) {
			$rows = Model('File')->where(array('fileID' => array('gt', $cursor)))->order('fileID asc')->limit($page)->select();
			if (!$rows) {
				if ($wrapped) break;
				$this->cleanupDeleted($client, $batch * 2);
				$cursor = 0;
				$wrapped = true;
				$this->writeCursor($cursor, array('started' => $startedAt));
				continue;
			}
			$targetIDs = array();
			foreach ((array)$rows as $candidate) {
				$candidateExt = strtolower(get_path_ext(_get($candidate, 'name', '')));
				if ($candidateExt && in_array($candidateExt, $extensions, true)) $targetIDs[] = intval($candidate['fileID']);
			}
			$knownDocs = $targetIDs ? $client->getDocuments($targetIDs) : array();
			foreach ((array)$rows as $file) {
				ElasticFulltextBackpressure::assertReady(array());
				$cursor = max($cursor, intval($file['fileID']));
				$scanned++;
				$ext = strtolower(get_path_ext(_get($file, 'name', '')));
				if ($ext && in_array($ext, $extensions, true)) {
					$this->writeCursor($cursor, array(
						'running' => 1, 'current' => (string)_get($file, 'name', ''), 'started' => $startedAt,
						'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'scanned' => $scanned, 'ignored' => $ignored,
					));
					$result = $this->indexFileRecord($file, $ext, $client, $config, array_key_exists(intval($file['fileID']), $knownDocs) ? $knownDocs[intval($file['fileID'])] : array());
					if ($result === 'ok') $processed++;
					else if ($result === 'skip') $skipped++;
					else if ($result === 'fail') $failed++;
				} else {
					$ignored++;
				}
				if ($processed >= $batch || microtime(true) >= $deadline) break;
			}
			$this->writeCursor($cursor, array('started' => $startedAt));
			if (count((array)$rows) < $page) {
				if ($wrapped) break;
				$this->cleanupDeleted($client, $batch * 2);
				$cursor = 0;
				$wrapped = true;
			}
		}
		$maxID = intval(Model('File')->max('fileID'));
		if ($cursor > $maxID) $cursor = $maxID;
		// 只有真正走到最后一个文件、且本轮没有新入库，才算扫完。绕回后只走了一段时保留游标，后面的文件下一轮继续刷新目录位置。
		$complete = $processed === 0 && $maxID > 0 && $cursor >= $maxID;
		$this->writeCursor($cursor, array(
			'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed,
			'scanned' => $scanned, 'ignored' => $ignored, 'running' => 0, 'current' => '',
			'complete' => $complete ? 1 : 0, 'completeMax' => $maxID,
			'corpusHash' => $corpusHash, 'scanHash' => $scanHash,
		));
		$this->log('batch indexed='.$processed.' skipped='.$skipped.' failed='.$failed.' ignored='.$ignored.' scanned='.$scanned.' cursor='.$cursor.' complete='.($complete?1:0));
		return $processed;
	}

	private function indexFileRecord($file, $ext, $client, $config, $knownDoc = null) {
		$fileID = intval(_get($file, 'fileID', 0));
		$modifyTime = intval(_get($file, 'modifyTime', 0));
		if (!$fileID) return false;
		$maxBytes = intval(_get($config, 'maxFileSizeMB', 30)) * 1024 * 1024;
		if (intval(_get($file, 'size', 0)) <= 0) {
			$this->saveState($file, 2, '空文件');
			$this->log('skip '.$this->fileLabel($file).' 空文件', 'warning');
			return 'skip';
		}
		if (intval(_get($file, 'size', 0)) >= $maxBytes) {
			$this->saveState($file, 2, '超过大小限制');
			$this->log('skip '.$this->fileLabel($file).' 超过大小限制', 'warning');
			return 'skip';
		}
		try {
			include_once($this->pluginPath.'lib/CorpusShare.class.php');
			$document = $file;
			$document['sourceID'] = intval(_get($file, 'sourceID', 0));
			$document['fileType'] = $ext;
			$document['ancestorIDs'] = KodboxCorpusShare::ancestorIDs($fileID);
			$origin = '';
			$ownDoc = is_array($knownDoc) ? $knownDoc : array();
			if ($knownDoc === null) {
				try { $ownDoc = $client->getDocument($fileID); } catch (Throwable $e) { $ownDoc = array(); }
			}
			if (KodboxCorpusShare::isFresh($ownDoc, $modifyTime) && _get($ownDoc, 'extractVersion', '') === $client->extractionVersion()) {
				if ($this->documentMetadataMatches($ownDoc, $document)) {
					$origin = 'fulltext';
				} else {
					$client->updateMetadata($document);
					$origin = 'metadata';
				}
			}
			if ($origin === 'fulltext') {
				$this->saveState($file, 1, '');
				return false;
			}
			if ($origin === '') {
				$path = _get($file, 'path', '');
				if (!$path || !IO::exist($path)) throw new Exception('找不到物理文件');
				$content = IO::getContent($path);
				if ($content === false) throw new Exception('无法读取文件内容');
				if (!$this->isPlainText($ext)) $content = $this->sanitizeOfficeZip($content, $ext);
				$client->indexFile($document, $content, $this->isPlainText($ext));
				$origin = 'tika';
			}
			$this->saveState($file, 1, '');
			$this->log('ok '.$this->fileLabel($file).' size='.intval(_get($file, 'size', 0)).' via='.$origin);
			return 'ok';
		} catch (Throwable $e) {
			$this->saveState($file, 3, substr($e->getMessage(), 0, 1000));
			$this->log('fail '.$this->fileLabel($file).' '.$e->getMessage(), 'error');
			return 'fail';
		}
	}

	private function sanitizeOfficeZip($content, $ext) {
		if (!in_array($ext, array('docx','docm','xlsx','xlsm','pptx','pptm'), true)) return $content;
		if (!class_exists('ZipArchive') || substr((string)$content, 0, 2) !== 'PK') return $content;
		$tmp = tempnam(sys_get_temp_dir(), 'eftzip');
		if (!$tmp || file_put_contents($tmp, $content) === false) {if ($tmp) @unlink($tmp); return $content;}
		$zip = new ZipArchive();
		if ($zip->open($tmp) !== true) {@unlink($tmp); return $content;}
		$types = (string)$zip->getFromName('[Content_Types].xml');
		$removed = 0;
		for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
			$name = $zip->getNameIndex($i);
			if (!$name || !preg_match('#^customXml/.*\\.bin$#i', $name)) continue;
			$part = '/'.ltrim(str_replace('\\', '/', $name), '/');
			$declared = $types !== '' && (strpos($types, $part) !== false || strpos($types, $name) !== false);
			if ($declared) continue;
			if ($zip->deleteName($name)) $removed++;
		}
		$zip->close();
		$out = $removed ? file_get_contents($tmp) : $content;
		@unlink($tmp);
		if ($removed) $this->log('sanitize office zip removed='.$removed.' undeclared customXml parts');
		return $out !== false ? $out : $content;
	}

	private function saveState($file, $status, $error) {
		$fileID = intval($file['fileID']);
		$data = array(
			'sourceID' => intval(_get($file, 'sourceID', 0)), 'modifyTime' => intval(_get($file, 'modifyTime', 0)),
			'status' => intval($status), 'error' => $error, 'indexTime' => time(),
		);
		$exists = Model($this->stateTable)->where(array('fileID' => $fileID))->find();
		if ($exists) return Model($this->stateTable)->where(array('fileID' => $fileID))->save($data);
		$data['fileID'] = $fileID;
		Model($this->stateTable)->setDataAuto(false);
		return Model($this->stateTable)->add($data);
	}

	private function cleanupDeleted($client, $limit) {
		$limit = max(1, intval($limit));
		$data = $this->readCursorData();
		$cleanupCursor = intval(_get($data, 'cleanupCursor', 0));
		$states = Model($this->stateTable)->where(array('fileID' => array('gt', $cleanupCursor)))->order('fileID asc')->limit($limit)->select();
		if (!$states && $cleanupCursor > 0) {
			$cleanupCursor = 0;
			$states = Model($this->stateTable)->order('fileID asc')->limit($limit)->select();
		}
		$ids = array();
		foreach ((array)$states as $state) $ids[] = intval($state['fileID']);
		$alive = array();
		if ($ids) {
			$rows = Model('File')->where(array('fileID' => array('in', $ids)))->field('fileID')->select();
			foreach ((array)$rows as $row) $alive[intval($row['fileID'])] = true;
		}
		foreach ((array)$states as $state) {
			$fileID = intval($state['fileID']);
			$cleanupCursor = max($cleanupCursor, $fileID);
			if (isset($alive[$fileID])) continue;
			try {
				$client->deleteFile($fileID);
				Model($this->stateTable)->where(array('fileID' => $fileID))->delete();
			} catch (Throwable $e) {
				$this->log('delete retry fileID='.$fileID.' '.$e->getMessage(), 'error');
			}
		}
		$this->writeCursor($this->readCursor(), array('cleanupCursor' => $cleanupCursor));
	}

	public function manage() {
		if (_get($_SERVER, 'REQUEST_METHOD', '') !== 'POST') return show_json(LNG('common.illegalRequest'), false);
		if (!KodUser::isRoot()) return show_json(LNG('explorer.noPermissionAction'), false);
		$this->releaseSession();
		$operation = trim(Input::get('operation', 'require'));
		try {
			if ($operation === 'test') {
				$info = $this->client()->info();
				return show_json(array('message' => 'Elasticsearch '.$info['version']['number'].' 连接正常'));
			}
			if ($operation === 'run') {
				if ($this->taskBusy()) return show_json(array('message' => '后台正在索引，请稍后再试'), false);
				if (!$this->hasPendingWork()) {
					$maxID = intval(Model('File')->max('fileID'));
					$this->writeCursor(max($this->readCursor(), $maxID), array(
						'running' => 0, 'current' => '', 'complete' => 1, 'completeMax' => $maxID,
					));
					return show_json(array(
						'message' => '全文索引已完成，没有待处理文件。如需重新提取请点「重建索引」。',
						'idle' => 1,
					));
				}
				$this->writeCursor($this->readCursor(), array('running' => 1, 'current' => '准备中', 'indexed' => 0, 'started' => time(), 'complete' => 0));
				$this->replyAndContinue('已开始处理，进度见运行情况');
				$this->runLocked(true);
				return;
			}
			if ($operation === 'rebuild') {
				$taskLock = @fopen(rtrim(TEMP_PATH, '/\\').'/elastic-fulltext-task.lock', 'c');
				if (!$taskLock || !@flock($taskLock, LOCK_EX | LOCK_NB)) {
					if ($taskLock) fclose($taskLock);
					return show_json(array('message' => '后台正在索引，请稍后再试'), false);
				}
				$heavy = @fopen(rtrim(TEMP_PATH, '/\\').'/kod-heavy-index.lock', 'c');
				if (!$heavy || !@flock($heavy, LOCK_EX | LOCK_NB)) {
					if ($heavy) fclose($heavy);
					flock($taskLock, LOCK_UN); fclose($taskLock);
					return show_json(array('message' => '全文或向量任务正在运行，请稍后重建'), false);
				}
				try {
					$this->initTable();
					$this->client()->rebuild();
					Model($this->stateTable)->where(array('fileID' => array('gt', 0)))->delete();
					$this->writeCursor(0, array('running' => 1, 'current' => '准备中', 'indexed' => 0, 'skipped' => 0, 'failed' => 0, 'scanned' => 0, 'ignored' => 0, 'started' => time(), 'complete' => 0, 'completeMax' => 0, 'cleanupCursor' => 0));
					$this->replyAndContinue('索引已重建，正在处理首批');
					try {$this->runTask(true);} catch (Throwable $e) {
						$this->log('rebuild batch failed: '.$e->getMessage(), 'error');
						$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => ''));
					}
				} finally {
					flock($heavy, LOCK_UN); fclose($heavy);
					flock($taskLock, LOCK_UN); fclose($taskLock);
				}
				return;
			}
			return show_json('Unknown operation', false);
		} catch (Throwable $e) {
			$this->log('manage '.$operation.' failed: '.$e->getMessage(), 'error');
			return show_json(array('message' => $e->getMessage()), false);
		}
	}

	public function status() {
		if (!KodUser::isRoot()) return show_json(LNG('explorer.noPermissionAction'), false);
		$this->releaseSession();
		$fast = intval(_get($_GET, 'fast', 0)) === 1;
		$html = $this->statusHtml($fast);
		$cursor = $this->readCursorData();
		return show_json(array(
			'html' => $html,
			'running' => intval(_get($cursor, 'running', 0)),
			'current' => (string)_get($cursor, 'current', ''),
		));
	}

	private function statusHtml($fast = false) {
		$this->initTable();
		$ok = false; $version = '-'; $documents = 0; $error = '';
		if (!$fast) {
			try {
				$client = $this->client(); $info = $client->info(3); $ok = true;
				$version = _get(_get($info, 'version', array()), 'number', '-');
				$documents = $client->count(3);
			} catch (Throwable $e) {$error = $e->getMessage();}
		} else {
			$ok = true; $version = ''; $documents = '-';
		}
		$extensions = $this->configuredExtensions();
		$fileTotal = intval(Model('File')->count());
		$targetTotal = $this->countTargetFiles($extensions);
		$nonDoc = max(0, $fileTotal - $targetTotal);
		$indexed = $this->countAliveStatus(1);
		$skipped = $this->countAliveStatus(2);
		$failed = $this->countAliveStatus(3);
		$pendingDocs = max(0, $targetTotal - $indexed - $skipped - $failed);
		$cursorData = $this->readCursorData();
		$cursor = intval(_get($cursorData, 'fileID', 0));
		$lastRun = intval(_get($cursorData, 'time', 0));
		if (!$lastRun) {
			$lastState = Model($this->stateTable)->order('indexTime desc')->find();
			$lastRun = intval(_get($lastState, 'indexTime', 0));
		}
		$remainFiles = $cursor > 0 ? intval(Model('File')->where(array('fileID' => array('gt', $cursor)))->count()) : $fileTotal;
		$complete = intval(_get($cursorData, 'complete', 0)) === 1 || ($pendingDocs === 0 && $remainFiles === 0 && $fileTotal > 0);
		if ($complete) $remainFiles = 0;
		$color = $ok ? '#20a53a' : '#d9822b';
		$lastRunText = $lastRun ? date('Y-m-d H:i:s', $lastRun) : '尚未运行';
		$batchSize = max(1, min(500, intval(_get($this->getConfig(), 'batchSize', 50))));
		$lastIndexed = intval(_get($cursorData, 'indexed', 0));
		$recentFail = $this->recentStatusHtml(3, 8);
		$recentSkip = $this->recentStatusHtml(2, 8);
		$running = intval(_get($cursorData, 'running', 0));
		$current = trim((string)_get($cursorData, 'current', ''));
		$pauseReason = trim((string)_get($cursorData, 'pauseReason', ''));
		$lastBeat = intval(_get($cursorData, 'time', 0));
		if ($running && $lastBeat && time() - $lastBeat > 120) {
			$running = 0;
			$current = '';
			$this->writeCursor($cursor, array('running' => 0, 'current' => ''));
		}
		$progress = '';
		if ($running) {
			$progress = '<div class="elastic-fulltext-progress"><div class="elastic-fulltext-progress-head"><span><span class="elastic-fulltext-dot is-loading"></span>正在索引</span><span class="v">本批 '.$lastIndexed.'/'.$batchSize.' · 已入库 '.$indexed.' / 可索引 '.$targetTotal.'</span></div>'
				.($current ? '<div class="elastic-fulltext-progress-name" title="'.$this->escape($current).'">'.$this->escape($current).'</div>' : '')
				.'</div>';
		} else if ($pauseReason !== '') {
			$progress = '<div class="elastic-fulltext-progress"><div class="elastic-fulltext-progress-head"><span style="color:#d9822b">背压暂停</span><span class="v">系统恢复后下一轮自动继续</span></div><div class="elastic-fulltext-progress-name">'.$this->escape($pauseReason).'</div></div>';
		} else if ($complete) {
			$progress = '<div class="elastic-fulltext-progress is-done"><div class="elastic-fulltext-progress-head"><span>全文扫描已完成</span><span class="v">已入库 '.$indexed.' / 可索引 '.$targetTotal.'</span></div></div>';
		}
		$esLine = $fast ? '' : '<div class="elastic-fulltext-es"><span style="color:'.$color.'">● '.($ok ? '正常' : '异常').'</span> '.$this->escape($version).'</div>';
		$totals = $this->statusStat('网盘文件', $fileTotal)
			.$this->statusStat('可索引', $targetTotal)
			.$this->statusStat('已入库', $indexed)
			.$this->statusStat('待处理', $pendingDocs)
			.$this->statusStat('非文档', $nonDoc)
			.$this->statusStat('过大', $skipped)
			.$this->statusStat('失败', $failed)
			.$this->statusStat('待扫描', $remainFiles)
			.$this->statusStat('上次', $lastRunText, true);
		$why = '<div class="elastic-fulltext-hint">「可索引」只统计允许的扩展名；图片、视频等计入「非文档」。「待处理」= 可索引 − 已入库 − 过大 − 失败。「待扫描」是游标之后还未走过的物理文件，不是 fileID 差值。</div>';
		$esNote = '';
		if (!$fast && is_int($documents) && $documents !== $indexed) {
			$esNote = '<div class="elastic-fulltext-hint">Elasticsearch 当前 '.$documents.' 篇。与「已入库」不一致时，多为已删除文件尚未从索引清理，下一轮扫描会回收。</div>';
		}
		return $progress.$esLine
			.'<div class="elastic-fulltext-metrics">'.$totals.'</div>'
			.$why.$esNote
			.($recentFail ? '<div class="elastic-fulltext-note"><span class="k">失败</span> '.$recentFail.'</div>' : '')
			.($recentSkip ? '<div class="elastic-fulltext-note"><span class="k">过大</span> '.$recentSkip.'</div>' : '')
			.'<div class="elastic-fulltext-status-actions"><button type="button" class="btn btn-primary btn-sm elastic-fulltext-action" data-operation="run">立即处理一批</button> <button type="button" class="btn btn-default btn-sm elastic-fulltext-action" data-operation="rebuild">重建索引</button></div>'
			.($error ? '<div class="elastic-fulltext-error">'.$this->escape($error).'</div>' : '');
	}

	private function statusStat($label, $value, $wide = false) {
		$class = 'elastic-fulltext-stat'.($wide ? ' is-wide' : '');
		return '<div class="'.$class.'"><span class="k">'.$this->escape((string)$label).'</span><span class="v">'.$this->escape((string)$value).'</span></div>';
	}

	private function countTargetFiles($extensions) {
		$bits = array();
		foreach ((array)$extensions as $ext) {
			$ext = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$ext));
			if ($ext === '') continue;
			$bits[] = "LOWER(`name`) LIKE '%.".$ext."'";
		}
		if (!$bits) return 0;
		try {
			return intval(Model('File')->where(implode(' OR ', $bits))->count());
		} catch (Throwable $e) {
			$total = 0;
			foreach ((array)$extensions as $ext) {
				$ext = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$ext));
				if ($ext === '') continue;
				$total += intval(Model('File')->where(array('name' => array('like', '%.'.$ext)))->count());
			}
			return $total;
		}
	}

	private function countAliveStatus($status) {
		$rows = Model($this->stateTable)->where(array('status' => intval($status)))->field('fileID')->select();
		$ids = array();
		foreach ((array)$rows as $row) {
			$id = intval(_get($row, 'fileID', 0));
			if ($id) $ids[] = $id;
		}
		if (!$ids) return 0;
		return intval(Model('File')->where(array('fileID' => array('in', $ids)))->count());
	}

	private function initTable() {
		if (in_array($this->stateTable, Model()->db()->getTables(), true)) return;
		$file = stristr($GLOBALS['config']['database']['DB_TYPE'], 'sqlite') ? 'sqlite.sql' : 'mysql.sql';
		foreach (sqlSplit(file_get_contents($this->pluginPath.'lib/data/'.$file)) as $sql) if (trim($sql)) Model()->db()->execute($sql);
	}

	private function updateTask($enable) {
		$event = $this->pluginName.'Plugin.task';
		$task = Model('SystemTask')->findByKey('event', $event);
		$data = array(
			'name' => LNG('elasticFulltext.meta.name'), 'type' => 'method', 'event' => $event,
			'time' => '{"type":"minute","minute":1}',
			'desc' => LNG('elasticFulltext.meta.desc'), 'enable' => $enable, 'system' => 1,
		);
		if ($task) return Model('SystemTask')->update($task['id'], array('name' => $data['name'], 'time' => $data['time'], 'enable' => $enable, 'event' => $event));
		return Model('SystemTask')->add($data);
	}

	private function client($config = null) {
		include_once($this->pluginPath.'lib/ElasticClient.class.php');
		include_once($this->pluginPath.'lib/CorpusShare.class.php');
		return new KodboxElasticClient($config === null ? $this->getConfig() : $config);
	}

	private function normalizeExtensions($value) {
		if (is_array($value)) $value = implode(',', $value);
		$items = preg_split('/[\s,;]+/', strtolower((string)$value));
		return array_values(array_unique(array_filter(array_map(function($v) {return preg_replace('/[^a-z0-9]+/', '', $v);}, $items))));
	}

	private function configuredExtensions($config = null) {
		$config = $config === null ? $this->getConfig() : $config;
		$allowed = $this->normalizeExtensions(_get($config, 'allowExtensions', ''));
		if (_get($config, 'extensionMode', 'allow') !== 'deny') return $allowed;
		return array_values(array_diff($allowed, $this->normalizeExtensions(_get($config, 'denyExtensions', ''))));
	}

	private function corpusFingerprint($config) {
		return sha1(json_encode(array(
			'url' => rtrim((string)_get($config, 'elasticUrl', ''), '/'),
			'index' => strtolower((string)_get($config, 'indexName', 'kodbox-fulltext')),
			'indexedChars' => max(100000, min(5000000, intval(_get($config, 'indexedChars', 1000000)))),
		)));
	}

	private function scanFingerprint($config) {
		return sha1(json_encode(array(
			'extensions' => $this->configuredExtensions($config),
			'maxFileSizeMB' => max(1, min(500, intval(_get($config, 'maxFileSizeMB', 30)))),
		)));
	}

	private function documentMetadataMatches($doc, $file) {
		$left = array_values(array_unique(array_filter(array_map('intval', (array)_get($doc, 'ancestorIDs', array())))));
		$right = array_values(array_unique(array_filter(array_map('intval', (array)_get($file, 'ancestorIDs', array())))));
		sort($left);
		sort($right);
		return intval(_get($doc, 'modifyTime', 0)) >= intval(_get($file, 'modifyTime', 0))
			&& intval(_get($doc, 'size', -1)) === intval(_get($file, 'size', 0))
			&& intval(_get($doc, 'sourceID', -1)) === intval(_get($file, 'sourceID', 0))
			&& (string)_get($doc, 'name', '') === (string)_get($file, 'name', '')
			&& strtolower((string)_get($doc, 'ext', '')) === strtolower((string)_get($file, 'fileType', ''))
			&& $left === $right;
	}

	private function searchInFolder($words, $limit, $parentID) {
		$client = $this->client();
		if (!$parentID) return $client->search($words, $limit);
		$scope = KodboxCorpusShare::folderFileIDs($parentID, 4000);
		if (!empty($scope['complete'])) {
			$ids = (array)_get($scope, 'ids', array());
			if (!$ids) return array();
			return $client->search($words, $limit, $ids);
		}
		$raw = $client->search($words, $limit, null, $parentID);
		$hits = $this->hitsInside($parentID, $raw);
		if (count($raw) >= $limit && count($hits) < count($raw)) {
			$raw = $client->search($words, min(2000, $limit * 2), null, $parentID);
			$hits = array_slice($this->hitsInside($parentID, $raw), 0, $limit);
		}
		$seen = array();
		foreach ($hits as $hit) $seen[intval($hit['fileID'])] = true;
		$after = null;
		for ($i = 0; $i < 4; $i++) {
			$page = $client->searchPage($words, 200, $after, true);
			if (empty($page['hits'])) break;
			$after = _get($page, 'after', null);
			$worst = -1;
			if (count($hits) >= $limit) {
				$worst = floatval(_get($hits[0], 'score', 0));
				foreach ($hits as $hit) {
					$score = floatval(_get($hit, 'score', 0));
					if ($score < $worst) $worst = $score;
				}
			}
			if ($worst >= 0 && floatval(_get($page['hits'][0], 'score', 0)) <= $worst) break;
			$ids = array();
			foreach ($page['hits'] as $hit) {
				if ($worst >= 0 && floatval(_get($hit, 'score', 0)) <= $worst) break;
				$ids[] = intval($hit['fileID']);
			}
			$allow = array_flip(KodboxCorpusShare::keepInFolder($parentID, $ids));
			foreach ($page['hits'] as $hit) {
				$id = intval($hit['fileID']);
				if (!$id || isset($seen[$id]) || !isset($allow[$id])) continue;
				if ($worst >= 0 && floatval(_get($hit, 'score', 0)) <= $worst) break;
				$seen[$id] = true;
				$hits[] = $hit;
			}
			usort($hits, function($a, $b) {
				$sa = floatval(_get($a, 'score', 0));
				$sb = floatval(_get($b, 'score', 0));
				if ($sa == $sb) return intval($a['fileID']) - intval($b['fileID']);
				return $sa > $sb ? -1 : 1;
			});
			if (count($hits) > $limit) $hits = array_slice($hits, 0, $limit);
			if (empty($page['more'])) break;
		}
		return $hits;
	}

	private function hitsInside($parentID, $hits) {
		$ids = array();
		foreach ((array)$hits as $hit) $ids[] = intval(_get($hit, 'fileID', 0));
		$allow = array_flip(KodboxCorpusShare::keepInFolder($parentID, $ids));
		$out = array();
		foreach ((array)$hits as $hit) {
			$id = intval(_get($hit, 'fileID', 0));
			if ($id && isset($allow[$id])) $out[] = $hit;
		}
		return $out;
	}

	private function recoverStaleRun() {
		$data = $this->readCursorData();
		if (!intval(_get($data, 'running', 0))) return;
		$beat = intval(_get($data, 'time', 0));
		if ($beat && time() - $beat > 120) {
			$this->writeCursor(intval(_get($data, 'fileID', 0)), array('running' => 0, 'current' => ''));
		}
	}

	private function hasPendingWork() {
		$this->initTable();
		$config = $this->getConfig();
		$cursorData = $this->readCursorData();
		if (_get($cursorData, 'corpusHash', '') !== $this->corpusFingerprint($config) ||
			_get($cursorData, 'scanHash', '') !== $this->scanFingerprint($config)) return true;
		if (intval(Model($this->stateTable)->where(array('status' => 3, 'indexTime' => array('lt', time() - 900)))->count()) > 0) return true;
		$maxID = intval(Model('File')->max('fileID'));
		if ($maxID <= 0) return false;
		$data = $this->readCursorData();
		$cursor = intval(_get($data, 'fileID', 0));
		if (intval(Model('File')->where(array('fileID' => array('gt', $cursor)))->count()) > 0) return true;
		$completeMax = intval(_get($data, 'completeMax', 0));
		if (intval(_get($data, 'complete', 0)) === 1 && $maxID <= $completeMax && $cursor >= $maxID) return false;
		$extensions = $this->configuredExtensions();
		$rows = Model('File')->order('fileID desc')->limit(40)->select();
		foreach ((array)$rows as $file) {
			$ext = strtolower(get_path_ext(_get($file, 'name', '')));
			if (!$ext || !in_array($ext, $extensions, true)) continue;
			$state = Model($this->stateTable)->where(array('fileID' => intval($file['fileID'])))->find();
			$modifyTime = intval(_get($file, 'modifyTime', 0));
			if ($state && intval($state['modifyTime']) >= $modifyTime && in_array(intval($state['status']), array(1, 2), true)) continue;
			return true;
		}
		return false;
	}

	private function taskBusy() {
		$path = rtrim(TEMP_PATH, '/\\').'/elastic-fulltext-task.lock';
		$lock = @fopen($path, 'c');
		if (!$lock) return true;
		$busy = !@flock($lock, LOCK_EX | LOCK_NB);
		if (!$busy) @flock($lock, LOCK_UN);
		@fclose($lock);
		return $busy;
	}

	private function replyAndContinue($message) {
		@ob_get_clean();
		if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
		$encode = function_exists('json_encode_force') ? 'json_encode_force' : 'json_encode';
		echo $encode(array(
			'code' => true,
			'data' => array('message' => $message),
			'timeUse' => '0',
			'timeNow' => (string)microtime(true),
		));
		if (class_exists('KodLog')) KodLog::$checkClientAbort = false;
		if (function_exists('http_close')) http_close();
		else {
			if (function_exists('ignore_timeout')) ignore_timeout();
			if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
		}
		if (class_exists('KodLog')) KodLog::$checkClientAbort = false;
	}

	private function releaseSession() {
		if (function_exists('session_write_close')) @session_write_close();
	}

	private function keepConfig($config, $prev, $key, $default) {
		if (!array_key_exists($key, $config) || $config[$key] === '' || $config[$key] === null) {
			return _get($prev, $key, $default);
		}
		return $config[$key];
	}

	private function isOpen() {return _get($this->getConfig(), 'serviceEnabled', '1') == '1';}

	private function isPlainText($ext) {
		return in_array($ext, array('txt','md','log','csv','json','xml','html','htm','css','js','php','py','java','c','cpp','h','ini','yaml','yml'), true);
	}

	private function readCursorData() {
		$data = is_file($this->cursorFile) ? json_decode(@file_get_contents($this->cursorFile), true) : array();
		$data = is_array($data) ? $data : array();
		$data['fileID'] = intval(_get($data, 'fileID', _get($data, 'sourceID', 0)));
		$data['time'] = intval(_get($data, 'time', 0));
		return $data;
	}

	private function readCursor() {
		return intval(_get($this->readCursorData(), 'fileID', 0));
	}

	private function writeCursor($fileID, $extra = null) {
		$prev = $this->readCursorData();
		$data = array('fileID' => intval($fileID), 'time' => time());
		foreach (array('indexed', 'skipped', 'failed', 'scanned', 'ignored', 'running', 'complete', 'completeMax', 'cleanupCursor') as $key) {
			$data[$key] = is_array($extra) && array_key_exists($key, $extra) ? intval($extra[$key]) : intval(_get($prev, $key, 0));
		}
		foreach (array('corpusHash', 'scanHash') as $key) {
			$data[$key] = is_array($extra) && array_key_exists($key, $extra) ? (string)$extra[$key] : (string)_get($prev, $key, '');
		}
		$data['current'] = is_array($extra) && array_key_exists('current', $extra) ? (string)$extra['current'] : (string)_get($prev, 'current', '');
		$data['pauseReason'] = is_array($extra) && array_key_exists('pauseReason', $extra) ? (string)$extra['pauseReason'] : (string)_get($prev, 'pauseReason', '');
		$started = is_array($extra) && array_key_exists('started', $extra) ? intval($extra['started']) : intval(_get($prev, 'started', 0));
		if (intval($data['running']) && !$started) $started = time();
		if (!intval($data['running'])) $started = 0;
		$data['started'] = $started;
		@file_put_contents($this->cursorFile.'.tmp', json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
		@rename($this->cursorFile.'.tmp', $this->cursorFile);
	}

	private function recentStatusHtml($status, $limit) {
		$rows = Model($this->stateTable)->where(array('status' => intval($status)))->order('indexTime desc')->limit(max(1, intval($limit)))->select();
		if (!$rows) return '';
		$items = array();
		foreach ((array)$rows as $row) {
			$file = Model('File')->where(array('fileID' => intval($row['fileID'])))->find();
			$name = $file ? _get($file, 'name', '') : ('#'.intval($row['fileID']));
			$when = intval($row['indexTime']) ? date('m-d H:i', intval($row['indexTime'])) : '';
			$reason = trim((string)_get($row, 'error', ''));
			$items[] = $this->escape($name).' <span style="color:#888">'.$when.($reason ? ' · '.$this->escape($reason) : '').'</span>';
		}
		return '<ul style="margin:4px 0 0;padding-left:18px"><li>'.implode('</li><li>', $items).'</li></ul>';
	}

	private function fileLabel($file) {
		$id = intval(_get($file, 'fileID', 0));
		$name = _get($file, 'name', '');
		return 'fileID='.$id.($name !== '' && $name !== null ? ' name='.$name : '');
	}

	private function sanitizeSnippet($text) {
		$text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8');
		$text = str_replace(array("\0", '&nbsp;', '&quot;', '<', '>'), array('', ' ', '"', ' ', ' '), $text);
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $text);
		$text = preg_replace('/\s+/u', ' ', $text);
		$text = trim((string)$text);
		if (function_exists('utf8Repair')) $text = utf8Repair($text);
		if (function_exists('mb_substr')) {
			if (mb_strlen($text, 'UTF-8') > 300) $text = mb_substr($text, 0, 300, 'UTF-8').'...';
		} elseif (strlen($text) > 900) {
			$text = substr($text, 0, 900).'...';
		}
		return $text;
	}

	private function escape($value) {return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');}
	private function log($message, $level = 'info') {
		if ($level === 'info' && _get($this->getConfig(), 'debugMode', 0) != '1') return;
		write_log('[elasticFulltext] '.$message, 'elasticFulltext', $level);
	}
}
