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

	public static function isFresh($doc, $modifyTime) {
		if (!is_array($doc)) return false;
		$content = (string)_get($doc, 'content', '');
		if (trim($content) === '') return false;
		$docTime = intval(_get($doc, 'modifyTime', 0));
		$modifyTime = intval($modifyTime);
		// A legacy document without modifyTime cannot prove freshness for a dated file.
		if ($modifyTime > 0 && ($docTime <= 0 || $docTime < $modifyTime)) return false;
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
}
