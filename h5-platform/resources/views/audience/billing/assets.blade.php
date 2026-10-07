@once
@push('head')
<style>
.billing-panel{max-width:760px;margin:0 auto 24px}.billing-facts{margin:20px 0}.billing-facts>div{display:flex;justify-content:space-between;gap:24px;padding:12px 0;border-bottom:1px solid #ffffff18}.billing-facts dt{opacity:.72}.billing-facts dd{margin:0;text-align:right;overflow-wrap:anywhere}.billing-price{font-size:1.6rem;font-weight:750}.billing-notice{border-left:3px solid #edb969;padding:12px 16px;background:#edb96912}.billing-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}.billing-check{display:flex;gap:12px;align-items:flex-start;margin:24px 0}.billing-check input{width:20px;height:20px;flex:0 0 20px;margin:3px 0 0;accent-color:#e9ac83}.billing-id{font-size:.85rem}.billing-panel button:disabled,.billing-panel a[aria-disabled=true]{opacity:.5;cursor:not-allowed}.billing-panel .button{display:inline-block}.billing-panel time{font-size:.92rem}@media(max-width:480px){.billing-facts>div{display:block}.billing-facts dd{text-align:left;margin-top:5px}.billing-panel{padding:20px}.billing-actions{align-items:flex-start;flex-direction:column}}
</style>
@endpush
@push('scripts')<script src="/audience/billing.js" defer></script>@endpush
@endonce
