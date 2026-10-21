<?php

namespace Tests\Feature\Contracts;

use App\Support\Admin\UsdAmount;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Tests\TestCase;

class AdminUiTest extends TestCase
{
    public function test_usd_round_trips_without_float_or_a_hundredfold_change(): void
    {
        foreach ([['12', 1200], ['12.00', 1200], ['3.5', 350], ['0.01', 1], ['00012.05', 1205], ['0', 0], ['-0.00', 0], ['-0.01', -1], ['999999.99', 99999999]] as [$input, $minor]) {
            $this->assertSame($minor, UsdAmount::fromDecimal($input));
            $this->assertSame($minor, UsdAmount::fromDecimal(UsdAmount::toDecimal($minor)));
        }
        $this->assertSame('12.00', UsdAmount::toDecimal(1200));
        $this->assertSame('$12.00 USD', UsdAmount::format(1200));
        $this->assertSame('-$0.01 USD', UsdAmount::format(-1));
        foreach ([PHP_INT_MIN, PHP_INT_MAX] as $minor) {
            $this->assertSame($minor, UsdAmount::fromDecimal(UsdAmount::toDecimal($minor)));
        }
    }

    public function test_usd_rejects_wrong_types_precision_notation_and_overflow(): void
    {
        foreach ([12, 12.0, true, null, [], '', ' ', '12 ', ' 12.00', '12.001', '1e2', 'NaN', 'INF', '12,00', '1,200.00', '.01', '1.', '+12', '--1', '１２.00', "12\n", '92233720368547758.08', '-92233720368547758.09'] as $value) {
            try {
                UsdAmount::fromDecimal($value);
                $this->fail('Invalid input must not be silently rounded or coerced.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_tabs_render_one_selected_panel_and_escape_labels(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-admin.workspace title="内容工作区">
                <x-admin.tabs id="content-tabs" :items="$items" active="episodes" />
                <x-admin.panel group="content-tabs" name="details" active="episodes">基本资料</x-admin.panel>
                <x-admin.panel group="content-tabs" name="episodes" active="episodes">分集与字幕</x-admin.panel>
            </x-admin.workspace>
            BLADE, ['items' => [['id' => 'details', 'label' => '<script>alert(1)</script>'], ['id' => 'episodes', 'label' => '分集与字幕']]]);
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//button[@role="tab" and @aria-selected="true"]')->length);
        $this->assertSame(1, $xpath->query('//section[@role="tabpanel" and not(@hidden)]')->length);
        $this->assertSame('content-tabs-panel-episodes', $xpath->query('//button[@aria-selected="true"]')->item(0)->getAttribute('aria-controls'));
        $this->assertSame(0, $xpath->query('//script')->length);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_livewire_tabs_call_the_module_method_without_using_inline_user_code(): void
    {
        $html = Blade::render('<x-admin.tabs id="billing-tabs" :items="$items" active="orders" method="selectTab" />', ['items' => [['id' => 'orders', 'label' => '订单'], ['id' => 'price', 'label' => '价格']]]);
        $this->assertStringContainsString('data-admin-mode="livewire"', $html);
        $this->assertStringContainsString('wire:click="selectTab(\'price\')"', html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString('wire:loading.attr="aria-disabled"', $html);
        $this->assertStringNotContainsString('wire:loading.attr="disabled"', $html);
        try {
            Blade::render('<x-admin.tabs id="billing-tabs" :items="$items" :method="$method" />', ['items' => [['id' => 'orders', 'label' => '订单']], 'method' => "selectTab(); alert('bad')"]);
            $this->fail('An arbitrary expression must not be accepted as a module method.');
        } catch (ViewException $error) {
            $cause = $error;
            while ($cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }
            $this->assertInstanceOf(InvalidArgumentException::class, $cause);
        }
    }

    public function test_shared_styles_are_loaded_only_in_the_staff_panel_and_do_not_bypass_login(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('/admin-ui/admin.css', false)->assertSee('/admin-ui/admin.js', false);
        $this->get('/identity/login')->assertOk()->assertDontSee('/admin-ui/')->assertSee('/audience/site.css', false);
        foreach (['content-library', 'billing-desk', 'staff-accounts'] as $page) {
            $this->get('/admin/'.$page)->assertRedirect('/admin/login');
        }
    }

    public function test_dialog_has_accessible_title_and_real_submit_button_can_target_its_form(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-admin.dialog id="edit-drama" title="编辑剧目">
                <form id="drama-form"><input aria-label="剧名"></form>
                <x-slot:actions><button type="submit" form="drama-form">保存</button></x-slot:actions>
            </x-admin.dialog>
            BLADE);
        $this->assertStringContainsString('aria-labelledby="edit-drama-title"', $html);
        $this->assertStringContainsString('data-admin-close', $html);
        $this->assertStringContainsString('form="drama-form"', $html);
        $this->assertStringNotContainsString(' open', $html);
    }
}
