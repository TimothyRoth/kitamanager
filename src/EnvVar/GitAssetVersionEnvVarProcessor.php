<?php

namespace App\EnvVar;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Resolves ASSET_VERSION for cache-busting: uses an explicit env value, or
 * falls back to `git rev-parse --short HEAD` when the value is empty/"auto".
 */
final class GitAssetVersionEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $configured = '';
        try {
            $configured = (string) $getEnv($name);
        } catch (\Exception) {
            // Missing env var → treat as auto.
        }

        if ($configured !== '' && strtolower($configured) !== 'auto') {
            return $configured;
        }

        return $this->resolveGitShortHash() ?? 'dev';
    }

    public static function getProvidedTypes(): array
    {
        return ['git_asset' => 'string'];
    }

    private function resolveGitShortHash(): ?string
    {
        $root = \dirname(__DIR__, 2);
        $gitPath = $root.\DIRECTORY_SEPARATOR.'.git';
        if (!is_dir($gitPath) && !is_file($gitPath)) {
            return null;
        }

        $command = 'git -C '.escapeshellarg($root).' rev-parse --short HEAD';
        $hash = trim((string) shell_exec($command));

        if ($hash !== '' && 1 === preg_match('/^[0-9a-f]+$/i', $hash)) {
            return $hash;
        }

        return null;
    }
}
