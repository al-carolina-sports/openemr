-- ZeroDoc Clinical Encounter Workflow - SQLite test schema.
--
-- THIS IS A TEST HARNESS, NOT THE PRODUCTION DDL. The real migrations are owned
-- by the contracts package; this file exists so the importer, the exporter and
-- the rollback behaviour can be tested with no database server. Column names
-- and types mirror the spec exactly (06 §1.4/§1.5, 07 §11.2.4, 08 §12.3); if the
-- real migrations disagree, they are right and this file is the bug.

PRAGMA foreign_keys = ON;

CREATE TABLE zdoc_template (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL UNIQUE,
  specialty    TEXT,
  body_region  TEXT,
  section_key  TEXT NOT NULL,
  is_active    INTEGER NOT NULL DEFAULT 1,
  owner_scope  TEXT NOT NULL DEFAULT 'practice'
);

CREATE TABLE zdoc_template_element (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id   INTEGER NOT NULL REFERENCES zdoc_template(id),
  label         TEXT NOT NULL,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  allows_multi  INTEGER NOT NULL DEFAULT 1,
  render_prefix TEXT
);

CREATE TABLE zdoc_template_option (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  element_id          INTEGER NOT NULL REFERENCES zdoc_template_element(id),
  label               TEXT NOT NULL,
  polarity            TEXT NOT NULL DEFAULT 'neutral',
  sort_order          INTEGER NOT NULL DEFAULT 0,
  value_slots         TEXT,
  excludes_option_ids TEXT
);

CREATE TABLE zdoc_saved_finding (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL UNIQUE,
  section_key TEXT NOT NULL,
  owner_scope TEXT NOT NULL DEFAULT 'practice',
  is_active   INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE zdoc_saved_finding_template (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  saved_finding_id INTEGER NOT NULL REFERENCES zdoc_saved_finding(id),
  template_id      INTEGER NOT NULL REFERENCES zdoc_template(id),
  sort_order       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE zdoc_saved_finding_preset (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  saved_finding_id INTEGER NOT NULL REFERENCES zdoc_saved_finding(id),
  element_id       INTEGER NOT NULL REFERENCES zdoc_template_element(id),
  option_id        INTEGER REFERENCES zdoc_template_option(id),
  value_text       TEXT,
  value_numeric    REAL,
  laterality       TEXT NOT NULL DEFAULT 'n/a',
  anatomic_level   TEXT
);

CREATE TABLE zdoc_order_set (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  code      TEXT NOT NULL UNIQUE,
  name      TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE zdoc_order_set_item (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  order_set_id     INTEGER NOT NULL REFERENCES zdoc_order_set(id),
  item_type        TEXT NOT NULL,
  reference_id     TEXT NOT NULL,
  laterality       TEXT NOT NULL DEFAULT 'n/a',
  anatomic_level   TEXT,
  default_quantity INTEGER NOT NULL DEFAULT 1,
  sort_order       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE zdoc_diagnosis_set (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  code              TEXT NOT NULL UNIQUE,
  name              TEXT NOT NULL,
  specialty         TEXT,
  body_region       TEXT,
  is_active         INTEGER NOT NULL DEFAULT 1,
  owner_scope       TEXT NOT NULL DEFAULT 'practice',
  presentation_mode TEXT NOT NULL DEFAULT 'pick_list',
  laterality_param  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE zdoc_diagnosis_set_item (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  diagnosis_set_id     INTEGER NOT NULL REFERENCES zdoc_diagnosis_set(id),
  icd10_code           TEXT NOT NULL,
  display_name         TEXT NOT NULL,
  laterality           TEXT NOT NULL DEFAULT 'n/a',
  anatomic_level       TEXT,
  default_plan_text    TEXT,
  default_selected     INTEGER NOT NULL DEFAULT 0,
  sort_order           INTEGER NOT NULL DEFAULT 0,
  linked_order_set_id  INTEGER REFERENCES zdoc_order_set(id),
  linked_education_ids TEXT
);

CREATE TABLE zdoc_plan_group (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  name      TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE zdoc_plan_group_code (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_group_id INTEGER NOT NULL REFERENCES zdoc_plan_group(id),
  icd10_code    TEXT NOT NULL
);

CREATE TABLE zdoc_plan_option (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_group_id         INTEGER NOT NULL REFERENCES zdoc_plan_group(id),
  label                 TEXT NOT NULL,
  sort_order            INTEGER NOT NULL DEFAULT 0,
  narrative_template    TEXT,
  creates_order_type_id TEXT,
  sets_followup         INTEGER NOT NULL DEFAULT 0,
  queues_consent_id     TEXT,
  parameters            TEXT
);
