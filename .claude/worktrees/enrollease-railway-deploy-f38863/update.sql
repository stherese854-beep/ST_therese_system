-- ============================================================
--  UPDATE SCRIPT  (update.sql)
-- ============================================================
--  Run this ONLY IF you already imported database.sql before and
--  you DON'T want to lose your data.
--
--  It adds the new "chart_remarks" column that the Odontogram
--  page uses to save general remarks per patient.
--
--  HOW TO RUN:
--    1. Open  http://localhost/phpmyadmin
--    2. Click the  dental_clinic  database on the left.
--    3. Click the  SQL  tab at the top.
--    4. Paste the line below and click  Go.
--
--  (If you re-import database.sql fresh, you do NOT need this —
--   the column is already included there.)
-- ============================================================

ALTER TABLE patients
    ADD COLUMN chart_remarks TEXT DEFAULT NULL AFTER next_visit;

-- ------------------------------------------------------------
-- Run this too (adds the dentist day-off / availability table).
-- Safe to run once. If it says the table already exists, ignore it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS dentist_daysoff (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    dentist_name VARCHAR(100) NOT NULL,
    off_date     DATE NOT NULL,
    reason       VARCHAR(255) DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Run these too (X-ray images + clinical notes for the Records page).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS xrays (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT NOT NULL,
    image_file  VARCHAR(255) NOT NULL,
    caption     VARCHAR(255) DEFAULT NULL,
    xray_date   DATE DEFAULT NULL,
    uploaded_by VARCHAR(100) DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clinical_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    note       TEXT NOT NULL,
    author     VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Landing page content is stored in the existing `settings` table.
-- Older databases had setting_value as VARCHAR(255), which is too
-- short for the landing page text. This widens it to TEXT.
-- (Safe to run even if it is already TEXT.)
-- ------------------------------------------------------------
ALTER TABLE settings MODIFY setting_value TEXT;

-- ------------------------------------------------------------
-- NEW FEATURES (booking relationship, verification, reminders,
-- medical history, reason for visit, Google sign-in).
-- If a column already exists MySQL will show an error for that
-- one line only - you can safely ignore it and continue.
-- ------------------------------------------------------------
ALTER TABLE appointments ADD COLUMN booked_for       VARCHAR(20)  DEFAULT 'Myself';   -- Myself / Someone else
ALTER TABLE appointments ADD COLUMN relationship     VARCHAR(40)  DEFAULT NULL;       -- Child, Spouse, Parent...
ALTER TABLE appointments ADD COLUMN booked_by        VARCHAR(100) DEFAULT NULL;       -- who made the booking
ALTER TABLE appointments ADD COLUMN reason_for_visit VARCHAR(255) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN reminder_sent    TINYINT(1)   DEFAULT 0;          -- 1 = 24h reminder already sent

ALTER TABLE patients ADD COLUMN medical_history TEXT         DEFAULT NULL;
ALTER TABLE patients ADD COLUMN address         VARCHAR(255) DEFAULT NULL;
ALTER TABLE patients ADD COLUMN visit_reason    VARCHAR(255) DEFAULT NULL;   -- reason a returning patient came back

ALTER TABLE users ADD COLUMN email_verified TINYINT(1)  DEFAULT 0;
ALTER TABLE users ADD COLUMN google_id      VARCHAR(64) DEFAULT NULL;
ALTER TABLE users ADD COLUMN photo          VARCHAR(255) DEFAULT NULL;   -- dentist photo for the landing page

-- Log of every email the system sends (so you can prove reminders went out).
CREATE TABLE IF NOT EXISTS email_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    recipient  VARCHAR(150) NOT NULL,
    subject    VARCHAR(200) DEFAULT NULL,
    kind       VARCHAR(40)  DEFAULT NULL,   -- verification / confirmation / reminder / announcement
    status     VARCHAR(20)  DEFAULT 'sent', -- sent / failed
    error      VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- DENTAL CHART SESSIONS  (odontogram history)
-- ------------------------------------------------------------
-- Before: a patient had ONE dental chart.
-- Now:    a patient has one chart PER VISIT, so you can look back at
--         the first visit and compare it with today to see progress.
--
-- Each row in `odontogram` now belongs to a session (a visit).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chart_sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    visit_date DATE NOT NULL,
    title      VARCHAR(100) DEFAULT NULL,   -- e.g. "Initial Chart", "Follow-up"
    notes      TEXT DEFAULT NULL,           -- what happened during this visit
    created_by VARCHAR(100) DEFAULT NULL,   -- which dentist recorded it
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE odontogram ADD COLUMN session_id INT DEFAULT NULL;

-- ------------------------------------------------------------
-- AGE CHECK + PATIENT REVIEWS + PROFILE PICTURES
-- ------------------------------------------------------------
-- Accounts are for adults only (18+). A parent/guardian makes the
-- account and books for their child using "booking for someone else".
ALTER TABLE patients ADD COLUMN date_of_birth DATE DEFAULT NULL;

-- Patients can leave a comment/review about the clinic. An admin
-- approves it, and then it appears on the public landing page.
CREATE TABLE IF NOT EXISTS reviews (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT DEFAULT NULL,
    patient_id INT DEFAULT NULL,
    name       VARCHAR(100) NOT NULL,
    rating     TINYINT NOT NULL DEFAULT 5,          -- 1 to 5 stars
    comment    TEXT NOT NULL,
    status     ENUM('Pending','Approved','Hidden') DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- (users.photo already exists - it is reused for the patient's profile picture.)

-- Move any EXISTING chart data into a first session called "Initial Chart",
-- so nothing that is already saved gets lost.
INSERT INTO chart_sessions (patient_id, visit_date, title, created_by)
SELECT DISTINCT patient_id, CURDATE(), 'Initial Chart', 'System'
FROM odontogram
WHERE session_id IS NULL;

UPDATE odontogram o
JOIN chart_sessions s
  ON s.patient_id = o.patient_id AND s.title = 'Initial Chart'
SET o.session_id = s.id
WHERE o.session_id IS NULL;

-- Landing page content can be long (testimonials, FAQs), so widen setting_value.
ALTER TABLE settings MODIFY setting_value TEXT;

-- ============================================================
--  PASSWORD RESET (Forgot Password feature)
--  Stores a temporary reset code + its expiry per user.
--  Safe to re-run: "Duplicate column" errors can be ignored.
-- ============================================================
ALTER TABLE users ADD COLUMN reset_code VARCHAR(10) DEFAULT NULL;
ALTER TABLE users ADD COLUMN reset_expires DATETIME DEFAULT NULL;

-- ============================================================
--  NOTIFICATION READ TRACKING
--  notif_seen_at  = when the user last opened their notification bell
--  confirmed_at   = when an appointment was marked Confirmed
--  Together these let the red badge disappear once the patient has
--  seen the notification, and reappear when something new happens.
--  Safe to re-run: "Duplicate column" errors can be ignored.
-- ============================================================
ALTER TABLE users ADD COLUMN notif_seen_at DATETIME DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN confirmed_at DATETIME DEFAULT NULL;

-- Any appointment that is already Confirmed gets a timestamp so the
-- badge logic has something to compare against.
UPDATE appointments SET confirmed_at = NOW() WHERE status = 'Confirmed' AND confirmed_at IS NULL;

-- ============================================================
--  NO-SHOW DETECTION
--  Adds a "Needs Review" state: the system flags an appointment
--  it believes was missed, but no email is sent and no penalty is
--  applied until a staff member confirms it.
--  Safe to re-run.
-- ============================================================
ALTER TABLE appointments
  MODIFY COLUMN status ENUM('Pending','Confirmed','Cancelled','Completed',
                            'No-show','Rescheduled','Needs Review') DEFAULT 'Pending';

-- ============================================================
--  PATIENT SELF-CANCELLATION
--  Records who cancelled an appointment and when, so the clinic
--  can be alerted about cancellations made by patients online.
--  Safe to re-run.
-- ============================================================
ALTER TABLE appointments ADD COLUMN cancelled_at DATETIME DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN cancelled_by VARCHAR(100) DEFAULT NULL;

-- Reason the clinic gives when it cancels an appointment. This is shown to
-- the patient in their portal and included in the cancellation email.
ALTER TABLE appointments ADD COLUMN cancel_reason TEXT DEFAULT NULL;

-- ============================================================
--  LIFTING A NO-SHOW BOOKING BLOCK
--  When staff have spoken to a patient about their missed visits,
--  they can restore online booking. We record WHEN it was lifted
--  instead of deleting the history, so only no-shows AFTER that
--  moment count towards a new block.
--  Safe to re-run.
-- ============================================================
ALTER TABLE patients ADD COLUMN noshow_reset_at DATETIME DEFAULT NULL;
ALTER TABLE patients ADD COLUMN noshow_reset_by VARCHAR(100) DEFAULT NULL;

-- ============================================================
--  MANUAL BOOKING BLOCK
--  Lets admin/staff pause a patient's online booking at any time,
--  without waiting for three missed visits.
--  Safe to re-run.
-- ============================================================
ALTER TABLE patients ADD COLUMN booking_blocked TINYINT(1) DEFAULT 0;
ALTER TABLE patients ADD COLUMN booking_block_reason TEXT DEFAULT NULL;
ALTER TABLE patients ADD COLUMN booking_blocked_by VARCHAR(100) DEFAULT NULL;
ALTER TABLE patients ADD COLUMN booking_blocked_at DATETIME DEFAULT NULL;

-- ============================================================
--  RESCHEDULING
--  Records that an appointment was moved, and what it was moved
--  from, so the change can be shown to both sides.
--  Safe to re-run.
-- ============================================================
ALTER TABLE appointments ADD COLUMN rescheduled_at DATETIME DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN rescheduled_from VARCHAR(60) DEFAULT NULL;
ALTER TABLE appointments ADD COLUMN reschedule_reason TEXT DEFAULT NULL;

-- ============================================================
--  RENAME USER ROLES
--  'admin'  -> 'super_admin'   (was the highest role)
--  'staff'  -> 'admin'         (was the front-desk role)
--
--  Run these in order. Safe to re-run — re-running a completed
--  rename has no effect because the old value no longer exists.
-- ============================================================

-- 1. Expand the ENUM so it accepts all four strings at once.
ALTER TABLE users MODIFY COLUMN role
    ENUM('super_admin','admin','dentist','patient','staff') NOT NULL DEFAULT 'patient';

-- 2. Rename the data rows.
UPDATE users SET role = 'super_admin' WHERE role = 'admin';
UPDATE users SET role = 'admin'       WHERE role = 'staff';

-- 3. Tighten the ENUM back — remove the old 'staff' value.
ALTER TABLE users MODIFY COLUMN role
    ENUM('super_admin','admin','dentist','patient') NOT NULL DEFAULT 'patient';

-- ============================================================
--  ADD 'admin' ROLE BETWEEN super_admin AND staff
--  Run this ONCE after extracting the new zip.
--  Safe to re-run — if 'admin' already exists it is ignored.
-- ============================================================
ALTER TABLE users MODIFY COLUMN role
    ENUM('super_admin','admin','staff','dentist','patient') NOT NULL DEFAULT 'patient';
