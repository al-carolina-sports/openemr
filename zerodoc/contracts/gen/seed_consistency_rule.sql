#IfNotRow zdoc_consistency_rule rule_key nkda_vs_active_allergy
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('nkda_vs_active_allergy',
   'NKDA asserted alongside an active allergy',
   'error',
   '{"assertion":"nkda","equals":true}',
   '{"exists":{"table":"zdoc_allergy","status":"active"}}',
   'No-known-drug-allergies is asserted while this patient has an active allergy on file. Resolve before signing.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key hpi_numbness_vs_ros_no_numbness
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('hpi_numbness_vs_ros_no_numbness',
   'HPI numbness or radiation vs ROS "no numbness" / "no shooting pain"',
   'error',
   '{"section":"hpi","element_label":"Associated Symptoms","option_label_any":["numbness","radiation"]}',
   '{"section":"ros","element_label":"Neurological","option_label_any":["no numbness","no shooting pain"]}',
   'The history documents numbness or radiation while the review of systems denies it.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key hpi_weakness_vs_no_weakness
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('hpi_weakness_vs_no_weakness',
   'HPI weakness vs ROS/PE "no weakness" or normal motor strength',
   'error',
   '{"section":"hpi","element_label":"Associated Symptoms","option_label_any":["weakness"]}',
   '{"section_any":["ros","pe"],"option_label_any":["no weakness","5/5"]}',
   'The history documents weakness while the review of systems or exam denies it.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key template_sex_age_mismatch
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('template_sex_age_mismatch',
   'Template applied that does not match the patient sex or age',
   'error',
   '{"event":"template_applied"}',
   '{"check":"template_demographic_match"}',
   'A template was applied whose sex or age range does not match this patient.',
   1,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key diagnosis_without_supporting_content
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('diagnosis_without_supporting_content',
   'A/P diagnosis with no corresponding PE or HPI content',
   'warning',
   '{"section":"assessment_plan","each":"diagnosis"}',
   '{"absent_support_in":["hpi","pe"]}',
   'This assessment has no supporting history or examination content documented.',
   0,1,1,1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_consistency_rule rule_key active_problem_absent_from_ap
INSERT INTO `zdoc_consistency_rule`
  (`rule_key`,`name`,`severity`,`when_jsonb`,`then_conflicts_jsonb`,`message`, `evaluate_on_change`,`evaluate_at_signoff`,`is_system_rule`,`is_active`, `created_at`,`updated_at`)
VALUES ('active_problem_absent_from_ap',
   'Active problem absent from the A/P',
   'warning',
   '{"exists":{"table":"zdoc_problem","status":"active"}}',
   '{"absent_in":{"table":"zdoc_encounter_diagnosis"}}',
   'An active problem on the problem list does not appear in this assessment and plan.',
   0,1,1,1,NOW(),NOW());
#EndIf
