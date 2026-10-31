<?php

namespace Tests\Feature\Contracts;

use App\Contracts\CheckoutAttribution;
use App\Data\AttributionSnapshot;
use App\Data\CheckoutPageContext;
use App\Data\PaymentSettled;
use Tests\TestCase;

class CheckoutAttributionContractTest extends TestCase
{
    private function old(): array
    {
        return ['source_id' => '019a2b3c-1234-7000-8000-000000000001',
            'link_id' => '019a2b3c-1234-7000-8000-000000000002', 'channel' => 'meta',
            'pixel_config_version' => 1, 'captured_at' => '2026-11-01T00:00:00Z', 'consent' => 'granted'];
    }

    public function test_old_six_field_json_and_disabled_handoff_remain_unchanged(): void
    {
        $data = $this->old();
        $old = AttributionSnapshot::fromArray($data);
        $this->assertSame($data, $old->toArray());
        $this->assertSame(json_encode($data), json_encode($old));
        $this->assertNull($old->checkout_context_id);
        $this->assertNull(app(CheckoutAttribution::class)->capture($old, new CheckoutPageContext('/membership', '2026-11-01T00:00:01Z')));
    }

    public function test_optional_context_survives_nested_payment_contract_without_changing_other_facts(): void
    {
        $context = '019a2b3c-1234-7000-8000-000000000003';
        $new = AttributionSnapshot::fromArray($this->old() + ['checkout_context_id' => $context]);
        $this->assertSame($context, $new->toArray()['checkout_context_id']);
        $payment = json_decode(file_get_contents(base_path('../docs/contracts/examples/payment-settled.json')), true);
        $payment['attribution_snapshot'] = $new->toArray();
        $this->assertSame($context, PaymentSettled::fromArray($payment)->toArray()['attribution_snapshot']['checkout_context_id']);
        unset($payment['attribution_snapshot']['checkout_context_id']);
        $this->assertSame($this->old(), PaymentSettled::fromArray($payment)->toArray()['attribution_snapshot']);
    }

    public function test_invalid_optional_context_is_not_silently_accepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AttributionSnapshot::fromArray($this->old() + ['checkout_context_id' => 'not-a-uuid']);
    }
}
