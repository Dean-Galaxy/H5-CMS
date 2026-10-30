<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\Viewer;
use Illuminate\Support\Facades\DB;

/** Fixed local test documents, never production terms or optional-purpose consent. */
final class RegistrationTerms
{
    public const TERMS = 'local-c-terms-r1-v1';

    public const PRIVACY = 'local-c-privacy-r1-v1';

    public static function enabled(): bool
    {
        return config('h5.measurement_c_registration') === true;
    }

    public function receipt(Viewer $viewer): void
    {
        DB::table('identity_registration_receipts')->insert([
            'viewer_id' => $viewer->id, 'terms_version' => self::TERMS,
            'privacy_notice_version' => self::PRIVACY,
            'locale' => app()->getLocale(), 'accepted_at' => now(), 'is_test' => true,
        ]);
    }
}
