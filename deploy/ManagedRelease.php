<?php
declare(strict_types=1);

namespace VidyaDeployment;

/** Runs without Laravel, shell commands, Composer, cron or database writes. */
final class ManagedRelease
{
    public static function relative(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\')
            && !str_contains($path, "\0") && !in_array('..', explode('/', $path), true);
    }

    private static function json(string $path): array
    {
        $data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
        return is_array($data) ? $data : [];
    }

    /** Fetch an immutable, checksum-pinned build directly into private storage. */
    private static function download(string $managed, array $candidate, string $schema): bool
    {
        $commit = (string)($candidate['artifact_commit'] ?? '');
        $sha = (string)($candidate['release'] ?? '');
        $hash = (string)($candidate['archive_sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}$/', $commit) || !preg_match('/^[a-f0-9]{40}$/', $sha) || !preg_match('/^[a-f0-9]{64}$/', $hash)
            || !function_exists('curl_init') || !class_exists(\ZipArchive::class)) return false;
        if (!is_dir($managed.'/releases')) mkdir($managed.'/releases', 0700, true);
        $temporary = $managed.'/incoming-'.bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        $archive = $temporary.'/package.zip';
        $stream = fopen($archive, 'wb');
        $curl = curl_init('https://raw.githubusercontent.com/nsrdrhitesh/vidya_counselling_center/'.$commit.'/deployment.zip');
        curl_setopt_array($curl, [CURLOPT_FILE=>$stream, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>45, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl); fclose($stream); chmod($archive, 0600);
        if (!$ok || $status !== 200 || filesize($archive) > 33554432 || !hash_equals($hash, (string)hash_file('sha256', $archive))) return false;
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true || $zip->numFiles > 15000) return false;
        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (!self::relative($name) || preg_match('~(^|/)\.env(?:$|[./])~', $name)) { $zip->close(); return false; }
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if ((($attributes >> 16) & 0170000) === 0120000) { $zip->close(); return false; }
        }
        $target = $temporary.'/release'; mkdir($target, 0700);
        $ok = $zip->extractTo($target); $zip->close(); unlink($archive);
        if (!$ok || !self::verify($target, $candidate, $schema)) return false;
        foreach (['bootstrap/cache','storage/app/private','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs'] as $folder) if (!is_dir($target.'/'.$folder)) mkdir($target.'/'.$folder, 0700, true);
        // The destination never replaces an active release directory.
        if (is_dir($managed.'/releases/'.$sha)) return true;
        return rename($target, $managed.'/releases/'.$sha);
    }

    public static function verify(string $root, array $candidate, string $schema): bool
    {
        $manifest = $root.'/deployment-manifest.json';
        if (!is_file($manifest) || !hash_equals((string)($candidate['manifest_sha256'] ?? ''), (string)hash_file('sha256', $manifest))) return false;
        $data = self::json($manifest);
        if (($data['commit'] ?? '') !== ($candidate['release'] ?? '') || ($data['schema_fingerprint'] ?? '') !== $schema) return false;
        $files = $data['files'] ?? [];
        if (!is_array($files) || count($files) < 2 || count($files) > 15000 || !isset($files['vendor/autoload.php'], $files['bootstrap/app.php'])) return false;
        $prefix = realpath($root);
        if (!$prefix) return false;
        foreach ($files as $path => $hash) {
            if (!self::relative((string)$path) || preg_match('~(^|/)\.env(?:$|[./])~', $path) || !preg_match('/^[a-f0-9]{64}$/', (string)$hash)) return false;
            $actual = realpath($root.'/'.$path);
            if (!$actual || !str_starts_with($actual, $prefix.DIRECTORY_SEPARATOR) || !is_file($actual) || !hash_equals($hash, (string)hash_file('sha256', $actual))) return false;
        }
        return true;
    }

    public static function select(string $private): array
    {
        $settings = self::json($private.'/deployment-settings.json');
        foreach (['managed_directory', 'environment_directory', 'storage_directory', 'fallback_directory'] as $key) {
            if (!self::relative((string)($settings[$key] ?? ''))) throw new \RuntimeException('Deployment configuration unavailable.');
        }
        $managed = $private.'/'.$settings['managed_directory'];
        $fallback = $private.'/'.$settings['fallback_directory'];
        $selected = ['root'=>$fallback, 'environment'=>$private.'/'.$settings['environment_directory'], 'storage'=>$private.'/'.$settings['storage_directory'], 'managed'=>false, 'managed_root'=>$managed];
        if (($settings['enabled'] ?? true) !== true) return $selected;
        $lock = fopen($private.'/managed-activation.lock', 'c');
        if (!$lock) return $selected;
        @chmod($private.'/managed-activation.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) return $selected;
            $pointer = ($settings['pointer_from_web'] ?? false) === true ? dirname($private).'/public_html/_deployment/current.json' : $managed.'/current.json';
            $candidate = self::json($pointer);
            if (($candidate['disabled'] ?? false) === true) return $selected;
            $active = self::json($private.'/managed-active.json');
            $activeSha = (string)($active['release'] ?? '');
            if (preg_match('/^[a-f0-9]{40}$/', $activeSha) && is_file($managed.'/releases/'.$activeSha.'/vendor/autoload.php')) {
                $selected['root'] = $managed.'/releases/'.$activeSha;
                $selected['managed'] = true;
            }
            $sha = (string)($candidate['release'] ?? '');
            if (!preg_match('/^[a-f0-9]{40}$/', $sha) || !preg_match('/^[a-f0-9]{64}$/', (string)($settings['schema_fingerprint'] ?? ''))) return $selected;
            if ($sha === $activeSha && ($active['manifest_sha256'] ?? '') === ($candidate['manifest_sha256'] ?? '')) return $selected;
            $root = $managed.'/releases/'.$sha;
            if (!is_dir($root) && ($settings['pointer_from_web'] ?? false) === true) self::download($managed, $candidate, $settings['schema_fingerprint']);
            // Incomplete Git checkouts never become active; the previous release keeps serving.
            if (!self::verify($root, $candidate, $settings['schema_fingerprint'])) return $selected;
            $temporary = $private.'/managed-active-'.bin2hex(random_bytes(8)).'.tmp';
            file_put_contents($temporary, json_encode($candidate, JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($temporary, 0600);
            if (!rename($temporary, $private.'/managed-active.json')) throw new \RuntimeException('Release activation unavailable.');
            $selected['root'] = $root;
            $selected['managed'] = true;
            return $selected;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function asset(string $publicRoot, array $selection, string $requestPath): ?array
    {
        $path = rawurldecode($requestPath);
        $root = $selection['managed'] ? $selection['root'].'/public' : $publicRoot;
        if (preg_match('~^/_assets/([a-f0-9]{40})/(.+)$~', $path, $match)) {
            $root = $selection['managed_root'].'/releases/'.$match[1].'/public';
            $path = '/'.$match[2];
        }
        $relative = ltrim($path, '/');
        if (!self::relative($relative) || !preg_match('~^(?:css/|js/|images/|vendor/quill/|robots\.txt$|favicon\.ico$)~', $relative)) return null;
        $types = ['css'=>'text/css; charset=UTF-8', 'js'=>'text/javascript; charset=UTF-8', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp', 'svg'=>'image/svg+xml', 'ico'=>'image/x-icon', 'txt'=>'text/plain; charset=UTF-8', 'woff'=>'font/woff', 'woff2'=>'font/woff2'];
        $type = $types[strtolower(pathinfo($relative, PATHINFO_EXTENSION))] ?? null;
        $file = realpath($root.'/'.$relative);
        $base = realpath($root);
        if (!$type || !$file || !$base || !str_starts_with($file, $base.DIRECTORY_SEPARATOR) || !is_file($file)) return null;
        return ['path'=>$file, 'type'=>$type];
    }
}
