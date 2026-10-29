# QA-F09-01：首次 MFA 二维码重复编码修复

2026-10-22。模块实现及受影响测试通过；**共享后台接线、06 正常首次绑定浏览器复验待完成，R01 暂停，新版人工验收仍0/12。** 本轮不推翻00-26已接收的其他工程结果，也不把MFA预置夹具算作首次绑定通过。

## 基线、根因与实现

- 01既有独立分支 `codex/01-identity-maintenance` 从49296fd正常快进到00指定 `185bb2b`（包含应用e4a6c65），没有复制主工作树未提交文件。实现 `0569e502327d95f208fe2939435fcf3dada12a2c`。
- 本机PHP8.4.26，imagick=false。实际已安装Filament5.9.0、google2fa-qrcode4.0.0、google2fa9.1.0、bacon-qr-code3.1.1。未安装扩展或改变任何依赖。
- 独立核实：Bacon的SvgImageBackEnd输出已包装为完整SVG data URI；Filament原无Imagick分支再次base64包装，解码得到嵌套URI而不是XML。新测试在尚未覆盖父类方法时3失败/1通过，随后最小修复后全通过。before-summary.json只存无敏感结果，未保存QR内容。
- 新类 `App\Modules\Identity\Filament\Auth\StaffAppAuthentication` 继承原AppAuthentication，只覆盖 `generateQrCodeDataUri(#[SensitiveParameter] string $secret): string`。使用原注入Google2FA对象、原getBrandName/getHolderName和相同默认二维码参数；完整data URI原样返回，原始SVG只编码一次。不新建或替换renderer，不覆盖认证/恢复码方法，不改MFA要求。

## 交00的准确接线

在00归属 `h5-platform/app/Providers/Filament/AdminPanelProvider.php` 中，将原导入：

```php
use Filament\Auth\MultiFactor\App\AppAuthentication;
```

替换成：

```php
use App\Modules\Identity\Filament\Auth\StaffAppAuthentication as AppAuthentication;
```

保持现有接入语句原样：

```php
->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
```

无需容器全局绑定、配置变更、迁移或依赖升级。此文件本轮**没有修改**；01的MfaTest仅在测试面板中替换provider验证受影响认证逻辑。实际运行面板必须由00按上述导入接线后再交06复验。

## 验证与隐私边界

| 检查 | 结果 |
|---|---|
| 当前真实无Imagick依赖 | 实际Google2FA/Bacon生成，data URI解码仅一层，XML解析成功，根节点svg且命名空间正确；未跳过 |
| 原始SVG兼容 | 有效合成SVG包装一次；使用真实Google2FA把配置传给测试renderer，issuer、holder、SHA256、8位、60秒、size200、utf-8均保留；recoverable/count/window配置也保留 |
| 完整data URI | 有效合成SVG URI逐字保持；PNG后端返回值不改标SVG、不再次编码。PNG检查是固定非二维码图片的兼容用例，不是实际Imagick后端验收 |
| MFA受影响回归 | 9项使用新类的测试面板通过：密码/空恢复码不绕过、真实TOTP挑战及session证明、TOTP与恢复码重放拒绝、密钥加密与隐藏、禁用员工拒绝、未绑定重定向、无挑战证明拒绝、通用登录不授MFA证明、改密撤销会话 |
| 总计 | 13项、50断言通过，1.71秒；见targeted-tests.txt。Pint及git diff --check通过。无关全量测试/契约/依赖审计未重跑，旧证据按其版本保留 |
| 环境与数据 | 01专属测试库h5_2396db4f70_test、PG6269；二维码测试用未保存合成员工，MFA回归只用RefreshDatabase测试库。没有新增开发库账号、用户二维码、用户密钥、恢复码或个人账号访问 |
| 结束状态 | 自有测试数据库停止，01网站/队列/定时/数据库/HTTP五项false；8377/5877、旧8206/8303和06/07数据完全未操作 |
| 禁止敏感证据 | 本目录仅代码摘要、版本、通过/失败结果；不含二维码图片、二维码内容、密钥、恢复码或截图。有效性断言采用布尔判断，避免失败时转储QR payload |

06需在00接线后的固定组合使用新的合成账号，走正常首次设置、取消重开、恢复码和重新登录；不要使用预先写入MFA密钥的夹具证明正常首次设置。模块测试不替代该浏览器验收，也不代用户标记R01通过。
