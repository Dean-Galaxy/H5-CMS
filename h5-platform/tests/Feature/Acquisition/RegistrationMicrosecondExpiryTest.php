<?php

namespace Tests\Feature\Acquisition;

use App\Enums\Role;
use App\Modules\Acquisition\Analytics\Clock;
use App\Modules\Acquisition\Registration\PurposeConflict;
use App\Modules\Acquisition\Registration\PurposePolicy;
use App\Modules\Acquisition\Registration\PurposeProof;
use App\Modules\Acquisition\Registration\PurposeStore;
use App\Modules\Identity\Models\Viewer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class RegistrationMicrosecondExpiryTest extends AcquisitionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $session = app('session.store');
        $session->start();
        $request = Request::create('/r1-microsecond-service-test');
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);
    }

    public static function fractions(): array
    {
        return [['123456'], ['999999']];
    }

    public static function operationBoundaries(): array
    {
        $cases = [];
        foreach (['123456', '999999'] as $fraction) {
            foreach ([-1, 0, 1] as $offset) {
                $cases[$fraction.' offset '.$offset] = [$fraction, $offset];
            }
        }

        return $cases;
    }

    private function origin(string $fraction): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-01 01:02:03.'.$fraction.' UTC');
    }

    private function change(string $choice, int $revision, string $operation): array
    {
        return app(PurposeStore::class)->change('account_link', $choice, PurposePolicy::NOTICE, $revision, $operation);
    }

    private function conflict(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expired or conflicting operation must not confirm an old allow');
        } catch (PurposeConflict $e) {
            $this->assertSame($code, $e->problemCode);
            $this->assertSame('deny', $e->state['choice']);
            $this->assertSame(2, $e->state['revision']);
            $this->assertFalse($e->state['effective']);
        }
    }

    #[DataProvider('fractions')]
    public function test_proof_is_unavailable_at_its_exact_non_integer_second_expiry(string $fraction): void
    {
        $this->travelTo($this->origin($fraction));
        $this->change('deny', 0, (string) Str::uuid());
        $proof = DB::table('acquisition_r1_proofs')->first();
        $expiry = CarbonImmutable::parse($proof->expires_at);
        $this->assertSame($fraction, $expiry->format('u'));
        $this->assertTrue($expiry->equalTo($this->origin($fraction)->addMonthsNoOverflow(24)));
        $this->travelTo($expiry->subMicrosecond());
        $this->assertSame($proof->id, app(PurposeProof::class)->current()?->id);
        $this->assertSame('deny', app(PurposeStore::class)->state('account_link')['choice']);
        foreach ([$expiry, $expiry->addMicrosecond()] as $time) {
            $this->travelTo($time);
            $this->assertNull(app(PurposeProof::class)->current());
            $this->assertSame('unknown', app(PurposeStore::class)->state('account_link')['choice']);
        }
        $this->assertEquals($proof, DB::table('acquisition_r1_proofs')->first());
        $this->assertDatabaseCount('acquisition_r1_choices', 1);
    }

    #[DataProvider('operationBoundaries')]
    public function test_operation_expiry_is_exact_and_replay_never_revives_the_current_denial(string $fraction, int $offset): void
    {
        $origin = $this->origin($fraction);
        $this->travelTo($origin);
        config(['h5.measurement_c_registration' => true, 'h5.measurement_c_experience' => true,
            'acquisition.registration.synthetic_policy' => true,
            'acquisition.registration.synthetic_from' => $origin->toIso8601ZuluString('microsecond'),
            'acquisition.registration.synthetic_until' => $origin->addHours(23)->toIso8601ZuluString('microsecond')]);
        $viewer = Viewer::create(['name' => 'Synthetic microsecond expiry', 'email' => Str::uuid().'@example.test', 'password' => 'SyntheticPass1234']);
        $this->actingAs($viewer, 'web');
        request()->session()->put('identity.version.web', $viewer->session_version);
        $id = (string) Str::uuid();
        $this->assertSame('allow', $this->change('allow', 0, $id)['state']['choice']);
        $old = DB::table('acquisition_r1_operations')->where('id', $id)->first();
        $expiry = CarbonImmutable::parse($old->expires_at);
        $this->assertSame($fraction, $expiry->format('u'));
        $this->assertTrue($expiry->equalTo($origin->addDay()));
        $this->travelTo($origin->addMinute());
        $this->change('deny', 1, (string) Str::uuid());
        $proof = DB::table('acquisition_r1_proofs')->first();
        $denial = DB::table('acquisition_r1_choices')->first();
        // Keep policy independently eligible at receipt expiry so this isolates the SQL boundary.
        config(['acquisition.registration.synthetic_from' => $origin->addHours(23)->toIso8601ZuluString('microsecond'),
            'acquisition.registration.synthetic_until' => $origin->addHours(46)->toIso8601ZuluString('microsecond')]);
        $this->travelTo($expiry->addMicroseconds($offset));
        if ($offset < 0) {
            $replay = $this->change('allow', 0, $id);
            $this->assertSame('saved', $replay['status']);
            $this->assertSame('deny', $replay['state']['choice']);
            $this->assertSame(2, $replay['state']['revision']);
            $this->conflict('OPERATION_CONFLICT', fn () => $this->change('deny', 2, $id));
            $this->assertEquals($old, DB::table('acquisition_r1_operations')->where('id', $id)->first());
        } else {
            // The expired receipt cannot confirm the old allow, even within its final whole second.
            $this->conflict('REVISION_CONFLICT', fn () => $this->change('allow', 0, $id));
            // A successful current-state command removes the expired receipt and is a new operation.
            $result = $this->change('deny', 2, $id);
            $this->assertSame('unchanged', $result['status']);
            $replacement = DB::table('acquisition_r1_operations')->where('id', $id)->first();
            $this->assertNotSame($old->fingerprint, $replacement->fingerprint);
            $this->assertTrue(CarbonImmutable::parse($replacement->expires_at)->equalTo(now()->addDay()));
            $this->assertSame($result, $this->change('deny', 2, $id));
            $this->conflict('OPERATION_CONFLICT', fn () => $this->change('allow', 0, $id));
        }
        $this->assertEquals($proof, DB::table('acquisition_r1_proofs')->first());
        $this->assertEquals($denial, DB::table('acquisition_r1_choices')->first());
        $this->assertDatabaseCount('acquisition_r1_operations', 2);
        $this->assertDatabaseCount('acquisition_c_events', 0);
    }

    private function nonR1Snapshot(): array
    {
        $hashes = [];
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
        foreach ($tables as $table) {
            $name = $table->tablename;
            if (! str_starts_with($name, 'acquisition_r1_')) {
                $row = DB::selectOne('SELECT coalesce(jsonb_agg(to_jsonb(x) ORDER BY to_jsonb(x)::text), \'[]\'::jsonb)::text AS data FROM "'.$name.'" x');
                $hashes[$name] = hash('sha256', $row->data);
            }
        }

        return $hashes;
    }

    #[DataProvider('fractions')]
    public function test_bounded_prune_respects_microsecond_boundaries_and_preserves_other_facts(string $fraction): void
    {
        $expiry = $this->origin($fraction);
        $this->travelTo($expiry->subMinute());
        $this->signIn($this->staff(Role::Owner));
        $this->queueEvent(); // Real Acquisition receipt/source/delivery of an explicitly synthetic payment fact.
        DB::table('acquisition_privacy')->insert(['id' => (string) Str::uuid(), 'analytics' => 'denied', 'advertising' => 'denied',
            'policy_version' => 'SYNTHETIC_MICROSECOND_GUARD', 'created_at' => Clock::stamp(), 'updated_at' => Clock::stamp(),
            'r1_advertising_denied_at' => Clock::stamp(), 'r1_advertising_denial_until' => Clock::stamp($expiry->addDay())]);
        $proofIds = [];
        foreach ([$expiry, $expiry, $expiry, $expiry->addMicrosecond()] as $until) {
            $id = (string) Str::uuid();
            $proofIds[] = $id;
            DB::table('acquisition_r1_proofs')->insert(['id' => $id, 'is_test' => true, 'created_at' => Clock::stamp(), 'expires_at' => Clock::stamp($until)]);
            DB::table('acquisition_r1_choices')->insert(['proof_id' => $id, 'purpose' => 'account_link', 'choice' => 'deny',
                'revision' => 1, 'notice_version' => PurposePolicy::NOTICE, 'policy_version' => PurposePolicy::VERSION,
                'is_test' => true, 'changed_at' => Clock::stamp(), 'expires_at' => Clock::stamp($until)]);
        }
        foreach ([$expiry, $expiry, $expiry, $expiry->addMicrosecond()] as $until) {
            DB::table('acquisition_r1_operations')->insert(['id' => (string) Str::uuid(), 'proof_id' => $proofIds[3], 'purpose' => 'account_link',
                'fingerprint' => hash('sha256', 'synthetic'), 'status' => 'saved', 'created_at' => Clock::stamp(), 'expires_at' => Clock::stamp($until)]);
        }
        $protected = $this->nonR1Snapshot();
        $this->assertDatabaseCount('acquisition_receipts', 1);
        $this->assertDatabaseCount('acquisition_sources', 1);
        $this->travelTo($expiry->subMicrosecond());
        $this->artisan('acquisition:r1-prune', ['--limit' => 1])->assertExitCode(0);
        foreach (['proofs', 'choices', 'operations'] as $table) {
            $this->assertDatabaseCount('acquisition_r1_'.$table, 4);
        }
        $this->travelTo($expiry);
        foreach ([3, 2, 1] as $remaining) {
            $this->artisan('acquisition:r1-prune', ['--limit' => 1])->assertExitCode(0);
            foreach (['proofs', 'choices', 'operations'] as $table) {
                $this->assertDatabaseCount('acquisition_r1_'.$table, $remaining);
            }
            $this->assertDatabaseHas('acquisition_r1_proofs', ['id' => $proofIds[3]]);
            $this->assertSame($protected, $this->nonR1Snapshot());
        }
        $this->travelTo($expiry->addMicrosecond());
        $this->artisan('acquisition:r1-prune', ['--limit' => 1])->assertExitCode(0);
        foreach (['proofs', 'choices', 'operations'] as $table) {
            $this->assertDatabaseCount('acquisition_r1_'.$table, 0);
        }
        $this->assertSame($protected, $this->nonR1Snapshot());
        Http::assertNothingSent();
    }
}
