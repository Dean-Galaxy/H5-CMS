# H5 新工程

主工程在 `/Volumes/KESU/H5/h5-platform`。本次交付 **01 工程基础与身份权限**，等待 00 接收共同基线。内容、账务、观众全站和投流模块尚未实现；`原版/` 永久只读，不参与运行或版本管理。

产品经理从 [本机使用说明](docs/01-本机使用说明.md) 开始。交接见 [01 交付单](docs/handoffs/01.md)，接口见 [共同契约候选 v1](docs/contracts/README.md)，环境与安全核对见 [依赖审计](docs/01-环境与依赖审计.md)。

本机已经安装运行环境。进入本目录后：

```sh
python3 scripts/dev.py start
python3 scripts/dev.py status
python3 scripts/dev.py stop
```

网站默认 [http://127.0.0.1:8101](http://127.0.0.1:8101)，后台 [http://127.0.0.1:8101/admin](http://127.0.0.1:8101/admin)。也可双击 `本地开发/` 中的启动、状态、停止、负责人创建和邮件预览文件。停止保留数据库和文件，不删除数据。

新的 Git 工作区首次使用：

```sh
python3 scripts/bootstrap.py
python3 scripts/dev.py start
python3 scripts/dev.py test
.runtime/contracts/bin/python scripts/validate_contracts.py
```

Bootstrap 从锁文件安装，不复制原工作区的账号、密钥、数据库、会话、队列或素材。工作区步骤及归属规则见 [开发接入说明](docs/01-开发接入说明.md) 和 [AGENTS.md](AGENTS.md)。
