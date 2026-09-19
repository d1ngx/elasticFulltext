<?php

class KodboxElasticClient {
	private $url;
	private $index;
	private $pipeline = 'kodbox-attachment';
	private $verifyTls;

	public function __construct($config) {
		$this->url = rtrim(_get($config, 'elasticUrl', 'http://elasticsearch:9200'), '/');
		$this->index = strtolower(_get($config, 'indexName', 'kodbox-fulltext'));
		$this->verifyTls = _get($config, 'verifyTls', '1') == '1';
	}

	public function info($timeout = 5) {return $this->request('GET', '/', null, array(200), $timeout);}

	public function ensureInfrastructure() {
		$this->request('PUT', '/_ingest/pipeline/'.$this->pipeline, array(
			'description' => 'Extract PDF and Office text for Kodbox',
			'processors' => array(
				array('attachment' => array('field' => 'data', 'target_field' => 'attachment', 'indexed_chars' => 200000, 'remove_binary' => true)),
				array('convert' => array('field' => 'attachment.content', 'target_field' => 'content', 'type' => 'string', 'ignore_failure' => true)),
				array('remove' => array('field' => 'attachment', 'ignore_missing' => true)),
			),
		));
		$exists = $this->request('HEAD', '/'.$this->index, null, array(200, 404));
		if ($exists['_status'] === 404) {
			$this->request('PUT', '/'.$this->index, array(
				'settings' => array('number_of_shards' => 1, 'number_of_replicas' => 0),
				'mappings' => array(
					'dynamic' => false,
					'properties' => array(
						'fileID' => array('type' => 'long'), 'sourceID' => array('type' => 'long'),
						'name' => array('type' => 'text'), 'ext' => array('type' => 'keyword'),
						'size' => array('type' => 'long'), 'modifyTime' => array('type' => 'date', 'format' => 'epoch_second'),
						'content' => array('type' => 'text'),
					),
				),
			));
		}
		return true;
	}

	public function indexFile($source, $content, $plainText) {
		$document = array(
			'fileID' => intval($source['fileID']), 'sourceID' => intval($source['sourceID']),
			'name' => (string)$source['name'], 'ext' => strtolower((string)$source['fileType']),
			'size' => intval($source['size']), 'modifyTime' => intval($source['modifyTime']),
		);
		$path = '/'.$this->index.'/_doc/'.intval($source['fileID']).'?refresh=false';
		if ($plainText) $document['content'] = $this->toUtf8($content);
		else {
			$document['data'] = base64_encode($content);
			$path .= '&pipeline='.$this->pipeline;
		}
		return $this->request('PUT', $path, $document, array(200, 201), $plainText ? 20 : 35);
	}

	public function search($words, $limit) {
		$body = array(
			'size' => max(1, intval($limit)),
			'_source' => array('fileID'),
			// “文件内容”搜索只匹配正文，与官方 docSearch 的 MATCH(content) 行为一致。
			'query' => array('bool' => array(
				'should' => array(
					array('match_phrase' => array('content' => array('query' => (string)$words, 'boost' => 3))),
					array('match' => array('content' => array('query' => (string)$words, 'operator' => 'and'))),
				),
				'minimum_should_match' => 1,
			)),
			'highlight' => array(
				'pre_tags' => array(''), 'post_tags' => array(''),
				'fields' => array('content' => array('fragment_size' => 260, 'number_of_fragments' => 1), 'name' => array('number_of_fragments' => 0)),
			),
		);
		$response = $this->request('POST', '/'.$this->index.'/_search', $body);
		$result = array();
		foreach ((array)_get(_get($response, 'hits', array()), 'hits', array()) as $hit) {
			$source = (array)_get($hit, '_source', array());
			$highlight = (array)_get($hit, 'highlight', array());
			$snippet = '';
			if (!empty($highlight['content'][0])) $snippet = $highlight['content'][0];
			else if (!empty($highlight['name'][0])) $snippet = $highlight['name'][0];
			$result[] = array('fileID' => intval(_get($source, 'fileID', _get($hit, '_id', 0))), 'snippet' => $snippet);
		}
		return $result;
	}

	public function getDocument($fileID) {
		$result = $this->request('GET', '/'.$this->index.'/_source/'.intval($fileID).'?_source_includes=content,modifyTime,name,size', null, array(200, 404), 8);
		if ($result['_status'] === 404) return array();
		return array(
			'content' => (string)_get($result, 'content', ''),
			'modifyTime' => intval(_get($result, 'modifyTime', 0)),
			'name' => (string)_get($result, 'name', ''),
			'size' => intval(_get($result, 'size', 0)),
		);
	}

	public function getContent($fileID) {
		return (string)_get($this->getDocument($fileID), 'content', '');
	}

	public function deleteFile($fileID) {$this->request('DELETE', '/'.$this->index.'/_doc/'.intval($fileID), null, array(200, 404));}

	public function count($timeout = 5) {
		$result = $this->request('GET', '/'.$this->index.'/_count', null, array(200, 404), $timeout);
		return intval(_get($result, 'count', 0));
	}

	public function rebuild() {
		$this->request('DELETE', '/'.$this->index, null, array(200, 404));
		return $this->ensureInfrastructure();
	}

	private function request($method, $path, $body = null, $allowed = array(200, 201), $timeout = 120) {
		if (!function_exists('curl_init')) throw new Exception('PHP cURL extension is required');
		$curl = curl_init($this->url.$path);
		$options = array(
			CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => min(5, max(1, intval($timeout))), CURLOPT_TIMEOUT => max(1, intval($timeout)),
			CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
			CURLOPT_SSL_VERIFYPEER => $this->verifyTls, CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
		);
		if ($method === 'HEAD') $options[CURLOPT_NOBODY] = true;
		if ($body !== null && $method !== 'HEAD') $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		curl_setopt_array($curl, $options);
		$response = curl_exec($curl);
		if ($response === false) {$error = curl_error($curl); curl_close($curl); throw new Exception('Elasticsearch connection failed: '.$error);}
		$status = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
		curl_close($curl);
		$data = $response === '' ? array() : json_decode($response, true);
		if (!is_array($data)) $data = array('_raw' => $response);
		$data['_status'] = $status;
		if (!in_array($status, $allowed, true)) {
			throw new Exception('Elasticsearch HTTP '.$status.': '.$this->formatError($data, $response));
		}
		return $data;
	}

	private function formatError($data, $raw) {
		$parts = array();
		$error = _get($data, 'error', array());
		$this->collectReasons($error, $parts);
		$root = _get(_get($error, 'root_cause', array()), 0, array());
		$this->collectReasons($root, $parts);
		$unique = array();
		foreach ($parts as $part) {
			$part = trim((string)$part);
			if ($part === '' || isset($unique[$part])) continue;
			$unique[$part] = true;
		}
		$parts = array_keys($unique);
		$generic = 'Error parsing document in field [data]';
		$specific = array_values(array_filter($parts, function($part) use ($generic) {return $part !== $generic;}));
		$message = $specific ? implode(' | ', array_slice($specific, 0, 5)) : ($parts ? $parts[0] : '');
		if (!$message) $message = is_array($error) ? json_encode($error, JSON_UNESCAPED_UNICODE) : (string)($error ? $error : $raw);
		if (strpos($message, 'does not have any content type') !== false || strpos($message, 'Package require content types') !== false) {
			$message .= '；Office 包内有未登记 Content Type 的部件（常见于 customXml 测试载荷或损坏文件），不是 xlsx/docx 格式本身不支持';
		} else if (stripos($message, 'Encrypted') !== false || stripos($message, 'password') !== false) {
			$message .= '；文件可能已加密';
		}
		return substr($message, 0, 1200);
	}

	private function collectReasons($node, &$parts, $depth = 0) {
		if (!is_array($node) || $depth > 8) return;
		if (!empty($node['reason']) && is_string($node['reason'])) $parts[] = $node['reason'];
		if (isset($node['caused_by'])) $this->collectReasons($node['caused_by'], $parts, $depth + 1);
	}

	private function toUtf8($content) {
		if (!function_exists('mb_check_encoding') || mb_check_encoding($content, 'UTF-8')) return $content;
		return mb_convert_encoding($content, 'UTF-8', 'UTF-8,GB18030,GBK,BIG5,ISO-8859-1');
	}
}
