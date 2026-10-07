<?php

namespace App\Modules\Billing\Filament\Pages;

use App\Enums\Role;
use App\Modules\Billing\Services\BillingAdministration;
use App\Support\ApiProblem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

final class BillingDesk extends Page
{
    protected static ?string $title = '订单会员与客服';

    protected static ?string $slug = 'billing-desk';

    protected string $view = 'billing.desk';

    public array $price = ['version' => 0, 'regular_amount_minor' => 1200, 'first_week_amount_minor' => 300, 'is_test' => true];

    public string $orderId = '';

    public ?string $caseId = null;

    public string $note = '';

    public bool $identityVerified = false;

    public bool $cancelRequested = false;

    public static function canAccess(): bool
    {
        $user = auth('staff')->user();

        return (bool) ($user?->active && in_array($user->role, [Role::Owner, Role::Support], true));
    }

    public function mount(): void
    {
        $data = app(BillingAdministration::class)->dashboard();
        if ($data['price']) {
            $this->price = array_intersect_key($data['price'], $this->price);
        }
    }

    public function savePrice(): void
    {
        $this->attempt(function () {
            $price = app(BillingAdministration::class)->savePrice($this->price);
            $this->price = array_intersect_key($price, $this->price);
            Notification::make()->title('测试价格新版本已保存，原订阅约定价保留')->success()->send();
        });
    }

    public function openCase(): void
    {
        $this->attempt(function () {
            $id = app(BillingAdministration::class)->openCase(trim($this->orderId));
            $this->selectCase($id);
        });
    }

    public function selectCase(string $id): void
    {
        $this->attempt(function () use ($id) {
            $detail = app(BillingAdministration::class)->caseDetail($id);
            $this->caseId = $id;
            $this->identityVerified = (bool) $detail['case']['identity_verified'];
            $this->cancelRequested = (bool) $detail['case']['cancel_requested'];
            $this->note = '';
        });
    }

    public function saveVerification(): void
    {
        $this->attempt(function () {
            app(BillingAdministration::class)->recordVerification($this->caseId ?? '', [
                'identity_verified' => $this->identityVerified, 'cancel_requested' => $this->cancelRequested,
            ]);
            Notification::make()->title('身份及取消意愿核实记录已保存')->success()->send();
        });
    }

    public function addNote(): void
    {
        $this->attempt(function () {
            app(BillingAdministration::class)->addNote($this->caseId ?? '', $this->note);
            $this->note = '';
            Notification::make()->title('客服备注已保存')->success()->send();
        });
    }

    public function cancelForCustomer(): void
    {
        $this->identityVerified = false;
        $this->cancelRequested = false;
        $this->attempt(function () {
            $membership = app(BillingAdministration::class)->cancelForCase($this->caseId ?? '');
            if ($membership->payment_resolution_required || $membership->renewal_enabled) {
                Notification::make()->title('取消仍在处理中，等待平台核对；请刷新查看')->warning()->send();
            } else {
                Notification::make()->title('已核实停止未来续费；已付权益至原到期，本次不自动退款')->success()->send();
            }
        });
    }

    protected function getViewData(): array
    {
        $data = app(BillingAdministration::class)->dashboard();
        unset($data['price']);
        $data['stripe_configured'] = config('billing.driver') === 'stripe_test';
        $data['selected'] = $this->caseId ? app(BillingAdministration::class)->caseDetail($this->caseId) : null;

        return $data;
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (ApiProblem $problem) {
            Notification::make()->title($problem->getMessage())->danger()->send();
        }
    }
}
