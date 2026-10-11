<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../lib/Security.class.php';
function _get($a,$k,$d=null){return isset($a[$k])?$a[$k]:$d;}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
require __DIR__.'/../lib/ElasticClient.class.php';
$r=new ReflectionClass('KodboxElasticClient');$c=new KodboxElasticClient(array('verifyTls'=>0));
check($r->getProperty('verifyTls')->getValue($c)===true,'legacy config cannot disable TLS verification');
foreach(array('file:///etc/passwd','gopher://localhost/','https://user:pass@host/')as$url){$rejected=false;try{new KodboxElasticClient(array('elasticUrl'=>$url));}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'reject unsafe Elasticsearch URL');}
check(!KodboxSearchSecurity::requestAllowed(array('REQUEST_METHOD'=>'POST'),array(),true),'forged admin POST denied');
check(!KodboxSearchSecurity::requestAllowed(array('REQUEST_METHOD'=>'GET','HTTP_X_KODBOX_PLUGIN'=>'1'),array(),true),'destructive GET denied');
