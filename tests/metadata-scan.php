<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
function write_log($m){}
class PluginBase {public $pluginPath;function getConfig(){return array();}}
class KodboxCorpusShare {static function ancestorIDs($id){return array();}static function isFresh($doc,$mtime){return trim(_get($doc,'content',''))!==''&&intval(_get($doc,'modifyTime',0))>=$mtime;}}
class StateModel {function where($q){return $this;}function find(){return array('fileID'=>7);}function save($r){return true;}}
function Model($n){return new StateModel;}
class IO {static function exist($p){return true;}static function getContent($p){return 'fresh text';}}
class MetadataClient {
 public $reads=0,$updates=0,$extracts=0,$body=array();
 function extractionVersion(){return 'v1';}function getDocument($id){$this->reads++;return $this->body;}
 function updateMetadata($doc){$this->updates++;}function indexFile($doc,$content,$plain){$this->extracts++;}
}
require __DIR__.'/../app.php';
$r=new ReflectionClass('elasticFulltextPlugin');$app=$r->newInstanceWithoutConstructor();$app->pluginPath=dirname(__DIR__).'/';$index=$r->getMethod('indexFileRecord');
$file=array('fileID'=>7,'name'=>'a.txt','size'=>10,'modifyTime'=>10,'sourceID'=>0,'path'=>'mock');
$doc=array('name'=>'a.txt','size'=>10,'modifyTime'=>10,'sourceID'=>0,'ext'=>'txt','extractVersion'=>'v1','ancestorIDs'=>array(),'contentReady'=>true);
$c=new MetadataClient;
check($index->invoke($app,$file,'txt',$c,array(),$doc)===false&&$c->reads===0&&$c->extracts===0,'fresh metadata reuses corpus without fetching any body');
$doc['contentReady']=null;$c->body=$doc+array('content'=>'legacy text');
check($index->invoke($app,$file,'txt',$c,array(),$doc)==='ok'&&$c->reads===1&&$c->updates===1&&$c->extracts===0,'legacy corpus is read individually and upgraded without re-extraction');
$c->body['content']='';
check($index->invoke($app,$file,'txt',$c,array(),$doc)==='ok'&&$c->extracts===1,'blank legacy content cannot masquerade as fresh metadata');
