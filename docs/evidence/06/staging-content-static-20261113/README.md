# 06 内容管理依赖静态入口窄验

2026-11-13。实际受测源码 **`7f2ecebd68c1e8e7a681feb98c5507e5db5653f7`**，父提交 `2ebe6438da4d7c5ec356bbb34160937cbaabfe1f`；00可仅单取此源码提交，不合入其他候选。代码/输入文件SHA见 [manifest](source-manifest.json)。仅修改Nginx模板和现有原生fixture，无业务、FPM、数据库或云端操作。

## 首败与同类根因

本人在07实测集成staging内容管理四tab仅导航、正文空白；00对40b8589公网复现 `/content/admin.js`、`/content/errors.js`、`/content/admin.css`404 text/html，而admin-ui脚本200。三个资源被遗漏在Nginx静态规则外，转入PHP后404，是已证实的首个失败环节；四tab是否还存在其他原因尚未排除，不能将静态修复写作全部业务恢复。

这是 [HLS同类记录](../staging-hls-static-20261113/README.md) 的再次出现：按手工资源清单准备入口，未从实际页面依赖反查完整发布产物/路由。沿该根因类别交00统一索引，不新增重复事件编号。此前HLS精确修复局限只检查HLS，未证明其他菜单完整。

## 修复与本轮实际证据

只新增三个location=：`/content/admin.css`、`/content/errors.js`、`/content/admin.js`，允许GET/HEAD，指定CSS/JavaScript MIME，继承no-sniff/no-store-private，其他方法拒绝。不开放/content前缀、目录或相邻文件；业务请求仍交原应用鉴权/CSRF。

新增 `--content-library-only` 窄入口，从受测源码 `resources/views/content/library.blade.php` 的实际asset表达式自动发现引用，不手填三项来宣称覆盖完整；新引用会自动进入文件与HTTP检查，缺文件/新静态路径未配置即失败，动态或超出支持范围的表达式明确失败。范围仅Content library的直接asset引用，不冒称递归整个应用或全站依赖审计。原24/HLS/资源全组未重复运行。

Nginx1.28.3/OpenSSL3.6.5原生完整TLS回环，用00指定 `/Volumes/KESU/H5/h5-platform/public/content` 真实文件，只读逐SHA核与06受测源码一致：

| 文件 | 字节 | SHA256 |
| --- | ---: | --- |
| admin.css | 6176 | 206f837f3140d330c8d293c7f2fba53c74901524925f8e3bccdfbbdc44ae3509 |
| errors.js | 2567 | 941c980ae51676ebedaddf313e0f13ff7c82de3485fa267d62d49bcdf7519afc |
| admin.js | 64720 | 760166718345a7ae3c3f55d9693981a0f93d9e6985a09c87220c07345011a9e0 |

[旧模板失败](before.txt)：自动发现的admin.css落合成FCGI418，实际云应用404另记。[新模板通过](after.json)：三文件带实际版本查询参数GET200逐字节相同、HEAD200空体、POST403、正确MIME与防护头；GET `/api/v1/admin/content`、POST uploads及普通/content业务路径仍到FCGI418；相邻文件/目录不作静态服务，dotfile/PHP404。合成上游不代表真实Auth/API成功；有权正文/各tab数据及代表操作尚待00。

```sh
python3 scripts/ops/tests/check_staging_operations.py \
  --nginx /private/tmp/h5-staging-tls-nginx.lwWODV/nginx-1.28.3/objs/nginx \
  --openssl /opt/homebrew/bin/openssl --content-library-only \
  --content-public /Volumes/KESU/H5/h5-platform/public
```

## 防再犯与接收边界

发布入口必须从目标页面实际引用发现依赖并核产物、URL、真实字节、MIME及方法，不能再仅凭route:list/首页200/手工白名单样本。保留此窄检查；后续Content library新增asset会进入同一检查，模板/产物未同步必须先失败。完成静态检查后，在真实员工权限和最终部署SHA下逐tab核业务正文、数据及代表操作，不能拿无权GET页面壳签完整可用。Content settings等其他Blade不在本次映射，不能据本项宣布所有后台菜单已验。

云写由00独占：备份后精确部署vhost变化，保留Owner/MFA/内容/像素与持续任务，后续实际权限正文证据及未解决事项由00统一记录。06本轮未开浏览器/登录、未索MFA、未改全局设置、未访问服务器或改数据。原始失败与限制保留，不创建skill。
