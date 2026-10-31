# R1 身份恢复总提示：公开源码/全mock诊断

2026-10-31，起点794520012266f7bdab72f32c93da4ed1d6996914，诊断测试源4d6d42d8e5297f4840d1ba1ecfe0d79076681bdb。产品三个文件与起点逐字节相同。这里只复用并扩展原Support夹具，不修改产品/翻译/导出或调用其内部函数。

observations.json是4条整份实际identity.js + 完整实际中英r1_purpose_text的客户端观测，不是此前P02前24项显示snapshot。HTTP全部拦截、状态内存合成；取页面来自拦截来源127.0.0.1:18601，无监听/真实HTTP。mock me使用既有最小消费者字段，不能当作真实后端API/schema验收。

每语两种可达来源：
- 明确注册成功响应：complete(true)最初显示真实created文案；首purpose身份读503，两项unconfirmed、0PATCH/0CSRF；先明确重试account，再明确重试advertising，后续两次me均匹配原expectedViewer。总计context1/register1/me3/csrf2/PATCH2，两个目的saved，但global仍account_unknown。
- 注册响应丢失后仅恢复当前账号：POST连接断开没有HTTP状态/创建证明；第一次me成功匹配表单邮箱，走实际checkAccount→complete(false)，显示真实current_account文案；第二次purpose身份读503。其余过程同上，总计me4。该假设不声称POST实际建号，当前账号可为此前已存在的账号；未手调complete或伪造身份确定性。

原始JSON分阶段保留beforeFault、paused、afterAccount、afterBoth的真实DOM文案/状态和精确HTTP顺序；trace中register的connection-reset是运输失败标签，不是HTTP码。summary.json便于只看候选矛盾与计数。

四条输出status=observed-contradiction，总结果diagnostic-observed；脚本正常返回只说明现状观测断言吻合，不是修后自测通过。没有新修后copy或修复产品，没有跑原26/P01/P02/后端/整站/无关工具套件。只做该夹具Node语法与diff检查。

复跑（仅诊断分支）：H5_IDENTITY_SCENARIOS=identity-recovery-observe-created-en,identity-recovery-observe-created-zh,identity-recovery-observe-recovered-en,identity-recovery-observe-recovered-zh，然后执行既有registration-purposes.browser.cjs。本分支用于00读取，不作为main修复交付或部署候选。

未读取运行env/state/私密验收材料或连接实际站/库/邮件/素材；没有启动、停止、探测DB/Web/queue/scheduler，因此不给任何实际站运行状态结论。mock contexts逐条close、browser在finally await close，执行正常结束；没有真实actor、注册case、偏好写或正式采集。源码与报告待00接收后再给产品修复范围。


## 补记首次身份恢复时点（同4条复跑，不改产品）

诊断补充源c925f2cdf5bee2f0c6b73acac838a6197080f43c。在第一个PATCH已由实际submit发出、mock尚未返回任何purpose响应时抓DOM：实际函数已消费me200并匹配原expectedViewer，但两目的仍unconfirmed，global已残留account_unknown。known读到第2次me、recovered读到第3次me；purposeResponsesDelivered=0。first-recovery-observation.json单列新时点及最终状态/相同请求数；先前原始结果不覆盖。该时点不依赖两purpose saved，不以purpose200或context GET推恢复。仅观测新增字段，无额外HTTP/产品钩子，Node语法/diff检查及同4诊断观察正常结束，仍不是修复通过。
