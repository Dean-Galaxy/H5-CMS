<?php

namespace App\Modules\Audience\Http;

use Illuminate\Http\Response;

final class LegalPageController
{
    public function __invoke(string $document): Response
    {
        return response()->view('audience.legal', compact('document'), headers: [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
