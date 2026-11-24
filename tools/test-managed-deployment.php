<?php
// Isolated temporary-files test; no hosting credentials, network or production database.
require __DIR__.'/../deploy/ManagedRelease.php';
use VidyaDeployment\ManagedRelease as Release;
$tmp = sys_get_temp_dir().'/vidya-managed-'.bin2hex(random_bytes(8));
mkdir($tmp.'/legacy', 0700, true);
mkdir($tmp.'/git-managed/releases', 0700, true);
$sha = str_repeat('a', 40); $schema = str_repeat('b', 64);
file_put_contents($tmp.'/deployment-settings.json', json_encode(['managed_directory'=>'git-managed','environment_directory'=>'legacy','storage_directory'=>'legacy/storage','fallback_directory'=>'legacy','schema_fingerprint'=>$schema]));
$root = $tmp.'/git-managed/releases/'.$sha;
mkdir($root.'/vendor', 0700, true); mkdir($root.'/bootstrap', 0700, true); mkdir($root.'/public/css', 0700, true);
file_put_contents($root.'/vendor/autoload.php', 'test autoloader'); file_put_contents($root.'/bootstrap/app.php', 'test application'); file_put_contents($root.'/public/css/test.css', 'body{color:green}');
$manifest = ['commit'=>$sha,'schema_fingerprint'=>$schema,'files'=>[]];
foreach (['vendor/autoload.php','bootstrap/app.php','public/css/test.css'] as $path) $manifest['files'][$path] = hash_file('sha256', $root.'/'.$path);
file_put_contents($root.'/deployment-manifest.json', json_encode($manifest));
$candidate = ['release'=>$sha,'manifest_sha256'=>hash_file('sha256', $root.'/deployment-manifest.json')];
file_put_contents($tmp.'/git-managed/current.json', json_encode($candidate));
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
file_put_contents($root.'/vendor/autoload.php', 'incomplete');
check(Release::select($tmp)['managed'] === false, 'Incomplete checkout must keep the legacy release.');
file_put_contents($root.'/vendor/autoload.php', 'test autoloader');
check(Release::select($tmp)['root'] === $root, 'A complete release must activate.');
check(Release::asset($tmp, Release::select($tmp), '/_assets/'.$sha.'/css/test.css') !== null, 'Versioned public CSS must resolve.');
foreach (['/vendor/autoload.php','/_assets/'.$sha.'/../vendor/autoload.php','/_assets/'.$sha.'/.env','/css/../../deployment-settings.json'] as $path) check(Release::asset($tmp, Release::select($tmp), $path) === null, 'Private files/traversal must be rejected.');
check(!Release::verify($root, $candidate, str_repeat('c', 64)), 'Unapproved schema fingerprint must fail.');
file_put_contents($tmp.'/git-managed/current.json', json_encode(['disabled'=>true]));
check(Release::select($tmp)['managed'] === false, 'Explicit rollback must serve legacy without changing data.');
echo "Managed deployment: 9 checks passed.\n";
