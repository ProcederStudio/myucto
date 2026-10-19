SET NAMES utf8mb4;

ALTER TABLE nonprofit_tax_assessments ADD COLUMN IF NOT EXISTS usage_manifest_id BIGINT UNSIGNED NULL AFTER coverage_manifest_id;
ALTER TABLE nonprofit_tax_assessments ADD CONSTRAINT fk_nta_usage_manifest FOREIGN KEY IF NOT EXISTS (supplier_id,usage_manifest_id) REFERENCES nonprofit_tax_calculation_manifests(supplier_id,id) ON DELETE RESTRICT;
