#IfNotRow zdoc_vocabulary vocab_key response_to_treatment
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('response_to_treatment','Response to treatment',
   'Shared response scale. Every element asking how a prior treatment worked MUST use this, so no element is missing a step (06 §3.2). All items are descriptor answers: polarity neutral.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key laterality
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('laterality','Laterality',
   'Structural side attribute. Laterality is NEVER part of a name (06 §3.4). Mirrors are structurally impossible to omit.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key severity_scale
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('severity_scale','Severity scale (0-10)',
   'Integer numeric rating scale, 0 through 10. Used for current pain, worst pain and any other 0-10 slot. 0 is an asserted absence.',
   'integer_range',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key frequency
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('frequency','Frequency',
   'Temporal pattern of a symptom. Descriptors of a symptom asserted elsewhere: polarity neutral.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key onset
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('onset','Onset',
   'How the symptom began. Descriptors: polarity neutral.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key pain_quality
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('pain_quality','Pain quality',
   'Descriptive character of pain. These ASSERT the presence of a quality, so polarity positive.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key reflex_grade
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('reflex_grade','Reflex grade',
   'Deep tendon reflex grading. 2+ is the normal value and is a descriptor (neutral, is_normal = 1), not an asserted absence.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key motor_grade
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('motor_grade','Motor strength grade',
   'Manual muscle testing, 0/5 through 5/5. 5/5 is the normal value and is a descriptor (neutral, is_normal = 1).',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key sensation
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('sensation','Sensation',
   'Sensory examination result. "normal" is a descriptor (neutral, is_normal = 1); decreased, absent and hyperesthetic assert a finding.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key special_test_result
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('special_test_result','Special test result',
   'Provocative test outcome. "negative" IS an asserted absence. "not performed" is a first-class value, distinct from an absent finding, and asserts nothing about the patient.',
   'nominal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key lumbar_levels
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('lumbar_levels','Lumbar and thoracolumbar levels',
   'Anatomical level qualifier for the lumbar region, T12 through S2 (06 §3.5). Referenced by zdoc_template_option.anatomic_level_vocabulary_id.',
   'ordinal',1,NOW(),NOW());
#EndIf

#IfNotRow zdoc_vocabulary vocab_key cervical_levels
INSERT INTO `zdoc_vocabulary`
  (`vocab_key`,`name`,`description`,`value_kind`,`is_active`,`created_at`,`updated_at`)
VALUES ('cervical_levels','Cervical levels',
   'Anatomical level qualifier for the cervical region, C2 through C8 (06 §3.5).',
   'ordinal',1,NOW(),NOW());
#EndIf
