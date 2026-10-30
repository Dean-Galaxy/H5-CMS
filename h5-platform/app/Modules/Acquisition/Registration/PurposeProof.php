<?php

namespace App\Modules\Acquisition\Registration;

use App\Modules\Acquisition\Analytics\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PurposeProof
{
    public function cookie(): string
    {
        return config('h5.workspace', 'h5').'_r1_purpose';
    }

    public function id(): ?string
    {
        $r = request();
        $id = $r->cookie($this->cookie()) ?? ($r->hasSession() ? $r->session()->get('measurement_r1.proof') : null);

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    public function current(): ?object
    {
        $id = $this->id();

        return $id ? DB::table('acquisition_r1_proofs')->where('id', $id)->where('expires_at', '>', Clock::stamp())->first() : null;
    }

    public function choice(string $purpose): ?object
    {
        $proof = $this->current();

        return $proof ? DB::table('acquisition_r1_choices')->where('proof_id', $proof->id)->where('purpose', $purpose)->first() : null;
    }

    public function serialized(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            $scope = $this->id() ?? (request()->hasSession() ? request()->session()->getId() : 'no-session');
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['acquisition.r1:'.hash('sha256', $scope)]);

            return $operation();
        });
    }

    public function remember(object $proof): void
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes(CarbonImmutable::parse($proof->expires_at), false)));
        Cookie::queue(Cookie::make($this->cookie(), $proof->id, $minutes, '/', null,
            request()->isSecure() || parse_url(config('app.url'), PHP_URL_SCHEME) === 'https', true, false, 'lax'));
        request()->cookies->set($this->cookie(), $proof->id);
        request()->session()->put('measurement_r1.proof', $proof->id);
    }
}
