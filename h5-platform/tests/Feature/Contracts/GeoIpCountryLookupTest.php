<?php

namespace Tests\Feature\Contracts;

use App\Support\DbIpCountryLookup;
use App\Support\ServerRegionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use MaxMind\Db\Reader;
use Tests\TestCase;

class GeoIpCountryLookupTest extends TestCase
{
    private string $directory;

    private string $database;

    private int $epoch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/h5-geoip-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->database = $this->directory.'/country.mmdb';
        copy(__DIR__.'/Fixtures/GeoIp/GeoIP2-Country-Test.mmdb', $this->database);
        chmod($this->database, 0444);
        $reader = new Reader($this->database);
        $this->epoch = $reader->metadata()->buildEpoch;
        $reader->close();
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch + 86400));
        config(['geoip.driver' => 'dbip_lite', 'geoip.database' => $this->database, 'geoip.max_age_days' => 45]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob($this->directory.'/*') as $path) {
            if (! is_link($path)) {
                chmod($path, is_dir($path) ? 0700 : 0600);
            }
        }
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function resolve(mixed $peer, string $environment = 'production', bool $local = false, bool $console = false): array
    {
        $request = Request::create('/?country=US', 'GET', ['country_code' => 'US'], [], [], [
            'REMOTE_ADDR' => $peer, 'HTTP_CF_IPCOUNTRY' => 'US', 'HTTP_X_COUNTRY_CODE' => 'US',
            'HTTP_X_FORWARDED_FOR' => '81.2.69.142', 'HTTP_FORWARDED' => 'for=81.2.69.142;country=US',
        ]);

        return (new ServerRegionResolver($request, $environment, $local, $console))->resolve()->toArray();
    }

    public function test_real_mmdb_uses_only_direct_public_ipv4_ipv6_country_not_registered_country_or_headers(): void
    {
        $reader = new Reader($this->database);
        $this->assertSame('GeoIP2-Country', $reader->metadata()->databaseType);
        $this->assertSame('US', $reader->get('81.2.69.142')['registered_country']['iso_code']);
        $reader->close();
        $this->assertSame(['country_code' => 'GB', 'source' => 'trusted'], $this->resolve('81.2.69.142'));
        $this->assertSame(['country_code' => 'JP', 'source' => 'trusted'], $this->resolve('2001:218::'));
        $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->resolve('8.8.8.8'));
        // Renaming a source after each query also exercises that no cached reader/file is retained.
        rename($this->database, $this->directory.'/moved.mmdb');
        $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->resolve('81.2.69.142'));
    }

    public function test_private_reserved_invalid_and_missing_peers_never_use_forwarded_headers(): void
    {
        foreach ([null, '', 'not-an-ip', '81.2.69.142:443', ['81.2.69.142'], '127.0.0.1', '::1', '10.1.2.3', '192.168.1.2',
            '100.64.0.1', '169.254.1.1', '0.0.0.0', '192.0.2.1', '203.0.113.10', '224.0.0.1', '255.255.255.255',
            'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1', '::ffff:127.0.0.1', '::'] as $peer) {
            $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->resolve($peer));
        }
    }

    public function test_metadata_date_bounds_use_build_epoch_not_file_mtime(): void
    {
        foreach ([$this->epoch - 1, $this->epoch + 45 * 86400 + 1] as $now) {
            Carbon::setTestNow(Carbon::createFromTimestampUTC($now));
            $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        }
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->epoch + 45 * 86400));
        $this->assertSame('GB', (new DbIpCountryLookup)->countryFor('81.2.69.142'));
        config(['geoip.max_age_days' => '45']);
        $this->assertSame('GB', (new DbIpCountryLookup)->countryFor('81.2.69.142'));
        foreach ([0, 46, '45.0', '046', 'x', true, null] as $days) {
            config(['geoip.max_age_days' => $days]);
            $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        }
    }

    public function test_unsafe_paths_permissions_and_missing_or_corrupt_files_fail_closed(): void
    {
        foreach (['', 'relative.mmdb', 'https://invalid.test/country.mmdb', 'php://memory', $this->directory.'/missing.mmdb', $this->directory,
            $this->directory.'/../'.basename($this->directory).'/country.mmdb', $this->database."\0"] as $path) {
            config(['geoip.database' => $path]);
            $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        }
        symlink($this->database, $this->directory.'/link.mmdb');
        config(['geoip.database' => $this->directory.'/link.mmdb']);
        $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        config(['geoip.database' => $this->database]);
        foreach ([0644, 0464, 0446, 0000] as $mode) {
            chmod($this->database, $mode);
            $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        }
        chmod($this->database, 0600);
        file_put_contents($this->database, 'corrupt fixture');
        chmod($this->database, 0444);
        $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        chmod($this->database, 0600);
        $handle = fopen($this->database, 'wb');
        ftruncate($handle, 64 * 1024 * 1024 + 1);
        fclose($handle);
        chmod($this->database, 0444);
        $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
    }

    public function test_public_directory_database_and_symlinked_parent_are_rejected(): void
    {
        $this->app->usePublicPath($this->directory);
        $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        $this->app->usePublicPath(base_path('public'));
        mkdir($this->directory.'/real', 0700);
        copy($this->database, $this->directory.'/real/file.mmdb');
        chmod($this->directory.'/real/file.mmdb', 0444);
        symlink($this->directory.'/real', $this->directory.'/alias');
        config(['geoip.database' => $this->directory.'/alias/file.mmdb']);
        $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
    }

    public function test_disabled_never_opens_any_database_or_network_and_old_local_boundaries_stay(): void
    {
        config(['geoip.driver' => 'disabled', 'geoip.database' => new class
        {
            public function __toString(): string
            {
                throw new \LogicException('Must not inspect disabled path');
            }
        }]);
        $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        $this->assertSame(['country_code' => 'US', 'source' => 'local_us_test'], $this->resolve('127.0.0.1', 'local', true));
        $this->assertSame(['country_code' => 'US', 'source' => 'local_us_test'], $this->resolve('::1', 'testing', true));
        $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->resolve('127.0.0.1', 'local', true, true));
        $this->assertSame(['country_code' => 'ZZ', 'source' => 'unresolved'], $this->resolve('81.2.69.142', 'production'));
        $this->expectException(\LogicException::class);
        $this->resolve('127.0.0.1', 'production', true);
    }

    public function test_metadata_is_country_only_structure_not_a_guessed_brand_allowlist(): void
    {
        $original = file_get_contents($this->database);
        foreach (['DBIP-Country-L' => true, 'City-Country--' => false, 'ASN--Country--' => false,
            'XxxXXXCountryX' => false, 'GeoIP2-City___' => false, "Bad\n-Country--" => false] as $type => $allowed) {
            $this->assertSame(strlen('GeoIP2-Country'), strlen($type));
            $bytes = str_replace('GeoIP2-Country', $type, $original, $count);
            $this->assertSame(1, $count);
            chmod($this->database, 0600);
            file_put_contents($this->database, $bytes);
            chmod($this->database, 0444);
            $reader = new Reader($this->database);
            $this->assertSame($type, $reader->metadata()->databaseType);
            $reader->close();
            $this->assertSame($allowed ? 'GB' : null, (new DbIpCountryLookup)->countryFor('81.2.69.142'));
        }
    }

    public function test_invalid_country_code_never_falls_back_to_registered_country(): void
    {
        $bytes = file_get_contents($this->database);
        foreach (['ZZ', 'gb', '1B'] as $invalid) {
            // Equal-length payload fault injection into a synthetic upstream fixture, not an MMDB decoder.
            $mutated = str_replace("\x42GB", "\x42".$invalid, $bytes, $count);
            $this->assertGreaterThan(0, $count);
            chmod($this->database, 0600);
            file_put_contents($this->database, $mutated);
            chmod($this->database, 0444);
            $reader = new Reader($this->database);
            $this->assertSame($invalid, $reader->get('81.2.69.142')['country']['iso_code']);
            $reader->close();
            $this->assertNull((new DbIpCountryLookup)->countryFor('81.2.69.142'));
        }
    }
}
