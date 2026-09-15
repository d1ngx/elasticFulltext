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
		Hook::bind('explorer.listSearch.searchDataBefore', 'elasticFulltextPlugin.searchBefore');
		Hook::bind('explorer.listSearch.searchDataAfter', 'elasticFulltextPlugin.searchAfter');
		Hook::bind('explorer.listSearch.fileContentText', 'elasticFulltextPlugin.fileContentText');
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
		$config['maxFileSizeMB'] = max(1, min(500, intval($this->keepConfig($config, $prev, 'maxFileSizeMB', 50))));
		$config['batchSize'] = max(1, min(500, intval($this->keepConfig($config, $prev, 'batchSize', 50))));
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
		if (!$this->isOpen()) return $param;
		if (!is_array($param) || empty($param['words']) || !in_array('content', (array)_get($param, 'option', array()), true)) return $param;
		if (strlen($param['words']) <= 1 || empty($param['parentID'])) return $param;
		try {
			$result = $this->client()->search($param['words'], 1000);
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
			$param['fileID'] = $fileIDs ? array_values(array_unique($fileIDs)) : array(-1);
			$param['_elasticFulltext'] = 1;
		} catch (Throwable $e) {
			$this->log('search failed: '.$e->getMessage(), 'error');
		}
		return $param;
	}

	public function searchAfter($param, $listData) {
		if (empty($param['_elasticFulltext']) || !is_array($listData)) return $listData;
		if (!isset($listData['fileList']) || !is_array($listData['fileList'])) return $listData;
		$filtered = array();
		foreach ($listData['fileList'] as $item) {
			$fileID = intval(_get($item, 'fileID', 0));
			if (!$fileID || !isset($this->matchedFileIDs[$fileID])) continue;
			if (isset($this->snippets[$fileID])) $item['searchContentMatch'] = $this->snippets[$fileID];
			$filtered[] = $item;
		}
		$listData['fileList'] = $filtered;
		$listData['folderList'] = array();
		if (!isset($listData['pageInfo']) || !is_array($listData['pageInfo'])) $listData['pageInfo'] = array();
		$listData['pageInfo']['totalNum'] = count($filtered);
		$listData['pageInfo']['pageTotal'] = 1;
		$listData['disableSort'] = 1;
		return $listData;
	}

	// 兼容 Kodbox 对物理路径及其他插件发起的正文读取。
	public function fileContentText($file, $makeNow = false) {
		if (!$this->isOpen()) return false;
		$fileID = intval(_get((array)$file, 'fileID', 0));
		if (!$fileID) return false;
		try {
			$content = $this->client()->getContent($fileID);
			return $content !== '' ? $content : false;
		} catch (Throwable $e) {
			return false;
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
		try {
			if (function_exists('ignore_timeout')) ignore_timeout();
			@ignore_user_abort(true);
			if (class_exists('KodLog')) KodLog::$checkClientAbort = false;
			return $this->runTask($fillBatch);
		} catch (Throwable $e) {
			$this->log('task failed: '.$e->getMessage(), 'error');
			$this->writeCursor($this->readCursor(), array('running' => 0, 'current' => ''));
			return 0;
		} finally {@flock($lock, LOCK_UN); @fclose($lock);}
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
		$cursor = $this->readCursor();
		$extensions = $this->configuredExtensions($config);
		if (!$extensions) return 0;
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
			'indexed' => 0, 'skipped' => 0, 'failed' => 0, 'scanned' => 0, 'ignored' => 0,
		));
		$failedRows = Model($this->stateTable)->where(array('status' => 3, 'indexTime' => array('lt', time() - 900)))->order('indexTime asc')->limit(min(5, $batch))->select();
		foreach ((array)$failedRows as $failedRow) {
			if (microtime(true) >= $deadline) break;
			$file = Model('File')->where(array('fileID' => intval($failedRow['fileID'])))->find();
			$ext = $file ? strtolower(get_path_ext(_get($file, 'name', ''))) : '';
			if ($file && in_array($ext, $extensions, true)) {
				$this->writeCursor($cursor, array(
					'running' => 1, 'current' => (string)_get($file, 'name', ''), 'started' => $startedAt,
					'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'scanned' => $scanned, 'ignored' => $ignored,
				));
				$result = $this->indexFileRecord($file, $ext, $client, $config);
				if ($result === 'ok') $processed++;
				else if ($result === 'skip') $skipped++;
				else if ($result === 'fail') $failed++;
			}
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
			foreach ((array)$rows as $file) {
				$cursor = max($cursor, intval($file['fileID']));
				$scanned++;
				$ext = strtolower(get_path_ext(_get($file, 'name', '')));
				if ($ext && in_array($ext, $extensions, true)) {
					$this->writeCursor($cursor, array(
						'running' => 1, 'current' => (string)_get($file, 'name', ''), 'started' => $startedAt,
						'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'scanned' => $scanned, 'ignored' => $ignored,
					));
					$result = $this->indexFileRecord($file, $ext, $client, $config);
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
		$this->writeCursor($cursor, array(
			'indexed' => $processed, 'skipped' => $skipped, 'failed' => $failed,
			'scanned' => $scanned, 'ignored' => $ignored, 'running' => 0, 'current' => '',
		));
		$this->log('batch indexed='.$processed.' skipped='.$skipped.' failed='.$failed.' ignored='.$ignored.' scanned='.$scanned.' cursor='.$cursor);
		return $processed;
	}

	private function indexFileRecord($file, $ext, $client, $config) {
		$fileID = intval(_get($file, 'fileID', 0));
		$modifyTime = intval(_get($file, 'modifyTime', 0));
		if (!$fileID) return false;
		$state = Model($this->stateTable)->where(array('fileID' => $fileID))->find();
		if ($state && intval($state['modifyTime']) >= $modifyTime && in_array(intval($state['status']), array(1, 2), true)) return false;
		$maxBytes = intval(_get($config, 'maxFileSizeMB', 50)) * 1024 * 1024;
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
			$path = _get($file, 'path', '');
			if (!$path || !IO::exist($path)) throw new Exception('找不到物理文件');
			$content = IO::getContent($path);
			if ($content === false) throw new Exception('无法读取文件内容');
			if (!$this->isPlainText($ext)) $content = $this->sanitizeOfficeZip($content, $ext);
			$document = $file;
			$document['sourceID'] = 0;
			$document['fileType'] = $ext;
			$client->indexFile($document, $content, $this->isPlainText($ext));
			$this->saveState($file, 1, '');
			$this->log('ok '.$this->fileLabel($file).' size='.intval(_get($file, 'size', 0)));
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
		$states = Model($this->stateTable)->order('indexTime asc')->limit(max(1, intval($limit)))->select();
		foreach ((array)$states as $state) {
			$exists = Model('File')->where(array('fileID' => intval($state['fileID'])))->count();
			if ($exists) {
				Model($this->stateTable)->where(array('fileID' => intval($state['fileID'])))->save(array('indexTime' => time()));
				continue;
			}
			try {$client->deleteFile(intval($state['fileID']));} catch (Throwable $e) {}
			Model($this->stateTable)->where(array('fileID' => intval($state['fileID'])))->delete();
		}
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
				if ($this->taskBusy()) return show_json(array('message' => '后台正在索引，请稍后再试'));
				$this->writeCursor($this->readCursor(), array('running' => 1, 'current' => '准备中', 'indexed' => 0, 'started' => time()));
				$this->replyAndContinue('已开始处理，进度见运行情况');
				$this->runLocked(true);
				return;
			}
			if ($operation === 'rebuild') {
				if ($this->taskBusy()) return show_json(array('message' => '后台正在索引，请稍后再试'));
				$this->initTable();
				$this->client()->rebuild();
				Model($this->stateTable)->where(array('fileID' => array('gt', 0)))->delete();
				$this->writeCursor(0, array('running' => 1, 'current' => '准备中', 'indexed' => 0, 'skipped' => 0, 'failed' => 0, 'scanned' => 0, 'ignored' => 0, 'started' => time()));
				$this->replyAndContinue('索引已重建，正在处理首批');
				$this->runLocked(true);
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
		$indexed = intval(Model($this->stateTable)->where(array('status' => 1))->count());
		$skipped = intval(Model($this->stateTable)->where(array('status' => 2))->count());
		$failed = intval(Model($this->stateTable)->where(array('status' => 3))->count());
		$cursorData = $this->readCursorData();
		$cursor = intval(_get($cursorData, 'fileID', 0));
		$lastRun = intval(_get($cursorData, 'time', 0));
		$maxID = intval(Model('File')->max('fileID'));
		$pending = max(0, $maxID - $cursor);
		$color = $ok ? '#20a53a' : '#d9822b';
		$lastRunText = $lastRun ? date('Y-m-d H:i:s', $lastRun) : '尚未运行';
		$batchSize = max(1, min(500, intval(_get($this->getConfig(), 'batchSize', 50))));
		$lastIndexed = intval(_get($cursorData, 'indexed', 0));
		$recentFail = $this->recentStatusHtml(3, 8);
		$recentSkip = $this->recentStatusHtml(2, 8);
		$running = intval(_get($cursorData, 'running', 0));
		$current = trim((string)_get($cursorData, 'current', ''));
		$lastBeat = intval(_get($cursorData, 'time', 0));
		if ($running && $lastBeat && time() - $lastBeat > 120) {
			$running = 0;
			$current = '';
			$this->writeCursor($cursor, array('running' => 0, 'current' => ''));
		}
		$progress = '';
		if ($running) {
			$progress = '<div class="elastic-fulltext-progress"><div class="elastic-fulltext-progress-head"><span><span class="elastic-fulltext-dot is-loading"></span>正在索引</span><span class="v">'.$lastIndexed.'/'.$batchSize.'</span></div>'
				.($current ? '<div class="elastic-fulltext-progress-name" title="'.$this->escape($current).'">'.$this->escape($current).'</div>' : '')
				.'</div>';
		}
		$esLine = $fast ? '' : '<div class="elastic-fulltext-es"><span style="color:'.$color.'">● '.($ok ? '正常' : '异常').'</span> '.$this->escape($version).'</div>';
		$indexedValue = $documents === '-' ? $indexed : $documents;
		$totals = $this->statusStat('扫描', $cursor.' / '.$maxID, true)
			.$this->statusStat('索引', $indexedValue)
			.$this->statusStat('剩余', $pending)
			.$this->statusStat('过大', $skipped)
			.$this->statusStat('失败', $failed)
			.$this->statusStat('上次', $lastRun ? $lastRunText : '尚未运行', true);
		return $progress.$esLine
			.'<div class="elastic-fulltext-metrics">'.$totals.'</div>'
			.($recentFail ? '<div class="elastic-fulltext-note"><span class="k">失败</span> '.$recentFail.'</div>' : '')
			.($recentSkip ? '<div class="elastic-fulltext-note"><span class="k">过大</span> '.$recentSkip.'</div>' : '')
			.'<div class="elastic-fulltext-status-actions"><button type="button" class="btn btn-primary btn-sm elastic-fulltext-action" data-operation="run">立即处理一批</button> <button type="button" class="btn btn-default btn-sm elastic-fulltext-action" data-operation="rebuild">重建索引</button></div>'
			.($error ? '<div class="elastic-fulltext-error">'.$this->escape($error).'</div>' : '');
	}

	private function statusStat($label, $value, $wide = false) {
		$class = 'elastic-fulltext-stat'.($wide ? ' is-wide' : '');
		return '<div class="'.$class.'"><span class="k">'.$this->escape((string)$label).'</span><span class="v">'.$this->escape((string)$value).'</span></div>';
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

	private function recoverStaleRun() {
		$data = $this->readCursorData();
		if (!intval(_get($data, 'running', 0))) return;
		$beat = intval(_get($data, 'time', 0));
		if ($beat && time() - $beat > 120) {
			$this->writeCursor(intval(_get($data, 'fileID', 0)), array('running' => 0, 'current' => ''));
		}
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
		foreach (array('indexed', 'skipped', 'failed', 'scanned', 'ignored', 'running') as $key) {
			$data[$key] = is_array($extra) && array_key_exists($key, $extra) ? intval($extra[$key]) : intval(_get($prev, $key, 0));
		}
		$data['current'] = is_array($extra) && array_key_exists('current', $extra) ? (string)$extra['current'] : (string)_get($prev, 'current', '');
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
		$text = str_replace(array("\0", '&nbsp;', '&quot;'), array('', ' ', '"'), $text);
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]+/', '', $text);
		$text = preg_replace("/\r\n|\r/", "\n", $text);
		$text = preg_replace("/\n{2,}/", "\n", $text);
		$text = preg_replace('/[ \t]{2,}/', ' ', $text);
		$text = trim($text, " \t\r\n\f");
		return function_exists('utf8Repair') ? utf8Repair($text) : $text;
	}

	private function escape($value) {return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');}
	private function log($message, $level = 'info') {
		if ($level === 'info' && _get($this->getConfig(), 'debugMode', 0) != '1') return;
		write_log('[elasticFulltext] '.$message, 'elasticFulltext', $level);
	}
}
