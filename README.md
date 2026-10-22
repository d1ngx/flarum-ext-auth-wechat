# WeChat OAuth for Flarum 2

Flarum 2 / PHP 8.3–8.5 微信开放平台**网站应用扫码登录**适配器。通过 FoF OAuth 2.x 提供登录、注册及账号连接管理。

**Status: implementation tested with mocked WeChat responses; real WeChat authorization acceptance is still required before production enablement.**

## 兼容旧微信绑定

- provider 名固定为 `wechat`，身份标识固定为 **UnionID**。
- 与旧 `hamzone/flarum-ext-auth-wechat` 写入的绑定兼容，无需批量修改旧用户或绑定表。
- 保持原微信应用及身份命名空间。缺少 UnionID 时拒绝登录，不回退为 OpenID，不按昵称或伪造邮箱合并账号。
- 原回调路径 `/auth/wechat` 由 FoF OAuth 自带路由提供，本扩展不另注册重复路由。
- 不修改、删除旧微信设置或账号数据，不自动开启微信 Provider。

本扩展不提供公众号网页授权。普通手机浏览器不会被错误分派到缺少配置的公众号授权。微信内的实际扫码体验仍需业务验收；需要公众号免扫码授权时应单独实现并配置公众号权限。

## 安装

需要 Flarum 2.x、FoF OAuth 2.x、PHP 8.3+ 及其常规模块。仓库若为私有，需要在服务器通过私有 Git 凭据访问，避免把 token 放进 composer.json 或仓库。

在论坛根目录执行（先替换仓库地址；不要在未验收的生产环境自动更新所有依赖）：

```bash
composer config repositories.wechat vcs https://github.com/d1ngx/flarum-ext-auth-wechat
composer require d1ngx/flarum-ext-auth-wechat:dev-main --no-update
composer update d1ngx/flarum-ext-auth-wechat --with-dependencies --dry-run
# 检查变更并完成测试后，执行对应更新。
php flarum migrate
php flarum cache:clear
```

启用本扩展后，在 **FoF OAuth** 设置中配置微信网站应用 AppID、AppSecret，并显式启用 WeChat。

配置键：`fof-oauth.wechat.client_id`、`fof-oauth.wechat.client_secret`、`fof-oauth.wechat`。

尚未写入新凭据时，服务端兼容读取旧键 `flarum-ext-auth-wechat.app_id` 和 `.app_secret`。后台新输入框不会展示旧密钥；如果后台校验要求填写，请通过服务器或受控后台迁入。显式保存空的新凭据会禁止回退到旧凭据。不要在聊天、日志、命令历史或 Git 中放入密钥。

微信侧回调为 `https://你的论坛域名/auth/wechat`，需核对原网站应用的授权回调域设置。

## 行为与安全边界

- 使用微信官网二维码授权端点和 HTTPS API。API 连接超时 5 秒，总超时 15 秒，不跟随重定向。
- 保留微信 `appid` / `secret` 参数格式，处理 HTTP 200 内的 `errcode` 错误。
- UnionID 来自微信服务端，检查 token 与 userinfo 的 OpenID/UnionID 一致性。
- 授权、绑定、state、会话更新和返回地址校验由 FoF OAuth 2.x 执行。本包不复制旧弹窗脚本、不继承旧 ResponseFactory。
- 微信没有提供经过验证的邮箱，因此新用户需要自行填写邮箱；用户名按 Flarum 规则处理，纯中文昵称可能无法直接成为用户名。
- 不持久化完整微信用户信息，不自动下载头像，避免未经限制的远程头像请求。
- 不记录上游 token、密钥、错误体；HTTP 错误不向应用层传播带凭据的请求 URL。不要给 HTTP 客户端添加 debug/请求日志中间件。
- 不沿用旧 `/api/auth/wechat/link` 和 `/unlink` 接口；账号绑定解绑从 FoF OAuth 的账号安全界面进行。

## 测试

```bash
composer install
composer test
```

已有 Flarum 依赖环境也可在独立目录测试：

```bash
FLARUM_VENDOR_AUTOLOAD=/path/to/forum/vendor/autoload.php php tests/run.php
```

测试不读取论坛 config.php、不访问生产数据库。HTTP 使用 Guzzle MockHandler；老账号查找及绑定冲突使用内存 SQLite 和实际 Flarum/FoF 类。测试包含 UnionID 连续性、错误与缺失身份拒绝、敏感信息隐藏、旧设置兼容、state 重放、跨会话 state、开放跳转、原账号匹配与串绑拒绝。

CI 在 PHP 8.3、8.4、8.5 上安装完整依赖后执行。CI 通过不代表微信侧应用或真实回调已经验收。

## 上线验收与回退

在独立测试实例完成：已绑定老用户扫码回原账号、未绑定用户注册、已登录用户绑定、冲突绑定拒绝、解绑后的登录方式保护、取消授权、超时与微信拒绝响应。使用真实微信完成这些流程后才开启生产 Provider。

生产上线前备份 Composer 文件与数据库，检查现有 FoF OAuth 版本、账号安全页和微信回调配置。回退时先关闭微信 Provider，再禁用本扩展并清理 Flarum 缓存。不要删除旧绑定表，不需要回退 Flarum 核心。

## 来源

本实现为 FoF OAuth 2.x 的独立适配器。兼容行为参考旧 MIT 项目 [HamZone WeChat Auth](https://github.com/HamZone/flarum-ext-auth-wechat) 及其 NomisCZ 上游；没有将旧认证响应和前端包复制进新实现。

License: MIT.
