<?php
declare(strict_types=1);

// This adapter is installed as public_html/index.php, not as the local Laravel entry.
$private = dirname(__DIR__).'/vidya-app';
require $private.'/deployment-runtime/ManagedRelease.php';
$selection = \VidyaDeployment\ManagedRelease::select($private);
$requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
if ($asset = \VidyaDeployment\ManagedRelease::asset(__DIR__, $selection, $requestPath)) {
    $etag = '"'.hash_file('sha256', $asset['path']).'"';
    header('Content-Type: '.$asset['type']);
    header('X-Content-Type-Options: nosniff');
    header('ETag: '.$etag);
    header('Cache-Control: public, max-age=0, must-revalidate');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    readfile($asset['path']);
    exit;
}
if (str_starts_with($requestPath, '/_assets/')) { http_response_code(404); exit; }
define('LARAVEL_START', microtime(true));
if (is_file($maintenance = $selection['storage'].'/framework/maintenance.php')) require $maintenance;
require $selection['root'].'/vendor/autoload.php';
$app = require $selection['root'].'/bootstrap/app.php';
$app->useEnvironmentPath($selection['environment']);
$app->useStoragePath($selection['storage']);
$app->usePublicPath(__DIR__);
$app->handleRequest(\Illuminate\Http\Request::capture());
