# elasticFulltext 与共享边界安全审计 — 2026-10-11

审计基线：aiRag `49ba736`（1.6.26），elasticFulltext `f247979`（1.5.8）。范围包括两个插件的 PHP、SQL、JavaScript、页面，以及宿主请求过滤、插件授权和文件授权的衔接。以下位置按方法名定位；摘录为修复前代码。本文所列修复随本轮安全更新一并提交，部署与验证边界见下文。

## 发现与修复

### 1. 页面启动 JSON 的反射型 XSS — 高

位置：aiRag `app.php::index()`、`static/page.html` 的 AIRAG_BOOT。

```php
$refs = $this->decodeJson(_get($this->in, 'refs', ...));
json_encode($this->airagBoot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
```

请求附件信息可包含 `</script>`；JSON 字符串转义不能阻止 HTML 解析器提前结束 script。诱导已登录用户打开构造链接可以执行同源脚本，管理员受害时影响更高。修复为内联 JSON 增加 JSON_HEX_TAG、JSON_HEX_AMP、JSON_HEX_APOS、JSON_HEX_QUOT。`tests/security.php` 渲染真实模板，`tests/security-ui.cjs` 在浏览器验证脚本不执行而数据正确恢复。

### 2. 后台属性转义缺失导致 XSS — 高

位置：aiRag `static/console.js::h()`、`static/admin.js::h()`，模型编辑和服务配置模板。

```js
return $('<div>').text(s==null?'':s).html();
// 返回值随后被拼到 <input value="..."> 等属性中
```

文本 HTML 序列化不转义引号。恶意模型列表或被污染配置中的名称/ID 可插入事件属性，攻击查看后台的管理员。已统一编码 `& < > " '`，同时编码筛选标签和引用属性。浏览器回归对两套实际函数输入引号、事件属性和 img 载荷，确认无注入元素、无事件属性、无执行。

### 3. 缺少插件自身的 CSRF/JSONP 防护 — 高

位置：两插件 `app.php::manage/status/task`，aiRag `chat/ask/media`；修复实现为两插件相同的 `lib/Security.class.php`。

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ...;
if (!KodUser::isRoot()) ...;
```

宿主 `app/controller/filter/post.class.php` 对 plugin 路由明确跳过统一 CSRF，并允许 JSONP。因此 POST 和登录/root 判断不能阻止借用受害者会话。风险包括配置变更、索引删除/重建、模型调用费用；chat 删除/收藏原先连 POST 限制也没有。

修复要求 `X-Kodbox-Plugin: 1`，校验提供的 Origin、Fetch Metadata，关闭并拒绝 JSONP，变更接口要求 POST；直接通过 plugin 路由调用 task 还要求 root，内部定时调用保持可用。前端只为同源、指定插件地址添加请求头。此头不是身份凭证，原有身份和对象权限检查仍保留。

回归：缺头、错误 Origin、跨站标志、GET、JSONP 均拒绝；浏览器测试检查实际请求头，并确认第三方地址不加头。自定义已认证 API 客户端需增加此头，升级须刷新前端。

### 4. 任意文件 ID 状态查询及副本元数据泄漏 — 中

位置：aiRag `app.php::fileFlags/visibleFileIDs/hydrateState`。

```php
$fileIDs[] = intval($id);
Model($this->stateTable)->where(array('fileID'=>array('in',$fileIDs)))->select();
// hydrateState 原先采用状态 sourceID 或该 fileID 任意第一个 Source
```

fileID 不是权限凭证，普通用户可枚举他人入库状态和分片数。同一物理文件的不同副本可能属于不同用户，可读正文不等于所有副本路径可读。修复为查询前批量校验 fileCanRead，记录本次请求已授权的 sourceID，引用采用可读副本。状态展示与语料禁用策略分离，自己禁用文件的状态仍可见。限制 500 个 ID、100 条路径。

`tests/security-authorization.php` 验证不可读 ID 不进入状态查询、禁用状态展示、可读副本选择；原 access-policy 回归保留失败关闭验证。

### 5. 请求路径直接进入 IO：权限缺口、潜在 SSRF/本地路径探测 — 中，影响取决于驱动

位置：aiRag `app.php::resolveAskScope/fileFlags`。

```php
$info = IO::info($path); // path 来自请求
```

宿主资源管理器的自动路径权限拦截针对 explorer 路由，不自动保护插件 IO 调用；IO 支持 URL/物理路径等驱动。未对真实内网服务执行利用，不能断言任意本地文件读取已成立。修复只接受 `{source:正整数}` 网盘引用，拒绝外部 URL、绝对路径、控制字符、反斜杠及点段，先 fileCanRead 后 IO。显式引用全部无效时仍保持受限检索，不能退化为全库。

回归覆盖 HTTP、本地路径、编码穿越、不可读 source，确认这些输入不会到达 IO。外部/挂载驱动直接路径不再支持，应选择已经入库的 source 文件。

### 6. 模型生成文件缺少显式写入权限和类型约束 — 高（越权写入）；未证实 RCE

位置：aiRag `app.php::applySaves()`。

```php
$name = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', ...);
IO::setContent($dir.'/'.$name, $block[2]);
$saved[] = $name;
```

原正则基本挡住目录分隔符，不能据此认定任意路径写入。但绕过正常上传/编辑入口后没有显式角色、目标写权限和空间检查，可执行扩展未受限，同名文件可覆盖，失败也可能显示成功。开启保存能力后，受文档提示注入影响的模型输出也会到达此写入点。能否执行 PHP 取决于存储/Web 映射，未确认服务器 RCE。

修复检查角色编辑权限、目标 upload 权与空间；只生成 txt/md/csv/json，其余扩展附加 .txt；拒绝点段和分隔符；随机后缀避免覆盖；最多 5 个文件、每个 1 MB；写失败明确报错。前端说明保存文本副本。回归测试危险扩展及穿越名称；未用真实多账号或第三方存储执行写盘验证，宿主配额实现仍是信任边界。

### 7. ASR 上传和媒体模型边界不足 — 中

位置：aiRag `app.php::media()`、`lib/Security.class.php::audioFile()`。

```php
is_uploaded_file($_FILES['file']['tmp_name']);
new CURLFile($tmp, $_FILES['file']['type'], $_FILES['file']['name']);
$model = $this->in['model'];
```

原实现不检查上传错误、空/过大文件或真实 MIME，透传客户端文件名；普通用户可指定配置外模型消耗共享 API 额度。未发现上传文件被本地保存执行的链路。

已检查 UPLOAD_ERR_OK、is_uploaded_file、实际 1–25 MB 大小与 finfo MIME 白名单，向服务发送固定安全文件名；仅使用管理员启用的对应类型模型及其服务，不再借聊天模型兜底。上传回归覆盖伪造来源、大小、部分上传、伪造 MIME 与名称；测试模拟 PHP 上传元数据，没有向真实 ASR 发送文件。MIME 检查不等于杀毒或完整音频解析。

### 8. HTTP 协议/TLS 与响应内存边界 — 中

位置：aiRag `lib/HttpJson.class.php`，elasticFulltext `lib/ElasticClient.class.php`。

原 upload 没有统一协议验证；SSE 同时累计全部 raw 和行缓冲；普通响应无应用层上限。elasticFulltext 仍会读取遗留 `verifyTls=0`，尽管配置保存已经移除该选项。

修复统一 HTTP(S) URL 校验，拒绝内嵌凭据、控制字符和 fragment，cURL 显式限制协议、不跟随重定向；强制 TLS 证书及主机验证。aiRag 普通响应 64 MB 上限，SSE 总量 16 MB、单行缓冲 1 MB、错误 raw 1200 字节；上传连接超时 8 秒。

回归：security-http 覆盖正常 JSON、协议/重定向、大响应中止；http-tls 验证三类请求 TLS；elasticFulltext security 验证遗留 TLS 配置及非法 URL。真实 ES/Milvus 临时资源集成验证通过。

SSRF 边界：Docker ES/Milvus/本地模型本来就需要私网地址，因此没有封禁所有内网 IP。任意服务 URL 只从管理员配置/检测入口接受，并加 CSRF 防护；普通用户使用已配置服务。未实施 DNS pinning 或出口 IP 白名单，恶意管理员、被控制的服务 DNS/代理仍是剩余风险。含用户名密码的 URL 现在被拒绝，旧客户端须调整；不应以关闭 TLS 解决证书问题。

### 9. Office ZIP 元数据无界解压 — 中

位置：aiRag `lib/TextNormalizer.class.php::sanitizeOfficeZip()`；elasticFulltext `app.php::sanitizeOfficeZip()`。

```php
$types = (string)$zip->getFromName('[Content_Types].xml');
```

小压缩包可声明极大 XML，磁盘大小上限不约束字符串展开内存。修复在提取前 statName 检查 XML 最大 1 MB、ZIP 最多 10,000 条目，读取也带上限；超限失败并清理临时文件。两个仓库的 security-archive 实际构造压缩 XML 验证。没有解压至请求指定路径，未发现 Zip Slip 落盘链路。这不等于审计了 Tika 的所有嵌套压缩/格式解析器。

### 10. 手工 SQL 搜索拼接 — 低/可维护性问题，未确认 SQL 注入

位置：aiRag `app.php::libraryJoin/libraryList`。原实现删除 `% _ \ '` 后拼接 LIKE，未确认可以逃出字符串，但损坏合法搜索词且容易因后续维护失去安全约束。现将搜索词交给 ORM `_complex` 条件，原始 JOIN 只保留整数、枚举及规范化目录前缀；数字搜索保留 fileID 匹配。security 回归验证攻击搜索词不进入原始 JOIN。

其他 SQL：ID、分页、状态取整，扩展名限 a-z0-9，目录前缀只数字/逗号，建表来自固定插件文件。Milvus ID 取整、字符串 JSON 编码。本轮没有确认 SQL 注入，不将潜在风险夸大成已证实漏洞；宿主 ORM/数据库驱动未被全量审计。

## 命令执行及其余边界

生产代码未发现 eval、shell_exec、system、passthru、popen、proc_open 或不可信 unserialize 调用，未确认服务器命令执行。测试中的 eval 只处理固定本地源码以模拟命名空间，proc_open 用于固定 PHP 并发测试，均 CLI-only。模型返回的命令文本不会自动运行。

elasticFulltext 没有独立用户上传端点，读取数据库已有文件；全文 hook 的上层 ACL 依赖宿主 listSearch。当前 Nginx 对 /data/ 和 /config/ 返回 404，未把 TEMP_PATH 中的聊天 JSON 判定为本部署公开泄漏；其它部署必须保持同等限制。新建聊天目录收紧为 0700，未迁移已有记录。

## 功能、架构、性能与 UI

- 修复 asrModels 未下发造成语音页不可用，以及图片生成错误传入聊天模型 ID。
- 普通用户隐藏管理入口；工具开关支持键盘、aria-checked；媒体状态支持屏幕阅读器通知；上传提示格式和大小。
- 新增明确 enableLibrary：管理员、安全 POST、共享索引锁保护，只恢复明确禁用文件，不恢复待删除项；保留旧分片清理范围。原“启用并重新生成”仅调用不会启用的 retry，现已修复。
- 禁用写库失败不再报告成功；“删除文件”改为“移除向量索引”，说明原文件保留、后台可能再次入库；使用真实返回状态显示结果。
- source/fileFlags 不再额外读取完整聊天历史；授权按有界批次执行并复用本请求的已授权副本；响应缓冲增加硬上限。
- 两个可独立安装的插件携带完全一致、class_exists 保护的安全边界类，避免引入安装顺序依赖。
- 保留已有增量流式渲染、长文断点续传和模型隔离。未执行生产负载压测，不能声称全场景“最优”。

## 验证方式与限制

新增 PHP 测试：security、security-upload、security-authorization、security-http、security-archive；新增浏览器 security-ui。最终 224 项 PHP 回归断言、全部 PHP/JavaScript 语法检查通过，包含新增启用操作测试。真实 ES 13 项、Milvus 5 项临时资源集成断言通过；桌面/390px 移动端 UI 与实际浏览器 XSS 回归通过。

```bash
for t in plugins/aiRag/tests/*.php plugins/elasticFulltext/tests/*.php; do php "$t" || exit 1; done
PLUGIN_AUDIT_ES_URL=http://elasticsearch:9200 php plugins/elasticFulltext/tests/integration-es.php
PLUGIN_AUDIT_MILVUS_URL=http://milvus:19530 php plugins/aiRag/tests/integration-milvus.php
NODE_PATH=/path/to/node_modules node plugins/aiRag/tests/ui.cjs
NODE_PATH=/path/to/node_modules node plugins/aiRag/tests/security-ui.cjs
```

浏览器可用 AUDIT_BROWSER_PATH 指定 Chromium；security-ui 的 AUDIT_RENDER_PATH 可指向 security.php 输出的真实 PHP 模板 HTML。集成测试只创建和删除随机测试资源，不修改业务索引/集合。

本审计不是无漏洞保证：未审计全部混淆宿主核心、Tika 或第三方存储驱动；未执行依赖 CVE 数据库扫描、真实多账号渗透、生产压测或模型计费配额系统改造。网络隔离、解析器资源限制和用户业务配额仍须部署层配合。
