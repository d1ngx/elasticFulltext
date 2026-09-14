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
		$config['elasticUrl'] = rtrim(trim(_get($config, 'elasticUrl', 'http://elasticsearch:9200')), '/');
		if (!preg_match('#^https?://#i', $config['elasticUrl'])) throw new Exception('Elasticsearch URL must begin with http:// or https://');
		$config['indexName'] = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '-', trim(_get($config, 'indexName', 'kodbox-fulltext'))));
		if (!$config['indexName'] || $config['indexName'][0] === '_' || $config['indexName'][0] === '-') throw new Exception('Invalid Elasticsearch index name');
		$config['maxFileSizeMB'] = max(1, min(500, intval(_get($config, 'maxFileSizeMB', 50))));
		$config['batchSize'] = max(1, min(200, intval(_get($config, 'batchSize', 20))));
		$config['serviceEnabled'] = _get($config, 'serviceEnabled', '1') == '1' ? 1 : 0;
		$config['extensionMode'] = _get($config, 'extensionMode', 'allow') === 'deny' ? 'deny' : 'allow';
		$config['allowExtensions'] = implode(',', $this->normalizeExtensions(_get($config, 'allowExtensions', '')));
		$config['denyExtensions'] = implode(',', $this->normalizeExtensions(_get($config, 'denyExtensions', '')));
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
			$this->log('search failed: '.$e->getMessage());
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
		if (!$this->isOpen()) return 0;
		$lock = @fopen(rtrim(TEMP_PATH, '/\\').'/elastic-fulltext-task.lock', 'c');
		if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {if ($lock) fclose($lock); return 0;}
		try {return $this->runTask();}
		finally {@flock($lock, LOCK_UN); @fclose($lock);}
	}

	private function runTask() {
		$this->initTable();
		$client = $this->client();
		$client->ensureInfrastructure();
		$config = $this->getConfig();
		$batch = max(1, min(200, intval(_get($config, 'batchSize', 20))));
		$cursor = $this->readCursor();
		$extensions = $this->configuredExtensions($config);
		if (!$extensions) return 0;
		$failedRows = Model($this->stateTable)->where(array('status' => 3))->order('indexTime asc')->limit(min(5, $batch))->select();
		foreach ((array)$failedRows as $failed) {
			$file = Model('File')->where(array('fileID' => intval($failed['fileID'])))->find();
			$ext = $file ? strtolower(get_path_ext(_get($file, 'name', ''))) : '';
			if ($file && in_array($ext, $extensions, true)) $this->indexFileRecord($file, $ext, $client, $config);
		}
		// 官方 docSearch 以 io_file 为扫描源。物理 fileID 天然去重，也能直接使用真实修改时间。
		$where = array('fileID' => array('gt', $cursor));
		$rows = Model('File')->where($where)->order('fileID asc')->limit($batch)->select();
		$processed = 0;
		foreach ((array)$rows as $file) {
			$cursor = max($cursor, intval($file['fileID']));
			$ext = strtolower(get_path_ext(_get($file, 'name', '')));
			if (!$ext || !in_array($ext, $extensions, true)) continue;
			if ($this->indexFileRecord($file, $ext, $client, $config)) $processed++;
		}
		if (count((array)$rows) < $batch) {
			$this->cleanupDeleted($client, $batch * 2);
			$cursor = 0;
		}
		$this->writeCursor($cursor);
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
			$this->saveState($file, 2, 'Empty file');
			return false;
		}
		if (intval(_get($file, 'size', 0)) >= $maxBytes) {
			$this->saveState($file, 2, 'File exceeds size limit');
			return false;
		}
		try {
			$path = _get($file, 'path', '');
			if (!$path || !IO::exist($path)) throw new Exception('Physical file path is missing');
			$content = IO::getContent($path);
			if ($content === false) throw new Exception('Cannot read source content');
			$document = $file;
			$document['sourceID'] = 0;
			$document['fileType'] = $ext;
			$client->indexFile($document, $content, $this->isPlainText($ext));
			$this->saveState($file, 1, '');
			return true;
		} catch (Throwable $e) {
			$this->saveState($file, 3, substr($e->getMessage(), 0, 1000));
			$this->log('index file='.$fileID.' failed: '.$e->getMessage());
			return false;
		}
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
		$operation = trim(Input::get('operation', 'require'));
		try {
			if ($operation === 'test') {
				$info = $this->client()->info();
				return show_json(array('message' => 'Elasticsearch '.$info['version']['number'].' 连接正常'));
			}
			if ($operation === 'run') {
				$count = $this->task();
				return show_json(array('message' => '本批次已索引 '.$count.' 个文件'));
			}
			if ($operation === 'rebuild') {
				$this->initTable();
				$this->client()->rebuild();
				Model($this->stateTable)->where(array('fileID' => array('gt', 0)))->delete();
				$this->writeCursor(0);
				$count = $this->task();
				return show_json(array('message' => '索引已重建，首批完成 '.$count.' 个文件'));
			}
			return show_json('Unknown operation', false);
		} catch (Throwable $e) {
			$this->log('manage '.$operation.' failed: '.$e->getMessage());
			return show_json(array('message' => $e->getMessage()), false);
		}
	}

	public function status() {
		if (!KodUser::isRoot()) return show_json(LNG('explorer.noPermissionAction'), false);
		return show_json(array('html' => $this->statusHtml()));
	}

	private function statusHtml() {
		$this->initTable();
		$ok = false; $version = '-'; $documents = 0; $error = '';
		try {
			$client = $this->client(); $info = $client->info(3); $ok = true;
			$version = _get(_get($info, 'version', array()), 'number', '-');
			$documents = $client->count();
		} catch (Throwable $e) {$error = $e->getMessage();}
		$indexed = intval(Model($this->stateTable)->where(array('status' => 1))->count());
		$skipped = intval(Model($this->stateTable)->where(array('status' => 2))->count());
		$failed = intval(Model($this->stateTable)->where(array('status' => 3))->count());
		$cursor = $this->readCursor();
		$maxID = intval(Model('File')->max('fileID'));
		$pending = max(0, $maxID - $cursor);
		$color = $ok ? '#20a53a' : '#d9822b';
		return '<div style="line-height:1.9"><div><b>Elasticsearch：</b><span style="color:'.$color.'">● '.($ok ? '正常' : '异常').'</span> '.$this->escape($version).'</div>'
			.'<div><b>扫描进度：</b>'.$cursor.' / '.$maxID.'；<b>待扫描（估算）：</b>'.$pending.'</div>'
			.'<div><b>索引文档：</b>'.$documents.'；<b>成功：</b>'.$indexed.'；<b>跳过：</b>'.$skipped.'；<b>待重试：</b>'.$failed.'</div>'
			.'<div class="elastic-fulltext-status-actions"><button type="button" class="btn btn-primary btn-sm elastic-fulltext-action" data-operation="run">立即处理一批</button> <button type="button" class="btn btn-default btn-sm elastic-fulltext-action" data-operation="rebuild">重建索引</button></div>'
			.($error ? '<div style="color:#c62828">'.$this->escape($error).'</div>' : '').'</div>';
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
		if ($task) return Model('SystemTask')->update($task['id'], array('name' => $data['name'], 'time' => $data['time'], 'enable' => $enable));
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

	private function isOpen() {return _get($this->getConfig(), 'serviceEnabled', '1') == '1';}

	private function isPlainText($ext) {
		return in_array($ext, array('txt','md','log','csv','json','xml','html','htm','css','js','php','py','java','c','cpp','h','ini','yaml','yml'), true);
	}

	private function readCursor() {
		$data = is_file($this->cursorFile) ? json_decode(@file_get_contents($this->cursorFile), true) : array();
		return intval(_get((array)$data, 'fileID', _get((array)$data, 'sourceID', 0)));
	}

	private function writeCursor($fileID) {
		@file_put_contents($this->cursorFile, json_encode(array('fileID' => intval($fileID), 'time' => time())), LOCK_EX);
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
	private function log($message) {write_log('[elasticFulltext] '.$message, 'elasticFulltext', 'error');}
}
