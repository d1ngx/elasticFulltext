CREATE TABLE IF NOT EXISTS `plugin_elastic_fulltext_state` (
  `fileID` bigint(20) unsigned NOT NULL,
  `sourceID` bigint(20) unsigned NOT NULL,
  `modifyTime` int(11) unsigned NOT NULL DEFAULT 0,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `error` varchar(1000) NOT NULL DEFAULT '',
  `indexTime` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`fileID`),
  KEY `status` (`status`),
  KEY `indexTime` (`indexTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
