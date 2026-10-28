# 06 Sandbox购买安全开关及新窗口交接

2026-10-28。**工具与隔离验证完成，06没有开启实际购买。** 工程在无本人配置的`/private/tmp/h5-06-binding-compat-20261028`、分支`codex/06-paypal-purchase-switch`完成；实际8406工作区的HEAD、env、数据库与服务未由06更改。

## 固定版本与证据

- 最终工具源码`942e6a4fd0739afab0c8bd25be79477603bcdc17`，包含03只读readiness `e50d7f2cb6852f31b46ac6ea8f9c0f5dd1217a0e`。
- 72项工具回归通过，无skip：凭据18、URL18、商户16、新开关20。共享`bound_environment`仅增加显式provider白名单参数，默认仍拒paypal；旧工具行为未放宽。
- 真实03 CLI失败封闭核对、混合动作拒绝；实际06 `--disable`在PG无监听、源credentials.json/窗口记录缺失时，将合成paypal/API=true安全关闭，其他env值完全保留。没有真实官方正向ready或付款验收。
- [源码摘要/证据](evidence/06/paypal-purchase-switch-20261028/manifest.json)，[实际CLI证据](evidence/06/paypal-purchase-switch-20261028/cli-compat.json)。03失败CLI的network_requested=true表示其先设置调用意图，不能从该标志推断完成网络请求；API-off情况下认证未通过。混用选项则network_requested=false先拒。

## 新窗口已交00接管

00明确“可启动”后，06在应用`64e8273261dd0c2f0f751a383d548edc9233ccb4`启动新bridge27535/tunnel27536、新nonce与新URL。实际正常TLS下15项范围+5项正文/五头/白名单/上游核对通过，直接和实际桥上游均为400拒绝；这不是官方签名通过。此窗口测试只访问HTTP与窗口记录，未读取env/凭据/数据库。

新私密`external-url.json`、`external-processes.json`记录完整命令/PID/starttime；已实际通过URL工具`active_window_url`及停止工具的归属校验。实际地址只在未跟踪0600文件和必要交接消息中，公开证据仅保留摘要。00已接管后续官方登记、保持窗口及结束关闭责任；06没有另开整站或8407。需要关闭使用原`paypal_stop_callback_window.py --stop`，只结束本次匹配进程。

## 开关边界

工具：`scripts/ops/paypal_purchase_switch.py`。

| 操作 | 必须满足 | 实际修改 |
|---|---|---|
| `--enable-purchase` | 精确已接收候选；业务与运维源码干净；本工作区绑定；web/queue/scheduler停止；无配置缓存；API-only=true且新购unconfigured；当前活动窗口URL与env完全相同；03只读官方ready通过 | 只改new_purchase_provider=paypal，不改API、身份、产品、Webhook或其他字段 |
| `--disable` | 精确候选、本工作区与停机/缓存守卫；当前provider为unconfigured/空/paypal，旧Stripe拒绝 | new_purchase_provider=unconfigured且API=false；无需PG、网络、活动窗口或credentials.json |

开启调用唯一命令`billing:paypal-prepare --check-readiness`，捕获输出不回显；要求exit0、configuration/network/人工绑定/App认证/Webhook/product/purchase readiness全严格true，人工来源operator_dashboard、api_merchant_identity_verified=false、无缺项/错误，返回merchant/product与本次配置相同。使用仅PATH/LANG的净子进程环境，避免外部APP_/H5_变量把检查指向另一套配置。资源、事件和收款事实判断由03完成，不由06重写；不调用create-product或其他资源变更命令。

共享`.apply.lock`保护读写，锁前后对比env和当前窗口记录摘要；慢网络检查后再次核对候选/身份/窗口，失败不写入。0600备份、原子替换后再次检查。如果替换后发现并发变化，明确报告“已写但未核验”，保持服务停止并由00用`--disable`恢复；不盲目覆盖旧env或任何新支付事实。

## 00串行操作步骤

所有命令仅由00在已接收的新06工作区运行，候选占位由00替换为最终组合40位SHA。06本轮没有在实际站执行以下购买开关命令。

1. 按既有保留数据流程接收工具和03候选。停止独立应用；注意`dev.py stop`连PG一起停，开启官方ready时需只恢复该工作区PG，web/queue/scheduler保持停止，缓存清理。
2. 已完成官方商户绑定、产品、同App新窗口URL/Webhook登记，按既有受控方式保持API-only=true、新购unconfigured。活动窗口必须仍属于本工作区且URL一致。
3. 执行开启命令；工具现场重新官方认证和只读核对，不接受过期的手写ready文件。

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-06-paypal-sandbox/H5
python3 scripts/ops/paypal_purchase_switch.py --candidate <最终组合完整SHA> --enable-purchase
```

成功后由00恢复应用、核对状态，07再指导本人完成Sandbox买家流程。仅开关成功不代表已经付款、会员生效、取消或退款通过。

4. 暂缓、核对失败或测试结束需要停止购买时，先停本工作区应用、清缓存，执行：

```sh
python3 scripts/ops/paypal_purchase_switch.py --candidate <最终组合完整SHA> --disable
```

这一步不用启动PG，也不访问网络；实际无PG演练已通过。它保留Client ID/Secret、商户、产品、Webhook和数据。不要用旧`paypal_apply_credentials.py --off`处理provider=paypal：旧工具仍按原守卫拒绝，应使用本专用`--disable`。关闭新购/API后的应用状态及已发生官方支付事实由00/03核对，不恢复旧数据库或用旧env备份抹掉新事实。

## 重现

在具备同锁依赖的隔离测试checkout：

```sh
PYTHONPATH=scripts/ops/tests python3 -m unittest test_paypal_apply_credentials test_paypal_complete_webhook_url test_paypal_merchant_receipt test_paypal_purchase_switch -v
```

真实CLI复演脚本存证据目录`synthetic-cli-replay.py`，必须复制到临时合成checkout的`.runtime`执行；其硬限制临时路径、942e6a4及合成Client/Secret，不能指向实际用户工作区。它的合成“已选paypal”准备不构成实际开启授权。
