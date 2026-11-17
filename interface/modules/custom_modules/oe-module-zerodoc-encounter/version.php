<?php

/**
 * ZeroDoc Clinical Encounter Workflow version.
 *
 * Core reads $v_major.$v_minor.$v_patch (only) into modules.sql_version when
 * the install or upgrade SQL is run; $v_tag and $v_database are ignored by the
 * module installer. Bump this in the same commit as any new
 * <a>_<b>_<c>-to-<x>_<y>_<z>_upgrade.sql file.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$v_major = '0';
$v_minor = '1';
$v_patch = '0';
$v_tag   = '-dev';
$v_database = 1;
