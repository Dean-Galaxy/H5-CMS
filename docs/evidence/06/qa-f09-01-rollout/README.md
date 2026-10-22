# QA-F09-01 8377实际接入证据

应用`6b6e7d5149b2e4b599766e22d73ff99e65c159d5`，启动/检查checkout`c9da6746105a219a4ae8b92a887dfa9d78567099`，00指定合入目标`991d985b7b60d905338ac7f87e4f75930b686f04`。仅本机8377/5877，不是外部部署或手机扫码通过。

- before.json：停止写入后备份回执；实际备份在本机私密目录，不随Git交付，未在本窗口恢复。
- after.json：48表比较，42业务表不变，只有sessions变；490媒体、原配置和密钥文件不变。
- process-http.json：实际进程环境和匿名HTTP，生产目录与候选一致。
- runtime.json：同checkout CLI实际panel注册、MFA要求、受限配置；不是用户登录。
- browser-login.json：Chrome真实匿名登录页显示，未输入账号或密码。
- status.json、migrations.txt：五项健康及15项Ran，无新迁移。
- metadata.json：方法、源码摘要、诊断失败和执行范围。

sha256.json覆盖本目录其他文件；不保存用户资料、二维码/密钥/恢复码、个人账号截图、数据库dump或.env。精确操作和限制见[接入回执](../../../06-QA-F09-01测试站接入回执.md)。
