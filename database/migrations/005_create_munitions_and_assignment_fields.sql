CREATE TABLE IF NOT EXISTS munitions (
  id INT NOT NULL AUTO_INCREMENT,
  calibre VARCHAR(40) NOT NULL,
  tipo VARCHAR(60) NOT NULL,
  quantidade INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_munitions_calibre_tipo (calibre, tipo)
);

ALTER TABLE current_assignments
  ADD COLUMN IF NOT EXISTS ammo_id INT NULL,
  ADD COLUMN IF NOT EXISTS ammo_qty INT NOT NULL DEFAULT 0;

ALTER TABLE current_assignments
  ADD INDEX IF NOT EXISTS idx_current_assignments_ammo_id (ammo_id);

ALTER TABLE current_assignments
  ADD CONSTRAINT fk_current_assignments_ammo
  FOREIGN KEY (ammo_id) REFERENCES munitions(id)
  ON DELETE SET NULL;
