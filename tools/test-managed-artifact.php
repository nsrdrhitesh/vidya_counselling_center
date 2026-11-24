<?php
declare(strict_types=1);
require __DIR__.'/../deploy/ManagedRelease.php';
use VidyaDeployment\ManagedRelease;
$base = sys_get_temp_dir().'/vidya-artifact-'.bin2hex(random_bytes(8));
mkdir($base.'/vidya-app/legacy', 0700, true);
mkdir($base.'/public_html/_deployment', 0700, true);
copy($argv[1], $base.'/public_html/_deployment/current.json');
$pointer = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($base.'/vidya-app/deployment-settings.json', json_encode(['managed_directory'=>'git-managed','environment_directory'=>'legacy','storage_directory'=>'legacy/storage','fallback_directory'=>'legacy','pointer_from_web'=>true,'schema_fingerprint'=>$argv[2]], JSON_THROW_ON_ERROR));
for ($attempt=0; $attempt<3; $attempt++) {
    $selected = ManagedRelease::select($base.'/vidya-app');
    if ($selected['managed']) break;
    usleep(500000);
}
if (!$selected['managed'] || basename($selected['root']) !== $pointer['release']) throw new RuntimeException('Immutable package download/activation failed.');
require $selected['root'].'/vendor/autoload.php';
if (!class_exists(\Illuminate\Foundation\Application::class)) throw new RuntimeException('Production Laravel dependencies unavailable.');
if (is_file($selected['root'].'/.env')) throw new RuntimeException('Environment leak in package.');
if (ManagedRelease::asset($base.'/public_html', $selected, '/_assets/'.$pointer['release'].'/css/site.css') === null) {
    $css = glob($selected['root'].'/public/css/*.css');
    if (!$css || ManagedRelease::asset($base.'/public_html', $selected, '/_assets/'.$pointer['release'].'/css/'.basename($css[0])) === null) throw new RuntimeException('Versioned public assets unavailable.');
}
echo "Immutable artifact: remote HTTPS download, checksum, activation, Laravel vendor, assets and secret exclusion passed.\n";
