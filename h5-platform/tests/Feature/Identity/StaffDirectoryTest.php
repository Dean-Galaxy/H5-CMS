<?php

namespace Tests\Feature\Identity;

use App\Contracts\AcquisitionStaffDirectory;
use App\Enums\Permission;
use App\Enums\Role;
use App\Modules\Identity\Models\Staff;
use App\Modules\Identity\Models\Viewer;
use App\Modules\Identity\Services\StaffDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/_identity-directory-test';

    protected function setUp(): void
    {
        parent::setUp();
        // Test-only consumer: no auth/role/MFA middleware that could hide a missing service check.
        Route::middleware('web')->get(self::ENDPOINT, fn (Request $request) => app(AcquisitionStaffDirectory::class)
            ->candidates($request->query('cursor'), (int) $request->query('limit', 50))->toArray());
        Route::middleware('web')->get(self::ENDPOINT.'/{staffId}', fn (string $staffId) => app(AcquisitionStaffDirectory::class)
            ->candidate($staffId)->toArray());
    }

    private function staff(Role $role = Role::Acquisition, ?string $id = null, string $name = 'Employee', bool $active = true, bool $mfa = true): Staff
    {
        $id ??= (string) Str::uuid7();
        $staff = Staff::forceCreate(['id' => $id, 'name' => $name, 'email' => $id.'@example.test',
            'password' => 'LongPassword1234', 'role' => $role, 'active' => $active]);
        if ($mfa) {
            $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        }

        return $staff->refresh();
    }

    private function signIn(Staff $staff, ?int $version = null): static
    {
        return $this->actingAs($staff, 'staff')->withSession([
            'identity.version.staff' => $version ?? $staff->session_version, 'identity.mfa_staff' => $staff->id,
        ]);
    }

    private function staffId(int $number): string
    {
        return sprintf('019a2b3c-1234-7000-8000-%012d', $number);
    }

    private function listing(array $query = []): string
    {
        return self::ENDPOINT.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function test_real_provider_returns_only_active_acquisition_staff_and_minimal_fields(): void
    {
        $this->assertInstanceOf(StaffDirectory::class, app(AcquisitionStaffDirectory::class));
        $owner = $this->staff(Role::Owner);
        $second = $this->staff(id: $this->staffId(2), name: 'Same name');
        $first = $this->staff(id: $this->staffId(1), name: 'Same name', mfa: false);
        $this->staff(active: false);
        $this->staff(Role::ContentOperator);
        $this->staff(Role::Support);
        $expected = array_map(fn (Staff $staff) => ['id' => $staff->id, 'name' => 'Same name', 'role' => 'acquisition', 'active' => true], [$first, $second]);
        $this->signIn($owner)->getJson(self::ENDPOINT)->assertOk()->assertExactJson(['items' => $expected, 'next_cursor' => null]);
        $this->getJson(self::ENDPOINT.'/'.$first->id)->assertOk()->assertExactJson($expected[0]);
    }

    public function test_real_empty_directory_does_not_offer_the_owner_as_an_assignment(): void
    {
        $owner = $this->staff(Role::Owner);
        $this->staff(active: false);
        $this->staff(Role::ContentOperator);
        $this->signIn($owner)->getJson(self::ENDPOINT)->assertOk()->assertExactJson(['items' => [], 'next_cursor' => null]);
        $this->getJson(self::ENDPOINT.'/'.$owner->id)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_guest_and_viewer_are_denied_before_input_or_candidate_information(): void
    {
        $eligible = $this->staff();
        $urls = [$this->listing(['limit' => 0, 'cursor' => 'forged']), self::ENDPOINT.'/not-a-uuid', self::ENDPOINT.'/'.$eligible->id];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        }
        $viewer = Viewer::create(['name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => 'LongPassword1234']);
        $this->actingAs($viewer, 'web')->withSession(['identity.version.web' => $viewer->session_version]);
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized()->assertJsonPath('error.code', 'AUTHENTICATION_REQUIRED');
        }
    }

    public static function nonOwnerRoles(): array
    {
        return ['content' => [Role::ContentOperator], 'support' => [Role::Support], 'acquisition' => [Role::Acquisition]];
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_other_roles_with_mfa_cannot_read_or_probe_the_directory(Role $role): void
    {
        $eligible = $this->staff();
        $this->signIn($this->staff($role));
        foreach ([$this->listing(['limit' => 0, 'cursor' => 'forged']), self::ENDPOINT.'/not-a-uuid', self::ENDPOINT.'/'.$eligible->id] as $url) {
            $this->getJson($url)->assertForbidden()->assertJsonPath('error.code', 'NOT_AUTHORIZED');
        }
        $this->assertDatabaseHas('identity_audit_events', ['action' => 'permission.denied', 'subject_id' => Permission::StaffManage->value]);
    }

    public function test_owner_needs_configured_mfa_and_the_current_staff_proof_on_both_methods(): void
    {
        foreach (['not_configured', 'missing_proof', 'wrong_staff'] as $state) {
            $owner = $this->staff(Role::Owner, mfa: $state !== 'not_configured');
            $this->signIn($owner);
            if ($state !== 'not_configured') {
                $this->withSession(['identity.mfa_staff' => $state === 'missing_proof' ? null : $this->staffId(999)]);
            }
            $this->getJson($this->listing(['limit' => 0, 'cursor' => 'forged']))->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
            $this->getJson(self::ENDPOINT.'/not-a-uuid')->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        }
    }

    public function test_disabled_revoked_and_demoted_actors_lose_read_access(): void
    {
        foreach ([
            [['active' => false], 401, 'ACCOUNT_DISABLED'],
            [['session_version' => 2], 401, 'SESSION_REVOKED'],
            [['role' => Role::Acquisition->value], 403, 'NOT_AUTHORIZED'],
        ] as [$changes, $status, $code]) {
            $owner = $this->staff(Role::Owner);
            $version = $owner->session_version;
            $this->signIn($owner)->getJson(self::ENDPOINT)->assertOk();
            DB::table('identity_staff')->where('id', $owner->id)->update($changes);
            foreach ([$this->listing(['limit' => 0]), self::ENDPOINT.'/not-a-uuid'] as $url) {
                $this->signIn($owner, $version)->getJson($url)->assertStatus($status)->assertJsonPath('error.code', $code);
            }
        }
    }

    public function test_saved_cursor_does_not_replace_permission_or_mfa_checks(): void
    {
        $owner = $this->staff(Role::Owner);
        $this->staff(id: $this->staffId(1));
        $selected = $this->staff(id: $this->staffId(2));
        $cursor = $this->signIn($owner)->getJson($this->listing(['limit' => 1]))->assertOk()->json('next_cursor');
        $this->withSession(['identity.mfa_staff' => null]);
        $this->getJson($this->listing(['cursor' => $cursor]))->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->getJson(self::ENDPOINT.'/'.$selected->id)->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->signIn($this->staff(Role::Acquisition));
        $this->getJson($this->listing(['cursor' => $cursor]))->assertForbidden()->assertJsonPath('error.code', 'NOT_AUTHORIZED');
    }

    public function test_unknown_inactive_and_wrong_role_candidates_share_the_same_not_found_response(): void
    {
        $owner = $this->staff(Role::Owner);
        $ids = [$this->staffId(999), $this->staff(active: false)->id, $owner->id,
            $this->staff(Role::ContentOperator)->id, $this->staff(Role::Support)->id];
        $this->signIn($owner);
        $messages = [];
        foreach ($ids as $id) {
            $messages[] = $this->getJson(self::ENDPOINT.'/'.$id)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND')->json('error.message');
        }
        $this->assertCount(1, array_unique($messages));
    }

    public function test_selected_staff_outside_the_current_page_can_be_read_and_is_rechecked_before_save(): void
    {
        $owner = $this->staff(Role::Owner);
        $first = $this->staff(id: $this->staffId(1));
        $selected = $this->staff(id: $this->staffId(2));
        $page = $this->signIn($owner)->getJson($this->listing(['limit' => 1]))->assertOk()->json();
        $this->assertSame([$first->id], array_column($page['items'], 'id'));
        $this->getJson(self::ENDPOINT.'/'.$selected->id)->assertOk()->assertJsonPath('id', $selected->id);
        DB::table('identity_staff')->where('id', $selected->id)->update(['active' => false]);
        $this->getJson(self::ENDPOINT.'/'.$selected->id)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        DB::table('identity_staff')->where('id', $selected->id)->update(['active' => true, 'role' => Role::Support->value]);
        $this->getJson(self::ENDPOINT.'/'.$selected->id)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        DB::table('identity_staff')->where('id', $selected->id)->update(['role' => Role::Acquisition->value, 'name' => 'Updated employee']);
        $this->getJson(self::ENDPOINT.'/'.$selected->id)->assertOk()->assertJsonPath('name', 'Updated employee');
    }

    public function test_default_limit_and_keyset_pagination_are_stable_for_duplicate_names(): void
    {
        $owner = $this->staff(Role::Owner);
        foreach (range(53, 1) as $number) {
            $this->staff(id: $this->staffId($number), name: 'Same name', mfa: false);
        }
        $expected = array_map(fn (int $number) => $this->staffId($number), range(1, 53));
        $first = $this->signIn($owner)->getJson(self::ENDPOINT)->assertOk()->json();
        $this->assertSame(array_slice($expected, 0, 50), array_column($first['items'], 'id'));
        $this->assertNotNull($first['next_cursor']);
        $second = $this->getJson($this->listing(['limit' => 2, 'cursor' => $first['next_cursor']]))->assertOk()->json();
        $this->assertSame(array_slice($expected, 50, 2), array_column($second['items'], 'id'));
        $this->assertNotNull($second['next_cursor']);
        $last = $this->getJson($this->listing(['limit' => 50, 'cursor' => $second['next_cursor']]))->assertOk()->json();
        $this->assertSame([$expected[52]], array_column($last['items'], 'id'));
        $this->assertNull($last['next_cursor']);
        $this->assertSame($expected, array_column([...$first['items'], ...$second['items'], ...$last['items']], 'id'));
    }

    public function test_renames_and_ineligible_boundary_rows_do_not_break_cursor_continuation(): void
    {
        $owner = $this->staff(Role::Owner);
        foreach (range(1, 4) as $number) {
            $this->staff(id: $this->staffId($number));
        }
        $cursor = $this->signIn($owner)->getJson($this->listing(['limit' => 2]))->assertOk()->json('next_cursor');
        DB::table('identity_staff')->where('id', $this->staffId(1))->update(['name' => 'ZZZ']);
        DB::table('identity_staff')->where('id', $this->staffId(2))->update(['active' => false]);
        DB::table('identity_staff')->where('id', $this->staffId(4))->update(['role' => Role::Owner->value]);
        $page = $this->getJson($this->listing(['limit' => 1, 'cursor' => $cursor]))->assertOk()->json();
        $this->assertSame([$this->staffId(3)], array_column($page['items'], 'id'));
        $this->assertNull($page['next_cursor']);
    }

    public function test_invalid_limits_and_staff_id_formats_are_validation_errors_for_an_authorized_owner(): void
    {
        $this->signIn($this->staff(Role::Owner));
        foreach ([-1, 0, 51] as $limit) {
            $this->getJson($this->listing(['limit' => $limit]))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['limit']]]);
        }
        foreach (['not-a-uuid', '1', $this->staffId(1).'x'] as $id) {
            $this->getJson(self::ENDPOINT.'/'.$id)->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['staff_id']]]);
        }
    }

    public function test_tampered_malformed_and_cross_purpose_cursors_are_rejected(): void
    {
        $owner = $this->staff(Role::Owner);
        $this->staff(id: $this->staffId(1));
        $this->staff(id: $this->staffId(2));
        $cursor = $this->signIn($owner)->getJson($this->listing(['limit' => 1]))->assertOk()->json('next_cursor');
        $point = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
        $invalid = [($cursor[0] === 'A' ? 'B' : 'A').substr($cursor, 1), substr($cursor, 0, -8), 'forged', str_repeat('a', 2049),
            Crypt::encryptString('not-json'), Crypt::encryptString('null'), Crypt::encryptString('[]')];
        foreach ([['purpose' => 'content.catalog'], ['order' => 'name:asc'], ['id' => 'not-a-uuid'], ['id' => []]] as $changes) {
            $invalid[] = Crypt::encryptString(json_encode(array_replace($point, $changes), JSON_THROW_ON_ERROR));
        }
        foreach ($invalid as $value) {
            $this->getJson($this->listing(['cursor' => $value]))->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')->assertJsonStructure(['error' => ['fields' => ['cursor']]]);
        }
    }

    public function test_internal_empty_cursor_is_not_silently_treated_as_the_first_page(): void
    {
        $this->signIn($this->staff(Role::Owner))->getJson(self::ENDPOINT)->assertOk();
        $this->expectException(ValidationException::class);
        app(AcquisitionStaffDirectory::class)->candidates('');
    }
}
