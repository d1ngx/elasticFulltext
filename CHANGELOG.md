# Changelog

## 1.1.0

- Align Kodbox search hook parameters with the official `docSearch` plugin.
- Scan physical `io_file.fileID` records instead of `io_source` references.
- Run the incremental task every minute.
- Add the `explorer.listSearch.fileContentText` compatibility hook.
- Restrict content search to the Elasticsearch `content` field.
- Retain strict post-filtering as a second validation layer.

## 1.0.1

- Remove unrelated Kodbox directory entries from full-text results.
- Fix PHP cURL `HEAD` requests waiting for a nonexistent response body.

## 1.0.0

- Initial release.
# 1.3.0

- 启用、禁用和保存配置不再同步访问 Elasticsearch 或重启任务进程，解决界面持续“操作中”和必须刷新才生效的问题。
- 配置页按官方 docSearch 分为基础设置和其他设置，服务状态改为异步检测。
- 支持允许/禁止两种文件格式策略，格式使用标签控件编辑。
- 状态页增加扫描游标、待扫描估算、成功/跳过/待重试数量和手动任务入口。
- 后台任务优先重试失败文件，避免扫描游标越过后永久遗漏。
# 1.3.1

- 配置页明确分为基础设置、其他设置，移除使用权限、候选结果和 HTTPS 验证三个非必要界面项。
- 统一并缩小连接测试与任务按钮，状态检测失败时在短超时后显示明确错误。
# 1.3.2

- 修正 `formStyle` 所在层级，使 Kodbox 正确显示“基础设置 / 其他设置”页签。
- 参考官方 docSearch，在每次打开配置窗口后自动异步获取运行状态，不需要先点击连接测试。
