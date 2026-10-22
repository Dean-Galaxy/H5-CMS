<?php

namespace App\Modules\Identity\Filament\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use SensitiveParameter;

class StaffAppAuthentication extends AppAuthentication
{
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        /** @var HasAppAuthentication $user */
        $user = Filament::auth()->user();

        // Keep the configured renderer, issuer and holder. Current Bacon already returns
        // a complete data URI without Imagick; older SVG renderers can return raw XML.
        $inlineQrCode = $this->google2FA->getQRCodeInline(
            $this->getBrandName(),
            $this->getHolderName($user),
            $secret,
        );

        return str_starts_with($inlineQrCode, 'data:')
            ? $inlineQrCode
            : 'data:image/svg+xml;base64,'.base64_encode($inlineQrCode);
    }
}
