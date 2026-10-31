# R1-P01 草稿意向标签窄修证据

2026-10-31。精确基线U=240e2548a189cbac92a94612c4c3723aadf08d7d；源码73e164cc1c7ec68aa6b6bd4706f0c26966f0e955。新分支codex/01-r1-p01，原01维护分支9099497保留，没有覆盖main或实际站。

- baseline-u-red.json：同一新增夹具在修改产品前运行U实际identity.js/实际中英完整翻译，四个安全文案断言全部按预期失败；英文草稿为Will save: Permission saved/Refusal saved，中文为本项将保存: 已保存允许/已保存拒绝。此处均未发合成注册请求，记录的是客户端草稿渲染。
- draft-labels-green.json：修后相同4类（英文/中文×allow/deny）通过。全量真实r1_purpose_text、真实identity.js/CSS；四个用例分别为账号项成功、广告项首次503未确认、明确重试后保存。每例合成注册POST1、目的PATCH3（成功项1/未知项2），重试保持同一operation/revision/notice/意向；已保存状态原文/false effective保持。结果文本快照仅本夹具生成，非实际站/账号。
- purpose-regression.json：原26类重新通过，包括两种换号CSRF时序、部分成功/冲突/未知、显式重试和短屏；兼容夹具仅新增draft标签实际翻译接线并修正旧错误期待，其他模拟文本保持其原边界。
- recovery-regression.json：原6类回程本次通过。
- product-byte-check.json：身份脚本相对U严格仅一次所需字符串替换；两翻译解析后严格只新增draft_allow/draft_deny，所有原key/value不变，尤其已保存allow/deny原字；register view/CSS/composer.lock字节不变。含新旧产品字节SHA256。
- 初始/最终状态五项false，全程未启动数据库、网站、队列或调度。状态命令返回1表示未运行，属预期。未操作任何其他站/manifest/env/4state/账号。
- Pint、产品及新夹具JS语法、两翻译文件PHP语法、git diff --check通过。纯客户端文案改动，未重跑PHP业务套件/892全站、数据库、契约结构或依赖审计；无这些范围的新结论。

复跑：运行h5-platform/tests/Feature/Identity/Support/registration-draft-labels.browser.cjs；工具通过H5_PHP_BIN/H5_PLAYWRIGHT_TEST/H5_CHROME指定本机已有运行时。所有HTTP被拦截，127.0.0.1:18602仅夹具可信来源，没有监听该端口；状态仅内存合成。旧U红结果在修改前已实际记录；新夹具支持H5_IDENTITY_SCRIPT指定旧JS导出文件复看错标签，不触碰运行站。

00指定真实before截图仅只读查看，没有复制到Git或在本轮重新取图。这里不代表实际partial-503案例已重跑、其私密数据审计已完成、08实际候选UI通过或R1整体/人工通过。P01实际接收由00继续，本人无需操作。
