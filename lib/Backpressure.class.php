<?php

class ElasticFulltextPressureException extends RuntimeException {}

/**
 * Standalone pressure guard. It intentionally shares airag-pressure.json and
 * airag-host-pressure.json with aiRag so both plugins observe one hysteresis
 * state even though either plugin can be installed independently.
 */
class ElasticFulltextBackpressure {
	public static function inspect($config) {
		$result = array('ok' => true, 'reason' => '', 'dirtyRatio' => null, 'waitFree' => null,
			'waitFreeDelta' => null, 'freePages' => null, 'checkpointRatio' => null);
		if (_get($config, 'backpressure', '1') != '1') return $result;
		$fp = @fopen(rtrim(TEMP_PATH, '/\\').'/airag-pressure.json', 'c+');
		if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
			if ($fp) fclose($fp);
			$result['ok'] = false; $result['reason'] = '等待背压采样'; return $result;
		}
		try {
			$previous = json_decode(stream_get_contents($fp), true);
			if (!is_array($previous)) $previous = array();
			$now = microtime(true);
			$hash = sha1(json_encode($config));
			if ($now - _get($previous, 'time', 0) < 2 && _get($previous, 'configHash', '') === $hash && isset($previous['result'])) return $previous['result'];
			$status = array();
			$type = _get(_get($GLOBALS['config'], 'database', array()), 'DB_TYPE', '');
			if (stripos($type, 'sqlite') === false) {
				$status = self::statusMap(Model()->db());
				if (!isset($status['Innodb_buffer_pool_pages_total'])) throw new RuntimeException('无法读取 InnoDB 指标');
			}
			$state = self::evaluate($status, $previous, $config, $now);
			$result = $state['result'];
			$hostPath = rtrim(TEMP_PATH, '/\\').'/airag-host-pressure.json';
			$host = is_file($hostPath) ? json_decode(@file_get_contents($hostPath), true) : null;
			if (is_array($host) && abs(time() - intval(_get($host, 'time', 0))) <= 60 &&
				(floatval(_get($host, 'mariadbMemoryGiB', 0)) >= 12.5 ||
				(floatval(_get($host, 'nvmeUtil', 0)) >= 80 && floatval(_get($host, 'nvmeBusySeconds', 0)) >= 30))) {
				$result['ok'] = false; $result['reason'] = '主机内存或 NVMe 持续高负载';
				$state['holdUntil'] = $now + 30;
			}
			$state['configHash'] = $hash;
			$state['result'] = $result;
			rewind($fp); ftruncate($fp, 0); fwrite($fp, json_encode($state)); fflush($fp);
		} catch (Throwable $e) {
			$result['ok'] = false;
			$result['reason'] = '背压指标不可用: '.$e->getMessage();
		} finally { flock($fp, LOCK_UN); fclose($fp); }
		return $result;
	}

	public static function evaluate($status, $previous, $config, $now) {
		$total = floatval(_get($status, 'Innodb_buffer_pool_pages_total', 0));
		$dirty = floatval(_get($status, 'Innodb_buffer_pool_pages_dirty', 0));
		$wait = isset($status['Innodb_buffer_pool_wait_free']) ? floatval($status['Innodb_buffer_pool_wait_free']) : null;
		$delta = $wait !== null && isset($previous['waitFree']) ? max(0, $wait - $previous['waitFree']) : 0;
		$ratio = $total > 0 ? 100 * $dirty / $total : null;
		$freePages = _get($status, 'Innodb_buffer_pool_pages_free', null);
		$freeRatio = $total > 0 && $freePages !== null ? 100 * floatval($freePages) / $total : null;
		$maxAge = floatval(_get($status, 'Innodb_checkpoint_max_age', 0));
		$checkpoint = $maxAge > 0 ? 100 * floatval(_get($status, 'Innodb_checkpoint_age', 0)) / $maxAge : null;
		$pause = max(10, min(90, floatval(_get($config, 'pauseDirtyPercent', 40))));
		$resume = max(5, min($pause - 5, floatval(_get($config, 'resumeDirtyPercent', 28))));
		$wasPaused = isset($previous['result']) && !$previous['result']['ok'];
		$hold = floatval(_get($previous, 'holdUntil', 0));
		$reason = '';
		if ($ratio !== null && ($ratio >= $pause || ($wasPaused && $ratio > $resume))) $reason = 'MariaDB 脏页 '.round($ratio, 1).'%（恢复阈值 '.$resume.'%）';
		$checkpointBusy = $ratio === null || $ratio > $resume || ($freeRatio !== null && $freeRatio < 10);
		if ($checkpoint !== null && ($checkpoint >= 85 || ($checkpoint >= 60 && $checkpointBusy))) $reason = 'checkpoint 使用率 '.round($checkpoint, 1).'%';
		if ($delta > 0) { $reason = 'buffer_pool_wait_free 新增 '.$delta; $hold = $now + 30; }
		if ($hold > $now && !$reason) $reason = '等待压力回落，冷却 '.ceil($hold - $now).' 秒';
		return array('time' => $now, 'waitFree' => $wait, 'holdUntil' => $hold,
			'result' => array('ok' => $reason === '', 'reason' => $reason,
				'dirtyRatio' => $ratio === null ? null : round($ratio, 1), 'waitFree' => $wait,
				'waitFreeDelta' => $delta, 'freePages' => $freePages,
				'checkpointRatio' => $checkpoint === null ? null : round($checkpoint, 1)));
	}

	public static function assertReady($config) {
		$result = self::inspect($config);
		if (!$result['ok']) throw new ElasticFulltextPressureException($result['reason']);
	}

	private static function statusMap($db) {
		$names = array('Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_dirty', 'Innodb_buffer_pool_pages_free',
			'Innodb_buffer_pool_wait_free', 'Innodb_checkpoint_age', 'Innodb_checkpoint_max_age');
		$rows = $db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('".implode("','", $names)."')");
		$map = array();
		foreach ((array)$rows as $row) {
			if (is_array($row)) $map[_get($row, 'Variable_name', _get($row, 'variable_name', ''))] = _get($row, 'Value', _get($row, 'value', 0));
		}
		return $map;
	}
}
