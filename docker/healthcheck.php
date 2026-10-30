<?php
declare(strict_types=1);

// El sondeo conserva el Host autorizado sin relajar la validación de dominios de Laravel.
$host=parse_url((string)getenv('APP_URL'),PHP_URL_HOST);
if (!is_string($host) || $host==='') exit(1);
$context=stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true,'header'=>'Host: '.$host."\r\n"]]);
$body=@file_get_contents('http://127.0.0.1:8080/up',false,$context);
exit($body!==false && preg_match('/^HTTP\/\S+ 200\b/',$http_response_header[0]??'')===1?0:1);
