-- ============================================================
--  CLEAN UP ORPHANED RECORDS  (cleanup_orphans.sql)
-- ============================================================
--  Run this ONCE in phpMyAdmin if you deleted a user from
--  User Management BEFORE the cascade fix. Back then only the
--  `users` row was removed, so the patient record and all of its
--  clinical data were left behind and kept showing up in the
--  patient list, odontogram and records.
--
--  This removes records whose owner no longer exists.
--  It is safe to run more than once.
--
--  HOW TO USE:
--    phpMyAdmin -> pick the dental_clinic database
--                -> SQL tab -> paste this -> Go
-- ============================================================

-- 1. Patient rows whose linked user account is gone.
--    (Walk-in patients have user_id = NULL — those are kept.)
DELETE p FROM patients p
LEFT JOIN users u ON p.user_id = u.id
WHERE p.user_id IS NOT NULL AND u.id IS NULL;

-- 2. Clinical records whose patient no longer exists.
DELETE t FROM treatments t
LEFT JOIN patients p ON t.patient_id = p.id
WHERE t.patient_id IS NOT NULL AND p.id IS NULL;

DELETE x FROM xrays x
LEFT JOIN patients p ON x.patient_id = p.id
WHERE x.patient_id IS NOT NULL AND p.id IS NULL;

DELETE n FROM clinical_notes n
LEFT JOIN patients p ON n.patient_id = p.id
WHERE n.patient_id IS NOT NULL AND p.id IS NULL;

DELETE o FROM odontogram o
LEFT JOIN patients p ON o.patient_id = p.id
WHERE o.patient_id IS NOT NULL AND p.id IS NULL;

DELETE cs FROM chart_sessions cs
LEFT JOIN patients p ON cs.patient_id = p.id
WHERE cs.patient_id IS NOT NULL AND p.id IS NULL;

-- 3. Appointments whose patient is gone.
--    Appointments with patient_id = NULL are old demo rows; they are
--    left alone here. Delete them manually if you want a clean demo.
DELETE a FROM appointments a
LEFT JOIN patients p ON a.patient_id = p.id
WHERE a.patient_id IS NOT NULL AND p.id IS NULL;

-- 4. Reviews whose author account is gone.
DELETE r FROM reviews r
LEFT JOIN users u ON r.user_id = u.id
WHERE r.user_id IS NOT NULL AND u.id IS NULL;

-- ============================================================
--  OPTIONAL: remove the leftover demo appointments that are not
--  linked to any patient (they inflate the No-Show report).
--  Uncomment the line below only if you want them gone.
-- ============================================================
-- DELETE FROM appointments WHERE patient_id IS NULL;
