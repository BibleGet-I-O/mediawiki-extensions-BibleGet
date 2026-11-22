-- The bibleget_notice statement of tables-generated.sql (from sql/tables.json via generateSchemaSql),
-- for wikis whose bibleget_installation table already exists. Regenerate it with that file.
CREATE TABLE bibleget_notice (
  bn_id VARCHAR(64) NOT NULL,
  bn_message TEXT NOT NULL,
  bn_link TEXT DEFAULT NULL,
  bn_first_seen TIMESTAMPTZ NOT NULL,
  PRIMARY KEY(bn_id)
);
