<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
class PluginBase {}
require __DIR__.'/../app.php';
$r=new ReflectionClass('elasticFulltextPlugin');$app=$r->newInstanceWithoutConstructor();
$file=tempnam(sys_get_temp_dir(),'archive-audit-');
try{
 $z=new ZipArchive;$z->open($file,ZipArchive::OVERWRITE);$z->addFromString('[Content_Types].xml',str_repeat('A',1048577));$z->close();$data=file_get_contents($file);
 foreach(array(function()use($r,$app,$data){$r->getMethod('sanitizeOfficeZip')->invoke($app,$data,'docx');})as$fn){$deny=false;try{$fn();}catch(RuntimeException $e){$deny=true;}if(!$deny)throw new RuntimeException('compressed XML accepted');echo "PASS reject compressed Office XML before expansion\n";}
}finally{unlink($file);}
