<?php

namespace App\Support;

use Illuminate\Support\ServiceProvider;

final class LocalModuleRegistry
{
    /** The provider allowlist is server configuration, never a request or arbitrary environment class name. */
    public function resolve(array $modules, string $requested, string $environment, array $allowlist): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $requested)), fn ($v) => $v !== '')));
        if ($names === []) {
            return $modules;
        }
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new \LogicException('Local module loading is forbidden outside local/testing.');
        }

        foreach ($names as $name) {
            if (! isset($modules[$name], $allowlist[$name])) {
                throw new \LogicException('The requested local module is not on the shared allowlist.');
            }
            $provider = $allowlist[$name];
            if (! is_string($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
                throw new \LogicException('The requested local module provider is missing or invalid.');
            }
            if ($modules[$name]['provider'] !== null && $modules[$name]['provider'] !== $provider) {
                throw new \LogicException('Local module loading cannot replace an accepted provider.');
            }
            $modules[$name] = ['provider' => $provider, 'ready' => true];
        }

        return $modules;
    }
}
