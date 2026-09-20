# Elasticsearch 全文搜索

为网盘中的 PDF、Office、文本提供全文检索。权限和目录过滤仍由网盘完成，Elasticsearch `ingest-attachment`（Apache Tika）只负责抽正文和检索。

当前版本 **1.5.0**。已在 Kodbox 1.69.03、Elasticsearch 8.19.4、Docker 环境下验证。

## 功能

- PDF / Word / Excel / PPT / OpenDocument / RTF / EPUB / 常见文本
- 每分钟增量索引；内容未变不重复上传；删除的文件会在一轮扫描后从索引清理
- 维护唯一正文索引 `kodbox-fulltext`：AIRAG 直接读取，用于 BM25 检索与切片向量，不再复制到第二套 ES 索引。
- 接管「文件内容」搜索，结果按当前目录和用户权限过滤
- 允许格式 / 禁止格式；DEBUG 关闭时不写成功日志

## 安装

1. 把 `elasticFulltext` 目录放到网盘 `plugins/elasticFulltext`（目录名必须一致）。
2. 网盘需要能访问 Elasticsearch，例如同一 Docker 网络：`http://elasticsearch:9200`。
3. 后台启用插件，填写地址并「连接测试」，保存后靠每分钟任务自动扫库。
4. 刷新页面，搜索时勾选「文件内容」。

```bash
docker exec kodbox-app-1 curl -fsS http://elasticsearch:9200
```

## 配置

| 项 | 默认 | 说明 |
| --- | --- | --- |
| Elasticsearch URL | `http://elasticsearch:9200` | 网盘可访问的地址 |
| 索引名称 | `kodbox-fulltext` | 小写字母、数字、点、横线、下划线 |
| 每批文件数 | `50` | 1–500，自动任务每轮约 50 秒、能编多少算多少 |
| 最大文件 | `30 MB` | Office/PDF 会完整读入 PHP 并 Base64 编码，超过则跳过 |
| 最大提取字符 | `1,000,000` | 每篇正文上限；修改后自动重新核对并提取 |
| 格式策略 | 允许格式 | 在其他设置中切换禁止列表 |
| DEBUG | 关 | 打开后才记录成功日志 |

「立即处理一批」会尽量跑满本批；日常扫库用自动任务即可。单个 Office/PDF 超过约 35 秒会记失败并继续，15 分钟后再重试。

## 与 AIRAG 的分工

```text
原始文件 → 本插件 / Tika → kodbox-fulltext
                           ├─ 网盘全文搜索 / AIRAG BM25
                           └─ AIRAG 只读 → 切片 → Embedding → Milvus
```

本插件独占正文的提取、写入和清理；两插件分别维护自己的状态表。
AIRAG 扫描仅检查正文就绪与版本，不写入第二套 ES 索引。正文携带 `extractVersion`，其中包含提取器代次和字符上限；两插件版本不匹配时，AIRAG 会等待本插件更新正文，不会把旧正文写入 Milvus。
正文缺失/过期时 AIRAG 等待；请协调两插件的格式和大小限制。
重建使用与 AIRAG 相同的重任务锁，期间关键词检索会受影响。
提取规则变化后，本插件会自动重新扫描；正文更新完成后 AIRAG 会按内容哈希更新对应分片和向量，无需删除共享正文。
停用本插件不删除已有正文，但新文件与更新文件停止提取。

## 运行情况计数

| 项 | 含义 |
| --- | --- |
| 网盘文件 | `io_file` 当前物理文件数 |
| 可索引 | 扩展名在允许列表中的文件 |
| 已入库 | 状态为成功且文件仍存在 |
| 待处理 | 可索引 − 已入库 − 过大 − 失败 |
| 非文档 | 网盘文件 − 可索引（图片、视频等） |
| 待扫描 | 扫描游标之后还未走过的物理文件数，不是 `max(fileID) − 游标` |
| ES 文档 | Elasticsearch 篇数；高于「已入库」时多为已删除文件待清理 |

## 说明

- 官方 ES 8 已带 ingest-attachment；裁剪镜像需自行确认。
- 大文件会在 PHP 里读入并 Base64，请按内存调整上限。
- 本插件与 AIRAG 共用重任务锁及背压状态。MariaDB 脏页、checkpoint、空闲页或主机内存超过阈值时暂停；运行情况会显示原因，指标回落到恢复阈值后由下一分钟任务自动继续。
- 重建索引只删除本插件使用的索引。
- 关闭 DEBUG 时，失败和过大仍会写入网盘日志分类 `elasticFulltext`。
- Elasticsearch 应放在可信内网，不要无认证暴露到公网。

更新插件后请 `Ctrl+F5` 再打开配置。连不上 ES 时，在网盘容器内 curl，确认与 ES 同一网络。

## 卸载

禁用插件会停掉计划任务，不会自动删除 ES 数据：

```bash
curl -X DELETE http://elasticsearch:9200/kodbox-fulltext
```

详细变更见 [CHANGELOG.md](CHANGELOG.md)。
