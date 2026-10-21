<?php

namespace App\Modules\Billing\Services;

use App\Contracts\AuditRecorder;
use App\Contracts\BillingService;
use App\Contracts\IdentityContext;
use App\Contracts\MembershipReader;
use App\Data\Membership;
use App\Data\ResourceScope;
use App\Data\StaffIdentity;
use App\Enums\Permission;
use App\Enums\Role;
use App\Support\Admin\UsdAmount;
use App\Support\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class BillingAdministration
{
    public function dashboard(): array
    {
        $staff = $this->deskStaff();
        $cases = DB::table('billing_cases')->orderByDesc('created_at');
        if ($staff->role !== Role::Owner) {
            $cases->where('staff_id', $staff->id);
        }

        return [
            'is_owner' => $staff->role === Role::Owner,
            'price' => $staff->role === Role::Owner ? $this->currentPrice() : null,
            'cases' => $cases->limit(50)->get(['id', 'order_id', 'identity_verified', 'cancel_requested', 'verified_at', 'created_at'])->all(),
            'orders' => $staff->role === Role::Owner
                ? DB::table('billing_orders')->orderByDesc('created_at')->limit(50)->get($this->orderColumns())->all() : [],
        ];
    }

    public function currentPrice(): ?array
    {
        app(IdentityContext::class)->requireStaff(Permission::PriceManage);
        $price = DB::table('billing_prices')->orderByDesc('version')->first();

        return $price ? (array) $price : null;
    }

    /** Staff presentation boundary only; all stored prices remain integer USD minor units. */
    public function savePriceUsd(array $input): array
    {
        app(IdentityContext::class)->requireStaff(Permission::PriceManage);
        $data = ['version' => $input['version'] ?? null, 'is_test' => $input['is_test'] ?? null];
        $fields = ['regular_amount_usd' => 'regular_amount_minor', 'first_week_amount_usd' => 'first_week_amount_minor'];
        $errors = [];
        foreach ($fields as $usd => $minor) {
            try {
                $data[$minor] = UsdAmount::fromDecimal($input[$usd] ?? null);
            } catch (InvalidArgumentException $exception) {
                $errors[$usd] = $exception->getMessage();
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            // Explicit construction ignores submitted legacy minor-unit fields,
            // currency and identifiers. Existing authorization/version locking remain authoritative.
            return $this->savePrice($data);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $usd = array_search($field, $fields, true);
                $errors[$usd === false ? $field : $usd] = match ($field) {
                    'regular_amount_minor' => '常规周价须为 $0.01 至 $999999.99。',
                    'first_week_amount_minor' => '首周优惠价须至少为 $0.01，且不能高于常规周价。',
                    'version' => '价格版本无效，请刷新后重新确认。',
                    'is_test' => '请明确勾选保存为测试价格。',
                    default => $messages,
                };
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /** Append a version; never overwrite the price agreed by an existing subscriber. */
    public function savePrice(array $input): array
    {
        app(IdentityContext::class)->requireStaff(Permission::PriceManage);
        $data = Validator::make($input, [
            'version' => ['required', 'integer', 'min:0'],
            'regular_amount_minor' => ['required', 'integer', 'min:1', 'max:99999999'],
            'first_week_amount_minor' => ['required', 'integer', 'min:1', 'lte:regular_amount_minor'],
            'is_test' => ['required', 'accepted'],
        ])->validate();

        return DB::transaction(function () use ($data) {
            // Serializes even the empty-price-table case; independent of viewer locks.
            DB::select('SELECT pg_advisory_xact_lock(30420301)');
            $version = (int) (DB::table('billing_prices')->max('version') ?? 0);
            if ($version !== (int) $data['version']) {
                throw new ApiProblem('STALE_VERSION', 409, '价格已更新，请刷新并重新确认');
            }
            $price = [
                'id' => BillingStore::id(), 'version' => $version + 1,
                'regular_amount_minor' => (int) $data['regular_amount_minor'],
                'first_week_amount_minor' => (int) $data['first_week_amount_minor'],
                'currency' => 'USD', 'is_test' => true, 'created_at' => now(),
            ];
            DB::table('billing_prices')->insert($price);
            app(AuditRecorder::class)->record('billing.price.created', 'billing_price', $price['id'], 'success', ['version' => $price['version']]);

            return $price;
        }, 3);
    }

    /** The exact persisted order establishes the account; no submitted viewer or owner is trusted. */
    public function openCase(string $orderId): string
    {
        $staff = $this->deskStaff();
        Validator::make(['order_id' => $orderId], ['order_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($staff, $orderId) {
            $order = DB::table('billing_orders')->where('id', $orderId)->first()
                ?? throw new ApiProblem('NOT_FOUND', 404, '未找到此站内订单，请核对用户提供的订单号');
            app(IdentityContext::class)->requireStaff(Permission::SupportLookup, new ResourceScope(ownerId: $order->viewer_id, caseLookup: true));
            BillingStore::lock($order->viewer_id);
            $existing = DB::table('billing_cases')->where('order_id', $order->id)->where('staff_id', $staff->id)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = BillingStore::id();
            DB::table('billing_cases')->insert([
                'id' => $id, 'viewer_id' => $order->viewer_id, 'order_id' => $order->id,
                'staff_id' => $staff->id, 'identity_verified' => false, 'cancel_requested' => false, 'created_at' => now(),
            ]);
            app(AuditRecorder::class)->record('billing.case.opened', 'billing_case', $id, 'success');

            return $id;
        }, 3);
    }

    public function caseDetail(string $caseId): array
    {
        [$case, $order] = $this->scopedCase($caseId, Permission::SupportLookup);
        $membership = app(MembershipReader::class)->membership($case->viewer_id, CarbonImmutable::now('UTC'));
        app(AuditRecorder::class)->record('billing.case.viewed', 'billing_case', $case->id, 'success');

        return [
            'case' => (array) $case,
            'order' => array_intersect_key((array) $order, array_flip($this->orderColumns())),
            'membership' => $membership->toArray(),
            'payments' => DB::table('billing_payments')->where('subscription_id', $order->subscription_id)
                ->where('viewer_id', $case->viewer_id)->orderByDesc('paid_at')->limit(30)
                ->get(['id', 'order_id', 'amount_minor', 'refunded_amount_minor', 'currency', 'is_test', 'period_start', 'period_end', 'paid_at'])->all(),
            'notes' => DB::table('billing_notes')->where('case_id', $case->id)->orderBy('created_at')->limit(100)
                ->get(['id', 'staff_id', 'body', 'created_at'])->all(),
        ];
    }

    /** These are a staff attestation about a real request, not browser-provided ownership. */
    public function recordVerification(string $caseId, array $input): void
    {
        $this->scopedCase($caseId, Permission::SupportNote);
        $data = Validator::make($input, [
            'identity_verified' => ['required', 'boolean'],
            'cancel_requested' => ['required', 'boolean'],
        ])->validate();
        DB::transaction(function () use ($caseId, $data) {
            [$case] = $this->scopedCase($caseId, Permission::SupportNote, true);
            $verified = (bool) $data['identity_verified'] && (bool) $data['cancel_requested'];
            DB::table('billing_cases')->where('id', $case->id)->update([
                'identity_verified' => (bool) $data['identity_verified'],
                'cancel_requested' => (bool) $data['cancel_requested'],
                'verified_at' => $verified ? now() : null,
            ]);
            app(AuditRecorder::class)->record($verified ? 'billing.case.verified' : 'billing.case.verification_cleared', 'billing_case', $case->id, 'success');
        }, 3);
    }

    public function addNote(string $caseId, string $body): void
    {
        $staff = $this->deskStaff();
        $this->scopedCase($caseId, Permission::SupportNote);
        $data = Validator::make(['body' => trim($body)], ['body' => ['required', 'string', 'max:2000']])->validate();
        DB::transaction(function () use ($caseId, $staff, $data) {
            [$case] = $this->scopedCase($caseId, Permission::SupportNote, true);
            DB::table('billing_notes')->insert(['id' => BillingStore::id(), 'case_id' => $case->id, 'staff_id' => $staff->id, 'body' => $data['body'], 'created_at' => now()]);
            // Notes may contain customer context. Audit contains only the case reference.
            app(AuditRecorder::class)->record('billing.case.note_added', 'billing_case', $case->id, 'success');
        }, 3);
    }

    public function cancelForCase(string $caseId): Membership
    {
        [$case] = $this->scopedCase($caseId, Permission::SubscriptionCancel);
        if (! $case->identity_verified || ! $case->cancel_requested || ! $case->verified_at) {
            throw new ApiProblem('CANCELLATION_NOT_VERIFIED', 403, '请先核实用户身份及取消意愿');
        }
        $staff = app(IdentityContext::class)->requireStaff(Permission::SubscriptionCancel, $this->caseScope($case));

        // The BillingService rechecks the persisted case and provider result; this UI never claims success on a request alone.
        return app(BillingService::class)->cancelForSupport($staff, $case->viewer_id, $case->id);
    }

    private function deskStaff(): StaffIdentity
    {
        // Case-lookup capability is chosen by this service, never copied from browser input.
        // Object reads and mutations additionally require scopedCase or an exact persisted order.
        return app(IdentityContext::class)->requireStaff(Permission::SupportLookup, new ResourceScope(caseLookup: true));
    }

    private function scopedCase(string $caseId, Permission $permission, bool $lock = false): array
    {
        $staff = $this->deskStaff();
        Validator::make(['case_id' => $caseId], ['case_id' => ['required', 'uuid']])->validate();
        $query = DB::table('billing_cases')->where('id', $caseId);
        if ($staff->role !== Role::Owner) {
            $query->where('staff_id', $staff->id);
        }
        $case = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $case) {
            app(AuditRecorder::class)->record('billing.case.access_denied', 'billing_case', $caseId, 'denied');
            throw new ApiProblem('NOT_FOUND', 404, '未找到可访问的客服记录');
        }
        $order = DB::table('billing_orders')->where('id', $case->order_id)->where('viewer_id', $case->viewer_id)->first()
            ?? throw new ApiProblem('NOT_FOUND', 404, '客服记录与订单不匹配');
        app(IdentityContext::class)->requireStaff($permission, $this->caseScope($case));

        return [$case, $order];
    }

    private function caseScope(object $case): ResourceScope
    {
        return new ResourceScope(ownerId: $case->viewer_id, assignedStaffIds: [$case->staff_id], caseLookup: true,
            verifiedCancellationRequest: (bool) $case->identity_verified && (bool) $case->cancel_requested && $case->verified_at !== null);
    }

    private function orderColumns(): array
    {
        return ['id', 'viewer_id', 'subscription_id', 'transaction_kind', 'amount_minor', 'regular_amount_minor', 'currency', 'discount_applied', 'is_test', 'price_version', 'state', 'payment_state', 'created_at'];
    }
}
