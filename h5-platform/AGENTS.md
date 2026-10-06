# H5 应用开发

遵循上级根 AGENTS.md 的模块归属、原版只读、契约和交付规则。

本应用环境由根 scripts/dev.py 管理，使用 PHP 8.4、PostgreSQL 17 和 composer.lock。官方应用骨架的首次安装说明已由本项目环境方案替代；不要执行会覆盖文件的脚手架安装或 SQLite 示例初始化。

测试必须使用本工作区专属 PostgreSQL 测试库。Filament 页面公开动作必须调用服务器权限服务，菜单可见性不是授权。未开发模块不得返回虚构成功。
