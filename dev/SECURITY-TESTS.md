# 三项安全修复与回归验证

2026-10-02：修复私密评论、验证码和图库维护入口。未处理此前审查的图片上传、CSS 压缩等其他问题。

## 行为

- 私密评论允许评论管理者、已登录作者/父评论作者，或持有对应访客随机凭证的浏览器阅读。邮箱不能证明身份。访客清除 Cookie 后不能通过填回邮箱认领旧评论；当前无生产数据，不需要迁移。
- 随机访客凭证只在服务端存摘要，Cookie 为 HttpOnly / SameSite=Lax，HTTPS 使用 Secure。覆盖模板、摘要、RSS、标准评论 REST 与缓存头；实时搜索保留原来的私密内容遮蔽。
- 图片验证码以随机 ID 对应服务端短期挑战，60 秒过期，每次尝试消费挑战。旧客户端字段保持不变，失败后刷新图片重新答题。登录校验不接受客户端的跳过标志；找回密码错误通过 WordPress 的错误对象返回。
- 图库 GET 仅显示确认页；执行需管理员权限、POST 与操作专用 nonce。转换只生成新的 `image.jpg.webp` 等副本，不移动原目录，不覆盖现有文件或自动替换索引。GIF 保留动画。之后手动重建索引会优先使用有效副本。

## 测试要求

必须使用可销毁的独立 WordPress 数据库和上传卷，主题目录只读挂载。测试会创建用户、文章、评论和图片，禁止连接真实站点。测试服务地址固定为 `http://127.0.0.1:18396`，测试管理员为 `securityadmin` / `cherry-security-test-2026`。

使用 WordPress 7.1 / PHP 8.3、MariaDB 的独立容器安装站点后，激活目录 slug `Cherry`。确保上传目录由 Web 服务用户（本次 UID 33）拥有，WP-CLI 同样以该用户运行，避免 CLI 和 Apache 写入权限不一致。

通过设置 `CHERRY_SECURITY_TEST=1` 的 WP-CLI 容器执行：

```text
wp eval-file wp-content/themes/Cherry/dev/security-regression.php
wp option patch update iro_options captcha_select iro_captcha
wp eval-file wp-content/themes/Cherry/dev/security-regression.php auth
wp option patch update iro_options captcha_select turnstile
wp option patch update iro_options turnstile_site_key test-only
wp option patch update iro_options turnstile_secret_key test-only
wp eval-file wp-content/themes/Cherry/dev/security-regression.php auth
wp option patch update iro_options captcha_select off
wp option patch update iro_options comment_captcha_select off
wp option update comment_moderation 0
wp option update comment_previously_approved 0
```

然后在宿主 PowerShell 7 执行 `pwsh -NoProfile -File dev/security-http.ps1`。

PHP 回归覆盖：伪造邮箱、不同访客/账号、作者/父作者/管理员权限、REST/RSS/摘要/搜索、验证码伪造/重放/过期/时间戳篡改/错误消费、两种登录验证码、找回密码错误对象，以及原图/已有文件/备份/索引不受转换影响、同名不同扩展名、无效图片、GIF 和索引去重。Turnstile 成功响应由测试内 HTTP mock 提供，不请求 Cloudflare。

HTTP 回归覆盖：真实访客提交与 Cookie 签发、重新访问与隔离访问、不可共享缓存、后台权限、缺失/错用 nonce、GET 确认、合法 POST 转换与重建，以及首页、文章、页面、分类、搜索、404、登录、设置页响应。

## 本次结果

- 8 个修改/新增的业务 PHP 文件及 PHP 回归脚本通过 PHP 8.3 语法检查。
- PHP 回归 50 项通过；HTTP 与基本页面检查 29 项通过。
- 全新站点激活时曾因上传目录权限发出警告；修正本次临时卷的目录所有者后完成测试。未向主题选项补入旧审查中的首页背景兼容值。
- 未验证真实 Cloudflare 密钥、第三方免密登录插件或浏览器视觉交互。
- 临时容器、网络和数据卷在测试后清理；未改动常用预览站的数据。

模块索引 `MODULES.md` 同步记录了修复，但该文件被仓库既有 `.gitignore` 忽略，不会随普通提交进入其他检出。
