# 首批非root身份与临时备份授权材料（未绑定、未执行）

起点`27280d5f7194065ef62d56a3821eba79fc3781ad`，2026-11-01。当前仅确认本人能进控制台、Free；MFA尚未核实，不写已绑定。具体账号、主体ARN、桶名仍未知，所有`__占位符__`由00在私密副本填入、07指导本人确认；本目录不提供自动创建/绑定工具。**JSON可解析只代表材料语法，不代表AWS接受、权限实际生效或允许创建付费资源。** 不改变冻结费用单。

## 只建一名操作用户和一个备份角色

| 身份/动作 | 谁做、精确权限及边界 |
|---|---|
| 账户所有者/已获准IAM管理员 | 本人在控制台先完成账户MFA；初始身份配置仅在本人明确批准后由其控制台执行，不给工程账号管理权限。IAM路径：Users → Create user，名字`h5-sparklingshort-operator`，只给控制台登录、本人保管密码；不创建Access Key、不加管理员组。Security credentials → Assign MFA，为**这个用户**另绑TOTP设备并记录设备ARN到私密副本；root的MFA不等于该用户的设备。若账户体验没有IAM入口或不准此操作则停，不改账号计划/高级模式。 |
| 项目操作用户（本人持有） | Attach policies directly：仅官方`arn:aws:iam::aws:policy/SignInLocalDevelopmentAccess`和[限定AssumeRole内联策略](operator-assume-backup.template.json)。不附AdministratorAccess/PowerUserAccess，不继承其他组/策略；本人持有登录和MFA，工程只维护已授权的本项目会话，不共享密码。它本身没有S3数据或计费资源创建权限。 |
| `h5-sparklingshort-staging-backup`角色 | 本人控制台 IAM → Roles → Create role → Custom trust policy，粘贴00已替换的[信任策略](backup-role-trust.template.json)；仅信任同账号那一名用户＋MFA＋TLS＋us-east-1。角色Maximum session duration固定1小时，唯一内联权限用[备份权限](backup-role-permissions.template.json)，不附其他策略。角色/策略不是收费机器；本轮也不代创建。 |
| 计费资源部署/撤销 | Lightsail实例/IP、S3建桶/加密/公共访问阻断、SES域身份/SMTP、SNS主题/订阅，由本人使用现有经核准的控制台所有者/管理员身份，在明确批准费用后按冻结单亲自完成；先核该主体真实可用权限、Free限制及MFA。本包不授予工程用户/备份角色`lightsail:*`、`s3:CreateBucket`、SES/SNS创建、IAM/PassRole或账单/升级权限，不把备份角色当创建授权，也不另要求部署role或新增工程create权限。若控制台实际权限不足或提示升级，按真实提示停止并交00，不代升级。所有者最初IAM引导与一次明确批准的资源创建，不自动成为日常root运维许可；root CLI/长期AK仍禁止。 |
| 恢复与留存删除 | 此备份角色仅写入/列举`__BUCKET_NAME__/h5-staging/`，无GetObject、DeleteObject、改桶政策或KMS权限；不可以拿它恢复/删除。远端上传完整性响应及前缀List核大小可用，不能用HeadObject（需GetObject）冒称已授予。独立恢复读取、保留期删除由本人控制台授权的运维主体另行批准；未批准就保留数据，不能拿备份角色越权。 |

00替换账号必须12位、桶名必须本项目同账号/us-east-1的实际唯一桶（未来收费资源创建获批后才能建，**角色可先引用未创建的拟定桶名**）。前缀固定`h5-staging/`，对象名用唯一UTC备份批次；PutObject仍允许同键覆盖，此模板不宣称不可变备份，未来桶版本化/留存须另验。实际桶所有权、SSE-S3/私密策略仍待核。上传权限含PutObject（包括分片初始化/上传/完成）、AbortMultipartUpload及ListMultipartUploadParts；不给ListBucketMultipartUploads、ListAllMyBuckets或全账号发现权限。Bucket层ListBucket也须带限定prefix，不能列根目录。[S3前缀授权](https://docs.aws.amazon.com/AmazonS3/latest/userguide/amazon-s3-policy-keys.html)、[分片权限](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpuoverview.html)

没有为方便控制台增加资源`*`的list/create项。唯一官方受管策略的宽范围为`signin:AuthorizeOAuth2Access`与`signin:CreateOAuth2Token`对`arn:aws:signin:*:*:oauth2/public-client/*`，只用于开发工具登录，不授任何H5数据/创建权；它是AWS当前v3原文，不是AdministratorAccess，未来版本需重核。GetCallerIdentity本身不需要Allow策略，不能把它成功当S3权限通过。[官方策略原文](https://docs.aws.amazon.com/aws-managed-policy/latest/reference/SignInLocalDevelopmentAccess.html)、[身份API语义](https://docs.aws.amazon.com/STS/latest/APIReference/API_GetCallerIdentity.html)

## aws login与MFA：不推测继承

AWS技术上允许aws login使用root、IAM user或federated身份；**本项目额外禁止root CLI及长期AK**，不误写成AWS不支持root。此包只选以上具TOTP的IAM user，不顺带搭Identity Center/Organizations或联合身份体系。AWS CLI≥2.32.0且该IAM身份挂SignInLocalDevelopmentAccess后，00准备的隔离CLI才可由本人浏览器选择这个非root用户登录；不能批准切换成root/其他管理员会话。CLI可刷新至多12小时，仍以主体实际会话期限为准；备份请求`DurationSeconds=3600`另受角色上限及链式角色一小时限制，不把12小时传给备份进程。[官方登录](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sign-in.html)、[AssumeRole期限](https://docs.aws.amazon.com/STS/latest/APIReference/API_AssumeRole.html)

**MFA绑定≠浏览器认证≠STS的MultiFactorAuthPresent。** 官方未在aws login页保证所有此类源会话都把浏览器MFA标志传给下游，因此本包不假定继承。信任策略严格`Bool aws:MultiFactorAuthPresent=true`，不用IfExists放过缺失；显式AssumeRole请求传该IAM用户的SerialNumber与TokenCode。本人仅在00准备的本机私密流程提供TOTP，工程将请求放0600私密临时文件，调用后立即删除；不放命令参数/聊天，不拿root设备ARN替代，不以“用户已创建/自己使用”推定MFA。

这条aws login源会话＋显式MFA AssumeRole组合**必须实际验证**；若临时源凭据/MFA类型/账户限制导致AccessDenied、MFA条件不满足或不支持，停止并把安全错误码交00，不能删除MFA条件或改用长期AK绕过。未来若改联合身份，要另审其MFA会话声明；不能套当前IAM用户trust。[MFA API保护](https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_mfa_configure-api-require.html)、[STS原生请求参数](https://docs.aws.amazon.com/cli/latest/reference/sts/assume-role.html)、[条件键可用性](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_condition-keys.html)

## 一次非root身份核对 → 一次STS进入角色核对

**以下给00工程人员填入私密副本，默认不执行；PM不手改JSON。** 00固定`AWS_CONFIG_FILE`、`AWS_SHARED_CREDENTIALS_FILE`（初始空文件）和`AWS_LOGIN_CACHE_DIRECTORY`到项目私密目录（目录0700、文件0600），不改HOME/全局PATH，不用默认profile；清除继承的AWS凭据及profile、禁用IMDS/容器凭据回退。先确认非root浏览器身份，再本人一次`aws login --profile h5-operator`；核自动生成的login_session正是该IAM用户ARN，不传播缓存token。[官方登录缓存变量](https://docs.aws.amazon.com/sdkref/latest/guide/feature-login-credentials.html)

不使用自动role profile：其角色缓存默认`~/.aws/cli/cache`，不能假定上述登录缓存变量会同时改变它。本包[profile片段](profiles.config.example)只有源登录。由00在私密临时请求文件填以下字段（TokenCode仅当次输入），再用显式STS调用，将返回值捕获到内存，绝不直接输出密钥。[角色缓存说明](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-role.html)

```json
{
  "RoleArn": "arn:aws:iam::__ACCOUNT_ID__:role/h5-sparklingshort-staging-backup",
  "RoleSessionName": "h5-staging-backup-check",
  "DurationSeconds": 3600,
  "SerialNumber": "__OPERATOR_MFA_SERIAL_ARN__",
  "TokenCode": "__LOCAL_CURRENT_TOTP__"
}
```

下面是私密流程的原生调用次序示例；00替换路径/账号、完成上述环境隔离并获授权后才可把绑定改为True。当前只作语法审查，不执行。角色会话仅在子进程环境传递，不落默认角色cache；其余维护进程若需使用凭据也只能由00私密限时传入，不进入日志/聊天/Git。

```python
import json, os, subprocess
from pathlib import Path
H5_IAM_BINDING_APPROVED = False
AWS = '__PROJECT_ISOLATED_AWS_BIN__'
ACCOUNT = '__ACCOUNT_ID__'
REQUEST = Path('__PRIVATE_0600_STS_REQUEST_JSON__')

def aws_call(args, env):
    result = subprocess.run([AWS, '--region', 'us-east-1',
        '--endpoint-url', 'https://sts.us-east-1.amazonaws.com',
        *args], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode:
        raise SystemExit('AWS_CHECK_FAILED_STOP_PRIVATE_DIAGNOSIS')
    return result.stdout.decode().strip()

if H5_IAM_BINDING_APPROVED:
    # 00先核三个私密路径、源login_session及无其他凭据回退；此处不代准备登录。
    source_env = dict(os.environ)
    user = aws_call(['--profile', 'h5-operator', 'sts', 'get-caller-identity',
                    '--query', 'Arn', '--output', 'text'], source_env)
    if user != f'arn:aws:iam::{ACCOUNT}:user/h5-sparklingshort-operator':
        raise SystemExit('WRONG_NONROOT_IDENTITY_STOP')
    try:
        data = json.loads(aws_call(['--profile', 'h5-operator', 'sts', 'assume-role',
            '--cli-input-json', REQUEST.as_uri(), '--output', 'json'], source_env))
    finally:
        REQUEST.unlink(missing_ok=True)  # 仅00为本次新建的私密临时请求；不指向其他资料。
    role_env = dict(source_env)
    for key in ('AWS_PROFILE', 'AWS_DEFAULT_PROFILE'):
        role_env.pop(key, None)
    creds = data['Credentials']
    role_env.update(AWS_ACCESS_KEY_ID=creds['AccessKeyId'],
        AWS_SECRET_ACCESS_KEY=creds['SecretAccessKey'], AWS_SESSION_TOKEN=creds['SessionToken'])
    role = aws_call(['sts', 'get-caller-identity', '--query', 'Arn', '--output', 'text'], role_env)
    if role != f'arn:aws:sts::{ACCOUNT}:assumed-role/h5-sparklingshort-staging-backup/h5-staging-backup-check':
        raise SystemExit('WRONG_ROLE_IDENTITY_STOP')
    print('NONROOT_AND_STS_VERIFIED_ONLY')
else:
    print('UNBOUND_NO_AWS_ACTION')
```

整个流程是两次身份读取＋中间一次显式AssumeRole，不建任何资源；全部成功表明本次STS接受该信任/MFA/源身份链，**不证明S3已存在、写入/加密/恢复成功或资源创建权**。角色策略/前缀边界当前仅离线可审；未来桶创建获批后才可做最小合成上传验权，不现在额外探测。任何输出ARN、MFA serial、登录授权码、缓存及失败详情保留本机私密记录，聊天只交结果/固定错误码；不复制AccessKey/Secret/SessionToken给PM或进Git。Free/new体验提示IAM/角色/登录不可用或要求升级/advanced时立即停，本人另选；不代升级、不切账户。

完成验证前不开收费实例。若成功也仍须本人批准冻结资源单的具体费用；本材料不等于采购/部署许可。维护窗口结束退出源登录并退出持有本次角色凭据的进程/删除私密临时资料；已经下发的STS会话不会因删除本机缓存自动失效，最长1小时，紧急时由本人已授权管理员使用IAM原生撤销会话。本轮未登录、未建身份、未调用API或读取真实秘密，未改原费用/模板/业务/旧环境。

## 00本机工具准备接收

2026-11-01，AWS官方macOS包2.39.3已通过Apple开发者安装包签名验证（AMZN团队94KV3E626L），只展开到本项目私有`.runtime/tools/aws-cli`，未运行系统installer或修改全局PATH。工程独立执行`--version`与`login help`均退出0，原生arm64；没有启动登录、调用AWS服务或读取真实账号秘密。[官方工具来源](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html)。安装包及可执行文件摘要保留本机私密回执，工具二进制不进Git。

工程填私密profile时预置期望的`login_session`用户ARN；登录页面只选该项目非root用户，如CLI提示覆盖为其他身份必须拒绝，再核同一身份。此设置不是AWS服务端拒绝其他身份的授权策略，实际身份读取仍不可省略。正式登录前由00绑定账号与私有配置/缓存路径；当前模板未绑定，权限、MFA、STS链和S3操作都未实际验证。
