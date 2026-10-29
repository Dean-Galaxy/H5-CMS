# 06 analytics-v1 准备证据

实际工具/测试源码 `bb947dcb7a56b04d5ce27433ab4dfdffeef26a31`，正常合并公共32e878/ecf132/f629a04/c9a1d9。消费者组合候选尚未提供，combined未执行，浏览器未启动。只在原06的专属_test运行，不访问真实PayPal站或周四站。

- [PHP准备回执](php-tests.txt)：8项170断言。
- [Python隔离](isolation-tests.txt)：6项通过。
- [浏览器helper](browser-helper-tests.txt)：5项通过；不是浏览器实跑。
- [能力缺项](preflight.json)、[prepare](prepare-result.json)、[combined gate](combined-result.json)、[browser gate](browser-gate.json)：后两者实际77，未记消费者通过。
- [新生成合成媒体](synthetic-media.json)：8秒H264、4TS，只有摘要无素材；生成于b85b09、在bb947dc再次校验文件摘要，未声称该版本浏览器已解码。

关闭消费者业务用例使用原06测试FakeGateway受控响应与真实Billing表持久化，只证明该场景下正常付款/权益/退款边界，不能替代启用05后的故障/来源/财务报表。Laravel Http记录器零发送不扩大为任意SDK或真实平台证明。原始私密输出留本区.runtime；本目录为人工检查后去色的安全副本。
