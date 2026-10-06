<?php

namespace App\Support;

use App\Contracts\RegionResolver;
use App\Data\ResolvedRegion;
use Illuminate\Http\Request;

final class ServerRegionResolver implements RegionResolver
{
    public function __construct(
        private Request $request,
        private string $environment,
        private bool $localUsTestEnabled,
        private bool $console,
    ) {}

    public function resolve(): ResolvedRegion
    {
        if ($this->localUsTestEnabled && ! in_array($this->environment, ['local', 'testing'], true)) {
            throw new \LogicException('A local test region is forbidden outside local/testing.');
        }

        // Deliberately use the direct peer, not getClientIp() or forwarding/country headers.
        $peer = $this->request->server->get('REMOTE_ADDR');
        if ($this->localUsTestEnabled && ! $this->console && in_array($peer, ['127.0.0.1', '::1'], true)) {
            return new ResolvedRegion('US', 'local_us_test');
        }

        // A deployment-specific trusted resolver must be supplied before geographical acceptance.
        return new ResolvedRegion('ZZ', 'unresolved');
    }
}
