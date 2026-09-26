<?php

if (class_exists('KodboxCorpusShare')) return;

class KodboxCorpusShare {
	const FULLTEXT_TABLE = 'plugin_elastic_fulltext_state';
	const AIRAG_TABLE = 'plugin_airag_state';
	const ST_ES = 1;
	const ST_OK = 2;
	const ST_SKIP = 3;

	public static function pluginConfig($app) {
		try {
			if (!function_exists('Model')) return array();
			$cfg = Model('Plugin')->getConfig($app);
			return is_array($cfg) ? $cfg : array();
		} catch (Throwable $e) {
			return array();
		}
	}

	public static function pluginEnabled($app) {
		try {
			if (!function_exists('Model')) return false;
			$list = Model('Plugin')->loadList($app);
			if (!$list || !is_array($list)) return false;
			return intval(_get($list, 'status', 0)) === 1;
		} catch (Throwable $e) {
			return false;
		}
	}

	public static function storeOptions($app, $fallbackUrl, $defaultIndex) {
		$cfg = self::pluginConfig($app);
		$url = rtrim((string)_get($cfg, 'elasticUrl', $fallbackUrl), '/');
		$index = strtolower(trim((string)_get($cfg, 'indexName', $defaultIndex)));
		if ($index === '') $index = $defaultIndex;
		return array(
			'elasticUrl' => $url !== '' ? $url : $fallbackUrl,
			'indexName' => $index,
			'verifyTls' => '1',
		);
	}

	public static function extractVersion() {
		$cfg = self::pluginConfig('elasticFulltext');
		$chars = max(100000, min(5000000, intval(_get($cfg, 'indexedChars', 1000000))));
		return 'attachment-v2-'.$chars;
	}

	public static function isFresh($doc, $modifyTime, $extractVersion = null) {
		if (!is_array($doc)) return false;
		$content = (string)_get($doc, 'content', '');
		if (trim($content) === '') return false;
		$docTime = intval(_get($doc, 'modifyTime', 0));
		$modifyTime = intval($modifyTime);
		// A legacy document without modifyTime cannot prove freshness for a dated file.
		if ($modifyTime > 0 && ($docTime <= 0 || $docTime < $modifyTime)) return false;
		if ($extractVersion !== null && (string)_get($doc, 'extractVersion', '') !== (string)$extractVersion) return false;
		return true;
	}

	public static function tableExists($name) {
		try {
			if (!function_exists('Model')) return false;
			return in_array($name, Model()->db()->getTables(), true);
		} catch (Throwable $e) {
			return false;
		}
	}

	public static function markFulltext($file) {
		if (!self::tableExists(self::FULLTEXT_TABLE)) return false;
		$fileID = intval(_get($file, 'fileID', 0));
		if (!$fileID) return false;
		$data = array(
			'sourceID' => intval(_get($file, 'sourceID', 0)),
			'modifyTime' => intval(_get($file, 'modifyTime', 0)),
			'status' => 1,
			'error' => '',
			'indexTime' => time(),
		);
		$exists = Model(self::FULLTEXT_TABLE)->where(array('fileID' => $fileID))->find();
		if ($exists) return Model(self::FULLTEXT_TABLE)->where(array('fileID' => $fileID))->save($data);
		$data['fileID'] = $fileID;
		Model(self::FULLTEXT_TABLE)->setDataAuto(false);
		return Model(self::FULLTEXT_TABLE)->add($data);
	}

	public static function markAiragExtracted($file, $hash = '') {
		if (!self::tableExists(self::AIRAG_TABLE)) return false;
		$fileID = intval(_get($file, 'fileID', 0));
		if (!$fileID) return false;
		$mt = intval(_get($file, 'modifyTime', 0));
		$exists = Model(self::AIRAG_TABLE)->where(array('fileID' => $fileID))->find();
		if ($exists) {
			$status = intval(_get($exists, 'status', 0));
			$oldMt = intval(_get($exists, 'modifyTime', 0));
			if ($status === self::ST_OK && $oldMt >= $mt) return true;
		}
		$data = array(
			'sourceID' => intval(_get($file, 'sourceID', 0)),
			'modifyTime' => $mt,
			'status' => self::ST_ES,
			'error' => '',
			'indexTime' => time(),
		);
		if ($hash !== '') $data['contentHash'] = (string)$hash;
		if ($exists) {
			if (intval(_get($exists, 'status', 0)) === self::ST_OK) $data['chunkCount'] = 0;
			return Model(self::AIRAG_TABLE)->where(array('fileID' => $fileID))->save($data);
		}
		$data['fileID'] = $fileID;
		$data['chunkCount'] = 0;
		$data['contentHash'] = (string)$hash;
		Model(self::AIRAG_TABLE)->setDataAuto(false);
		return Model(self::AIRAG_TABLE)->add($data);
	}

	public static function contentHits() {
		$bag = isset($GLOBALS['_kodboxContentSearch']) ? $GLOBALS['_kodboxContentSearch'] : array();
		return is_array($bag) ? $bag : array();
	}

	public static function takeContentHits($param, $fileIDs, $snippets, $flag) {
		$bag = self::contentHits();
		if (!$bag) $bag = array('fileID' => array(), 'snippets' => array(), 'flags' => array());
		$ids = array();
		foreach ((array)$fileIDs as $id) {
			$id = intval($id);
			if ($id > 0) $ids[] = $id;
		}
		$bag['fileID'] = array_values(array_unique(array_merge((array)_get($bag, 'fileID', array()), $ids)));
		foreach ((array)$snippets as $id => $text) {
			$id = intval($id);
			$text = trim((string)$text);
			if ($id && $text !== '' && empty($bag['snippets'][$id])) $bag['snippets'][$id] = $text;
		}
		if (!isset($bag['flags']) || !is_array($bag['flags'])) $bag['flags'] = array();
		$bag['flags'][$flag] = 1;
		$GLOBALS['_kodboxContentSearch'] = $bag;
		if (!is_array($param)) $param = array();
		$param['fileID'] = $bag['fileID'] ? $bag['fileID'] : array(-1);
		if (!empty($bag['flags']['elasticFulltext'])) $param['_elasticFulltext'] = 1;
		if (!empty($bag['flags']['aiRag'])) $param['_aiRag'] = 1;
		return $param;
	}

	public static function applyContentHits($listData) {
		$bag = self::contentHits();
		if (!$bag || empty($bag['fileID']) || !is_array($listData) || !isset($listData['fileList'])) return null;
		$allow = array_flip($bag['fileID']);
		$snippets = (array)_get($bag, 'snippets', array());
		$filtered = array();
		foreach ((array)$listData['fileList'] as $item) {
			$fileID = intval(_get($item, 'fileID', _get($item, 'fileInfo.fileID', 0)));
			if (!$fileID || !isset($allow[$fileID])) continue;
			if (!empty($snippets[$fileID])) $item['searchContentMatch'] = $snippets[$fileID];
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

	public static function overlaySnippets($listData) {
		$bag = self::contentHits();
		if (!$bag || empty($bag['snippets']) || !is_array($listData) || empty($listData['fileList'])) return $listData;
		$snippets = (array)_get($bag, 'snippets', array());
		foreach ($listData['fileList'] as &$item) {
			$fileID = intval(_get($item, 'fileID', _get($item, 'fileInfo.fileID', 0)));
			if ($fileID && !empty($snippets[$fileID])) $item['searchContentMatch'] = $snippets[$fileID];
		}
		unset($item);
		return $listData;
	}

	public static function folderPrefix($sourceID) {
		static $cache = array();
		$sourceID = intval($sourceID);
		if ($sourceID <= 0) return '';
		if (isset($cache[$sourceID])) return $cache[$sourceID];
		$folder = array();
		try { $folder = Model('Source')->where(array('sourceID' => $sourceID))->find(); } catch (Throwable $e) { $folder = array(); }
		$prefix = preg_replace('/[^0-9,]/', '', rtrim((string)_get($folder, 'parentLevel', ''), ',').','.$sourceID.',');
		$cache[$sourceID] = $prefix;
		return $prefix;
	}

	// 只判断这一小批命中是否落在目录内，不把整个目录的文件编号读进检索请求。
	public static function keepInFolder($sourceID, $fileIDs) {
		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$fileIDs))));
		$sourceID = intval($sourceID);
		if (!$ids || $sourceID <= 0 || !function_exists('Model')) return array();
		$prefix = self::folderPrefix($sourceID);
		$base = array('fileID' => array('in', $ids), 'isFolder' => 0, 'isDelete' => 0);
		$direct = $base;
		$direct['parentID'] = $sourceID;
		$nested = $base;
		$nested['parentLevel'] = array('like', $prefix.'%');
		$keep = array();
		foreach (array($direct, $nested) as $where) {
			try { $rows = Model('Source')->where($where)->field('fileID')->limit(count($ids))->select(); }
			catch (Throwable $e) { continue; }
			foreach ((array)$rows as $row) {
				$id = intval(_get($row, 'fileID', 0));
				if ($id) $keep[$id] = $id;
			}
		}
		return array_values($keep);
	}

	public static function ancestorIDs($fileID) {
		$fileID = intval($fileID);
		if ($fileID <= 0 || !function_exists('Model')) return array();
		$ids = array();
		$after = 0;
		do {
			try {
				$rows = Model('Source')->where(array('fileID' => $fileID, 'isFolder' => 0, 'isDelete' => 0, 'sourceID' => array('gt', $after)))->field('sourceID,parentID,parentLevel')->order('sourceID asc')->limit(100)->select();
			} catch (Throwable $e) { break; }
			$n = 0;
			foreach ((array)$rows as $row) {
				$n++;
				$after = intval(_get($row, 'sourceID', $after));
				$parent = intval(_get($row, 'parentID', 0));
				if ($parent > 0) $ids[$parent] = $parent;
				foreach (explode(',', (string)_get($row, 'parentLevel', '')) as $part) {
					$part = intval($part);
					if ($part > 0) $ids[$part] = $part;
				}
				if (count($ids) >= 64) break 2;
			}
			if ($n < 100) break;
		} while (count($ids) < 64);
		return array_slice(array_values($ids), 0, 64);
	}

	public static function folderFileIDs($sourceID, $max = 4000) {
		$sourceID = intval($sourceID);
		$max = max(1, min(8000, intval($max)));
		if ($sourceID <= 0 || !function_exists('Model')) return array('ids' => array(), 'complete' => true);
		$prefix = self::folderPrefix($sourceID);
		if ($prefix === '') return array('ids' => array(), 'complete' => false);
		$limit = $max + 1;
		$sql = "SELECT DISTINCT `fileID` AS `fileID` FROM `io_source` WHERE `isDelete`=0 AND `isFolder`=0 AND (`parentID`=".$sourceID." OR `parentLevel` LIKE '".addslashes($prefix)."%') LIMIT ".$limit;
		try {
			$rows = Model()->db()->query($sql);
		} catch (Throwable $e) {
			return array('ids' => array(), 'complete' => false);
		}
		if (!is_array($rows)) return array('ids' => array(), 'complete' => false);
		if (count($rows) > $max) return array('ids' => array(), 'complete' => false);
		$ids = array();
		foreach ($rows as $row) {
			$id = intval(is_array($row) ? _get($row, 'fileID', _get($row, 'fileid', 0)) : 0);
			if ($id) $ids[$id] = $id;
		}
		return array('ids' => array_values($ids), 'complete' => true);
	}

	public static function putSnippets($snippets) {
		$bag = self::contentHits();
		if (!$bag) $bag = array('fileID' => array(), 'snippets' => array(), 'flags' => array());
		if (!isset($bag['snippets']) || !is_array($bag['snippets'])) $bag['snippets'] = array();
		foreach ((array)$snippets as $id => $text) {
			$id = intval($id);
			$text = trim((string)$text);
			if ($id && $text !== '') $bag['snippets'][$id] = $text;
		}
		$GLOBALS['_kodboxContentSearch'] = $bag;
	}
}
