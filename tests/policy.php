<?php
namespace FulltextPolicyTest;
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
use Exception;use RuntimeException;
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
class PluginBase {function getConfig(){return array();}}
class Wire {static $body;static $url;static $result=array('hits'=>array('hits'=>array()));}
function curl_init($url){$ch=new \stdClass;$ch->url=$url;return $ch;}
function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
function curl_exec($ch){Wire::$url=$ch->url;Wire::$body=json_decode($ch->options[CURLOPT_POSTFIELDS],true);return json_encode(Wire::$result);}
function curl_getinfo($ch,$key){return 200;}
function curl_close($ch){}
$source=file_get_contents(__DIR__.'/../lib/ElasticClient.class.php');
$source=preg_replace('/require_once __DIR__[^;]+;/', '', $source);
require_once __DIR__.'/../lib/Security.class.php';
eval('namespace FulltextPolicyTest; use \KodboxSearchSecurity; use Exception; use RuntimeException; use stdClass; '.substr($source,5));
$client=new KodboxElasticClient(array('elasticUrl'=>'http://example.invalid','allowExtensions'=>'txt,pdf','extensionMode'=>'deny','denyExtensions'=>'pdf','maxFileSizeMB'=>10));
$client->search('test',10);
$policy=Wire::$body['query']['bool']['filter'];
check($policy===array(array('terms'=>array('ext'=>array('txt'))),array('range'=>array('size'=>array('gt'=>0,'lt'=>10*1024*1024)))),'fulltext applies current extension and strict size limits');
$client->searchPage('test',10,null,true);
check(array_slice(Wire::$body['query']['bool']['filter'],-2)===$policy,'ancestor migration fallback cannot bypass current policy');
$client->snippets('test',array(1));
check(array_slice(Wire::$body['query']['bool']['filter'],-2)===$policy,'snippet hydration retains policy');
check($client->getContent(1)==='','excluded direct body reads return empty');
check(array_slice(Wire::$body['query']['bool']['filter'],0,2)===$policy,'direct body reads are filtered');
Wire::$result=array('deleted'=>10);
check($client->purgeExcluded(10)===10&&strpos(Wire::$url,'max_docs=10')!==false,'old corpus pruning is bounded');
check(Wire::$body['query']['bool']['must_not'][0]['bool']['filter']===$policy,'pruning deletes only the complement of the query policy');
foreach(array('failures'=>array(array('reason'=>'failed')),'timed_out'=>true,'version_conflicts'=>1)as$key=>$value){Wire::$result=array('deleted'=>1,$key=>$value);$failed=false;try{$client->purgeExcluded(10);}catch(RuntimeException $e){$failed=true;}check($failed,'incomplete purge remains retryable: '.$key);}
$empty=new KodboxElasticClient(array('allowExtensions'=>''));Wire::$result=array('hits'=>array('hits'=>array()));$empty->search('test',10);
check(isset(Wire::$body['query']['bool']['filter'][0]['match_none']),'empty allow list denies all results');
$source=file_get_contents(__DIR__.'/../app.php');$source=preg_replace('/require_once __DIR__[^;]+;/', '', $source);
require_once __DIR__.'/../lib/Security.class.php';
eval('namespace FulltextPolicyTest; '.substr($source,5));
$dir=sys_get_temp_dir().'/fulltext-policy-test-'.getmypid();mkdir($dir);
try {
 $r=new \ReflectionClass('FulltextPolicyTest\\elasticFulltextPlugin');$app=$r->newInstanceWithoutConstructor();$r->getProperty('cursorFile')->setValue($app,$dir.'/cursor.json');
 $write=$r->getMethod('writeCursor');$read=$r->getMethod('readCursorData');
 $write->invoke($app,0,array('policyCleanupPending'=>1));$write->invoke($app,10,array('running'=>0));
 check($read->invoke($app)['policyCleanupPending']===1,'pending cleanup survives ordinary cursor updates');
 $write->invoke($app,10,array('policyCleanupPending'=>0));check($read->invoke($app)['policyCleanupPending']===0,'completed cleanup clears durable flag');
} finally {foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
Wire::$result=array('docs'=>array(array('_id'=>'1','found'=>true,'_source'=>array('name'=>'a.txt','size'=>10,'modifyTime'=>123,'extractVersion'=>'attachment-v2-1000000','contentReady'=>true))));
$metadata=$client->getDocuments(array(1));
check(strpos(Wire::$url,'_mget?_source_includes=contentReady,')!==false&&!array_key_exists('content',$metadata[1]),'batch scan requests metadata only and never retains document bodies');
check($metadata[1]['contentReady']===true,'metadata distinguishes extracted content from missing body');
Wire::$result=array('docs'=>array(array('_id'=>'2','found'=>true,'_source'=>array('name'=>'legacy.txt'))));
check($client->getDocuments(array(2))[2]['contentReady']===null,'legacy metadata is explicitly unknown and requires one-file validation');
Wire::$result=array('docs'=>array(array('_id'=>'1','error'=>array('reason'=>'failed'))));$failed=false;try{$client->getDocuments(array(1));}catch(RuntimeException $e){$failed=true;}check($failed,'partial metadata read failures cannot trigger false reindexing');
