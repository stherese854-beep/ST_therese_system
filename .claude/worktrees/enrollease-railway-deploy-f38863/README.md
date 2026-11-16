# St. Therese Dental Clinic — Management System

A simple dental clinic management system.
**Front end:** HTML, CSS, JavaScript, Bootstrap 5
**Back end:** PHP + MySQL (built to run on **XAMPP**)

---

## ✅ What you need

1. **XAMPP** installed — download from https://www.apachefriends.org
   (XAMPP gives you Apache + PHP + MySQL/MariaDB + phpMyAdmin, all in one.)
2. An **internet connection the first time you open the app** — Bootstrap is
   loaded from a CDN. After it caches, the rest works offline.

---

## 🚀 Setup (5 easy steps)

### Step 1 — Copy the project into XAMPP
Copy the whole **`dental-clinic`** folder into the XAMPP **`htdocs`** folder.

- Windows: `C:\xampp\htdocs\dental-clinic`
- macOS:   `/Applications/XAMPP/htdocs/dental-clinic`

### Step 2 — Start the servers
Open the **XAMPP Control Panel** and click **Start** on:
- **Apache**  (runs the PHP pages)
- **MySQL**   (runs the database)

### Step 3 — Create the database
1. Open your browser and go to **http://localhost/phpmyadmin**
2. Click the **Import** tab at the top.
3. Click **Choose File** and select **`database.sql`** (inside this folder).
4. Scroll down and click **Go / Import**.

This creates a database called **`dental_clinic`** with all the tables and
sample data already filled in.

### Step 4 — Set the passwords (run this ONCE)
Open this address in your browser, just one time:

```
http://localhost/dental-clinic/setup.php
```

This sets the password for every sample account to **`password123`**.
(Passwords have to be encrypted by PHP, which is why this one-time step exists.)

### Step 5 — Open the app and log in
Go to:

```
http://localhost/dental-clinic/
```

---

## 🔑 Test accounts (password is `password123` for all)

| Role    | Email                            | What they see                         |
|---------|----------------------------------|---------------------------------------|
| Admin   | `admin@stthereesedental.ph`      | Everything + User Mgmt, Settings, etc |
| Dentist | `a.santos@stthereesedental.ph`   | Patients, Schedule, Odontogram, etc   |
| Patient | `patient@email.com`              | Patient Portal (own records & chart)  |

You can also click **Create Account** on the login page to register a new
patient and log in as them.

---

## 📂 What's inside

```
dental-clinic/
├── index.php          ← Login / Register page
├── setup.php          ← One-time password setup (run once, see Step 4)
├── logout.php
├── dashboard.php      ← Main dashboard (stats, today's appts, calendar)
├── patients.php       ← Patient records (search, add, edit, delete)
├── appointments.php   ← Schedule (approve / cancel appointments)
├── odontogram.php     ← Interactive dental chart
├── records.php        ← Treatment records
├── reports.php        ← Generate Reports (with live preview + print)
├── noshow.php         ← Weekly No-Show Report
├── book.php           ← 4-step online booking wizard
├── schedule.php       ← Dentist availability (mark days off)
├── portal.php         ← Patient Portal (records + dental chart)
├── admin_users.php    ← User Management (admin)
├── admin_dentists.php ← Dentists → their patients → patient chart (admin)
├── announcements.php  ← Announcements (admin)
├── messaging.php      ← Email / SMS config (admin)
├── settings.php       ← System Settings (clinic info, security, backup)
│
├── config/
│   ├── db.php         ← Database connection (XAMPP defaults)
│   └── auth.php       ← Login / session helpers
├── includes/
│   ├── head.php       ← Shared <head> (loads Bootstrap + our CSS)
│   ├── sidebar.php    ← Left navigation (changes by role)
│   ├── admin_tabs.php ← Top tab bar for admin pages
│   └── teeth.php      ← Draws the teeth for the dental chart
├── css/
│   └── style.css      ← The teal & gold theme
├── js/
│   └── app.js         ← Clock, calendar, tooth selection helpers
└── database.sql       ← The database (import this in phpMyAdmin)
```

> **Already imported an older `database.sql`?** Run **`update.sql`** once in
> phpMyAdmin (SQL tab) to add the newer tables/columns (`chart_remarks`,
> `dentist_daysoff`, `xrays`, `clinical_notes`) without losing your data.
> A fresh import of `database.sql` already includes everything.
>
> **X-ray uploads:** the `uploads/xrays/` folder stores uploaded X-ray images.
> Keep this folder in the project; on XAMPP it is writable by default.

---

## ⚙️ Default database connection (in `config/db.php`)

These are the standard XAMPP defaults — no changes needed unless you set a
MySQL password yourself:

| Setting   | Value           |
|-----------|-----------------|
| Host      | `localhost`     |
| Username  | `root`          |
| Password  | *(empty)*       |
| Database  | `dental_clinic` |

---

## ❓ Common problems

- **"Connection failed" / blank page** → Make sure **MySQL is started** in XAMPP
  and that you imported `database.sql`.
- **Page shows PHP code as text** → You opened the file directly. Always open it
  through **`http://localhost/dental-clinic/`**, not by double-clicking the file.
- **Can't log in** → Did you run **`setup.php`** once (Step 4)? Passwords are
  blank until you do.
- **Styling looks broken** → Check your internet connection (Bootstrap loads from
  a CDN the first time).

---

*Built for learning — kept simple and well-commented so it's easy to follow.*
