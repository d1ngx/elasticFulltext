CREATE TABLE IF NOT EXISTS "plugin_elastic_fulltext_state" (
  "fileID" INTEGER NOT NULL PRIMARY KEY,
  "sourceID" INTEGER NOT NULL,
  "modifyTime" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 0,
  "error" TEXT NOT NULL DEFAULT '',
  "indexTime" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_elastic_fulltext_status" ON "plugin_elastic_fulltext_state" ("status");
CREATE INDEX IF NOT EXISTS "idx_elastic_fulltext_time" ON "plugin_elastic_fulltext_state" ("indexTime");
