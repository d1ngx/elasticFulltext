<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function _get($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
class PluginBase {public $pluginPath='';public function __construct(){} public function getConfig(){return array();}}
require __DIR__.'/../lib/Backpressure.class.php';
require __DIR__.'/../lib/CorpusShare.class.php';
require __DIR__.'/../lib/ElasticClient.class.php';
require __DIR__.'/../app.php';

$status=array(
 'Innodb_buffer_pool_pages_total'=>1000,'Innodb_buffer_pool_pages_dirty'=>410,
 'Innodb_buffer_pool_pages_free'=>200,'Innodb_buffer_pool_wait_free'=>0,
 'Innodb_checkpoint_age'=>20,'Innodb_checkpoint_max_age'=>100,
);
check(!ElasticFulltextBackpressure::evaluate($status,array(),array(),100)['result']['ok'],'dirty pages pause fulltext independently');
$status['Innodb_buffer_pool_pages_dirty']=200;
check(ElasticFulltextBackpressure::evaluate($status,array(),array(),101)['result']['ok'],'healthy database allows fulltext');

check(KodboxCorpusShare::isFresh(array('content'=>'正文','modifyTime'=>10,'extractVersion'=>'attachment-v2-1000000'),10,'attachment-v2-1000000'),'matching corpus version is fresh');
check(!KodboxCorpusShare::isFresh(array('content'=>'正文','modifyTime'=>10,'extractVersion'=>'attachment-v2-200000'),10,'attachment-v2-1000000'),'old corpus version is stale');

$client=new KodboxElasticClient(array('elasticUrl'=>'http://example.invalid','indexName'=>'test','indexedChars'=>250000));
check($client->extractionVersion()==='attachment-v2-250000','client extraction version follows character limit');

$ref=new ReflectionClass('elasticFulltextPlugin');
$app=$ref->newInstanceWithoutConstructor();
$corpus=$ref->getMethod('corpusFingerprint');$corpus->setAccessible(true);
$scan=$ref->getMethod('scanFingerprint');$scan->setAccessible(true);
$meta=$ref->getMethod('documentMetadataMatches');$meta->setAccessible(true);
$base=array('elasticUrl'=>'http://es:9200','indexName'=>'body','indexedChars'=>1000000,'extensionMode'=>'allow','allowExtensions'=>'pdf,docx','maxFileSizeMB'=>30);
check($corpus->invoke($app,$base)!==$corpus->invoke($app,array_merge($base,array('indexName'=>'body-v2'))),'index change invalidates corpus cursor');
check($corpus->invoke($app,$base)!==$corpus->invoke($app,array_merge($base,array('indexedChars'=>2000000))),'character limit invalidates corpus cursor');
check($scan->invoke($app,$base)!==$scan->invoke($app,array_merge($base,array('allowExtensions'=>'pdf,docx,xlsx'))),'extension change invalidates scan cursor');
$file=array('name'=>'a.pdf','size'=>10,'sourceID'=>3,'modifyTime'=>20,'fileType'=>'pdf');
$doc=array('name'=>'a.pdf','size'=>10,'sourceID'=>3,'modifyTime'=>20,'ext'=>'pdf');
check($meta->invoke($app,$doc,$file),'matching metadata is reusable');
$doc['name']='renamed.pdf';
check(!$meta->invoke($app,$doc,$file),'renamed metadata must refresh');
echo "OK\n";
