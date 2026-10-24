<?php
declare(strict_types=1);
$root=dirname(__DIR__);
if(ini_get('phar.readonly'))throw new RuntimeException('Use php -d phar.readonly=0 tools/build.php');
@mkdir($root.'/build',0777,true);
$projects=['AmyxClans'=>$root,'AmyxEconomy'=>$root.'/extras/AmyxEconomy','ChatPerms'=>$root.'/extras/ChatPerms'];
foreach(['AmyBuilderGuard','AmyxCommandAccess'] as $optional)if(is_dir($root.'/extras/'.$optional))$projects[$optional]=$root.'/extras/'.$optional;
foreach($projects as $name=>$dir){
    $out=$root.'/build/'.$name.'.phar';if(is_file($out))unlink($out);$phar=new Phar($out);$phar->startBuffering();
    foreach(['plugin.yml','LICENSE','LICENSE.txt','LICENSE.md'] as $file)if(is_file($dir.'/'.$file))$phar->addFile($dir.'/'.$file,$file);
    foreach(['src','resources'] as $folder)if(is_dir($dir.'/'.$folder))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.'/'.$folder,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile())$phar->addFile($f->getPathname(),substr($f->getPathname(),strlen($dir)+1));
    $phar->setStub('<?php __HALT_COMPILER();');$phar->setSignatureAlgorithm(Phar::SHA256);$phar->stopBuffering();echo $name,': ',filesize($out),' bytes',PHP_EOL;
}
