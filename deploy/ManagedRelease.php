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
            $candidate = self::json($managed.'/current.json');
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
