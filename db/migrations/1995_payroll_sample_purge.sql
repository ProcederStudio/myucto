DELIMITER //

CREATE OR REPLACE TRIGGER `trg_payroll_employer_policy_delete` BEFORE DELETE ON `payroll_employer_policies`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_fb3d2e81f9768cf8e93480a5'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_runs run
     WHERE run.supplier_id = OLD.supplier_id
       AND run.period_start >= OLD.valid_from
       AND run.period_start <= COALESCE(OLD.valid_to, '9999-12-31')
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll employer policy used by a payroll run cannot be deleted';
  END IF;
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_employer_policy_audit_delete` BEFORE DELETE ON `payroll_employer_policy_audit`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_5ef69db038b546243800129b'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll employer policy audit is append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_enforcement_result_immutable_delete` BEFORE DELETE ON `payroll_enforcement_month_results`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_86e05a109a0afe9e6e819e25'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll enforcement results are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_jmhz_work_month_no_delete` BEFORE DELETE ON `payroll_jmhz_work_month_revisions`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_6614fcea93be3beb4674082a'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'JMHZ work month revisions are immutable';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_office_registration_immutable_delete` BEFORE DELETE ON `payroll_office_registration_versions`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_37acc11391a53211a92a2b19'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  IF EXISTS (
    SELECT 1
      FROM payroll_office_registration_versions
     WHERE supplier_id = OLD.supplier_id
       AND office_id = OLD.office_id
       AND effective_from > OLD.effective_from
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Only the newest payroll office registration version can be removed';
  END IF;
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_payment_liability_immutable_delete` BEFORE DELETE ON `payroll_payment_liabilities`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_2abd61e59f90cee8cf8b1249'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll payment liabilities are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_posting_allocation_immutable_delete` BEFORE DELETE ON `payroll_posting_allocations`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_cec7fb16434f9d80c17452f2'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll posting allocations are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_posting_batch_immutable_delete` BEFORE DELETE ON `payroll_posting_batches`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_fbe58575bf4d3c0369346191'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll posting batches are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_run_revision_immutable_delete` BEFORE DELETE ON `payroll_run_revisions`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_625b80eb22045168c666c6bf'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  IF OLD.status IN ('approved', 'superseded') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Approved payroll run revisions are append-only';
  END IF;
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_statutory_entry_immutable_delete` BEFORE DELETE ON `payroll_statutory_accumulator_entries`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_0c0c48f5c7bbf86f918b5546'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory accumulator entries are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_statutory_opening_immutable_delete` BEFORE DELETE ON `payroll_statutory_accumulator_openings`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_6b2e91b242647f384917829d'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory accumulator openings are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_run_event_append_only_delete` BEFORE DELETE ON `payroll_run_events`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_d1232c6f5d530038aeb7a10a'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  DECLARE allowed_empty_run_delete TINYINT DEFAULT 0;

  IF @payroll_empty_run_delete_supplier_id <=> OLD.supplier_id
     AND @payroll_empty_run_delete_run_id <=> OLD.run_id
     AND OLD.revision_id IS NULL
     AND NOT EXISTS (
       SELECT 1
         FROM payroll_run_revisions revision
        WHERE revision.supplier_id = OLD.supplier_id
          AND revision.run_id = OLD.run_id
     )
     AND NOT EXISTS (
       SELECT 1
         FROM payroll_generated_documents document
        WHERE document.supplier_id = OLD.supplier_id
          AND document.run_id = OLD.run_id
     )
     AND NOT EXISTS (
       SELECT 1
         FROM payroll_posting_batches posting
        WHERE posting.supplier_id = OLD.supplier_id
          AND posting.run_id = OLD.run_id
     ) THEN
    IF @payroll_empty_run_delete_event_id <=> OLD.id
       AND OLD.event_type = 'created'
       AND OLD.from_status IS NULL
       AND OLD.to_status = 'draft'
       AND OLD.reason IS NULL
       AND EXISTS (
         SELECT 1
           FROM payroll_runs run
          WHERE run.supplier_id = OLD.supplier_id
            AND run.id = OLD.run_id
            AND run.row_version = @payroll_empty_run_delete_row_version
            AND run.status IN ('draft', 'cancelled')
            AND (
              (run.status = 'draft' AND run.row_version = 1)
              OR (run.status = 'cancelled' AND run.row_version = 2)
            )
            AND run.current_revision_no = 0
            AND OLD.actor_user_id <=> run.created_by
       )
       AND NOT EXISTS (
         SELECT 1
           FROM payroll_run_commands command_receipt
          WHERE command_receipt.supplier_id = OLD.supplier_id
            AND command_receipt.run_id = OLD.run_id
       ) THEN
      SET allowed_empty_run_delete = 1;
    ELSEIF @payroll_empty_run_delete_cancel_event_id <=> OLD.id
       AND OLD.event_type = 'cancel'
       AND OLD.from_status = 'draft'
       AND OLD.to_status = 'cancelled'
       AND OLD.reason IS NOT NULL
       AND TRIM(OLD.reason) <> ''
       AND EXISTS (
         SELECT 1
           FROM payroll_runs run
           JOIN payroll_run_commands command_receipt
             ON command_receipt.supplier_id = run.supplier_id
            AND command_receipt.run_id = run.id
            AND command_receipt.id =
                @payroll_empty_run_delete_cancel_command_id
          WHERE run.supplier_id = OLD.supplier_id
            AND run.id = OLD.run_id
            AND run.status = 'cancelled'
            AND run.row_version = @payroll_empty_run_delete_row_version
            AND run.row_version = 2
            AND run.current_revision_no = 0
            AND command_receipt.command_name = 'cancel'
            AND command_receipt.revision_id IS NULL
            AND command_receipt.expected_row_version = 1
            AND command_receipt.from_status = 'draft'
            AND command_receipt.to_status = 'cancelled'
            AND command_receipt.actor_user_id <=> run.updated_by
            AND OLD.actor_user_id <=> command_receipt.actor_user_id
            AND JSON_UNQUOTE(
              JSON_EXTRACT(OLD.metadata_json, '$.request_hash')
            ) = command_receipt.request_hash
            AND JSON_UNQUOTE(
              JSON_EXTRACT(
                OLD.metadata_json,
                '$.idempotency_key_hash'
              )
            ) = LOWER(HEX(command_receipt.idempotency_key_hash))
            AND CAST(JSON_UNQUOTE(
              JSON_EXTRACT(OLD.metadata_json, '$.row_version')
            ) AS UNSIGNED) = run.row_version
       ) THEN
      SET allowed_empty_run_delete = 1;
    END IF;
  END IF;

  IF allowed_empty_run_delete = 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Payroll run events are append-only';
  END IF;
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_statutory_person_immutable_delete` BEFORE DELETE ON `payroll_statutory_person_results`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_73bbd88eeac661859dca7360'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory person results are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_statutory_relationship_immutable_delete` BEFORE DELETE ON `payroll_statutory_relationship_results`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_c190154d1a4aec96639793a1'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory relationship results are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_statutory_result_immutable_delete` BEFORE DELETE ON `payroll_statutory_results`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_70041e3667914a17229fe026'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll statutory results are append-only';
END;
  END IF;
END//

CREATE OR REPLACE TRIGGER `trg_payroll_time_month_event_no_delete` BEFORE DELETE ON `payroll_time_month_events`
FOR EACH ROW
BEGIN
  IF NOT (
    @payroll_sample_purge_supplier_id <=> OLD.supplier_id
    AND EXISTS (
      SELECT 1 FROM sample_data_entries sample
       WHERE sample.supplier_id = OLD.supplier_id
         AND sample.entity_type = 'payroll_row_794863eb32759fb390f9c047'
         AND sample.entity_id = OLD.`id`
    )
  ) THEN
    BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Payroll time month events are immutable';
END;
  END IF;
END//

DELIMITER ;
