<?php

$dir      = __DIR__;
$pharName = 'phptobpc.phar';
$path     = $dir . '/' . $pharName;

@unlink($path);

$phar = new Phar($path);

$phar->startBuffering();

$phar->buildFromDirectory(__DIR__, '/^((?!\.git).)*$/');

$phar->delete('make-phar.php');

$phar->setStub("#!/usr/bin/php
<?php

Phar::mapPhar('phptobpc.phar');
include 'phar://phptobpc.phar/phptobpc.php';

__HALT_COMPILER();");

$phar->compressFiles(Phar::GZ);

$phar->stopBuffering();

chmod($path, 0755);
