SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS nonprofit_tax_snapshot_line_locator (
 supplier_id INT UNSIGNED NOT NULL,
 period_id BIGINT UNSIGNED NOT NULL,
 entry_date DATE NOT NULL,
 entry_id BIGINT UNSIGNED NOT NULL,
 line_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(supplier_id,period_id,entry_date,entry_id,line_id),
 UNIQUE KEY uq_ntsll_line(supplier_id,line_id),
 FOREIGN KEY(supplier_id,period_id) REFERENCES accounting_periods(supplier_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(supplier_id,entry_id) REFERENCES journal_entries(supplier_id,id) ON DELETE CASCADE,
 FOREIGN KEY(supplier_id,line_id) REFERENCES journal_entry_lines(supplier_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO nonprofit_tax_snapshot_line_locator(supplier_id,period_id,entry_date,entry_id,line_id)
SELECT l.supplier_id,e.period_id,e.entry_date,e.id,l.id
FROM journal_entry_lines l
JOIN journal_entries e ON e.supplier_id=l.supplier_id AND e.id=l.entry_id;

DROP TRIGGER IF EXISTS trg_ntsll_line_insert;
DROP TRIGGER IF EXISTS trg_ntsll_line_update;
DROP TRIGGER IF EXISTS trg_ntsll_entry_update;

DELIMITER $$
CREATE TRIGGER trg_ntsll_line_insert AFTER INSERT ON journal_entry_lines FOR EACH ROW
BEGIN
 INSERT INTO nonprofit_tax_snapshot_line_locator(supplier_id,period_id,entry_date,entry_id,line_id)
 SELECT NEW.supplier_id,e.period_id,e.entry_date,e.id,NEW.id
 FROM journal_entries e WHERE e.supplier_id=NEW.supplier_id AND e.id=NEW.entry_id;
END$$
CREATE TRIGGER trg_ntsll_line_update AFTER UPDATE ON journal_entry_lines FOR EACH ROW
BEGIN
 IF NOT (OLD.supplier_id <=> NEW.supplier_id) OR NOT (OLD.entry_id <=> NEW.entry_id) OR NOT (OLD.id <=> NEW.id) THEN
  DELETE FROM nonprofit_tax_snapshot_line_locator WHERE supplier_id=OLD.supplier_id AND line_id=OLD.id;
  INSERT INTO nonprofit_tax_snapshot_line_locator(supplier_id,period_id,entry_date,entry_id,line_id)
  SELECT NEW.supplier_id,e.period_id,e.entry_date,e.id,NEW.id
  FROM journal_entries e WHERE e.supplier_id=NEW.supplier_id AND e.id=NEW.entry_id;
 END IF;
END$$
CREATE TRIGGER trg_ntsll_entry_update AFTER UPDATE ON journal_entries FOR EACH ROW
BEGIN
 IF NOT (OLD.period_id <=> NEW.period_id) OR NOT (OLD.entry_date <=> NEW.entry_date) THEN
  UPDATE nonprofit_tax_snapshot_line_locator
  SET period_id=NEW.period_id,entry_date=NEW.entry_date
  WHERE supplier_id=NEW.supplier_id AND entry_id=NEW.id;
 END IF;
END$$
DELIMITER ;
