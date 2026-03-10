#!/usr/bin/env php
<?php
/**
 * Standalone app signing script – mirrors occ integrity:sign-app without requiring Nextcloud boot.
 * Usage: php scripts/sign-app-standalone.php
 * Requires: Key and cert at ~/.nextcloud/certificates/arbeitszeitcheck.{key,crt}
 * Run from app root (apps/arbeitszeitcheck).
 */
$appPath = dirname(__DIR__);
$certDir = getenv('HOME') . '/.nextcloud/certificates';
$keyPath = $certDir . '/arbeitszeitcheck.key';
$certPath = $certDir . '/arbeitszeitcheck.crt';

if (!is_file($keyPath) || !is_file($certPath)) {
    fwrite(STDERR, "Error: Key or certificate not found. Expected:\n  $keyPath\n  $certPath\n");
    exit(1);
}

require $appPath . '/../../3rdparty/autoload.php';

use phpseclib\Crypt\RSA;
use phpseclib\File\X509;

$privateKey = file_get_contents($keyPath);
$cert = file_get_contents($certPath);
if ($privateKey === false || $cert === false) {
    fwrite(STDERR, "Error: Could not read key or certificate.\n");
    exit(1);
}

$rsa = new RSA();
$rsa->loadKey($privateKey);
$x509 = new X509();
$x509->loadX509($cert);
$x509->setPrivateKey($rsa);

// Excludes matching Nextcloud release tarball (see Makefile)
$excludeDirs = ['.git', 'build', 'node_modules', 'tests', '.github'];
$excludeFiles = ['.DS_Store', '.directory', '.rnd', 'Thumbs.db', '.phpunit.result.cache'];
$excludePaths = ['appinfo/signature.json'];

$hashes = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = substr($file->getPathname(), strlen($appPath) + 1);
    $rel = str_replace('\\', '/', $rel);

    if (in_array($rel, $excludePaths, true)) {
        continue;
    }
    if (in_array($file->getFilename(), $excludeFiles, true)) {
        continue;
    }
    $skip = false;
    foreach ($excludeDirs as $d) {
        if (strpos($rel, $d . '/') === 0 || $rel === $d) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $hashes[$rel] = hash_file('sha512', $file->getPathname());
}

ksort($hashes);

$rsa->setSignatureMode(RSA::SIGNATURE_PSS);
$rsa->setMGFHash('sha512');
$rsa->setSaltLength(0);
$signature = $rsa->sign(json_encode($hashes));

$data = [
    'hashes' => $hashes,
    'signature' => base64_encode($signature),
    'certificate' => $x509->saveX509($x509->currentCert),
];

$outPath = $appPath . '/appinfo/signature.json';
if (!is_dir(dirname($outPath)) || !is_writable(dirname($outPath))) {
    fwrite(STDERR, "Error: appinfo/ is not writable.\n");
    exit(1);
}
file_put_contents($outPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Successfully signed. Written to appinfo/signature.json\n";
