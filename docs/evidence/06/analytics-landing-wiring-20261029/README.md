# 06 独立landing优先接线修正

源码 `c5ccb4d771ff1bc5c0c7acb7a81fc6a238748ce6`。仅两份06测试文件；[语法回执](php-lint.txt)通过，消费者组合未运行，没有启动应用/数据库或改动实际C2/周四环境。

CombinedTestCase::landing始终先独立POST landing并检查confirmed=true，可选click_context只在该请求；analytics=true分支还真实读取context要求granted，再发没有匹配字段的landing_view。广告only分支不发通用事件。

MatchingContextTest刻意乱序时先要求503、sources/behaviors/event_keys均0；1秒后独立确认，再用原UUID/原内容重复两次，要求accepted但持久只有一条，继续核加密context和原匹配ID不泄露。没有将业务失败改成宽泛可接受状态，也没有用手写source替代真实确认。

05在其独立环境的旧接线8失败/6通过仅为该次历史，原日志留05私密.runtime；本回执不改写其结果，也不宣称修正后消费者已通过。空properties=[]导致批次422由05修present+array，06保持逐条rejected的验收要求。

最终完整候选仍须含03微秒/新迁移、05正式源码及04隐私慢GET/加载保存反馈修复。00给出唯一SHA后，在原06独立环境执行；当前不启动旧候选。
