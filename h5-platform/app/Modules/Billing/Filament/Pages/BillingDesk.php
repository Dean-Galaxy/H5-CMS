<?php

namespace App\Modules\Billing\Filament\Pages;

use App\Enums\Role;
use App\Modules\Billing\Services\BillingAdministration;
use App\Modules\Billing\Support\BillingRuntimePolicy;
use App\Support\Admin\UsdAmount;
use App\Support\ApiProblem;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

final class BillingDesk extends Page
{
    protected static ?string $title = '订单与会员';

    protected static ?string $slug = 'billing-desk';

    protected string $view = 'billing.desk';

    #[Url(as: 'tab', except: 'orders')]
    public string $tab = 'orders';

    #[Url(as: 'case', except: null)]
    public ?string $caseId = null;

    public array $price = ['version' => 0, 'regular_amount_usd' => '', 'first_week_amount_usd' => '', 'is_test' => true];

    #[Locked]
    public array $savedPrice = [];

    #[Locked]
    public array $savedVerification = ['identity_verified' => false, 'cancel_requested' => false];

    public string $orderId = '';

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
        $this->fillPrice($data['price']);
        if (! $this->tabAllowed($this->tab, $data['is_owner'])) {
            $this->tab = 'orders';
        }
        if ($this->caseId !== null && ! $this->attempt(fn () => $this->loadCase($this->caseId))) {
            $this->caseId = null;
        }
    }

    public function selectTab(string $tab): bool
    {
        return $this->attempt(function () use ($tab) {
            $data = app(BillingAdministration::class)->dashboard();
            if (! $this->tabAllowed($tab, $data['is_owner'])) {
                throw new ApiProblem('NOT_AUTHORIZED', 403, '无权查看此账务页面');
            }
            $this->tab = $tab;
        });
    }

    public function savePrice(): bool
    {
        return $this->attempt(function () {
            $this->fillPrice(app(BillingAdministration::class)->savePriceUsd($this->price));
            Notification::make()->title('测试价格新版本已保存，原订阅约定价保留')->success()->send();
        });
    }

    public function openCase(?string $orderId = null): bool
    {
        return $this->attempt(function () use ($orderId) {
            $id = app(BillingAdministration::class)->openCase(trim($orderId ?? $this->orderId));
            $this->loadCase($id);
            $this->tab = 'orders';
        });
    }

    public function selectCase(string $id): bool
    {
        return $this->attempt(function () use ($id) {
            $this->loadCase($id);
            $this->tab = 'support';
        });
    }

    public function saveVerification(): bool
    {
        return $this->attempt(function () {
            app(BillingAdministration::class)->recordVerification($this->caseId ?? '', [
                'identity_verified' => $this->identityVerified, 'cancel_requested' => $this->cancelRequested,
            ]);
            $this->savedVerification = ['identity_verified' => $this->identityVerified, 'cancel_requested' => $this->cancelRequested];
            Notification::make()->title('身份及取消意愿核实记录已保存')->success()->send();
        });
    }

    public function addNote(): bool
    {
        return $this->attempt(function () {
            app(BillingAdministration::class)->addNote($this->caseId ?? '', $this->note);
            $this->note = '';
            Notification::make()->title('客服备注已保存')->success()->send();
        });
    }

    public function cancelForCustomer(): bool
    {
        return $this->attempt(function () {
            $administration = app(BillingAdministration::class);
            try {
                $membership = $administration->cancelForCase($this->caseId ?? '');
            } catch (ApiProblem $problem) {
                if ($problem->errorCode === 'BILLING_PENDING') {
                    // Acceptance consumes verification even when the provider result is unknown.
                    // Refresh only that persisted state; keep other unsaved inputs intact.
                    $detail = $administration->caseDetail($this->caseId ?? '');
                    $this->fillVerification($detail['case']);
                }

                throw $problem;
            }
            $detail = $administration->caseDetail($this->caseId ?? '');
            $this->fillVerification($detail['case']);
            if ($membership->payment_resolution_required || $membership->renewal_enabled) {
                Notification::make()->title('取消仍在处理中，等待平台核对；请刷新查看')->warning()->send();
            } else {
                Notification::make()->title('已核实停止未来续费；已付权益至原到期，本次不自动退款')->success()->send();
            }
        });
    }

    /** Explicit booleans are consumed by the shared unsaved-changes dialog. */
    public function savePending(): bool
    {
        if (! $this->attempt(fn () => app(BillingAdministration::class)->dashboard())) {
            return false;
        }
        if ($this->price !== $this->savedPrice && ! $this->savePrice()) {
            return false;
        }
        $verification = ['identity_verified' => $this->identityVerified, 'cancel_requested' => $this->cancelRequested];
        if ($verification !== $this->savedVerification && ! $this->saveVerification()) {
            return false;
        }
        if ($this->note !== '' && ! $this->addNote()) {
            return false;
        }

        return true;
    }

    public function discardPending(): bool
    {
        return $this->attempt(function () {
            $data = app(BillingAdministration::class)->dashboard();
            $detail = $this->caseId ? app(BillingAdministration::class)->caseDetail($this->caseId) : null;
            $this->fillPrice($data['price']);
            $this->fillVerification($detail['case'] ?? []);
            $this->note = '';
        });
    }

    protected function getViewData(): array
    {
        $data = app(BillingAdministration::class)->dashboard();
        $activeTab = $this->tabAllowed($this->tab, $data['is_owner']) ? $this->tab : 'orders';
        $data['activeTab'] = $activeTab;
        $data['tabs'] = [
            ['id' => 'orders', 'label' => '订单查询'],
            ...($data['is_owner'] ? [['id' => 'prices', 'label' => '会员价格']] : []),
            ['id' => 'support', 'label' => '客服记录'],
        ];
        unset($data['price']);
        $data['stripe_configured'] = false;
        try {
            app(BillingRuntimePolicy::class)->assertAvailable();
            $data['stripe_configured'] = true;
        } catch (\Throwable) {
            // Configuration readiness is separate from official payment verification.
        }
        $data['selected'] = null;
        if ($this->caseId) {
            try {
                $data['selected'] = app(BillingAdministration::class)->caseDetail($this->caseId);
            } catch (ApiProblem|ValidationException) {
                $this->addError('caseId', '未找到可访问的客服记录，请重新核对订单号。');
            }
        }

        return $data;
    }

    private function tabAllowed(string $tab, bool $owner): bool
    {
        return in_array($tab, $owner ? ['orders', 'prices', 'support'] : ['orders', 'support'], true);
    }

    private function loadCase(string $id): void
    {
        $detail = app(BillingAdministration::class)->caseDetail($id);
        $this->caseId = $id;
        $this->orderId = $detail['order']['id'];
        $this->fillVerification($detail['case']);
        $this->note = '';
    }

    private function fillPrice(?array $price): void
    {
        $this->price = $price ? [
            'version' => $price['version'],
            'regular_amount_usd' => UsdAmount::toDecimal($price['regular_amount_minor']),
            'first_week_amount_usd' => UsdAmount::toDecimal($price['first_week_amount_minor']),
            'is_test' => (bool) $price['is_test'],
        ] : ['version' => 0, 'regular_amount_usd' => '', 'first_week_amount_usd' => '', 'is_test' => true];
        $this->savedPrice = $this->price;
    }

    private function fillVerification(array $case): void
    {
        $this->identityVerified = (bool) ($case['identity_verified'] ?? false);
        $this->cancelRequested = (bool) ($case['cancel_requested'] ?? false);
        $this->savedVerification = ['identity_verified' => $this->identityVerified, 'cancel_requested' => $this->cancelRequested];
    }

    private function attempt(callable $action): bool
    {
        $this->resetValidation();
        try {
            $action();

            return true;
        } catch (ValidationException $error) {
            foreach ($error->errors() as $key => $messages) {
                $field = in_array($key, ['version', 'regular_amount_usd', 'first_week_amount_usd', 'is_test'], true) ? 'price.'.$key : $key;
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }
        } catch (ApiProblem $problem) {
            $this->addError('billing', $problem->getMessage());
            Notification::make()->title($problem->getMessage())->danger()->send();
        }

        return false;
    }
}
