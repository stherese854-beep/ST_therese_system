-- ============================================================
--  ST. THERESE DENTAL CLINIC - DATABASE
-- ============================================================
--  HOW TO USE THIS IN XAMPP:
--  1. Start Apache + MySQL in the XAMPP Control Panel.
--  2. Open your browser and go to:  http://localhost/phpmyadmin
--  3. Click the "Import" tab at the top.
--  4. Click "Choose File" and select THIS file (database.sql).
--  5. Click "Go" at the bottom. The database + tables + sample
--     data will be created automatically.
--
--  NOTE: This file creates a database called `dental_clinic`.
--        If it already exists it will be dropped and recreated.
-- ============================================================

DROP DATABASE IF EXISTS dental_clinic;
CREATE DATABASE dental_clinic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dental_clinic;

-- ------------------------------------------------------------
-- TABLE: users
-- Everyone who can log in: admin, dentists, staff, patients.
-- The `role` column controls which dashboard they see.
-- ------------------------------------------------------------
CREATE TABLE users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(100) NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,          -- stored hashed (see note below)
    role        ENUM('admin','dentist','staff','patient') NOT NULL DEFAULT 'patient',
    specialty   VARCHAR(100) DEFAULT NULL,       -- e.g. "Orthodontics" (for dentists)
    position    VARCHAR(100) DEFAULT NULL,       -- e.g. "Receptionist" (for staff)
    contact     VARCHAR(30)  DEFAULT NULL,
    status      ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    email_verified TINYINT(1)  DEFAULT 0,          -- 1 = the email address was verified by code
    google_id      VARCHAR(64) DEFAULT NULL,       -- set when the user signs in with Google
    photo          VARCHAR(255) DEFAULT NULL,      -- dentist photo shown on the landing page
    reset_code     VARCHAR(10) DEFAULT NULL,       -- temporary code for Forgot Password
    reset_expires  DATETIME    DEFAULT NULL,       -- when that reset code stops working
    notif_seen_at  DATETIME    DEFAULT NULL,       -- when they last opened the notification bell
    archived_at        DATETIME    DEFAULT NULL,   -- when this account was moved to the Archive
    archived_by        VARCHAR(100) DEFAULT NULL,  -- which admin archived it
    pre_archive_status VARCHAR(20) DEFAULT NULL,   -- status to restore back to (active/inactive)
    last_login  DATETIME DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: deleted_accounts_log
-- A trace of accounts that were PERMANENTLY deleted from the Archive.
-- The users row is gone by then, so this is how the login page can
-- still recognize the email and tell the person their account was
-- permanently deleted (instead of just "wrong email or password").
-- ------------------------------------------------------------
CREATE TABLE deleted_accounts_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL,
    role       VARCHAR(20)  DEFAULT NULL,
    deleted_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    deleted_by VARCHAR(100) DEFAULT NULL
);

-- ------------------------------------------------------------
-- TABLE: activity_log
-- A running record of meaningful actions taken in the system —
-- logins, account changes, appointment status changes, settings
-- edits, announcements, and review moderation. Read-only from the
-- admin UI; entries are never edited or deleted through it.
-- ------------------------------------------------------------
CREATE TABLE activity_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    actor_name VARCHAR(100) DEFAULT NULL,
    actor_role VARCHAR(20)  DEFAULT NULL,
    action     VARCHAR(60)  NOT NULL,
    details    VARCHAR(255) DEFAULT NULL,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX (created_at)
);

-- ------------------------------------------------------------
-- TABLE: email_log  (a record of every email the system sends)
-- ------------------------------------------------------------
CREATE TABLE email_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    recipient  VARCHAR(150) NOT NULL,
    subject    VARCHAR(200) DEFAULT NULL,
    kind       VARCHAR(40)  DEFAULT NULL,   -- verification / confirmation / reminder / announcement
    status     VARCHAR(20)  DEFAULT 'sent', -- sent / failed
    error      VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: reviews  (patient comments about the clinic / system)
-- A patient writes one from their portal. An admin approves it,
-- and then it shows in the Testimonials section of the landing page.
-- ------------------------------------------------------------
CREATE TABLE reviews (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT DEFAULT NULL,
    patient_id INT DEFAULT NULL,
    name       VARCHAR(100) NOT NULL,
    rating     TINYINT NOT NULL DEFAULT 5,          -- 1 to 5 stars
    comment    TEXT NOT NULL,
    status     ENUM('Pending','Approved','Hidden') DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: patients
-- Extra info for people whose role = 'patient'.
-- Linked to users.id through user_id.
-- ------------------------------------------------------------
CREATE TABLE patients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT DEFAULT NULL,            -- link to the login account (can be NULL)
    name            VARCHAR(100) NOT NULL,
    email           VARCHAR(100) DEFAULT NULL,
    phone           VARCHAR(30)  DEFAULT NULL,
    age             INT DEFAULT NULL,
    date_of_birth   DATE DEFAULT NULL,
    blood_type      VARCHAR(5) DEFAULT NULL,
    patient_type    ENUM('New','Returning') DEFAULT 'New',
    status          ENUM('Active','Inactive','Archived') DEFAULT 'Active',
    archived_at     DATETIME DEFAULT NULL,       -- when this patient was moved to the Archive
    archived_by     VARCHAR(100) DEFAULT NULL,   -- who archived them
    pre_archive_status VARCHAR(20) DEFAULT NULL, -- status to restore back to (Active/Inactive)
    primary_dentist VARCHAR(100) DEFAULT NULL,
    medical_alert   VARCHAR(255) DEFAULT NULL,   -- e.g. "Allergic to Penicillin"
    last_visit      DATE DEFAULT NULL,
    next_visit      DATE DEFAULT NULL,
    chart_remarks   TEXT DEFAULT NULL,          -- general remarks for the dental chart (odontogram page)
    noshow_reset_at DATETIME DEFAULT NULL,      -- when staff restored online booking after missed visits
    noshow_reset_by VARCHAR(100) DEFAULT NULL,  -- which staff member restored it
    booking_blocked      TINYINT(1)   DEFAULT 0,     -- 1 = staff paused online booking by hand
    booking_block_reason TEXT         DEFAULT NULL,  -- shown to the patient
    booking_blocked_by   VARCHAR(100) DEFAULT NULL,
    booking_blocked_at   DATETIME     DEFAULT NULL,
    date_of_birth   DATE DEFAULT NULL,          -- used to check the account holder is 18+
    medical_history TEXT DEFAULT NULL,          -- past conditions, allergies, medication
    address         VARCHAR(255) DEFAULT NULL,
    visit_reason    VARCHAR(255) DEFAULT NULL,  -- why a Returning patient came back
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: appointments
-- ------------------------------------------------------------
CREATE TABLE appointments (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    patient_id       INT DEFAULT NULL,
    patient_name     VARCHAR(100) NOT NULL,      -- kept as text for easy display
    dentist          VARCHAR(100) DEFAULT NULL,
    treatment        VARCHAR(100) DEFAULT NULL,
    appointment_date DATE DEFAULT NULL,
    appointment_time VARCHAR(20) DEFAULT NULL,   -- e.g. "09:00 AM"
    status           ENUM('Pending','Confirmed','Cancelled','Completed','No-show','Rescheduled','Needs Review') DEFAULT 'Pending',
    notes            TEXT DEFAULT NULL,
    booked_for       VARCHAR(20)  DEFAULT 'Myself',   -- Myself / Someone else
    relationship     VARCHAR(40)  DEFAULT NULL,       -- Child, Spouse, Parent, Guardian...
    booked_by        VARCHAR(100) DEFAULT NULL,       -- the account holder who made the booking
    reason_for_visit VARCHAR(255) DEFAULT NULL,
    reminder_sent    TINYINT(1)   DEFAULT 0,          -- 1 = the 24-hour reminder was already emailed
    confirmed_at     DATETIME     DEFAULT NULL,       -- when the clinic marked it Confirmed
    cancelled_at     DATETIME     DEFAULT NULL,       -- when it was cancelled
    cancelled_by     VARCHAR(100) DEFAULT NULL,       -- 'patient', or the staff member's name
    cancel_reason    TEXT         DEFAULT NULL,       -- why the clinic cancelled (shown to the patient)
    rescheduled_at   DATETIME     DEFAULT NULL,       -- when it was last moved
    rescheduled_from VARCHAR(60)  DEFAULT NULL,       -- the date and time it was moved from
    reschedule_reason TEXT        DEFAULT NULL,       -- why it was moved
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: treatments  (Treatment Records / Clinical history)
-- ------------------------------------------------------------
CREATE TABLE treatments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT DEFAULT NULL,
    patient_name   VARCHAR(100) NOT NULL,
    treatment_name VARCHAR(100) NOT NULL,        -- e.g. "Root Canal"
    tooth          VARCHAR(20) DEFAULT NULL,      -- e.g. "16"
    dentist        VARCHAR(100) DEFAULT NULL,
    treatment_date DATE DEFAULT NULL,
    status         ENUM('Completed','In Progress','Planned') DEFAULT 'Completed',
    notes          TEXT DEFAULT NULL
);

-- ------------------------------------------------------------
-- TABLE: odontogram  (condition of each tooth per patient)
-- tooth_number uses the FDI numbering shown in the chart.
-- ------------------------------------------------------------
CREATE TABLE odontogram (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    patient_id   INT NOT NULL,
    session_id   INT DEFAULT NULL,          -- which visit/chart this tooth belongs to
    tooth_number VARCHAR(5) NOT NULL,
    tooth_status ENUM('Healthy','Decayed','Filled','Missing','Crowned','Extracted','Impacted','Fractured') DEFAULT 'Healthy',
    notes        VARCHAR(255) DEFAULT NULL
);

-- ------------------------------------------------------------
-- TABLE: chart_sessions
-- A patient gets ONE dental chart PER VISIT. Keeping each visit as
-- its own session lets the dentist compare the first visit with the
-- latest one and see the patient's progress.
-- ------------------------------------------------------------
CREATE TABLE chart_sessions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    visit_date DATE NOT NULL,
    title      VARCHAR(100) DEFAULT NULL,   -- e.g. "Initial Chart", "Follow-up"
    notes      TEXT DEFAULT NULL,           -- what happened during this visit
    created_by VARCHAR(100) DEFAULT NULL,   -- which dentist recorded it
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: dentist_daysoff
-- Days a dentist marks as unavailable (so the system knows they
-- will not take appointments on that day).
-- ------------------------------------------------------------
CREATE TABLE dentist_daysoff (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    dentist_name VARCHAR(100) NOT NULL,      -- matches users.name, e.g. "Dr. Ana Santos"
    off_date     DATE NOT NULL,
    reason       VARCHAR(255) DEFAULT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: xrays  (uploaded X-ray images per patient)
-- The image file itself is saved in the  uploads/xrays/  folder;
-- here we only store the file name and some details.
-- ------------------------------------------------------------
CREATE TABLE xrays (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT NOT NULL,
    image_file  VARCHAR(255) NOT NULL,        -- file name inside uploads/xrays/
    caption     VARCHAR(255) DEFAULT NULL,
    xray_date   DATE DEFAULT NULL,
    uploaded_by VARCHAR(100) DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: clinical_notes  (dated free-text notes per patient)
-- ------------------------------------------------------------
CREATE TABLE clinical_notes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    note       TEXT NOT NULL,
    author     VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: announcements
-- ------------------------------------------------------------
CREATE TABLE announcements (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(150) NOT NULL,
    content    TEXT,
    channel    VARCHAR(50) DEFAULT 'Email',       -- Email / SMS / Dashboard
    audience   VARCHAR(50) DEFAULT 'All Patients',
    status     ENUM('Published','Draft') DEFAULT 'Draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- TABLE: settings  (simple key/value store for clinic config)
-- ------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(50) PRIMARY KEY,
    setting_value TEXT
);

-- ============================================================
--  SAMPLE DATA
-- ============================================================
--  *** IMPORTANT - PASSWORDS / ONE EXTRA SETUP STEP ***
--  Passwords must be hashed by PHP (password_hash) which can only
--  run on your XAMPP machine. So the password field below starts
--  empty ('').
--
--  AFTER you import this file, open this URL ONCE in your browser:
--      http://localhost/dental-clinic/setup.php
--  That page sets the password for ALL sample accounts to:
--      password123
--
--  Then you can log in with these test accounts:
--    Admin   ->  admin@stthereesedental.ph    / password123
--    Dentist ->  a.santos@stthereesedental.ph / password123
--    Patient ->  patient@email.com            / password123
-- ============================================================

-- Users (password is set later by setup.php)
INSERT INTO users (name, email, password, role, specialty, position, contact, status, last_login) VALUES
('Admin User',     'admin@stthereesedental.ph',    '', 'admin',   NULL, 'Administrator', '09000000000', 'active', NOW()),
('Dr. Ana Santos', 'a.santos@stthereesedental.ph', '', 'dentist', 'General Dentistry & Cosmetics', NULL, '09171234567', 'active', NOW()),
('Dr. Ben Reyes',  'b.reyes@stthereesedental.ph',  '', 'dentist', 'Orthodontics', NULL, '09181234567', 'active', NOW()),
('Claire Mendoza', 'c.mendoza@stthereesedental.ph','', 'staff',   NULL, 'Receptionist', '09201234567', 'active', NOW()),
('Dan Torres',     'd.torres@stthereesedental.ph', '', 'staff',   NULL, 'Dental Assistant', '09211234567', 'inactive', NULL),
('Maria Santos',   'patient@email.com',            '', 'patient', NULL, NULL, '09171234567', 'active', NOW());

-- Patients
INSERT INTO patients (user_id, name, email, phone, age, date_of_birth, blood_type, patient_type, status, primary_dentist, medical_alert, last_visit, next_visit) VALUES
(6, 'Maria Santos',   'patient@email.com', '09171234567', 34, '1990-03-12', 'O+', 'Returning', 'Active',   'Dr. Ana Santos', 'Allergic to Penicillin', '2024-12-15', '2025-02-10'),
(NULL,'Juan dela Cruz','juan@email.com',    '09281234567', 28, '1996-11-20', 'A+', 'Returning', 'Active',   'Dr. Ben Reyes',  NULL, '2024-11-20', '2025-01-28'),
(NULL,'Ana Reyes',     'ana@email.com',     '09351234567', 45, '1979-05-05', 'B+', 'New',       'Inactive', 'Dr. Ana Santos', NULL, '2024-10-05', '2025-03-15'),
(NULL,'Carlos Tan',    'carlos@email.com',  '09461234567', 52, '1972-01-02', 'AB+','Returning', 'Active',   'Dr. Ana Santos', NULL, '2025-01-02', '2025-02-05'),
(NULL,'Lisa Gonzales', 'lisa@email.com',    '09571234567', 22, '2002-09-18', 'O-', 'Returning', 'Active',   'Dr. Ben Reyes',  NULL, '2024-09-18', '2025-04-20');

-- Appointments
INSERT INTO appointments (patient_id, patient_name, dentist, treatment, appointment_date, appointment_time, status) VALUES
(1, 'Maria Santos',   'Dr. Santos', 'Cleaning',     '2025-02-10', '09:00 AM', 'Confirmed'),
(2, 'Juan dela Cruz', 'Dr. Reyes',  'Extraction',   '2025-01-28', '10:30 AM', 'Pending'),
(4, 'Carlos Tan',     'Dr. Santos', 'Root Canal',   '2025-02-05', '02:00 PM', 'Confirmed'),
(5, 'Lisa Gonzales',  'Dr. Reyes',  'Consultation', '2025-04-20', '11:00 AM', 'Pending'),
(3, 'Ana Reyes',      'Dr. Santos', 'Filling',      '2025-03-15', '03:30 PM', 'Cancelled');

-- Extra "missed appointment" sample rows so the No-Show Report (noshow.php)
-- has data to show. These use the No-show / Cancelled / Rescheduled statuses.
INSERT INTO appointments (patient_id, patient_name, dentist, treatment, appointment_date, appointment_time, status) VALUES
(NULL, 'Maria Reyes', 'Dr. Santos', 'Cleaning',    '2025-04-14', '09:00 AM', 'No-show'),
(NULL, 'Jose Cruz',   'Dr. Reyes',  'Extraction',  '2025-04-14', '11:00 AM', 'Cancelled'),
(NULL, 'Ana Lim',     'Dr. Cruz',   'Filling',     '2025-04-15', '02:00 PM', 'No-show'),
(NULL, 'Ben Torres',  'Dr. Santos', 'Check-up',    '2025-04-15', '03:30 PM', 'Rescheduled'),
(NULL, 'Rico Mendez', 'Dr. Reyes',  'Consultation','2025-04-16', '10:00 AM', 'No-show'),
(NULL, 'Lara Vidal',  'Dr. Santos', 'Cleaning',    '2025-04-17', '01:00 PM', 'Cancelled');

-- Treatments (clinical records)
INSERT INTO treatments (patient_id, patient_name, treatment_name, tooth, dentist, treatment_date, status, notes) VALUES
(1, 'Maria Santos',   'Root Canal', '16', 'Dr. Santos', '2025-01-15', 'Completed',   'Root canal therapy completed.'),
(2, 'Juan dela Cruz', 'Filling',    '26', 'Dr. Reyes',  '2025-01-12', 'Completed',   'Composite filling.'),
(4, 'Carlos Tan',     'Crown',      '36', 'Dr. Santos', '2025-01-08', 'In Progress', 'Crown preparation done, awaiting fitting.'),
(1, 'Maria Santos',   'Teeth Cleaning', 'All', 'Dr. Santos', '2024-12-15', 'Completed', 'Routine cleaning. Good oral hygiene.'),
(1, 'Maria Santos',   'Consultation', 'All', 'Dr. Santos', '2023-11-20', 'Completed', 'Initial orthodontic assessment.');

-- Odontogram for Maria Santos (patient_id = 1) - matches the chart in the screenshots
INSERT INTO odontogram (patient_id, tooth_number, tooth_status) VALUES
(1, '16', 'Decayed'),
(1, '26', 'Filled'),
(1, '46', 'Missing'),
(1, '36', 'Crowned');

-- Announcements
INSERT INTO announcements (title, content, channel, audience, status) VALUES
('Holiday Schedule - January 2025', 'Please be informed that our clinic will be closed on January 25-26 for the Chinese New Year holiday. Regular operations resume January 27. Happy New Year!', 'Email', 'All Patients', 'Published'),
('New Orthodontic Services Now Available', 'We are excited to announce that Dr. Ben Reyes now offers comprehensive orthodontic consultations every Tuesday and Saturday. Book your appointment today!', 'Email', 'All Patients', 'Published'),
('Appointment Reminder: Bring Your Insurance Card', 'Reminder to all patients: Please bring your PhilHealth or HMO card to your next visit to avail of covered treatments.', 'SMS', 'Upcoming Appts', 'Published'),
('Upcoming Promo: Free Cleaning for Returning Patients', 'Watch out for our upcoming promo!', 'Email', 'All Patients', 'Draft');

-- Settings (clinic information shown on the System Settings page)
INSERT INTO settings (setting_key, setting_value) VALUES
('clinic_name',     'St. Therese of Carmel Dental Clinic'),
('clinic_tagline',  'Your Smile, Our Passion'),
('clinic_phone',    '(046) 123-4567'),
('clinic_email',    'hello@stthereesedental.ph'),
('clinic_address',  '123 Dental St., Naic, Cavite'),
('operating_hours', 'Mon-Fri 8:00 AM - 5:00 PM');

-- ============================================================
--  END OF FILE
-- ============================================================
