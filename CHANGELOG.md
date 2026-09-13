# Changelog

## 1.2.0

- Sanitize Elasticsearch highlight snippets as plain text, matching official `docSearch` output.
- Use `searchTextFile` for text files and `searchContentMatch` for PDF/Office.
- Hide duplicate type icons in search snippets when no real cover image exists.

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
