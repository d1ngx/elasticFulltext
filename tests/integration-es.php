<?php
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}
// Opt-in: creates and deletes only a randomly named test index, never the production corpus.
$url = rtrim((string)getenv('PLUGIN_AUDIT_ES_URL'), '/');
if ($url === '') {echo "SKIP ES integration (set PLUGIN_AUDIT_ES_URL)\n";exit;}
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
function wire($method,$path,$body=null){
 global $url;
 $ch=curl_init($url.$path);
 $opts=array(CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>array('Content-Type: application/json'));
 if($body!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($body);
 curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
 if($method==='DELETE'&&$status===404)return array();
 if($raw===false||$status<200||$status>=300)throw new RuntimeException($method.' '.$path.' failed: '.$status.' '.$error.' '.$raw);
 return json_decode($raw,true);
}
require __DIR__.'/../lib/ElasticClient.class.php';
require __DIR__.'/../../aiRag/lib/HttpJson.class.php';
require __DIR__.'/../../aiRag/lib/ElasticStore.class.php';
$index='kodbox-plugin-test-'.bin2hex(random_bytes(8));$created=false;$pipeline=$index.'-pipeline';
try {
 wire('PUT','/'.$index,array('mappings'=>array('dynamic'=>false,'properties'=>array('fileID'=>array('type'=>'long'),'ext'=>array('type'=>'keyword'),'size'=>array('type'=>'long'),'content'=>array('type'=>'text'),'name'=>array('type'=>'text')))));$created=true;
 foreach(array(1=>array('txt',100),2=>array('pdf',100),3=>array('txt',1048576),4=>array('txt',0))as$id=>$row)wire('PUT','/'.$index.'/_doc/'.$id,array('fileID'=>$id,'ext'=>$row[0],'size'=>$row[1],'content'=>'auditpolicy sample','name'=>'audit.'.$row[0]));
 wire('POST','/'.$index.'/_refresh');
 $config=array('elasticUrl'=>$url,'indexName'=>$index,'allowExtensions'=>'txt','maxFileSizeMB'=>1);
 $client=new KodboxElasticClient($config);
 check(array_column($client->search('auditpolicy',10),'fileID')===array(1),'real ES applies extension, empty-file and size boundary filters');
 check(array_column($client->searchPage('auditpolicy',10,null,true)['hits'],'fileID')===array(1),'real ES fallback paging obeys policy');
 check($client->getContent(2)===''&&$client->getContent(1)==='auditpolicy sample','real ES direct content policy matches search');
 check(array_keys($client->snippets('auditpolicy',array(1,2,3,4)))===array(1),'real ES snippet policy matches search');
 $rag=new AiRagElasticStore(array_merge($config,array('readOnly'=>true)));
 $filter=array('extensions'=>array('txt'),'maxBytes'=>1048576,'excludeFileIDs'=>array(1));
 check(!$rag->search('auditpolicy',10,null,$filter)&&!$rag->searchPage('auditpolicy',10,null,$filter)['hits'],'real ES RAG normal and paged queries exclude disabled content');
 $deleted=0;
 for($i=0;$i<4;$i++){$n=$client->purgeExcluded(1);check($n<=1,'real ES pruning respects one-document batch');$deleted+=$n;wire('POST','/'.$index.'/_refresh');}
 check($deleted===3&&$client->count()===1&&$client->getContent(1)!=='','real ES pruning preserves allowed corpus');
 $config['allowExtensions']='';$denyAll=new KodboxElasticClient($config);
 check(!$denyAll->search('auditpolicy',10)&&$denyAll->purgeExcluded(10)===1,'real ES empty allow list filters and cleans all test documents');
 $config['allowExtensions']='txt';$metadataClient=new KodboxElasticClient($config);
 (new ReflectionClass('KodboxElasticClient'))->getProperty('pipeline')->setValue($metadataClient,$pipeline);
 $metadataClient->ensureInfrastructure();
 $source=array('fileID'=>9,'sourceID'=>0,'name'=>'new.txt','fileType'=>'txt','size'=>90000,'modifyTime'=>123);
 $metadataClient->indexFile($source,str_repeat('metadata ',10000),true);
 $source['fileID']=10;$source['size']=20;$metadataClient->indexFile($source,'Tika metadata test',false);
 $docs=$metadataClient->getDocuments(array(9,10));
 check($docs[9]['contentReady']===true&&$docs[10]['contentReady']===true,'real ES plain and Tika extraction persist content-ready metadata');
 check(!isset($docs[9]['content'])&&strlen(json_encode($docs))<2000,'real ES batch metadata payload is independent of body length');
} finally {if($created)wire('DELETE','/'.$index);wire('DELETE','/_ingest/pipeline/'.$pipeline);}
