-- v19 (S03.T03.3): CRM columns on parties. Applied automatically by pb_migrate() (pb_mig_19_party_crm).
-- crm_notes / crm_tasks are generic entities (ent_crm_notes, ent_crm_tasks) created from the registry in entities.php.
ALTER TABLE parties ADD COLUMN tags VARCHAR(300) NULL, ADD COLUMN stage VARCHAR(20) NOT NULL DEFAULT 'active';
