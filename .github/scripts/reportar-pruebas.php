<?php
declare(strict_types=1);

// Publica únicamente el diagnóstico de las pruebas, sin volcar el entorno del runner.
$path=$argv[1]??'build/phpunit.xml';
if (!is_file($path)) exit(0);
$document=new DOMDocument();
if (!$document->load($path,LIBXML_NONET)) exit(1);
$query=new DOMXPath($document);
$count=0;
foreach ($query->query('//testcase[failure or error]') as $case) {
    $problem=$query->query('failure|error',$case)->item(0);
    $message=implode("\n",array_slice(explode("\n",trim($problem->textContent)),0,8));
    $message=preg_replace('/\b(gh[pous]_[A-Za-z0-9]+|re_[A-Za-z0-9]{20,})\b/','[secreto omitido]',$message);
    $message=mb_substr($message,0,1800);
    $escape=static fn(string $value):string=>str_replace(['%',"\r","\n",':',','],['%25','%0D','%0A','%3A','%2C'],$value);
    echo '::error title='.$escape($case->getAttribute('name')).'::'.$escape($message),PHP_EOL;
    if (++$count>=20) break;
}
echo 'Fallos de pruebas anotados: ',$count,PHP_EOL;
