<?php

namespace Tests\Security;

use App\Enums\Role;
use Tests\Feature\Content\ContentTestCase;
use Tests\Integration\Support\PaymentScenario;

final class CrossModuleBoundaryTest extends ContentTestCase
{
    public function test_viewer_cannot_use_another_order_or_bypass_media_after_signout(): void
    {
        $d = $this->fixture(6, 6, true);
        $p = PaymentScenario::paid();
        $this->asViewer($p['viewer']);
        $path = '/audience/dramas/'.$d->id.'/episodes/'.$d->episodes[5]->id.'/playback';
        $grant = $this->postJson($path, ['play_session_id' => null])->assertOk()->json();
        $this->get($grant['short_lived_url'])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->get($grant['short_lived_url'])->assertNotFound();
        $this->getJson('/api/v1/billing/orders/'.$p['order']->id)->assertUnauthorized();
        $this->postJson('/api/v1/auth/register', ['name' => 'Other', 'email' => 'other06@example.test', 'password' => 'Different06Password!', 'password_confirmation' => 'Different06Password!'])->assertSuccessful();
        $this->getJson('/api/v1/billing/orders/'.$p['order']->id)->assertNotFound();
        $this->getJson('/api/v1/me/progress/'.$d->id)->assertContent('null');
    }

    public function test_content_staff_has_no_billing_authority_even_with_mfa(): void
    {
        $staff = $this->makeStaff(Role::ContentOperator);
        $this->asStaff($staff);
        $this->get('/admin/billing-desk')->assertForbidden();
    }
}
