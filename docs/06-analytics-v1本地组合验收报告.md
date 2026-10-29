# 06 analytics-v1 本地组合验收报告

日期：2026-10-29。结论：本地采集与完整购买观看链路已实际通过；大样本报表问题待05修复和06定向复验，整体埋点验收尚未收尾。真实广告平台、真机、外部部署不在本次通过范围。

## 版本与环境

| 证据 | 实际执行代码 | 范围 |
|---|---|---|
| 完整PHP | `a7570f3e02d78d49f786dce9a61eea22dfd9ba43` | Feature、Integration、Security；767项/10531断言 |
| 完整真实Chrome、HTTP、隐私/后台补验、最终恢复 | `6729f7f538e67f52634b75fe01568eb9136743a9` | 下表逐项列明；不同运行分别保留 |
| 应用业务候选 | `65a2ced135c46075346cb6fa4a2848beacb043c9` | 上述两个执行提交的app/bootstrap/config/database/public/resources/routes和composer锁均与此候选一致 |
| 后续06工具 | `a87ea029fa0e3f46948b03696e06166242294b05` | 固定full入口、计划生成、同业务合成夹具重绑、HTTP/媒体断网/恢复工具；不把旧浏览器结果改标为此提交运行 |

共同业务契约1.0.5，附加analytics-v1。原06独立工作区，8206/5606、`h5_217e1741db_test`。APP_ENV=testing仅用于本人已有的本机回环隔离环境；不是把公共站点伪装为testing。普通支付入口仍unconfigured/PayPal off；独立测试路由显式安装03 FakeGateway，真实HTTP建单后由06 CLI调用既有Settlement服务。没有手写paid、email_verified_at或会员权益。

媒体为06自制8秒H264，经02真实上传/处理/发布，6集/前5免费；测试价格300/1200美分由06自行生成。未搬其他工作区素材、账号、价格或密钥。真实PayPal06、8406/5906及周四8377/5877均未操作。

## 已执行结果

| 场景 | 结果与证据层级 |
|---|---|
| 干净迁移、默认关闭及显式消费者 | 完整PHP 767项/10531断言通过，22迁移；新增15项06真实消费者组合用例包含真实01注册/验证、03结算/Reader、04许可、05持久化/报表，非单一消费者替身 |
| 有来源完整浏览器链路 | 慢1秒独立landing先返回200再发入口events；免费集MSE实际帧数/时间增长→第6集拒绝→实际注册→渲染本区新邮件并点击真正签名链接的新标签→真实HTTP建单→受控结算/outbox→第6集MSE帧数/时间增长；46步完成 |
| 同主体/来源与账本 | 独立数据库核对已付款主体具备完整前序事件，服务端注册/验证/建单/付款的viewer/source匹配原订单冻结快照；1测试付款/1权益，gross=net=300、退款0；注册漏斗paid_play=1 |
| 断采集与刷新重试 | 真实页面只阻断tracking请求，出现≤64KiB短期缓冲；恢复后刷新完成重传并清缓冲。请求UUID摘要保留，不以请求次数冒充持久接受次数 |
| 真实媒体断网 | 独立7项通过：MSE播放后全网络offline使视频暂停、产生有界无签名URL缓冲；online并重新加载后取得许可继续真实解码，network事件进入采集；无签名媒体路径403 |
| 仅广告同意/撤回 | 真实UI 7项断言通过：unknown不增subject/source/behavior；仅广告同意增加一个加密TikTok来源而subject/behavior不增、无events请求；当前平台字段隔离；界面撤回使来源revoked并清缓冲；5张账务表逐行摘要保持 |
| 真实HTTP边界 | 21项通过：consent/events/landing各自无token419、错token419、外站Origin403且不新增主体/来源/事件/key；正常同站200；同UUID重传只持久一次、改内容拒绝；>20项422、>32KiB413、未知envelope422；撤回后不采集；实际429及Retry-After。未单独模拟服务器会话自然过期TTL |
| 后台权限 | 完整PHP包含Owner/投流/其他角色与owned OR assigned、撤销立即失权、空范围不回退全站、财务/维度/诊断一致；实际Owner密码+TOTP进入“站内分析”2项通过。未把Owner页面截图当作所有角色UI人工通过 |
| 财务、微秒和来源规则 | 完整PHP包括退出≠撤回、换号隔离、可靠事实Retry/Skipped/Accepted、重复乱序、真Reader/退款累计100→300→纠正0、同秒.100同意/.200实际本地退款、precision6新迁移及200800不变、7天边界/点击覆盖/普通访问不延长/建单冻结；Meta/TikTok payload使用HTTP fake校验，未连真实平台 |
| 04浏览器状态替身 | 18/18通过，包含真实Chrome解码自制MP4、seek不算观看、隐藏/同意/撤回、旧GET不覆盖草稿、加载/失败反馈、中英320×500滚动；这些HTTP状态替身与上面的真实本机HTTP/HLS分开记证据 |
| 依赖/隔离工具 | composer audit exit0、advisories/abandoned空；symfony/mime安装与锁均8.1.10。8 Python+7 Node辅助检查通过；计划生成器实际输出46步，与成功运行的私密计划逐项一致（仅新合成邮箱变化）、权限0600，不启动数据库 |
| 最终独立恢复 | 完整浏览器及隐私/断网补验后的数据dump，恢复到全新独立PG实例；62表逐行摘要/22迁移、1付款1权益、7媒体文件摘要一致；真正停止/重启恢复实例后再比一致；两次content:publish-due后订单/付款/权益/订阅/剧/集/发布状态保持，原库逐行摘要不变；恢复实例已关闭 |

首次完整PHP被本机默认128MB终止；第二次误把analytics全局打开，767项运行得到20 errors/3 failures；恢复正常默认modules/analytics均off后，同一应用完整767/10531通过。未修改业务或放宽断言。错误运行记录留在私密runtime。

浏览器中断曾来自06等待方法：付费提示尚未滚入可见区域、误设注册后固定落到账号页、邮件target=_blank却等原标签、选择器匹配两个区域。均保留原结果；没有证据把它们交作业务缺陷。最终成功运行前5次中断合成尝试未删除：成功回执当时累计6入口、5注册、3验证、2建单、1付款，不能称全部入口都转化。早期状态工具混合全体主体的one_subject=false不代表最终付款链失败；独立已付主体核对结果为true。后续HTTP/隐私/断网补验另有无来源测试观测，后台截图独立总量不强行与此前快照凑数。

## 仍未通过的部分

1. **AN06-R01-CAPACITY**：00发现报表全站前10000原始事件截断可能挤掉筛选链接或合法播放心跳；05正在修复。当前小样本报表/完整链路通过不证明大样本完整。等待00指定新候选，06须跑>10000无关raw干扰、目标链接/同入口漏斗、正常大样本及范围隔离定向回归。本报告不背书新补丁。
2. 真实Meta/TikTok资产、官方面板匹配/归因、真实广告外发、真机完整链路未验；未进行采购或正式发送。浏览器模拟不是手机人工结果。
3. 邮件只验证本区preview真实签名点击，SMTP真实投递/收件箱送达未验；付款仅受控FakeGateway，不重做或替代真实PayPal/Stripe官方付款验收。不能启用正式收费。
4. 未部署公网HTTPS站、未做云服务器重启/队列服务积压恢复或异地备份取回。独立PG真实重启/发布重复执行不能替代这些条件。
5. 页面设计仍待08基于本报告新截图复看；截图通过不是产品经理人工验收。没有跨地区合规结论。

## 打开、启动、使用、验证、停止

当前本区网站、队列、定时进程和PG均已关闭；不需要本人立即购买或重试付款。可直接打开本目录证据截图复看。`http://127.0.0.1:8206`仅指本Mac回环地址，不是他人手机入口。

下面从**原06工作区**执行，`SHA`必须是00指定且实际HEAD的完整40位提交；先核对报告候选，不能自动将新业务候选重绑到旧结果。

```sh
SHA=$(git rev-parse HEAD)
# 新一轮全套PHP：会刷新本区专属_test；本轮已归档数据，不为查看页面重跑此步骤。
python3 scripts/ops/analytics_acceptance.py --candidate "$SHA" --stage full
# 新空白_test准备本区合成账号、价格、6集合成剧，不从别区复制。
python3 scripts/ops/analytics_browser_fixture.py --candidate "$SHA" seed
python3 scripts/ops/analytics_browser_fixture.py --candidate "$SHA" plan
python3 scripts/ops/analytics_browser_fixture.py --candidate "$SHA" start
# H5_PLAYWRIGHT_MODULE 指向本机已安装的playwright包，不在此采购/下载服务。
node h5-platform/tests/Browser/analytics-journey.cjs "$SHA" .runtime/06/analytics-browser/journey-plan.json
python3 scripts/ops/analytics_browser_fixture.py --candidate "$SHA" state
python3 scripts/ops/analytics_browser_fixture.py --candidate "$SHA" stop
```

若只恢复已保留的同业务合成夹具，停机后可先`rebind`再`start`；它逐项比较app/bootstrap/config/database/public/resources/routes及composer，业务有差异就拒绝，旧证据不改SHA。业务候选改变时先由00安排接收、归档/迁移和受影响回归，不手工改fixture候选绕过。

已纳入工具的补验入口：`H5_ANALYTICS_ACCEPTANCE_ROOT`设置为本工作区绝对路径，运行`scripts/ops/analytics_http_acceptance.py`（站点启动后；限流会暂时影响本区后续请求）、`h5-platform/tests/Browser/analytics-media-network.cjs`（需同一Playwright配置）、`scripts/ops/analytics_recovery.py`（站点/队列/调度均停后，仅恢复到新实例）。恢复过程保留数据和私密归档；日志、账号、邮件、签名URL、dump及媒体不进Git。

回退只回退应用到00指定兼容版本，保留新付款、退款、权益和可靠outbox事实；禁止用旧dump覆盖当前库或逆向删除已采用迁移。新precision6迁移不修改paid_at/权益区间；回退前必须核对新表/字段兼容和新增支付事实。原部署资源用途/待申请账号见[部署与恢复手册](06-部署与恢复手册.md)，本轮未购买、未新增云费用或外部部署放行。

证据目录：[analytics-local-20261029](evidence/06/analytics-local-20261029/manifest.json)。恢复dump、PG角色密码、原始日志/邮件及浏览器私密计划仍只保存在本区`.runtime/06`，公开报告只留摘要与合成页面。00接收后负责固定版本、GitHub推送及远端回读；本轮不自行声称已经上传。
