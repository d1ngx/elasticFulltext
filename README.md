# Kodbox Elasticsearch Full-text Search

为 Kodbox 提供 PDF、Office 与文本文件的全文搜索。设计参考 Nextcloud Full Text Search 的分层方式，但代码为独立实现：Kodbox 管理文件、目录与访问权限，Elasticsearch `ingest-attachment`（Apache Tika）负责内容提取和检索。

当前版本：`1.3.7`

已验证环境：

- Kodbox `1.69.03`
- Elasticsearch `8.19.4` 官方镜像
- MariaDB 与 SQLite 状态表
- Docker Compose 部署

## 功能

- 支持 PDF、Word、Excel、PowerPoint、OpenDocument、RTF、EPUB 和常见文本格式。
- 每分钟增量扫描一批目标文件（自动跳过图片等非索引格式）；修改时间未变化的文件不会重复上传。
- 删除后的物理文件会在一轮扫描结束后从索引清理。
- 直接接管 Kodbox 原生“文件内容”搜索；ES 只返回 `fileID` 候选，最终结果仍由 Kodbox 核心按当前目录和用户权限过滤。
- 对 Kodbox 返回结果再次执行严格的 `fileID` 交集过滤，避免当前目录中的无关文件混入结果。
- 管理页提供连接测试、立即执行一批、重建索引和状态统计。
- 管理页参照官方 `docSearch` 分为基础设置和其他设置；服务状态异步检测，不阻塞配置读取或保存。
- 启停和保存仅更新本地状态与计划任务，不同步连接 Elasticsearch。
- 后台任务使用进程锁避免重叠执行，并在扫描新文件前优先重试失败文件。
- 支持“允许格式”和“禁止格式”两种格式策略。

## 支持的格式

默认配置包括：

```text
txt, md, log, csv, json, xml, html, htm,
pdf, doc, docx, xls, xlsx, ppt, pptx,
odt, ods, odp, rtf, epub
```

纯文本由插件直接读取；PDF、Office、OpenDocument、RTF 和 EPUB 由 Elasticsearch attachment processor 调用 Apache Tika 提取。

## 工作流程

```text
Kodbox 文件
    │
    ├─ 纯文本 ────────────────┐
    │                         │
    └─ PDF / Office ─ Base64 ─┼─> Elasticsearch ingest pipeline
                              │        │
                              │        └─ Apache Tika 提取正文
                              ▼
                       kodbox-fulltext 索引
                              │
Kodbox“文件内容”搜索 ──────────┘
    │
    ├─ Elasticsearch 返回候选 fileID
    ├─ Kodbox 按用户权限和目录范围过滤
    └─ 插件严格过滤结果并补充正文摘要
```

## 前置条件

- PHP 已启用 `curl` 和 `mbstring` 扩展。
- Kodbox 容器能够通过 HTTP 访问 Elasticsearch。
- Elasticsearch 使用包含 attachment processor 的官方发行版。
- Elasticsearch JVM 与 Kodbox PHP 有足够内存处理设定的最大文件大小。

## 安装

1. 将整个 `elasticFulltext` 目录复制到 Kodbox 的 `/var/www/html/plugins/elasticFulltext`。目录名必须是 `elasticFulltext`。
2. 确保 Kodbox 容器与 Elasticsearch 位于同一个 Docker 网络。例如 Elasticsearch 已连接外部网络 `nextcloud-backend` 时，Kodbox Compose 可添加：

   ```yaml
   services:
     app:
       networks:
         - default
         - nextcloud-backend

   networks:
     nextcloud-backend:
       external: true
   ```

   应用网络变更：

   ```bash
   docker compose up -d
   docker exec kodbox-app-1 curl -fsS http://elasticsearch:9200
   ```

3. 在 Kodbox 管理后台启用“Elasticsearch 全文搜索”。
4. 打开插件配置，确认 Elasticsearch 地址和索引名，然后保存。
5. 点击“测试连接”，再点击“立即处理一批”或“重建索引”。后台任务会继续处理其余文件。
6. 刷新 Kodbox 页面，使用右上角搜索框，并在高级筛选中勾选“文件内容”。

也可以从本目录的父目录安装 ZIP 包。解压后务必保证结构是 `plugins/elasticFulltext/app.php`，不要多套一层目录。

## 运行原理

PDF/Office 文件以 Base64 发送给 Elasticsearch 的 `kodbox-attachment` ingest pipeline。该 pipeline 使用内置 attachment processor（Apache Tika）提取纯文本，移除原始二进制，再写入 `kodbox-fulltext`。纯文本文件直接写入 `content` 字段。

搜索流程：Kodbox `explorer.listSearch.searchDataBefore` 钩子查询 Elasticsearch → 得到物理 `fileID` → Kodbox `Source::listSearch()` 在当前已授权目录中筛选 → `searchDataAfter` 钩子补充摘要。

插件不会把 Kodbox 用户权限复制到 Elasticsearch。Elasticsearch 只保存物理文件 ID 和索引内容，不直接向浏览器提供搜索结果；最终列表始终经过 Kodbox 当前用户的目录与权限检查。

## 配置项

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| Elasticsearch URL | `http://elasticsearch:9200` | 从 Kodbox 容器内部可访问的地址 |
| Index | `kodbox-fulltext` | 插件独立使用的索引名 |
| 索引扩展名 | 见“支持的格式” | 使用英文逗号分隔 |
| 最大文件大小 | `50 MB` | 超过限制的文件标记为跳过 |
| 每批文件数 | `50` | 每次任务要索引的目标文件数（1–500）；非匹配扩展名不计入 |
| 最大候选结果 | `1000` | ES 返回后仍会经过 Kodbox 权限过滤 |
| 验证 HTTPS 证书 | 开启 | 仅对 HTTPS 地址生效 |

## 增量索引

插件每分钟运行一次计划任务，并参考官方 `docSearch` 的方式按物理文件表 `io_file.fileID` 扫描。每次任务会跳过非目标格式，直到索引满配置的批次或用完约 50 秒时间窗口：

- 文件修改时间未变化时不会重复发送到 Elasticsearch。
- 修改后的文件会重新提取和覆盖原文档。
- 每个物理文件只处理一次，不会因多个 `io_source` 引用而重复索引。
- 完成一轮扫描后，已从 Kodbox 删除的物理文件会从 ES 清理。
- 失败、跳过、成功都会写入 Kodbox 的 `elasticFulltext` 日志；配置页「运行情况」会列出最近失败和跳过的文件。
- 日志位于 Kodbox 后台日志的 `elasticFulltext` 分类（成功为 info，跳过为 warning，失败为 error）。

首次安装建议点击一次“重建索引”，然后等待后台任务完成剩余批次。

## 故障排查

### 插件启用后按钮没有反应

插件前端脚本在 Kodbox 页面加载时注入。启用或更新插件后按 `Ctrl+F5` 强制刷新，再打开插件配置。

### 运行情况一直转圈

升级到 `1.3.3` 后打开配置即可自动刷新，不必先点“连接测试”。若仍无数据，确认计划任务已开启，并查看状态里的“上次任务”时间。

### 自动索引一天只有几百个

旧版本把图片等非目标文件也算进每批限额。`1.3.3` 改为只统计真正索引的文档，并在约 50 秒内连续扫描。更新插件后保存一次配置，让计划任务重新注册。后台管理里确认该任务为“每 1 分钟”。

### 无法连接 Elasticsearch

在 Kodbox 容器中测试，而不是只在宿主机测试：

```bash
docker exec kodbox-app-1 curl -fsS http://elasticsearch:9200
```

若主机名无法解析，检查两个容器是否加入相同 Docker 网络：

```bash
docker inspect kodbox-app-1 --format '{{json .NetworkSettings.Networks}}'
docker inspect elasticsearch --format '{{json .NetworkSettings.Networks}}'
```

### 能看到正确摘要，但混入无关文件

请升级到 `1.0.1` 或更高版本。`1.0.1` 增加了返回阶段的严格 `fileID` 交集过滤；`1.1.0` 进一步保留 Kodbox 原始搜索参数，与官方 `docSearch` 的过滤流程保持一致。

### 中文搜索结果不理想

默认使用 Elasticsearch standard analyzer，不依赖第三方插件。它可以检索中文，但不会进行面向中文语义的精细分词。需要更自然的中文分词时，可安装与 ES 版本严格匹配的 IK 插件，并在重建索引前调整 mapping。

### 查看状态

```bash
curl http://elasticsearch:9200/_cat/indices/kodbox-fulltext?v
curl http://elasticsearch:9200/kodbox-fulltext/_count
curl http://elasticsearch:9200/_ingest/pipeline/kodbox-attachment?pretty
```

## 注意事项

- ES 8.x 官方发行版已包含 ingest-attachment 模块；若使用裁剪版镜像，请先确认 `_nodes/plugins`/模块清单中存在 attachment processor。
- 大文件会在 PHP 中读取并 Base64 编码，峰值内存可能达到文件大小的数倍。默认上限 50 MB，可按容器内存调整。
- 当前版本使用 Elasticsearch 标准分析器。中文可以搜索，但如需更自然的中文分词，可另装 IK 分词器并在新索引 mapping 中配置 analyzer。
- “重建索引”只删除本插件配置的索引，不会触碰 Nextcloud 的索引。
- 日志位于 Kodbox 后台日志的 `elasticFulltext` 分类（成功为 info，跳过为 warning，失败为 error）。配置页也会显示最近失败/跳过明细。
- 索引中包含从文件提取的正文，应把 Elasticsearch 保留在可信内网，不要无认证暴露到公网。

## 目录结构

```text
elasticFulltext/
├── app.php                         # Kodbox 插件入口、搜索钩子和计划任务
├── package.json                    # 插件元数据和配置表单
├── lib/
│   ├── ElasticClient.class.php     # Elasticsearch HTTP 客户端
│   └── data/
│       ├── mysql.sql               # MariaDB/MySQL 状态表
│       └── sqlite.sql              # SQLite 状态表
├── i18n/
│   ├── zh-CN.php
│   └── en.php
└── static/
    ├── admin.js
    └── icon.svg
```

## 开发与检查

修改后至少执行 PHP 和 JSON 语法检查：

```bash
php -l app.php
php -l lib/ElasticClient.class.php
php -r 'json_decode(file_get_contents("package.json"), true, 512, JSON_THROW_ON_ERROR);'
```

本项目不是 Kodbox 官方插件，也不包含 Nextcloud 插件源码。

## 版本记录

### 1.1.0

- 参考 Kodbox 官方 `docSearch 1.51` 调整搜索钩子调用方式。
- 保留原始 `words` 与 `content` 条件，只给 Kodbox 附加 Elasticsearch 候选 `fileID`。
- 索引扫描源由 `io_source` 改为物理文件表 `io_file`，避免共享/引用导致重复处理。
- 后台任务周期调整为每分钟一次。
- 新增 `explorer.listSearch.fileContentText` 兼容钩子。
- “文件内容”搜索仅查询正文，不再因为文件名命中而混入结果。
- 保留返回阶段的严格候选交集过滤，作为 Kodbox 核心过滤后的第二层校验。

### 1.0.1

- 修复 Kodbox 忽略候选列表时混入当前目录无关文件的问题。
- 修复 PHP cURL `HEAD` 请求可能等待响应体直至超时的问题。

### 1.0.0

- 首个可用版本，支持 Elasticsearch attachment processor、增量索引和正文摘要。

## 卸载

禁用插件会停用计划任务。卸载插件不会自动删除 Elasticsearch 索引，避免误删数据；如确认不再使用，可手动执行：

```bash
curl -X DELETE http://elasticsearch:9200/kodbox-fulltext
```
