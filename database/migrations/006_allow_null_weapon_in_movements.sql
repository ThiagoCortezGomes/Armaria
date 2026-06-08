ALTER TABLE movements DROP FOREIGN KEY movements_ibfk_1;

ALTER TABLE movements
  MODIFY COLUMN weapon_id INT(11) NULL;

ALTER TABLE movements
  ADD CONSTRAINT movements_ibfk_1
  FOREIGN KEY (weapon_id) REFERENCES weapons(id);
