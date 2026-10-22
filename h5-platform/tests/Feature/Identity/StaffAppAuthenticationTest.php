<?php

namespace Tests\Feature\Identity;

use App\Enums\Role;
use App\Modules\Identity\Filament\Auth\StaffAppAuthentication;
use App\Modules\Identity\Models\Staff;
use DOMDocument;
use Filament\Facades\Filament;
use PragmaRX\Google2FAQRCode\Google2FA;
use PragmaRX\Google2FAQRCode\QRCode\QRCodeServiceContract;
use SensitiveParameter;
use Tests\TestCase;

class StaffAppAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // No saved staff, enrollment secret or recovery code is required to render the QR.
        $this->actingAs(new Staff(['name' => 'Synthetic QR test', 'email' => 'qr-test@example.test', 'role' => Role::Owner]), 'staff');
    }

    private function assertSingleLayerSvg(#[SensitiveParameter] string $uri): void
    {
        $prefix = 'data:image/svg+xml;base64,';
        // Boolean assertions deliberately keep the QR/secret out of failure output.
        $this->assertTrue(str_starts_with($uri, $prefix), 'Expected an SVG data URI.');
        $svg = base64_decode(substr($uri, strlen($prefix)), true);
        $this->assertTrue(is_string($svg) && ! str_starts_with($svg, 'data:'), 'The decoded payload must not be another data URI.');
        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'Expected valid XML.');
        $this->assertSame('svg', $document->documentElement->localName);
        $this->assertSame('http://www.w3.org/2000/svg', $document->documentElement->namespaceURI);
    }

    public function test_current_real_renderer_without_imagick_produces_one_layer_of_valid_svg(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('This regression targets the real no-imagick runtime.');
        }
        $provider = StaffAppAuthentication::make()->recoverable();
        $this->assertSingleLayerSvg($provider->generateQrCodeDataUri($provider->generateSecret()));
        $this->assertTrue($provider->isRecoverable());
        $this->assertSame('app', $provider->getId());
    }

    private function renderer(string $output): QRCodeServiceContract
    {
        return new class($output) implements QRCodeServiceContract
        {
            public array $metadata = [];

            public function __construct(private string $output) {}

            public function getQRCodeInline(#[SensitiveParameter] $string, $size = 200, $encoding = 'utf-8')
            {
                parse_str(parse_url($string, PHP_URL_QUERY), $query);
                // Capture only non-secret configuration, never the complete otpauth URI.
                $this->metadata = [
                    'scheme' => parse_url($string, PHP_URL_SCHEME),
                    'holder' => rawurldecode(parse_url($string, PHP_URL_PATH)),
                    'issuer' => $query['issuer'], 'algorithm' => $query['algorithm'],
                    'digits' => $query['digits'], 'period' => $query['period'],
                    'size' => $size, 'encoding' => $encoding,
                ];

                return $this->output;
            }
        };
    }

    public function test_raw_svg_is_wrapped_once_and_preserves_provider_and_qr_configuration(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><path d="M0 0h10v10H0z"/></svg>';
        $renderer = $this->renderer($svg);
        $google = new Google2FA($renderer);
        $google->setAlgorithm('sha256');
        $google->setOneTimePasswordLength(8);
        $google->setKeyRegeneration(60);
        $provider = (new StaffAppAuthentication($google))->brandName('H5 Synthetic Issuer')->recoverable()->recoveryCodeCount(6)->codeWindow(2);
        $uri = $provider->generateQrCodeDataUri($provider->generateSecret());
        $this->assertSingleLayerSvg($uri);
        $this->assertTrue(base64_decode(explode(',', $uri, 2)[1], true) === $svg);
        $this->assertSame([
            'scheme' => 'otpauth', 'holder' => '/H5 Synthetic Issuer:qr-test@example.test',
            'issuer' => 'H5 Synthetic Issuer', 'algorithm' => 'SHA256', 'digits' => '8', 'period' => '60',
            'size' => 200, 'encoding' => 'utf-8',
        ], $renderer->metadata);
        $this->assertTrue($provider->isRecoverable());
        $this->assertSame(6, $provider->getRecoveryCodeCount());
        $this->assertSame(2, $provider->getCodeWindow());
    }

    public function test_existing_svg_data_uri_is_returned_unchanged(): void
    {
        $expected = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"/>');
        $provider = new StaffAppAuthentication(new Google2FA($this->renderer($expected)));
        $actual = $provider->generateQrCodeDataUri($provider->generateSecret());
        $this->assertTrue($actual === $expected, 'A complete SVG data URI must remain unchanged.');
        $this->assertSingleLayerSvg($actual);
    }

    public function test_existing_png_data_uri_is_not_relabelled_as_svg(): void
    {
        // Fixed one-pixel image, not a QR or credential; represents an image backend's output.
        $expected = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jWZkAAAAASUVORK5CYII=';
        $provider = new StaffAppAuthentication(new Google2FA($this->renderer($expected)));
        $this->assertTrue($provider->generateQrCodeDataUri($provider->generateSecret()) === $expected, 'Existing image data URI must remain unchanged.');
    }
}
