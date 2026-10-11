<?php
// Kept identical in both independently installable plugins.
if (!class_exists('KodboxSearchSecurity', false)) {
class KodboxSearchSecurity {
    public static function requestAllowed($server, $input, $post = false) {
        if ($post && ($server['REQUEST_METHOD'] ?? '') !== 'POST') return false;
        if (($server['HTTP_X_KODBOX_PLUGIN'] ?? '') !== '1') return false;
        if (isset($input['callback']) || isset($input['jsonp'])) return false;
        if (isset($server['HTTP_SEC_FETCH_SITE']) && $server['HTTP_SEC_FETCH_SITE'] !== 'same-origin') return false;
        if (isset($server['HTTP_ORIGIN'])) {
            $origin = parse_url($server['HTTP_ORIGIN']);
            if (!$origin || !isset($origin['host']) || !in_array($origin['scheme'] ?? '', array('http','https'), true)) return false;
            $authority = strtolower($origin['host']).(isset($origin['port']) ? ':'.$origin['port'] : '');
            if ($authority !== strtolower($server['HTTP_HOST'] ?? '')) return false;
        }
        return true;
    }
    public static function protect($input, $post = false) {
        $GLOBALS['config']['jsonpAllow'] = false;
        if (self::requestAllowed($_SERVER, (array)$input, $post)) return true;
        show_json('请求校验失败，请刷新页面后重试', false);
        return false;
    }
    public static function sourcePath($path) {
        if (!is_string($path) || strlen($path) > 4096 || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) return false;
        if (!preg_match('/^\{source:[1-9][0-9]*\}(?:\/|$)/D', $path)) return false;
        foreach (explode('/', rawurldecode($path)) as $part) if ($part === '..' || $part === '.') return false;
        return true;
    }
    public static function readablePath($path) {
        if (!self::sourcePath($path)) return false;
        try { return (bool)Action('explorer.auth')->fileCanRead($path); }
        catch (Throwable $e) { return false; }
    }
    public static function httpUrl($url) {
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) throw new InvalidArgumentException('服务地址必须是有效的 HTTP(S) URL');
        $p = parse_url($url);
        if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), array('http','https'), true) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) throw new InvalidArgumentException('服务地址不允许非 HTTP(S) 协议、内嵌凭据或片段');
        // Private service addresses are intentional administrator configuration.
        return $url;
    }
    public static function audioFile($file) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('音频上传失败');
        $size = filesize($file['tmp_name']);
        if (!$size || $size > 25 * 1024 * 1024) throw new InvalidArgumentException('音频文件须大于 0 且不超过 25 MB');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $types = array('audio/mpeg'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/flac'=>'flac','audio/x-flac'=>'flac','audio/mp4'=>'m4a','video/mp4'=>'mp4','audio/webm'=>'webm','video/webm'=>'webm');
        if (!isset($types[$mime])) throw new InvalidArgumentException('不支持的音频格式，请上传 MP3、WAV、OGG、FLAC、M4A 或 WebM');
        return new CURLFile($file['tmp_name'], $mime, 'audio.'.$types[$mime]);
    }
    public static function saveName($name) {
        $name = trim((string)$name);
        if ($name === '' || strlen($name) > 180 || preg_match('/[\x00-\x1f\x7f\\\\\/:]/', $name) || $name[0] === '.' || strpos($name, '..') !== false) throw new InvalidArgumentException('生成文件名无效');
        if (!preg_match('/\.(txt|md|csv|json)$/i', $name)) $name .= '.txt';
        return $name;
    }
}
}
