<?php

class KodboxElasticClient {
	private $url;
	private $index;
	private $pipeline;
	private $indexedChars;
	private $verifyTls;

	public function __construct($config) {
		$this->url = rtrim(_get($config, 'elasticUrl', 'http://elasticsearch:9200'), '/');
		$this->index = strtolower(_get($config, 'indexName', 'kodbox-fulltext'));
		$this->indexedChars = max(100000, min(5000000, intval(_get($config, 'indexedChars', 1000000))));
		$this->pipeline = 'kodbox-attachment-'.$this->indexedChars;
		$this->verifyTls = _get($config, 'verifyTls', '1') == '1';
	}

	public function info($timeout = 5) {return $this->request('GET', '/', null, array(200), $timeout);}

	public function ensureInfrastructure() {
		$pipeline = $this->request('GET', '/_ingest/pipeline/'.$this->pipeline, null, array(200, 404), 5);
		if ($pipeline['_status'] === 404) {
			$this->request('PUT', '/_ingest/pipeline/'.$this->pipeline, array(
				'description' => 'Extract PDF and Office text for Kodbox ('.$this->indexedChars.' chars)',
				'_meta' => array('owner' => 'elasticFulltext', 'indexed_chars' => $this->indexedChars),
				'processors' => array(
					array('attachment' => array('field' => 'data', 'target_field' => 'attachment', 'indexed_chars' => $this->indexedChars, 'remove_binary' => true)),
					array('convert' => array('field' => 'attachment.content', 'target_field' => 'content', 'type' => 'string', 'ignore_failure' => true)),
					array('remove' => array('field' => 'attachment', 'ignore_missing' => true)),
				),
			));
		}
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
						'content' => array('type' => 'text'), 'extractVersion' => array('type' => 'keyword'),
						'ancestorIDs' => array('type' => 'long'),
					),
				),
			));
		}
		$field = $this->request('GET', '/'.$this->index.'/_mapping/field/extractVersion', null, array(200, 404), 5);
		$fieldMap = (array)_get((array)_get($field, $this->index, array()), 'mappings', array());
		if ($field['_status'] === 404 || !isset($fieldMap['extractVersion'])) {
			$this->request('PUT', '/'.$this->index.'/_mapping', array('properties' => array(
				'extractVersion' => array('type' => 'keyword'),
			)), array(200));
		}
		$ancestors = $this->request('GET', '/'.$this->index.'/_mapping/field/ancestorIDs', null, array(200, 404), 5);
		$ancestorMap = (array)_get((array)_get($ancestors, $this->index, array()), 'mappings', array());
		if ($ancestors['_status'] === 404 || !isset($ancestorMap['ancestorIDs'])) {
			$this->request('PUT', '/'.$this->index.'/_mapping', array('properties' => array(
				'ancestorIDs' => array('type' => 'long'),
			)), array(200));
		}
		return true;
	}

	public function indexFile($source, $content, $plainText) {
		$document = array(
			'fileID' => intval($source['fileID']), 'sourceID' => intval($source['sourceID']),
			'name' => (string)$source['name'], 'ext' => strtolower((string)$source['fileType']),
			'size' => intval($source['size']), 'modifyTime' => intval($source['modifyTime']),
			'extractVersion' => $this->extractionVersion(),
			'ancestorIDs' => array_values(array_unique(array_filter(array_map('intval', (array)_get($source, 'ancestorIDs', array()))))),
		);
		$path = '/'.$this->index.'/_doc/'.intval($source['fileID']).'?refresh=false';
		if ($plainText) $document['content'] = $this->limitText($this->toUtf8($content));
		else {
			$document['data'] = base64_encode($content);
			$path .= '&pipeline='.$this->pipeline;
		}
		return $this->request('PUT', $path, $document, array(200, 201), $plainText ? 20 : 35);
	}

	public function search($words, $limit, $fileIDs = null, $ancestorID = 0) {
		$ids = null;
		if (is_array($fileIDs)) {
			$ids = array_values(array_unique(array_filter(array_map('intval', $fileIDs))));
			if (!$ids) return array();
		}
		$body = array(
			'size' => max(1, intval($limit)),
			'track_total_hits' => false,
			'_source' => array('fileID'),
			// “文件内容”搜索只匹配正文，与官方 docSearch 的 MATCH(content) 行为一致。
			'query' => array('bool' => array(
				'should' => array(
					array('match_phrase' => array('content' => array('query' => (string)$words, 'boost' => 3))),
					array('match' => array('content' => array('query' => (string)$words, 'operator' => 'and'))),
				),
				'minimum_should_match' => 1,
			)),
		);
		$filter = array();
		if ($ids) $filter[] = array('terms' => array('fileID' => $ids));
		if (intval($ancestorID) > 0) $filter[] = array('term' => array('ancestorIDs' => intval($ancestorID)));
		if ($filter) $body['query']['bool']['filter'] = $filter;
		$response = $this->request('POST', '/'.$this->index.'/_search', $body, array(200), 8);
		$result = array();
		foreach ((array)_get(_get($response, 'hits', array()), 'hits', array()) as $hit) {
			$source = (array)_get($hit, '_source', array());
			$result[] = array('fileID' => intval(_get($source, 'fileID', _get($hit, '_id', 0))), 'snippet' => '', 'score' => floatval(_get($hit, '_score', 0)));
		}
		return $result;
	}

	// 只翻还没写上祖先字段的旧文档。字段补齐后这一页为空，目录搜索不再扫全库。
	public function searchPage($words, $size, $searchAfter = null, $missingAncestors = false) {
		$body = array(
			'size' => max(1, min(300, intval($size))),
			'track_total_hits' => false,
			'_source' => array('fileID'),
			'sort' => array(array('_score' => 'desc'), array('fileID' => 'asc')),
			'query' => array('bool' => array(
				'should' => array(
					array('match_phrase' => array('content' => array('query' => (string)$words, 'boost' => 3))),
					array('match' => array('content' => array('query' => (string)$words, 'operator' => 'and'))),
				),
				'minimum_should_match' => 1,
			)),
		);
		if ($missingAncestors) $body['query']['bool']['filter'] = array(array('bool' => array('must_not' => array(array('exists' => array('field' => 'ancestorIDs'))))));
		if (is_array($searchAfter) && count($searchAfter) >= 2) $body['search_after'] = array($searchAfter[0], intval($searchAfter[1]));
		$response = $this->request('POST', '/'.$this->index.'/_search', $body, array(200), 8);
		$hits = array();
		$after = null;
		foreach ((array)_get(_get($response, 'hits', array()), 'hits', array()) as $hit) {
			$source = (array)_get($hit, '_source', array());
			$fileID = intval(_get($source, 'fileID', _get($hit, '_id', 0)));
			$sort = (array)_get($hit, 'sort', array());
			if ($fileID) $hits[] = array('fileID' => $fileID, 'snippet' => '', 'score' => floatval(_get($hit, '_score', 0)));
			if (count($sort) >= 2) $after = array($sort[0], intval($sort[1]));
		}
		return array('hits' => $hits, 'after' => $after, 'more' => count($hits) >= $body['size']);
	}

	public function snippets($words, $fileIDs) {
		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$fileIDs))));
		$ids = array_slice($ids, 0, 40);
		if (!$ids || trim((string)$words) === '') return array();
		$body = array(
			'size' => count($ids),
			'track_total_hits' => false,
			'_source' => array('fileID'),
			'query' => array('bool' => array(
				'filter' => array(array('terms' => array('fileID' => $ids))),
				'must' => array('bool' => array(
					'should' => array(
						array('match_phrase' => array('content' => array('query' => (string)$words, 'boost' => 3))),
						array('match' => array('content' => array('query' => (string)$words, 'operator' => 'and'))),
					),
					'minimum_should_match' => 1,
				)),
			)),
			'highlight' => array(
				'max_analyzed_offset' => 40000,
				'pre_tags' => array(''),
				'post_tags' => array(''),
				'fields' => array('content' => array('fragment_size' => 220, 'number_of_fragments' => 1)),
			),
		);
		$response = $this->request('POST', '/'.$this->index.'/_search', $body, array(200), 8);
		$out = array();
		foreach ((array)_get(_get($response, 'hits', array()), 'hits', array()) as $hit) {
			$source = (array)_get($hit, '_source', array());
			$fileID = intval(_get($source, 'fileID', _get($hit, '_id', 0)));
			$snippet = (string)_get(_get((array)_get($hit, 'highlight', array()), 'content', array()), 0, '');
			if ($fileID && $snippet !== '') $out[$fileID] = $snippet;
		}
		return $out;
	}

	public function getDocument($fileID) {
		$result = $this->request('GET', '/'.$this->index.'/_source/'.intval($fileID).'?_source_includes=content,modifyTime,name,size,sourceID,ext,extractVersion,ancestorIDs', null, array(200, 404), 8);
		if ($result['_status'] === 404) return array();
		return $this->normalizeDocument($result);
	}

	public function getDocuments($fileIDs) {
		$ids = array_values(array_unique(array_filter(array_map('intval', (array)$fileIDs))));
		if (!$ids) return array();
		$result = $this->request('POST', '/'.$this->index.'/_mget?_source_includes=content,modifyTime,name,size,sourceID,ext,extractVersion,ancestorIDs', array('ids' => $ids), array(200), 15);
		$documents = array();
		foreach ((array)_get($result, 'docs', array()) as $doc) {
			$id = intval(_get($doc, '_id', 0));
			if (!$id) continue;
			$documents[$id] = !empty($doc['found']) ? $this->normalizeDocument((array)_get($doc, '_source', array())) : array();
		}
		return $documents;
	}

	public function updateMetadata($source) {
		$fileID = intval(_get($source, 'fileID', 0));
		if (!$fileID) return false;
		$doc = array(
			'fileID' => $fileID,
			'sourceID' => intval(_get($source, 'sourceID', 0)),
			'name' => (string)_get($source, 'name', ''),
			'ext' => strtolower((string)_get($source, 'fileType', '')),
			'size' => intval(_get($source, 'size', 0)),
			'modifyTime' => intval(_get($source, 'modifyTime', 0)),
			'ancestorIDs' => array_values(array_unique(array_filter(array_map('intval', (array)_get($source, 'ancestorIDs', array()))))),
		);
		return $this->request('POST', '/'.$this->index.'/_update/'.$fileID.'?refresh=false', array('doc' => $doc), array(200), 12);
	}

	public function extractionVersion() {
		return 'attachment-v2-'.$this->indexedChars;
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

	private function limitText($content) {
		$content = (string)$content;
		if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > $this->indexedChars) {
			return mb_substr($content, 0, $this->indexedChars, 'UTF-8');
		}
		return $content;
	}

	private function normalizeDocument($source) {
		return array(
			'content' => (string)_get($source, 'content', ''),
			'modifyTime' => intval(_get($source, 'modifyTime', 0)),
			'name' => (string)_get($source, 'name', ''),
			'size' => intval(_get($source, 'size', 0)),
			'sourceID' => intval(_get($source, 'sourceID', 0)),
			'ext' => strtolower((string)_get($source, 'ext', '')),
			'extractVersion' => (string)_get($source, 'extractVersion', ''),
			'ancestorIDs' => array_values(array_unique(array_filter(array_map('intval', (array)_get($source, 'ancestorIDs', array()))))),
		);
	}
}
