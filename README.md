# 牛牛助手 · MacCMS10 整合版

本仓库是 **苹果 CMS v10（MacCMS10）与牛牛助手烤进 `application/` 的整站**，不是独立插件。
后台菜单、采集接口和 `application/common/service/nnzs/` 已经在站内。不要再把 `addons/niuniuzs` 上传启用。
独立插件包是另一个仓库，不要和本整合版混装。

ThinkPHP **5.0.x**（`thinkphp/`）。不要升到 ThinkPHP 6/8。CMS 版本见 `application/extra/version.php`（`code` 2024.1000.4045）。后台入口默认 **`admina.php`**。

## 环境

- PHP **8.5.12 及以上**。低于 8.5.12 的版本不再支持；Composer 和网站入口都会拒绝运行。
- 扩展：`pdo_mysql`、`gd`、`curl`、`mbstring`、`openssl`、`json`、`fileinfo`、`zip`、`xml`、`dom`
- MySQL / MariaDB
- 服务器能访问助手商店（默认 `https://api.niuniucms.cc`，可改）

HTTPS 采集需要有效的 CA 信任库。Windows PHP 若提示 `unable to get local issuer certificate`，
可从 [curl 官方 CA 页面](https://curl.se/docs/caextract.html) 获取证书包，并在站点实际使用的 `php.ini` 中
将 `curl.cainfo` 和 `openssl.cafile` 指向该文件的绝对路径，然后重启 PHP 服务。
CLI 与 Web 服务可能使用不同的配置文件；不要通过关闭证书校验解决此问题。

## 安装

1. 下载或克隆完整代码即可安装，**无需安装 Composer，也不需要上传 `vendor/`**。框架位于 `thinkphp/`，验证码组件、字体和图片资源已随程序放在 `extend/think-captcha/`，请完整上传该目录。验证码绘图需要 PHP GD 和 FreeType 支持。

2. 站点根目录指向本仓库（含 `index.php`、`admina.php`、`install.php`）。目录 `application/extra`、`application/data`、`runtime`、`upload` 对 PHP 用户可写。

3. 浏览器打开 `install.php`，按向导填写数据库和管理员。装完会生成 `application/data/install/install.lock`，再次访问 `install.php` 应拒绝。向导会检查内置验证码组件是否完整；若提示缺失，请补传 `extend/think-captcha/`，无需执行 Composer。

4. 复制 `application/extra/maccms.php.example` 的结构仅作对照。安装程序会写 `application/extra/maccms.php`。**不要把带真实 `auth_key`、数据库口令的 `maccms.php` 提交进仓库。** 牛牛助手配置在该文件的 `nnzs` 段（商店地址、`auth_key`、`siteid` 等）。口令也可只放在环境变量 `NNZS_AUTH_KEY`，不写进文件。

5. 后台：`admina.php`。默认开启登录验证码（`app.admin_login_verify`，示例配置里为 `'1'`）。

6. 牛牛助手为必装组件，安装向导自动生成接口口令；可选择导入影视分类（默认）或不导入分类。选择导入时同时配置分类访问权限；不导入时保留当前分类和权限。采集接口、播放器、分类绑定和定时任务不自动初始化，安装后在后台按需配置。安装时自动向商店登记站点信息并发送一次心跳；不会自动创建心跳定时任务。上报失败会在完成页明确提示，可在后台补登记。初始化失败会明确报错，可登录后台处理后重试。

资讯采集不内置默认接口；请在商店配置正式接口后再启用。

## 对外接口（语义保持不变）

口令等于 `nnzs.auth_key`（或环境变量 `NNZS_AUTH_KEY`），`hash_equals` 比对。来源：参数 `token`、参数 `key`，或请求头 `X-Nnzs-Token`。

- `api.php/auto/test`、`api.php/auto/replace`、`api.php/auto/update`
- `api.php/auto/rsync`：异步采集入站。GET 且口令正确返回 `{"code":1,"msg":"pong",...}`；POST 推送 JSON。不要改这条路径的鉴权方式。
- `api.php/provide/vod/` 仍是苹果 CMS 自己的资源接口，本整合不拦截它。

`api.php/auto/replace` 成功时输出格式不变（`--replace img--行数--` / `--replace play_url--行数 条`）。备份失败时不改数据，输出 `backup-fail`。

## 备份

清空分类、重置会员组、批量替换、批量海报之前，会把受影响行写到 `application/data/backup/nnzs/`。

- 新文件是 `.php`：第一行拒绝 HTTP 访问并使用 `__halt_compiler()`，JSON 在这行之后，供人工恢复。JSON 中的 PHP 标签也不会被解析。不要用浏览器打开。
- 目录里有 `index.php`（403）、`.htaccess`（Apache 拒绝）、`web.config`（IIS 拒绝）。
- Nginx 不会读 `.htaccess`。在站点配置里加上，并放在 PHP 解析之前：

  ```nginx
  location ^~ /application/data/backup/ { deny all; }
  ```

- **备份失败会中止**对应的 TRUNCATE / UPDATE，后台提示错误，不会在没留底的情况下改数据。

历史 `.json` 快照如果还在这个目录里，同样会被上面的拒绝规则挡住；新代码不再写 `.json`。

## 升级与范围

- 站内升级走后台已有流程。不要把本仓库改回插件目录布局，也不要改 `/nnzs/` 的打包忽略规则。
- `application/data/update/` 里的 SQL 需人工确认后再执行，安装和日常请求不会自动跑它们。

新安装包不附带演示分类、采集绑定、播放器、服务器或定时任务；接口口令和缓存标识由安装过程生成。分类仅由安装页的“导入影视分类”选项控制。

## 模板兼容性

第三方旧模板接入前，请阅读[模板兼容性与适配说明](docs/template-compatibility.md)，确认需要处理的模板写法、主程序配套修复和未覆盖的验证范围。

## 回归测试

公开 AJAX 接口仅返回列表展示字段，不返回内容密码、访问积分、正文或播放下载源。搜索建议最多返回 20 条已发布内容；评分写入接受 1 至 10，缺省或 0 保持只读查询。桌面快捷方式只接受与站点配置同源的 HTTP(S) 地址。

密码尝试、评分和顶踩按客户端地址与内容分别限频；点击统计采用较宽松的写入频率限制，只读查询不受这些写入限制影响。反向代理部署应正确配置已有的可信代理设置。运行目录需保持可写，供缓存和跨进程限频锁使用。

AJAX 与安装提示回归：`php tests/ajax-regression.php`。验证码独立回归：`php tests/captcha-regression.php`，强制在无 Composer 依赖的环境验证 PNG 输出、会话及后台登录。测试使用内存 SQLite 和临时目录，不连接业务数据库。

前台搜索验证表单回归：`node tests/captcha-form-regression.cjs`，验证成功后重新加载原搜索地址，失败后显示错误并刷新图片。会员登录、注册、留言和评论是否要求验证码，由后台对应开关控制；图片与校验均使用内置组件。

安装依赖后执行 `php tests/security-regression.php`。测试另需 `pdo_sqlite`，使用内存数据库和临时目录，不读取站点数据库口令、不修改业务数据。
覆盖验证码正确/错误/重放/过期、公告过滤、`|raw` 模板、管理员会话撤销和权限更新、备份中的 PHP 标签、部分写入失败及危险操作中止。
HTTP 安装、后台登录与生产 Web 服务器的备份目录拒绝规则仍需在隔离站点复测。

播放器回归：`node tests/player-regression.cjs`（Node.js 18+，无需额外依赖）。
覆盖 HLS 的 MSE 优先与原生回退、错误提示、资源释放、静音策略、MP4、同站下一集导航和独立播放器参数保留。
这是脚本行为测试；真实采集源的网络、跨域响应、媒体解码和画面播放仍需浏览器实测。

发布前可运行 `php tests/release-defaults-regression.php`，检查默认配置中没有站点身份、固定口令或已接入资源。
