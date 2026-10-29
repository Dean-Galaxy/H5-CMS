# 05 带点 TikTok 点击标识最小兼容补修

2026-10-29，按00后续修复指令。实现636c1fa1ab16ddb31805819723ac1cabfbd50fdc；正常接入00共同起点65a2ced135c46075346cb6fa4a2848beacb043c9（04 5d6bc051点号解析、102d62a隐私草稿/反馈；06组合设置纠偏）。浏览器验证脚本提交和源码hash见manifest.json。

`ttclid`从仅字母数字下划线连字符改为允许点号，不改大小写/字节，不放宽Meta字段。512bytes、严格整体锚点、按平台最小保存及广告同意门禁保持。额外负例证实Laravel输入修剪会把尾换行删除后意外接受，已仅在05匹配输入处校验原始query/JSON；无原query/标识日志或明文持久化。无新迁移、依赖、权限或发送配置。

| 验证 | 实际结果 | 证据 |
|---|---|---|
| 修补前 | 带点透传/落地失败；仅放宽正则时尾换行被误裁剪成合法值 | before.txt、trim-boundary-before.txt（均为明确合成数据） |
| 全05+附加契约 | 79项1066断言通过 | module-tests.txt |
| 00/06更新的真实模块组合 | 14项361断言通过，前交付3条待复核关闭 | combined-tests.txt |
| 已接04客户端实际Chrome | 20项通过、0外联、0JS异常 | browser-result.json |

ClickMatchingTest两平台端到端测试把TikTok形状改为`E.C.P.SYNTHETIC_06-CLICK`：经过/r白名单、同意、独立landing、加密context和最终TikTok发送适配器HTTP替身payload，严格断言相同字节；跨平台字段不混用，同付款不重复发送。另验证512字节混合大小写边界合法；513字节、URL、脚本、空格/换行/tab非法，未生成来源。该合成串仅用于测试，不声称其对应真实账号或平台归因。

Chrome在05自身8205/5605使用`E.C.P.LOCAL_BROWSER_TIKTOK_…`合成值，消费04真实解析器并确认加密来源保真；也复验Meta、慢落地、真实合成12秒视频、503缓冲刷新、撤回和账务保持、员工MFA及桌面/手机/短屏/暗色。实际浏览器不调用广告平台；最终payload另由HTTP替身测试核验。截图全部为自己的合成媒体/测试数据；保留此前证据，不将旧截图挪作新候选。

复演：沿用[前交付操作说明](../analytics-v1-20261029/README.md)的05独立环境。模块命令不变；统一组合必须显式加载local/testing acquisition且使用自身测试库。05不复制任何.env、他人数据库/素材，不修改04/06/公共层文件。

广告transport=disabled、external_enabled=false，保持站点供08只读截图。真实Meta/TikTok平台接受、点击匹配与投放效果仍未验；GitHub留存由00固定候选后统一执行，不能把本地证据当远程回读。
