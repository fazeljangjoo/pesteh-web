-- v13 (S01.T01.1): lots point to warehouses / ounce grades by uid. Also applied automatically by pb_migrate().
-- Tables ent_warehouses / ent_ounces are created by the entity registry sync (migrations.php), not here.
ALTER TABLE purchases
  ADD COLUMN warehouse_uid CHAR(36) NULL,
  ADD COLUMN ounce_uid CHAR(36) NULL,
  ADD KEY idx_pur_wh (customer_id, warehouse_uid);
