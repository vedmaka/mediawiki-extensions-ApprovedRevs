CREATE TABLE /*_*/approved_revs_stats
(
    row_id int NOT NULL PRIMARY KEY DEFAULT 1,
    total int DEFAULT 0,
    not_latest int DEFAULT 0,
    unapproved int DEFAULT 0,
    invalid int DEFAULT 0,
    time_updated int NOT NULL
) /* $wgDbTableOptions */;