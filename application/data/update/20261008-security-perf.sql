-- ============================================================================
-- MacCMS v10 安全/性能整改 批次E 数据库迁移（2026-10-08）
-- 【需 DBA 先执行】本文件不会被程序自动执行，应用层也不会加载它。
-- 执行前必须先备份数据库；以下语句每句独立，可逐条执行、逐条回滚。
-- 幂等性：MODIFY 可重复执行；ADD INDEX 不可重复执行（索引已存在时报 ERROR 1061 Duplicate key name），
--         执行前请用 SHOW INDEX FROM `mac_vod`; 等方法确认同名索引是否已存在，已存在则跳过该句。
-- 引擎与锁：mac_admin/mac_user/mac_vod/mac_art/mac_website/mac_comment 当前均为 MyISAM，
--         MyISAM 支持直接 ALTER，但 ALTER 期间是表级锁（该表不可写），大表请在低峰期执行；
--         若后续迁移到 InnoDB，同样建议用 pt-online-schema-change / gh-ost 等在线工具。
-- 表前缀：以 application/extra/maccms.php 的 db.tablepre 为准（默认 mac_），如已改前缀请同步替换。
-- ============================================================================


-- 1. 口令列扩容：为 password_hash()/password_verify() 迁移预留长度（bcrypt 为 60 字符，argon2id 更长）
--    说明：仅扩容列长度，不影响现有 MD5 值（32 字符）的读取；应用侧"登录成功即升级"的迁移逻辑另行确认。
ALTER TABLE `mac_admin` MODIFY `admin_pwd` VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE `mac_user` MODIFY `user_pwd` VARCHAR(255) NOT NULL DEFAULT '';


-- 2. 组合索引：列表页常见的 status + type_id + order by time/hits 组合，避免 type=ALL + filesort
--    mac_vod：视频列表/分类页（按更新时间、按人气、按添加时间）
ALTER TABLE `mac_vod` ADD INDEX `vod_status_type_time` (`vod_status`,`type_id`,`vod_time`);
ALTER TABLE `mac_vod` ADD INDEX `vod_status_type_hits` (`vod_status`,`type_id`,`vod_hits`);
ALTER TABLE `mac_vod` ADD INDEX `vod_status_time_add` (`vod_status`,`vod_time_add`);
--    mac_art：文章列表/分类页
ALTER TABLE `mac_art` ADD INDEX `art_status_type_time` (`art_status`,`type_id`,`art_time`);
--    mac_website：网址列表/分类页
ALTER TABLE `mac_website` ADD INDEX `website_status_type_time` (`website_status`,`type_id`,`website_time`);
--    mac_user：会员按邮箱/手机号/状态检索（注册唯一性校验、后台筛选）
ALTER TABLE `mac_user` ADD INDEX `user_email` (`user_email`);
ALTER TABLE `mac_user` ADD INDEX `user_phone` (`user_phone`);
ALTER TABLE `mac_user` ADD INDEX `user_status` (`user_status`);
--    mac_comment：评论列表（按内容ID+回复ID+审核状态）
ALTER TABLE `mac_comment` ADD INDEX `comment_mid_rid_status` (`comment_mid`,`comment_rid`,`comment_status`);


-- 3. 可选后续项（本次不执行，需单独评估、单独变更窗口）
--  a. 前台中缀模糊搜索（vod_name LIKE '%关键词%'）无法命中 B+Tree 索引：
--     可评估 InnoDB + FULLTEXT(ngram) 方案，例如
--       ALTER TABLE `mac_vod` ENGINE=InnoDB;
--       ALTER TABLE `mac_vod` ADD FULLTEXT INDEX `ft_vod_name` (`vod_name`) WITH PARSER ngram;
--     注意事项：ngram 需要 MySQL 5.7.6+ 且 ngram_token_size 影响最小检索词长度；MyISAM 的 FULLTEXT 不支持 ngram 解析器，
--     且 MyISAM 表级锁不适合高并发写入，故与 b 项应一并评估；应用侧需同步改造搜索 SQL（见 VodSearch 搜索优化开关）。
--  b. 内容表迁移 InnoDB：解决 hits 计数与采集并发下的表级锁阻塞；迁移前需评估事务/崩溃恢复行为变化、
--     AUTO_INCREMENT 保持、磁盘占用（约 MyISAM 的 1.5~2 倍）以及停机窗口。
--  c. 冗余单列索引清理：mac_vod/mac_art/mac_website 现有 25~30 个单列索引，新增上述组合索引后
--     vod_time/vod_hits/vod_time_add/type_id 等单列索引可能冗余（写放大），需先 EXPLAIN 复核执行计划，
--     并观察一段时间 slow log 后再 DROP INDEX，切勿与本次变更同批执行。
--  d. application/install/sql/install.sql 未同步本次索引与列变更（该文件不在本批次分配清单内），
--     全新安装的库需另行同步，否则新装站点仍缺这些索引。
