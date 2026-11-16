# Demo Script — St. Therese of Carmel Dental Clinic Appointment System

A page-by-page walkthrough for the capstone defense. Each section gives you
**what to open**, **what to click**, and **what to say**.

---

## Before You Start (do this 30 minutes before)

Nothing here is optional. Each one prevents a demo from stalling in front of the panel.

| # | Task | Why |
|---|---|---|
| 1 | Start **XAMPP** — Apache + MySQL both green | Nothing works otherwise |
| 2 | Run `update.sql` in phpMyAdmin | Missing columns cause fatal errors mid-demo |
| 3 | Open `localhost/dental-clinic/` and confirm the landing page loads | Catches a bad extract early |
| 4 | Log in as **admin** once | Triggers the daily no-show scan so the queue is ready |
| 5 | Log in as **patient** once, log out | Confirms the account works |
| 6 | Check the clinic email inbox is open in a tab | You will show a live email arriving |
| 7 | Have a **second browser** (or incognito) ready | Lets you show two roles at once without logging out |
| 8 | Delete `setup.php` from `htdocs/dental-clinic/` if it is still there | It has no login guard |

**Prepare this test data** so the demo has something to show:

- One patient with **2 or more odontogram visits** (to show progress tracking)
- One **Pending** appointment (to approve live)
- One **Confirmed** appointment dated far in the future (to cancel live)
- One approved **review** (so the landing page testimonials are not empty)

**Accounts to have written down:**

```
admin@stthereesedental.ph      password123     (admin)
[your dentist account]         password123     (dentist)
[your staff account]           password123     (staff)
patient@email.com              password123     (patient)
```

---

## Opening Statement (60 seconds)

> "Good morning. We built an **Appointment Management System for St. Therese of Carmel
> Dental Clinic**, a general dentistry and orthodontics clinic in General Mariano Alvarez,
> Cavite, run by Dr. Maricris Agbisit-Cuison.
>
> The clinic books appointments by phone and keeps records on paper. That causes
> double-booking, lost records, and no way for a patient to check their own history.
>
> Our system covers the whole cycle — from a patient booking online, to the dentist
> recording the dental chart, to the clinic generating reports. It has **four user roles**,
> each seeing only what they need, and it sends **real email** for confirmations,
> reminders, and password resets.
>
> Let me walk you through it."

---

# PART 1 — The Public Side

## 1.1 Landing Page

**Open:** `localhost/dental-clinic/`

**Say:**
> "This is what anyone sees when they visit. It is the clinic's public homepage."

**Scroll through and point out:**

| Section | What to say |
|---|---|
| **Hero** | "Clinic photo and the two main actions — Book Appointment and Login." |
| **Features** | "What the system offers." |
| **How It Works** | "Four steps, so a first-time patient knows what to expect." |
| **Services** | "The nine treatments the clinic offers." |
| **About the Clinic** | "Information about Dr. Agbisit-Cuison and the clinic." |
| **Why Choose Us** | "Six reasons, editable by the admin." |
| **Meet Our Dentists** | "This is **live** — it pulls the actual active dentists from the database. Add a dentist in the admin panel and they appear here automatically." |
| **Testimonials** | "Real patient reviews that the admin has approved. Not hardcoded." |
| **FAQ** | "Click to expand — common questions." |
| **Contact** | "With a **real Google Map** showing the clinic's exact location." |

**Click the footer links:**
> "Privacy Policy and Terms open the actual policies — covering the Data Privacy Act of 2012, RA 10173, which applies because we handle health records."

**Key point to land:**
> "Every word on this page is editable by the admin without touching code.
> I will show you that editor later."

---

## 1.2 Registration with Age Verification

**Click:** Login → **Create Account**

**Say:**
> "A patient registers here. Notice the **Date of Birth** field."

**Demo:** Enter a birthdate under 18, submit.

> "The system blocks it. Only adults can hold an account, because a minor cannot
> legally consent to treatment. But a child can still be a patient — a parent
> creates the account and books **for someone else**. I will show that."

**Then point out:**
- **Confirm Password** — must match
- **Password strength meter** — type a weak password, then a strong one, and let the panel see the bar change colour
- **Eye icon** — click it, show the password, and note it hides itself again after 5 seconds

**Say:**
> "Registration is not complete until the email is verified. The system emails a
> six-digit code."

**Demo:** Register with a real address, show the code arriving, enter it.

---

## 1.3 Forgot Password

**Click:** Login → **Forgot password?**

**Say:**
> "If a patient forgets their password, they do not need to call the clinic. They enter
> their email and receive a reset code, valid for 15 minutes."

**Point out (a security detail worth mentioning):**
> "Notice the message is the same whether or not the email exists in our system.
> That is deliberate — it stops a stranger from discovering which addresses
> are registered."

---

# PART 2 — The Patient

**Log in as the patient.**

## 2.1 Patient Portal

**Say:**
> "This is the patient's own space. Four sections."

| Section | What to show |
|---|---|
| 📅 **Appointments** | Upcoming, and full visit history |
| 🦷 **My Dental Chart** | Their odontogram, read-only |
| 📋 **My Records** | Treatment history |
| 📣 **Announcements** | Clinic notices |

## 2.2 Booking an Appointment

**Click:** Book Appointment

**Walk through the steps:**

**Step 1 — Who is this for?**
> "Myself, or someone else. If they choose someone else, the form asks for the
> relationship and that person's date of birth — this is how a parent books for a child.
> For themselves, we do not ask again, because we already have it from registration."

**Step 2 — Treatment and schedule**
> "The dentist is **assigned automatically** — either their usual dentist, or the
> least busy one. The patient does not pick, because the clinic manages the workload."

**Step 3 — Review and confirm**
> "They must tick the Terms checkbox. The links open the real policy."

**After booking, point out:**
> "The status is **Pending**, not confirmed. An appointment is only final when the
> clinic approves it. The patient gets an email saying we received the request."

**Mention the safeguards:**
> "The system blocks three things: more than 3 pending requests, two bookings for the
> same person on the same day, and double-booking a dentist at the same time slot."

## 2.3 Dental Chart — Per Visit

**Click:** 🦷 My Dental Chart

**Say:**
> "This is where our system goes beyond a normal appointment app."

**Point to the visit tabs:**
> "The chart is saved **per visit**, not overwritten. Each tab is a different date.
> Click Visit 1 — this is how the teeth looked then. Click Visit 2 — this is now."

**Point to the progress panel:**
> "The system compares the two and tells the patient what changed —
> *one tooth treated, one needs attention* — with the specific teeth listed."

**Why this matters (say this):**
> "In a paper chart, the dentist erases and redraws. The history is lost. Here,
> every visit is preserved, so both the dentist and the patient can see the
> progress of treatment over time."

---

# PART 3 — The Clinic Staff

## 3.1 Front Desk (Staff Role)

**Log in as staff.**

**Say:**
> "This is the receptionist's view. Notice what is **not** in the sidebar —
> no Odontogram, no clinical Records."

**Say the principle clearly:**
> "This is the **principle of least privilege**. Front desk staff handle scheduling.
> They do not need to read a patient's medical history, so they cannot open it.
> That is required by the Data Privacy Act's data minimisation rule."

**Show what staff CAN do:**
- 📅 **Appointments** — approve, cancel, mark completed
- 👥 **Patients** — register walk-ins, update contact details, reassign a patient's dentist
- 📑 **Reports**
- 📝 **No-Show Report**

## 3.2 Approving an Appointment — Live Email

**Open:** 📅 Appointments → find the Pending one → **✓ Approve**

**Say:**
> "Watch the patient's inbox."

**Switch to the email tab and show it arriving.**

> "The patient is notified the moment the clinic confirms. This is a real SMTP
> connection to Gmail — not simulated."

**Then switch to the patient account and show:**
- The notification bell now has a red badge
- Click it — the badge clears, and stays cleared on the next page
- The appointment now has a **🖨 Print** button

**Click Print:**
> "A printable confirmation slip with a reference number, the clinic's details,
> and a reminder to arrive ten minutes early. Save as PDF or print it."

## 3.3 Cancelling — With a Reason

**Back on staff:** find a Confirmed appointment → **✕ Cancel**

**Say:**
> "The system will not let us cancel without saying why."

**Type a reason, e.g.** *"The dentist is unwell that day"*

**Then show two things:**
1. The **patient's email** — an apology with the reason
2. The **patient's portal** — the cancelled appointment shows the reason

**Say:**
> "An appointment never simply disappears. The patient always knows why."

## 3.4 Patient Cancels — 24-Hour Rule

**Log in as patient:**

**Say:**
> "The patient can also cancel — but only up to **24 hours before**. After that the
> button is replaced with *Call clinic to cancel*, because there is no longer time
> to offer the slot to someone else."

**Cancel one and show:**
- It asks the patient for a reason too
- The **clinic receives an email** with that reason
- The admin's notification bell shows **🚫 1 appointment cancelled by patients**

---

# PART 4 — The Dentist

**Log in as the dentist.**

## 4.1 Role Scoping

**Say:**
> "The dentist sees only **their own** assigned patients. Not another dentist's."

**Show:** 👥 My Patients, 📅 Schedule — point out the list is shorter than the admin's.

> "This is enforced in the database query, not just hidden in the interface.
> Even if someone crafted a request by hand, the server checks ownership before
> allowing any change."

## 4.2 Odontogram — Recording a Visit

**Open:** 🦷 Odontogram → pick a patient

**Say:**
> "This is the dental chart. 32 teeth, upper and lower arch, using the standard
> two-digit notation."

**Demo:**
1. Click a tooth → set its condition (Decayed, Filled, Missing, Crowned, and so on)
2. Click **+ New Chart** → show the option to copy from the last visit or start blank

**Say:**
> "Each visit is its own record. Copying from the last visit is the normal choice —
> the dentist only changes what is different today."

**Point to the 📈 Progress panel:**
> "It compares this visit with the previous one and counts what improved and what
> worsened."

**Important — say this:**
> "Even for a check-up where nothing changes, the dentist creates a visit record
> titled *Check-up*. That matters for the no-show detection, which I will explain."

## 4.3 Patient Records

**Open:** 📋 Treatment Records

**Show the tabs:** Overview · Treatments · X-rays · Clinical Notes

> "X-ray images are uploaded and stored per patient. Clinical notes are dated."

## 4.4 Availability

**Open:** 🗓 My Availability

> "The dentist marks days off. The booking page then refuses those dates —
> patients cannot book a day the dentist is away."

---

# PART 5 — The Administrator

**Log in as admin.**

## 5.1 Dashboard

**Say:**
> "The admin sees everything. These four figures are live from the database —
> total patients, today's appointments, pending requests, and completed treatments."

**Point out:**
> "Below them, today's schedule and the most recent patients."

## 5.2 System Panel

**Click:** ⚙️ System — point to the tab bar.

| Tab | What it does |
|---|---|
| 👤 **User Management** | Create staff, dentist, and admin accounts |
| 📣 **Announcements** | Post a notice, and email it to all patients |
| ⭐ **Patient Reviews** | Approve or hide reviews before they appear publicly |
| 🎨 **Edit Landing Page** | Edit the whole public site |
| ✉️ **Messaging Config** | The email settings |
| ⚙️ **System** | Clinic info, hours, security, backup |

## 5.3 Creating an Account — Live Email

**Open:** 👤 User Management → **+ Add User**

**Say:**
> "Name is split into title, first, and last. Watch what happens with a bad email."

**Demo:** type `someone@gmial.com` (deliberate typo) → Save

> "Rejected. The system checks that the domain actually has a mail server, so a
> believable typo cannot get through. It also checks the format and whether the
> address is already used."

**Then use a real address:**
> "And the new account receives their login details by email — which also proves
> the address works."

## 5.4 Landing Page Editor

**Open:** 🎨 Edit Landing Page

**Say:**
> "Every heading, paragraph, and list on the public site is editable here — over
> 60 items. The clinic can change their services or hours without a programmer."

**Demo one live change:** edit a heading → Save → click **👁 View Landing Page**

## 5.5 Announcements — Live Email

**Open:** 📣 Announcements → create one → **Send**

> "It is posted in every patient's portal **and** emailed to all active patients.
> The system reports exactly how many were sent."

## 5.6 Messaging Config

**Open:** ✉️ Messaging Config

**Say:**
> "This is the email engine. It connects to Gmail over SMTP."

**Point to the status:**
> "It says **Verified**, with the date a real test email was sent. If the settings
> change, that verification resets — the system will not claim email works
> unless it has been proven."

**Show the email log** (in the database or mention it):
> "Every message is logged — recipient, type, status, and any error."

**Be honest about SMS:**
> "SMS is not implemented. A Philippine SMS gateway costs around fifty centavos per
> message and needs a registered sender name. We used email instead, which is free
> and reliable. SMS is in our recommendations for future development."

## 5.7 Reports

**Open:** 📑 Generate Reports

**Show all four types:**

| Report | Contents |
|---|---|
| 👤 **Patient Profile** | Full details, dental chart, medical history |
| 💊 **Treatment History** | All treatments for one patient |
| 📅 **Appointments List** | Filtered appointment list |
| 👥 **List of Patients** | Patient registry |

**Demo the two selectors:**
> "First, filter by dentist — this gives me only Dr. [name]'s patients.
> Second, and this is the important one — **which visit's dental chart** goes into
> the report. It defaults to the latest, but I can print an earlier visit."

**Click Print:**
> "The printed page shows only the report. The sidebar and buttons are hidden.
> Choosing *Save as PDF* in the print dialog produces a file."

## 5.8 No-Show Detection

**Open:** 📝 No-Show Report → **Needs Review** tab

**Say (this is your strongest technical story — take your time):**

> "This is the part we thought hardest about.
>
> The system **cannot actually know** whether a patient walked in. There is no
> scanner at the door. It can only infer from what was recorded.
>
> So each day, when the system is first opened, it looks for appointments that
> are still marked *Confirmed* even though the date has passed. Then — and this is
> the key part — it checks for **evidence of the visit**: a dental chart session
> dated that day, a treatment record, or a clinical note.
>
> If evidence exists, the patient clearly came. The system quietly marks it
> Completed. Nobody is bothered.
>
> If there is **no evidence at all**, it does not accuse the patient. It flags the
> appointment as **Needs Review** and waits for a person."

**Point to the two buttons:**
> "Staff choose: *Confirm no-show*, or *Did attend*. Only after a human confirms
> does the system send anything."

**Explain the escalation:**
> "First missed visit — a polite note. Second — a warning. Third — online booking
> pauses, and we ask them to walk in and talk to us. Not to phone: in person, the
> problem actually gets sorted out."

**Show the fairness rules:**
> "Two things keep this fair. The count uses a **rolling 12-month window**, so a
> patient of five years who missed three visits across all of them is not punished.
> And staff can **restore** a patient's booking after speaking to them — the missed
> visits stay on record, but the count starts fresh."

**Anticipate the obvious question:**
> "We deliberately did **not** make this fully automatic. If the dentist forgets to
> record a visit, an automatic system would email an apology-worthy accusation to a
> patient who actually showed up. So the machine remembers, and the human decides."

## 5.9 Manual Booking Block

**Open:** 👥 Patients

**Say:**
> "Staff can also pause a patient's online booking directly, without waiting for
> three no-shows. It requires a reason, and the patient is shown that reason
> when they try to book."

## 5.10 Data & Backup

**Open:** ⚙️ System → scroll to **📦 Data & Backup**

> "Live figures — the number of tables, total records, and the actual database size
> read from MySQL. Below it, the backup procedure through phpMyAdmin."

---

# PART 6 — Closing

## Summary Statement

> "To summarise what the system does:
>
> **For patients** — book online, see their own dental chart across every visit,
> print a confirmation slip, cancel within the policy window, and reset their own
> password.
>
> **For the clinic** — approve and manage appointments, keep a versioned dental
> chart per visit, generate four kinds of report, and detect missed appointments
> without accusing anyone unfairly.
>
> **Throughout** — four roles with strictly separated access, and real email at
> every step that matters."

## Technical Summary (if asked)

| | |
|---|---|
| **Front end** | HTML, CSS, JavaScript, Bootstrap 5 |
| **Back end** | PHP with PDO prepared statements |
| **Database** | MySQL — 13 tables |
| **Server** | Apache via XAMPP |
| **Email** | Custom SMTP client over TLS to Gmail |
| **Authentication** | Password hashing (bcrypt), email verification, Google OAuth 2.0 |
| **Methodology** | Modified Waterfall |
| **Evaluation** | ISO 25010 quality model |

---

# Anticipated Questions

**"How do you protect patient data?"**
> "Three layers. First, **role-based access** — the dentist sees only their assigned
> patients; front-desk staff cannot open clinical records at all. Second, **password
> hashing** with bcrypt, so even we cannot read a stored password. Third, every
> restriction is enforced **server-side**, not just hidden in the interface —
> a crafted request is still rejected. This follows the data minimisation
> principle in RA 10173."

**"What if two patients book the same slot?"**
> "The system checks for a conflict before saving. If that dentist already has an
> appointment at that date and time, the booking is refused with an explanation."

**"Why email and not SMS?"**
> "Cost and reliability. SMS in the Philippines requires a paid gateway and an
> approved sender name. Email is free, immediate, and every patient who registers
> already has a verified address. SMS is in our future recommendations."

**"What happens if the computer is off overnight?"**
> "That is exactly why the no-show scan does **not** use a scheduled task. It runs
> the first time the system is opened each day and catches up on every day that was
> missed while the computer was off."

**"Can a dentist change another dentist's appointment?"**
> "No. The query filters by ownership, and there is a second check on the server
> before any status change. We can demonstrate that if you would like."

**"How is the dental chart different from a paper chart?"**
> "A paper chart is overwritten — you erase and redraw, and the history is gone.
> Ours saves a separate chart per visit, so you can open any past visit and see
> exactly how the teeth looked that day, plus an automatic comparison of what changed."

**"What are the system's limitations?"**
> Be honest — this is a strong answer, not a weak one:
> - No SMS
> - Runs on a local server, so it is only reachable inside the clinic
> - Backup is manual through phpMyAdmin
> - No billing or payment module
> - No-show detection depends on the dentist recording visits

**"What would you add next?"**
> "Online payment, SMS notifications, a mobile application, automated backups,
> and cloud hosting so patients could reach it from anywhere."

---

# Quick Reference — Page Map

| Page | Who | Purpose |
|---|---|---|
| `index.php` | Public | Landing page |
| `login.php` | Public | Login, register, email verification |
| `forgot_password.php` | Public | Password reset by email code |
| `google_auth.php` | Public | Google sign-in |
| `book.php` | Patient | Book an appointment |
| `portal.php` | Patient | Appointments, chart, records, announcements |
| `slip.php` | All | Printable appointment slip |
| `dashboard.php` | Admin, Dentist, Staff | Overview and statistics |
| `appointments.php` | Admin, Dentist, Staff | Approve, cancel, complete |
| `patients.php` | Admin, Dentist, Staff | Patient management |
| `reports.php` | Admin, Dentist, Staff | Four report types |
| `noshow.php` | Admin, Dentist, Staff | Missed appointment review |
| `odontogram.php` | Admin, Dentist | Dental charting per visit |
| `records.php` | Admin, Dentist | Treatments, X-rays, clinical notes |
| `schedule.php` | Admin, Dentist | Days off |
| `settings.php` | Admin, Dentist, Staff | Profile; admin also gets clinic settings |
| `admin_users.php` | Admin | Create and manage accounts |
| `admin_dentists.php` | Admin | Dentist profiles |
| `announcements.php` | Admin | Post and email notices |
| `reviews.php` | Admin | Moderate patient reviews |
| `landing_edit.php` | Admin | Edit the public site |
| `messaging.php` | Admin | Email configuration |

---

# Timing

| Part | Minutes |
|---|---|
| Opening | 1 |
| Public side + registration | 4 |
| Patient portal + booking + chart | 6 |
| Staff + approval + cancellation | 5 |
| Dentist + odontogram | 5 |
| Admin + system panel | 6 |
| No-show detection | 4 |
| Closing | 2 |
| **Total** | **~33 minutes** |

If you are given 15 minutes, cut to: landing page → booking → approval with live
email → odontogram visit tabs → no-show detection → closing. Those five carry the
strongest material.

---

# Final Reminders

- **Speak to the problem, not the code.** The panel wants to know what the clinic
  gains, not how the loop works.
- **When a live email arrives on screen, pause.** Let them see it. It is the most
  convincing moment in the demo.
- **If something breaks, say so plainly** and move on. Composure reads better than
  a scramble.
- **Do not oversell.** If asked about SMS or payments, say they are not implemented.
  A panel trusts a team that knows its own limits.
