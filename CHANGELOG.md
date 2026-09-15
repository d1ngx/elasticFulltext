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
# 1.3.18

- 去掉运行情况中的「已用时」。

# 1.3.17

- 单个 Office/PDF 提取超时 35 秒后跳过，避免卡死自动任务；失败文件 15 分钟后再重试。

# 1.3.16

- 手动「立即处理一批」会跑满本批；计划任务仍约 50 秒一轮。修复连接断开后任务被中途 abort。

# 1.3.15

- 修复运行中「已用时」一直停在 1 秒。

# 1.3.14

- 运行中固定显示已用时，避免刷新时闪一下。

# 1.3.13

- 「立即处理一批」先返回再后台跑，避免 Nginx 60 秒 504；每轮最多约 50 秒，给每分钟任务留空隙。

# 1.3.12

- 其他设置增加 DEBUG：关闭时不写成功日志。

# 1.3.11

- Elasticsearch 地址说明改为「网盘可访问」；运行情况去掉每批/入库，超限改为过大；最大文件移到基础设置。
- 精简 README。

# 1.3.10

- 运行情况改为两列对齐：扫描/上次各占一行，避免分数换行；进度条文件名单独截断显示。

# 1.3.9

- 运行情况改为两行指标网格，去掉括号说明和重复的「已入库」。

# 1.3.8

- 运行情况不再混用「跳过 / 忽略格式」：空文件或超限、非目标格式分开显示。

# 1.3.7

- 连接测试按钮保持“检测中”至少约 0.9 秒并在旁显示结果；立即处理一批改为进度条刷新，释放会话锁，避免界面长时间无反馈。

# 1.3.6

- 展开 Elasticsearch/Tika 内层解析错误；去掉 Office 包中未声明 Content Type 的 customXml 测试载荷后再索引。

# 1.3.5

- 成功、跳过、失败均写入 Kodbox `elasticFulltext` 日志；运行情况展示最近失败/跳过文件及原因。

# 1.3.4

- 每批文件数按配置生效：保存缺失时保留原值；任务时长随批次数放大，不再被固定 50 秒窗口截成几十个。
- 将该项移到基础设置，运行情况中显示当前「每批上限」。

# 1.3.3

- 配置页打开后持续跟踪并回填当前“运行情况”节点，避免表单重绘后一直停在加载中、必须先点连接测试才显示。
- 后台任务按目标文件计批次，并在约 50 秒窗口内连续扫描，不再把图片等非索引文件算进每批限额。
