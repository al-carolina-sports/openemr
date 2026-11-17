#IfNotRow zdoc_section_policy section_key reason
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('reason',               1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key chief_complaint
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('chief_complaint',      1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key vitals
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('vitals',               1,1,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key allergies
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('allergies',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key medications
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('medications',          1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key problems
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('problems',             1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key family_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('family_hx',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key social_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('social_hx',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key surgical_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('surgical_hx',          1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key past_medical_hx
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('past_medical_hx',      1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key screening
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('screening',            1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key hpi
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('hpi',                  1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key ros
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('ros',                  1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key imaging
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('imaging',              1,0,1,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key pe
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('pe',                   1,0,1,1,'block',
     'I personally performed this examination. Findings not modified above are correct as documented.',
     NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key assessment_plan
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('assessment_plan',      1,0,1,1,'block',
     'The assessment and plan are mine. I performed the medical decision-making for this visit.',
     NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key procedures
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('procedures',           1,0,1,1,'block',
     'I personally performed this procedure as documented.',
     NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key orders
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('orders',               1,0,0,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key time_spent
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('time_spent',           1,1,0,0,'block',NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key patient_instructions
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('patient_instructions', 1,0,0,0,'warn', NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key discussion_notes
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('discussion_notes',     1,0,0,0,'warn', NULL,NOW());
#EndIf

#IfNotRow zdoc_section_policy section_key follow_up
INSERT INTO `zdoc_section_policy`
  (`section_key`,`ma_may_prepopulate`,`ma_may_attest`, `requires_provider_review`,`requires_provider_attestation`, `on_violation`,`attestation_text`,`updated_at`)
VALUES ('follow_up',            1,0,0,0,'warn', NULL,NOW());
#EndIf
