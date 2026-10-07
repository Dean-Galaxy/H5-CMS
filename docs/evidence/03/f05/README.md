# 03 F05 修复重交证据

2026-10-07。源码 `ac52cdc4d8c70aa99de62a04ec2f027ce2a5e498`，上轮候选 `48af857811ab86fa6a14f4e8e653a8564bdee9b6`。00的复核记录位于 main 提交 `a0ed0611f6f3ce5eb386a711e5f5ceba314f69c0`，本轮只读该记录和复验入口，不写 main 或00环境。契约1.0.2，D02/D04不变，无新增迁移或依赖。

## F05 处理

新首周coupon请求和无优惠常规价请求都完全省略 `allow_promotion_codes`；无优惠也不发送 discounts。默认不显示手填优惠码输入框，未开放另一套用户优惠。[Stripe官方启用说明](https://docs.stripe.com/billing/subscriptions/coupons#add-promotion-codes-to-checkout)、[Cashier默认行为](https://laravel.com/framework/docs/12.x/billing#promotion-codes)。

读取已保存请求时只接受键缺省或严格布尔false，拒绝true/null/字符串false/0；有优惠必须是该优惠订单的一项coupon结构，无优惠不得携带discounts。读取不会过滤或改写原请求。遇到旧版coupon与false共存，即使尚无attempted标记，也仅GET查询原Customer下的Session，再读取唯一匹配对象核实。open/complete/expired均可取得原事实，原创建请求、key及截止时间保持不变；0个或多个匹配都维持待核对。不能凭查不到、过期或修复部署就释放占位、重新POST或换key。

SDK HTTP桩在预设成功响应前主动拒绝coupon与促销码字段共存（包括序列化字符串false），另有直接通过StripeClient发送冲突参数的用例证明该拒绝分支有效。普通FakeGateway也不再接受这一非法组合。这里的400是明确的**本地桩行为**，不是Stripe官方拒绝响应。

3项新增真实PostgreSQL+BillingEngine+StripeGateway回归保存旧版完整意图：未知结果无Session时保留原订单/订阅并阻止第二建单；后来唯一Session出现时绑定原订单；未attempted的旧冲突意图也只查询。原JSON、站内key、平台key和绝对期限均断言不变，未付款不会生成付款/权益/outbox。HTTP桩拒绝所有未预计请求，另行断言它们没有被“处理中”异常处理吞掉。

代码功能修复仅StripeGateway；BillingEngine的一行变化仅补空格格式。其余为Billing归属测试及FakeGateway。新增测试首次加载发现辅助方法session与Laravel父类同名，改名后执行了以下完整通过结果。

## 结果

| 文件 | 本轮结果 |
|---|---|
| [final-tests.txt](final-tests.txt) | 169项通过，1526断言，28.64秒；含完整旧功能及新F05回归 |
| [reception-probes.txt](reception-probes.txt) | 00独立复验入口2项/29断言通过；参数互斥与Billing→Content授权组合 |
| [concurrency.json](concurrency.json) | 本轮3组真实PG双进程锁竞争均完成，付款网关为testing桩 |
| [contracts.txt](contracts.txt) | OpenAPI1.0.2结构与47个正反例通过 |
| [start.txt](start.txt)、[runtime.json](runtime.json) | 仅03自己的8203/5603运行环境；web/queue/scheduler/database/http均true |
| [http.json](http.json) | 真实HTTP、CSRF、身份隔离、邮箱预览与Billing未配置503通过 |
| [migrations.txt](migrations.txt) | 原4项Billing迁移均Ran；本轮无新增迁移 |
| [dependency-audit.json](dependency-audit.json) | 锁定依赖advisories/abandoned均为空，未升级依赖 |
| [format.txt](format.txt) | Billing模块、路由和测试Pint通过 |
| [source-manifest.json](source-manifest.json) | 本轮固定源码及文件SHA-256；文档提交不改变源码 |

复验命令：

```sh
cd /Users/hejunxiang/.codex/worktrees/h5-03-billing/H5
H5_LOCAL_MODULES= python3 scripts/dev.py test
python3 /Volumes/KESU/H5/docs/evidence/00/billing-reception/run-probes.py --workspace /Users/hejunxiang/.codex/worktrees/h5-03-billing/H5
```

两个命令串行执行，只用03自己的测试库；第一条与其他测试任务不能同时清库。00入口由主目录只读加载，运行目录及配置仍是03工作区。真实HTTP使用自己的开发库，未复制任何环境或旧数据。

本轮F05本地修复和独立复验通过，仍由00决定是否关闭问题并接收。没有公司Stripe账号、没有官方请求、没有个人账号或真实收费。官方Checkout、SCA、Test Clocks、通知及退款验收继续未配置／未验证。
