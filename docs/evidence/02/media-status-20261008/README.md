# 02 本地媒体处理收尾（2026-10-08）

工作区：/Users/hejunxiang/.codex/worktrees/h5-02-content/H5
主线核对：普通合并 8405f40d6dec372db3ce49b267559ed190da558a，无冲突；当前契约 1.0.4。未重置工作区，未复制 .env、数据库或素材。

真实剧目：A Star Between Us；总集数 62；实际已公开 1–6 集。开发库中的 Content 状态逐集检查结果：

| 项目 | 数量 |
|---|---:|
| 视频 ready | 62 |
| 视频 processing | 0 |
| 视频 pending | 0 |
| 视频 failed | 0 |
| 字幕 ready | 62 |
| 字幕 processing/pending/failed | 0 |
| 封面 ready | 1 |
| Banner ready | 1 |
| 计划 | paused / updating |
| 已公开集数 | 6 |

每集 MediaStore.playable 检查均通过：MP4、HLS 主清单、ENDLIST、引用分片均存在且非空；字幕 VTT 均 ready 且非空。没有待处理视频可继续，因此只执行了 content:process-media --limit=200 队列确认，没有修改发布状态。未就绪媒体由现有发布服务整批阻断，播放授权也要求当前媒体可用。

自动化回归：37 项、619 个断言通过，覆盖发布计划、前五集免费、第六集会员拦截、字幕/封面绑定、处理状态、私有播放及片单读取。详细输出见本目录 regression.txt。

人工待办仍是版权授权真实性、全剧画面/音轨/字幕质量、真机播放、正式视频服务和外部访问配置。保存授权字段或本地文件 ready 状态不代表版权已核实。
