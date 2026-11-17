--
-- ZeroDoc Clinical Encounter Workflow -- manual teardown.
--
-- OpenEMR NEVER runs this file. Run it by hand only, and only when you really
-- want ZeroDoc's data gone. Unregistering the module disables the forms and
-- drops `zdoc_schema_version`; it deliberately leaves everything that holds
-- patient data in place, because saved encounter notes are part of the
-- medical record.
--
-- Back up first (see DEPLOY.md: ~/openemr-backups/ on the VM).
--

-- Hide ZeroDoc's encounter forms.
UPDATE `registry` SET `state` = 0 WHERE `directory` IN ('zdoc_encounter');

-- Module bookkeeping.
DROP TABLE IF EXISTS `zdoc_schema_version`;

-- ============================================================================
-- BEGIN ZeroDoc clinical teardown (owned by the contracts agent)
--
-- Add one `DROP TABLE IF EXISTS` per `zdoc_*` table, children before parents.
-- Keep the form tables LAST and clearly marked, since dropping them destroys
-- signed clinical notes.
-- ============================================================================

--
-- Children before parents: the exact reverse of the install order, so every
-- FK is gone before its target. FOREIGN_KEY_CHECKS is deliberately NOT
-- disabled -- if a drop fails here something still references it, and you
-- want to know that rather than silently orphan it.
--
-- EVERY TABLE BELOW MAY HOLD PATIENT DATA. Back up first (see DEPLOY.md).
-- zdoc_finding, zdoc_encounter_section, zdoc_encounter_diagnosis,
-- zdoc_thread_entry and zdoc_encounter_procedure hold the clinical note
-- itself; zdoc_allergy and zdoc_patient_allergy_assertion hold the allergy
-- record. Dropping these destroys signed medical records.
--
DROP TABLE IF EXISTS `zdoc_consistency_violation`;
DROP TABLE IF EXISTS `zdoc_consistency_rule`;
DROP TABLE IF EXISTS `zdoc_revenue_cycle_task`;
DROP TABLE IF EXISTS `zdoc_bundling_edit`;
DROP TABLE IF EXISTS `zdoc_claim_line`;
DROP TABLE IF EXISTS `zdoc_claim`;
DROP TABLE IF EXISTS `zdoc_em_time_code_rule`;
DROP TABLE IF EXISTS `zdoc_billing_code_revision`;
DROP TABLE IF EXISTS `zdoc_billing_task`;
DROP TABLE IF EXISTS `zdoc_encounter_time`;
DROP TABLE IF EXISTS `zdoc_encounter_plan_selection`;
DROP TABLE IF EXISTS `zdoc_encounter_procedure`;
DROP TABLE IF EXISTS `zdoc_dispatch_task`;
DROP TABLE IF EXISTS `zdoc_order`;
DROP TABLE IF EXISTS `zdoc_encounter_diagnosis`;
DROP TABLE IF EXISTS `zdoc_order_set_item`;
DROP TABLE IF EXISTS `zdoc_encounter_education`;
DROP TABLE IF EXISTS `zdoc_education_item`;
DROP TABLE IF EXISTS `zdoc_encounter_consent`;
DROP TABLE IF EXISTS `zdoc_plan_option`;
DROP TABLE IF EXISTS `zdoc_procedure_type`;
DROP TABLE IF EXISTS `zdoc_order_type`;
DROP TABLE IF EXISTS `zdoc_receiver`;
DROP TABLE IF EXISTS `zdoc_payer_auth_rule`;
DROP TABLE IF EXISTS `zdoc_consent_type`;
DROP TABLE IF EXISTS `zdoc_plan_group_code`;
DROP TABLE IF EXISTS `zdoc_diagnosis_set_item`;
DROP TABLE IF EXISTS `zdoc_plan_group`;
DROP TABLE IF EXISTS `zdoc_order_set`;
DROP TABLE IF EXISTS `zdoc_diagnosis_set`;
DROP TABLE IF EXISTS `zdoc_section_flag`;
DROP TABLE IF EXISTS `zdoc_medication`;
DROP TABLE IF EXISTS `zdoc_external_source`;
DROP TABLE IF EXISTS `zdoc_patient_allergy_assertion`;
DROP TABLE IF EXISTS `zdoc_allergy`;
DROP TABLE IF EXISTS `zdoc_vital_reading`;
DROP TABLE IF EXISTS `zdoc_finding`;
DROP TABLE IF EXISTS `zdoc_thread_entry`;
DROP TABLE IF EXISTS `zdoc_problem_thread`;
DROP TABLE IF EXISTS `zdoc_problem`;
DROP TABLE IF EXISTS `zdoc_section_policy`;
DROP TABLE IF EXISTS `zdoc_section_attribution`;
DROP TABLE IF EXISTS `zdoc_encounter_section`;
DROP TABLE IF EXISTS `zdoc_encounter_ext`;
DROP TABLE IF EXISTS `zdoc_encounter_plan_item`;
DROP TABLE IF EXISTS `zdoc_encounter_plan`;
DROP TABLE IF EXISTS `zdoc_reason_for_visit`;
DROP TABLE IF EXISTS `zdoc_macro`;
DROP TABLE IF EXISTS `zdoc_saved_finding_preset`;
DROP TABLE IF EXISTS `zdoc_saved_finding_template`;
DROP TABLE IF EXISTS `zdoc_saved_finding`;
DROP TABLE IF EXISTS `zdoc_template_option`;
DROP TABLE IF EXISTS `zdoc_template_element`;
DROP TABLE IF EXISTS `zdoc_template_group`;
DROP TABLE IF EXISTS `zdoc_template`;
DROP TABLE IF EXISTS `zdoc_vocabulary_item`;
DROP TABLE IF EXISTS `zdoc_vocabulary`;
DROP TABLE IF EXISTS `zdoc_warning_dismissal`;
DROP TABLE IF EXISTS `zdoc_usage_stat`;
DROP TABLE IF EXISTS `zdoc_application`;
DROP TABLE IF EXISTS `zdoc_audit_event`;

-- The clinical schema revision row. The module's unregister hook drops the
-- whole bookkeeping table; this line is for tearing down only the clinical
-- schema while leaving the module registered.
DELETE FROM `zdoc_schema_version` WHERE `component` = 'clinical';

-- The one column added to the scaffold's form table. Drop only if removing
-- the clinical schema but keeping the stub form.
-- ALTER TABLE `form_zdoc_encounter` DROP COLUMN `encounter_ext_id`;

-- Encounter form data -- destroys clinical notes. Uncomment deliberately.
-- DELETE FROM `forms` WHERE `formdir` IN ('zdoc_encounter');
-- DROP TABLE IF EXISTS `form_zdoc_encounter`;
-- DELETE FROM `registry` WHERE `directory` IN ('zdoc_encounter');

-- ============================================================================
-- END ZeroDoc clinical teardown
-- ============================================================================
