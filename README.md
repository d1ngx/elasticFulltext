# Elasticsearch 全文搜索

为网盘中的 PDF、Office、文本提供全文检索。权限和目录过滤仍由网盘完成，Elasticsearch `ingest-attachment`（Apache Tika）只负责抽正文和检索。

当前版本 **1.3.18**。已在 Kodbox 1.69.03、Elasticsearch 8.19.4、Docker 环境下验证。

## 功能

- PDF / Word / Excel / PPT / OpenDocument / RTF / EPUB / 常见文本
- 每分钟增量索引；内容未变不重复上传；删除的文件会在一轮扫描后从索引清理
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
| 最大文件 | `50 MB` | 超过则跳过 |
| 格式策略 | 允许格式 | 在其他设置中切换禁止列表 |
| DEBUG | 关 | 打开后才记录成功日志 |

「立即处理一批」会尽量跑满本批；日常扫库用自动任务即可。单个 Office/PDF 超过约 35 秒会记失败并继续，15 分钟后再重试。

## 说明

- 官方 ES 8 已带 ingest-attachment；裁剪镜像需自行确认。
- 大文件会在 PHP 里读入并 Base64，请按内存调整上限。
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
