<?php

namespace App\Modules\Audience\Billing;

use Carbon\CarbonImmutable;

final class BillingText
{
    public static function get(string $key): string
    {
        $words = [
            'membership' => ['Membership', '会员中心'], 'weekly' => ['Weekly membership', '全站周会员'],
            'intro' => ['Watch published member episodes while your membership is active.', '有效会员可观看所在地区已上架的会员剧集。'],
            'test' => ['Local test preview · Sample prices are for testing. Live charging is not enabled.', '本地测试预览 · 当前价格为测试样例，尚未开放正式收费。'],
            'active' => ['Membership is active', '会员权益有效'], 'inactive' => ['No active paid access', '当前没有有效付费权益'],
            'until' => ['Paid access ends', '本期权益到期'], 'renewal' => ['Next automatic renewal', '下次自动续费'],
            'no_renewal' => ['No future automatic renewal is scheduled.', '当前没有已安排的未来自动续费。'],
            'pending' => ['Payment or subscription status is being checked. Do not make a second purchase. Refresh to view the latest verified status.', '付款或订阅状态正在核对，请勿重复购买。刷新可查看最新核实结果。'],
            'renewal_pending' => ['A request is being checked. Renewal status below is the last verified result; it is not a promise that cancellation has completed.', '请求正在核对。以下续费状态为最后核实结果，不代表取消已经完成。'],
            'recheck_payment' => ['Check original payment', '核对原付款'],
            'recheck_note' => ['Check the payment you already confirmed and recover its payment page if available. This keeps the original price and payment deadline; it does not start another purchase.', '核对刚才已确认的原付款，若已恢复可支付状态则取回原支付页面。保留原价格和支付截止时间，不发起第二次购买。'],
            'recovery_missing' => ['The original payment details are not available in this browser session. The payment is still being checked. Do not start another purchase; return to the original browser or refresh later.', '当前浏览器会话没有原付款的可靠凭据，付款仍待核对。请勿重复购买，可返回原浏览器或稍后刷新状态。'],
            'recent_order' => ['View latest payment order', '查看最近付款订单'],
            'refresh' => ['Refresh status', '刷新状态'], 'purchase' => ['View price', '查看购买价格'], 'manage' => ['Manage existing membership', '管理已有订阅'],
            'first_week' => ['First week', '首周价格'], 'regular' => ['Regular weekly price', '常规周价'],
            'after_first' => ['From the second week, renews automatically every week at', '第二周起每周自动续费'],
            'eligibility' => ['Sign in to confirm your first-week offer eligibility. Each account can use the offer once.', '登录后确认首周优惠资格；每个账号仅可享受一次。'],
            'ineligible' => ['Your account has already used its first-week offer. The regular weekly price applies.', '此账号已使用首周优惠，本次按常规周价购买。'],
            'offer_deadline' => ['This price can be confirmed until', '此报价可确认至'],
            'offer_expired' => ['This quote has expired. Read the current price and confirm again.', '此报价已过期，请重新查看价格并确认。'],
            'reprice' => ['Read current price', '重新查看价格'],
            'confirm_buy' => ['I confirm the displayed price and weekly automatic renewal. I can cancel future renewal in Membership.', '我确认上述价格及每周自动续费，可在会员中心随时取消未来续费。'],
            'checkout' => ['Confirm and open payment', '确认并前往支付'],
            'signin' => ['Sign in to purchase', '登录后购买'], 'register' => ['Create account', '注册账号'],
            'verify' => ['Verify your email before the first payment. You can resend the email or correct it on the account page.', '首次付款前请验证邮箱，可在账号页面重发邮件或更正邮箱。'],
            'verify_action' => ['Verify email', '前往验证邮箱'],
            'cancel' => ['Cancel automatic renewal', '取消自动续费'], 'cancel_heading' => ['Stop future renewal', '停止未来自动续费'],
            'cancel_terms' => ['Cancellation stops future automatic renewal. Paid access remains until the verified end time. This does not automatically refund the current payment, and your applicable legal rights are preserved.', '取消后停止未来自动续费，已付权益保留至核实的本期到期时间。本操作不自动退还本期付款，依法享有的权利不受影响。'],
            'cancel_confirm' => ['Confirm cancellation of future renewal', '确认取消未来自动续费'],
            'canceled' => ['Cancellation has been checked. The current membership and renewal status are shown below.', '取消请求已核实，当前权益和续费状态如下。'],
            'resume' => ['Review resuming renewal', '查看恢复续费条件'], 'resume_heading' => ['Confirm original renewal terms', '确认原订阅续费条件'],
            'original_price' => ['Original agreed renewal price', '原约定续费价格'], 'next_at' => ['Next charge time', '下次扣款时间'],
            'resume_terms' => ['No charge is made now. The current paid end time stays unchanged. This resumes the same subscription and does not grant another first-week offer.', '恢复时不立即扣款，本期到期时间不变；恢复同一订阅，不重新享受首周优惠。'],
            'resume_confirm' => ['I confirm these original terms and want to resume renewal', '我确认上述原订阅条件并主动恢复续费'],
            'resume_submit' => ['Confirm resuming renewal', '确认恢复自动续费'],
            'resumed' => ['Renewal has been checked. The current membership and renewal status are shown below.', '恢复请求已核实，当前权益和续费状态如下。'],
            'resume_expired' => ['This confirmation has expired. Read the original terms again and confirm.', '本次确认已过期，请重新读取原订阅条件并确认。'],
            'quote_valid' => ['Confirmation expires', '确认凭证截止'], 'back' => ['Back to membership', '返回会员中心'],
            'continue' => ['Continue current episode', '继续当前剧集'],
            'payment' => ['Payment status', '付款状态'], 'order' => ['Order', '订单'], 'paid_amount' => ['Order amount', '订单金额'],
            'payment_deadline' => ['Payment deadline', '本次支付截止'], 'pay_now' => ['Open secure payment page', '打开支付页面'],
            'provider_deadline_unknown' => ['The payment provider has not supplied an expiry time. Recheck the original payment to retrieve a link if it is still available.', '支付平台未提供失效时间。请重新核对原付款；若链接仍有效，系统会重新取回。'],
            'payment_link_hidden' => ['The payment link is no longer shown while the order is being checked. Do not create another purchase.', '订单仍待核对，本站已停止展示旧支付链接。请勿另行重复购买。'],
            'recheck_order' => ['Recheck this original payment', '重新核对这笔原付款'],
            'payment_expired' => ['The payment window has ended. Refresh the verified status before starting again.', '支付窗口已结束，请先刷新核实状态，再重新开始。'],
            'return_note' => ['Returning from payment does not activate membership. This page only reads your order and membership facts.', '从支付页返回不代表已开通会员；本页只查询订单和会员事实。'],
            'payment_pending' => ['Payment is not yet confirmed. Check the status before trying again.', '付款尚未核实成功，请先查看状态，勿重复购买。'],
            'payment_wait_hint' => ['Payment confirmation may take about a minute. Refresh shortly to check your membership.', '付款确认约需1分钟，请稍后刷新查看会员状态。'],
            'partial' => ['A partial refund is recorded. The original paid period is preserved; membership and renewal processing are shown separately below.', '已记录部分退款，原已付周期保留；当前会员权益与续费处理状态分别显示如下。'],
            'full_refund' => ['A full refund is recorded. Membership below reflects the remaining verified paid periods, if any.', '已记录全额退款；以下会员状态显示其他已核实付款可能保留的有效权益。'],
            'unavailable' => ['Payment is not available. The payment service or price has not been configured. You can continue watching free episodes.', '当前无法创建付款：支付服务或价格尚未配置。你仍可继续观看免费剧集。'],
            'stale' => ['Price, eligibility or subscription terms changed or expired. Read the updated details and explicitly confirm again.', '价格、资格或订阅条件已变化或过期，请重新查看并再次主动确认。'],
            'exists' => ['An existing subscription must be managed first. A second purchase was not started.', '已有订阅，请先管理原订阅；未发起第二次购买。'],
            'not_found' => ['This order or confirmation is unavailable for this account.', '此账号无法查看该订单或确认凭证。'],
            'region' => ['Purchases are not available in the current region.', '当前地区尚未开放购买。'],
            'error' => ['The request could not be completed. Read the latest status before trying again.', '请求未能完成，请先读取最新状态后再操作。'],
            'utc' => ['UTC', 'UTC'], 'payment_succeeded' => ['Payment verified', '付款已核实'], 'payment_failed' => ['Payment failed', '付款失败'],
            'payment_refunded' => ['Fully refunded', '已全额退款'], 'payment_partially_refunded' => ['Partially refunded', '已部分退款'], 'payment_unknown' => ['Payment needs review', '付款待核对'],
            'order_pending' => ['Awaiting payment', '等待付款'], 'order_processing' => ['Processing', '处理中'], 'order_paid' => ['Paid', '已付款'],
            'order_failed' => ['Failed', '失败'], 'order_expired' => ['Expired', '已过期'], 'order_canceled' => ['Canceled', '已取消'], 'order_review_required' => ['Review required', '待核对'],
            'state_none' => ['No subscription', '无订阅'], 'state_pending' => ['Pending', '处理中'], 'state_active' => ['Active', '有效'],
            'state_canceling' => ['Future renewal stopped', '已停止未来续费'], 'state_past_due' => ['Renewal payment unresolved', '续费付款待处理'],
            'state_ended' => ['Ended', '已结束'], 'state_unknown' => ['Being checked', '待核对'],
        ];

        return ($words[$key] ?? [$key, $key])[app()->getLocale() === 'zh_CN' ? 1 : 0];
    }

    public static function money(int $minor, string $currency): string
    {
        return $currency.' '.number_format($minor / 100, 2, '.', ',');
    }

    public static function date(?string $time): string
    {
        return $time ? CarbonImmutable::parse($time)->utc()->format('Y-m-d H:i:s').' UTC' : '—';
    }
}
