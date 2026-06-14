<?php

namespace Elcreator\aEvoAST\Parsers;

class SourceDiscovery
{
    /**
     * Discover all parseable sources in the current Evolution CMS project.
     *
     * @param  string $basePath  Project root (where composer.json lives)
     * @return array<int, array{path: string, layer: string, name: string, version: string|null}>
     */
    public function discover(string $basePath): array
    {
        $sources = [];

        // Layer 0: Evolution CMS core
        $corePath = $basePath . '/vendor/evolution-cms/evolution/core';
        if (!is_dir($corePath)) {
            $corePath = $basePath . '/core/vendor/evolution-cms/evolution';
        }
        if (!is_dir($corePath)) {
            // Try the core src directly
            $corePath = $basePath . '/core/src';
        }

        if (is_dir($corePath)) {
            $sources[] = [
                'path'    => $corePath,
                'layer'   => 'core',
                'name'    => 'evolution-cms',
                'version' => $this->getComposerVersion($basePath, 'evolution-cms/evolution'),
            ];
        }

        // Layer 1: Auto-discovered extras
        if (config('aevoast.auto_extras', true)) {
            $vendors = config('aevoast.extra_vendors', []);
            foreach ($vendors as $vendor) {
                $vendorPath = $this->vendorPath($basePath) . '/' . $vendor;
                if (!is_dir($vendorPath)) continue;

                foreach (new \DirectoryIterator($vendorPath) as $item) {
                    if ($item->isDot() || !$item->isDir()) continue;

                    $packageName = $vendor . '/' . $item->getFilename();
                    $sources[] = [
                        'path'    => $item->getRealPath(),
                        'layer'   => 'extra',
                        'name'    => $packageName,
                        'version' => $this->getComposerVersion($basePath, $packageName),
                    ];
                }
            }
        }

        // Layer 2: Local custom code
        $localPaths = config('aevoast.local_paths', []);
        foreach ($localPaths as $rel) {
            $absPath = $basePath . '/' . ltrim($rel, '/');
            if (!is_dir($absPath)) continue;

            $sources[] = [
                'path'    => $absPath,
                'layer'   => 'local',
                'name'    => 'local/' . basename($rel),
                'version' => null,
            ];
        }

        return $sources;
    }

    /**
     * Get installed version of a composer package from composer.lock.
     */
    private function getComposerVersion(string $basePath, string $packageName): ?string
    {
        $lockFile = $basePath . '/core/composer.lock';
        if (!file_exists($lockFile)) {
            $lockFile = $basePath . '/composer.lock';
        }

        if (!file_exists($lockFile)) {
            return null;
        }

        $lock = json_decode(file_get_contents($lockFile), true);
        if (!$lock) return null;

        $packages = array_merge(
            $lock['packages'] ?? [],
            $lock['packages-dev'] ?? []
        );

        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? '') === $packageName) {
                return $pkg['version'] ?? null;
            }
        }

        return null;
    }

    private function vendorPath(string $basePath): string
    {
        // Evolution CMS 3.x keeps vendor inside /core/
        $corePath = $basePath . '/core/vendor';
        if (is_dir($corePath)) {
            return $corePath;
        }
        return $basePath . '/vendor';
    }
}
