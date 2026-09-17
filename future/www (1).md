# os-15 — Implementation Plan: Parent Portal, Guardians, Teachers & Student Photos

**Goal:** turn `guardian_phone` (a string) into real Guardian accounts that can log
in, see their own child(ren)'s results/remarks/analytics/tips, add student
photos end-to-end, and introduce Teachers as a first-class entity so
RBAC has something real to restrict against.

This depends on / feeds into the RBAC work already flagged in
`os-15-notes.md` — build them together, not RBAC first then this bolted on.

---

## 0. Order of implementation (do in this order)

1. Database migrations (guardians, teachers, pivots, remarks, image already exists)
2. Models
3. Seeders (roles: `admin`, `teacher`, `guardian` + a demo guardian/teacher)
4. RBAC filter update (role-aware, not just "logged in")
5. Auth changes (registration flow differs per role; guardian self-registration
   vs admin-created teacher accounts)
6. Student photo upload (admin/registration side)
7. Teacher assignment + teacher dashboard
8. Guardian linking (admin links guardian to student during/after registration)
9. Parent portal (dashboard, results, remarks, analytics, tips, PDF)
10. SMS notification hook on result publish (Africa's Talking)
11. Testing pass (manual flow test end to end, one of each role)

---

## 1. Database migrations

### 1.1 `guardians`
```php
$this->forge->addField([
    'id'         => ['type' => 'CHAR', 'constraint' => 36],
    'user_id'    => ['type' => 'CHAR', 'constraint' => 36, 'null' => true], // link to users table (nullable until they activate login)
    'firstname'  => ['type' => 'VARCHAR', 'constraint' => 100],
    'lastname'   => ['type' => 'VARCHAR', 'constraint' => 100],
    'phone'      => ['type' => 'VARCHAR', 'constraint' => 20, 'unique' => true],
    'email'      => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
    'relation'   => ['type' => 'ENUM', 'constraint' => ['father','mother','guardian','other']],
    'is_active'  => ['type' => 'ENUM', 'constraint' => ['yes','no'], 'default' => 'yes'],
    'created_at' => ['type' => 'DATETIME'],
    'updated_at' => ['type' => 'DATETIME', 'null' => true],
]);
$this->forge->addPrimaryKey('id');
$this->forge->addForeignKey('user_id', 'users', 'id', onDelete: 'SET NULL');
$this->forge->createTable('guardians');
```

### 1.2 `student_guardians` (pivot — many-to-many)
```php
$this->forge->addField([
    'id'           => ['type' => 'CHAR', 'constraint' => 36],
    'student_id'   => ['type' => 'CHAR', 'constraint' => 36],
    'guardian_id'  => ['type' => 'CHAR', 'constraint' => 36],
    'is_primary'   => ['type' => 'ENUM', 'constraint' => ['yes','no'], 'default' => 'no'], // who gets SMS first
    'created_at'   => ['type' => 'DATETIME'],
]);
$this->forge->addPrimaryKey('id');
$this->forge->addUniqueKey(['student_id', 'guardian_id']);
$this->forge->addForeignKey('student_id', 'students', 'id', onDelete: 'CASCADE');
$this->forge->addForeignKey('guardian_id', 'guardians', 'id', onDelete: 'CASCADE');
$this->forge->createTable('student_guardians');
```

### 1.3 `teachers`
```php
$this->forge->addField([
    'id'             => ['type' => 'CHAR', 'constraint' => 36],
    'user_id'        => ['type' => 'CHAR', 'constraint' => 36],
    'employee_no'    => ['type' => 'VARCHAR', 'constraint' => 50, 'unique' => true],
    'firstname'      => ['type' => 'VARCHAR', 'constraint' => 100],
    'lastname'       => ['type' => 'VARCHAR', 'constraint' => 100],
    'phone'          => ['type' => 'VARCHAR', 'constraint' => 20],
    'specialization' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true], // e.g. "Mathematics"
    'is_active'      => ['type' => 'ENUM', 'constraint' => ['yes','no'], 'default' => 'yes'],
    'created_at'     => ['type' => 'DATETIME'],
    'updated_at'     => ['type' => 'DATETIME', 'null' => true],
]);
$this->forge->addPrimaryKey('id');
$this->forge->addForeignKey('user_id', 'users', 'id', onDelete: 'CASCADE');
$this->forge->createTable('teachers');
```

### 1.4 `teacher_assignments`
```php
$this->forge->addField([
    'id'               => ['type' => 'CHAR', 'constraint' => 36],
    'teacher_id'       => ['type' => 'CHAR', 'constraint' => 36],
    'class_section_id' => ['type' => 'CHAR', 'constraint' => 36], // FK -> class_sections
    'subject_id'       => ['type' => 'CHAR', 'constraint' => 36], // FK -> exam_subjects or a new `subjects` master table
    'session_id'       => ['type' => 'CHAR', 'constraint' => 36], // FK -> sessions (academic year/term)
    'created_at'       => ['type' => 'DATETIME'],
]);
$this->forge->addPrimaryKey('id');
$this->forge->addUniqueKey(['teacher_id', 'class_section_id', 'subject_id', 'session_id']);
$this->forge->createTable('teacher_assignments');
```
> Note: check whether a standalone `subjects` master table exists — if
> subjects currently only live inside `exam_subjects` (per-exam), pull them
> into their own `subjects` table first so a teacher can be assigned to a
> subject independent of any specific exam.

### 1.5 `remarks`
```php
$this->forge->addField([
    'id'         => ['type' => 'CHAR', 'constraint' => 36],
    'student_id' => ['type' => 'CHAR', 'constraint' => 36],
    'exam_id'    => ['type' => 'CHAR', 'constraint' => 36],
    'subject_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true], // null = general/overall remark
    'teacher_id' => ['type' => 'CHAR', 'constraint' => 36],
    'comment'    => ['type' => 'TEXT'],
    'created_at' => ['type' => 'DATETIME'],
    'updated_at' => ['type' => 'DATETIME', 'null' => true],
]);
$this->forge->addPrimaryKey('id');
$this->forge->createTable('remarks');
```

### 1.6 Student photo — already exists
`students.image` (VARCHAR 255) + `StudentModel` validation already present.
No migration needed — just wire up the upload flow (Section 6).

---

## 2. Models

- `GuardianModel` — `allowedFields`: user_id, firstname, lastname, phone,
  email, relation, is_active. Add a `getStudents($guardianId)` helper that
  joins `student_guardians` → `students`.
- `StudentGuardianModel` — thin pivot model.
- `TeacherModel` — mirrors `GuardianModel` pattern. Add `getAssignments($teacherId)`.
- `TeacherAssignmentModel` — pivot model, with `getClassesForTeacher()` /
  `getSubjectsForTeacher()` helpers used by the teacher dashboard.
- `RemarkModel` — `allowedFields`: student_id, exam_id, subject_id,
  teacher_id, comment. Add `getForStudentExam($studentId, $examId)`.

Follow the existing `BaseModel` pattern (UUID `beforeInsert`, see
`UserRole::ensureUuid` as the reference implementation) for all of the above.

---

## 3. Seeders

Update / extend the existing role seeding to guarantee three roles exist:
`admin`, `teacher`, `guardian` (type field on `user_roles` already supports
this). Add a `DemoGuardianTeacherSeeder` for local dev: one demo teacher
assigned to a class/subject, one demo guardian linked to a demo student, so
you can test the parent portal without registering real data every time.

---

## 4. RBAC filter update

Replace the current `AuthGuard` (login-only check) with a role-aware filter.

```php
// app/Filters/RoleGuard.php
class RoleGuard implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = session();
        if (! $session->get('isLoggedIn')) {
            return redirect()->to('/login');
        }
        if ($arguments && ! in_array($session->get('role'), $arguments)) {
            return redirect()->to('/dashboard')
                ->with('error', 'You do not have access to that page.');
        }
    }
}
```

Route usage:
```php
$routes->group('parent', ['filter' => 'role:guardian'], function ($routes) {
    $routes->get('/', 'GuardianPortalController::index');
    $routes->get('child/(:segment)', 'GuardianPortalController::child/$1');
});

$routes->group('teacher', ['filter' => 'role:teacher'], function ($routes) {
    $routes->get('/', 'TeacherDashboardController::index');
});

$routes->group('', ['filter' => 'role:admin'], function ($routes) {
    // existing admin-only routes (students, classes, exams, etc.)
});
```
`role` must be stored in session at login (`AuthController::login`) by
looking up the user's role via `user_user_roles` → `user_roles`.

---

## 5. Auth changes

- **Admin/Teacher accounts**: created by an admin (not self-registration) —
  add a "Create Teacher" form under admin that creates a `users` row +
  `teachers` row + role assignment in one transaction.
- **Guardian accounts**: two options, pick one for v1:
  - (a) *Admin-initiated*: admin creates the guardian record while
    registering a student (simplest, matches how schools actually operate —
    admin office already collects parent phone/name at enrollment), then
    sends an SMS/email invite with a link to set a password.
  - (b) *Self-service*: guardian registers with their phone number, and the
    system matches it against an existing `guardian_phone` migrated from a
    student record, or an admin approves the match.
  - **Recommendation: start with (a)** — far less ambiguity, and Tanzanian
    schools already collect this data at enrollment.

---

## 6. Student photo upload flow

1. `students/create` and `students/edit` forms: add `<input type="file" name="image">`
2. `StudentManagementController::store()` / `update()`: validate via existing
   `is_image[image]|max_size[1024]` rule, then upload to MinIO using the
   existing `MinioService.php` (don't reinvent — reuse it), store the
   returned object key/URL in `students.image`
3. Add a `getStudentImageUrl()` helper (in `StudentModel` or a view helper)
   that returns a signed MinIO URL or a default avatar path if `image` is null
4. Display the photo: student list thumbnail, student profile page, and
   critically — **inject into the PDF report card** in `PDFController.php`
   (dompdf/tcpdf both support embedding images by URL/path)

---

## 7. Teacher dashboard flow

1. `TeacherDashboardController::index()` — pull the logged-in teacher's
   `teacher_assignments`, list their classes/subjects for the current session
2. `TeacherDashboardController::marks($classSectionId, $subjectId)` — reuse
   the existing `BulkExamMarksController` logic but scoped/pre-filtered to
   only that teacher's assignment (don't let a teacher post marks for a
   class/subject they're not assigned to — check in the controller, not just hide in UI)
3. `TeacherDashboardController::addRemark($studentId, $examId)` — form to
   write a `remarks` row for a student in a class/subject they teach

---

## 8. Guardian linking flow (admin side)

1. During `StudentManagementController::store()` (or right after), show a
   "Guardians" section: search existing guardian by phone, or create new
2. On submit: create/find `guardians` row, insert into `student_guardians`
   with `is_primary` flag
3. Support multiple guardians per student (father + mother), and multiple
   students per guardian (siblings) — the pivot table already allows this,
   just needs UI for "add another guardian" / "link existing student"

---

## 9. Parent portal flow (the main feature)

```
Guardian logs in
   -> session role = 'guardian', guardian_id resolved from users.id -> guardians.user_id
GuardianPortalController::index()
   -> GuardianModel::getStudents(guardian_id) -> list of linked students
   -> if only 1 child: redirect straight to child view
   -> if multiple: show child-switcher cards (photo + name + class)

GuardianPortalController::child($studentId)
   -> verify this student_id belongs to this guardian (student_guardians check!)
   -> pull: current session's published results (ExamResultModel / AlevelExamResultModel)
   -> pull: remarks for this student this term (RemarkModel::getForStudentExam)
   -> pull: historical results across sessions for trend chart (reuse DataAnalyticsController queries, scoped to one student)
   -> compute: simple tips (see below)
   -> render dashboard: photo, class, division/GPA, subject-by-subject table,
      remarks, trend chart, "Download report card PDF" button
```

### Tips logic (v1 — rule-based, no AI needed yet)
Simple thresholds computed server-side per subject, compared to the
student's own previous-term score (not a class average, to keep this data-
light and avoid needing cross-student aggregation for v1):

- Drop of >10% vs previous term in a subject → "Your child's performance in
  {subject} has declined this term. Consider arranging extra study time or
  speaking with the {subject} teacher."
- Consistent top performer (division I, 2+ terms) → encouragement message
- Failing grade in a subject → flag prominently, suggest teacher meeting

This can be upgraded later to an AI-generated narrative (feed the term's
scores + remarks into a Claude API call for a natural-language summary) —
worth doing as a v2, not blocking v1.

---

## 10. SMS notification hook

In the result-publishing controller (`PublishAlevelResults` /
`ResultGradingController` publish action), after results are marked
published:
```php
foreach ($student->guardians as $guardian) {
    if ($guardian['is_primary'] === 'yes' || count($student->guardians) === 1) {
        $this->smsService->send(
            $guardian['phone'],
            "Matokeo ya {$student->firstname} kwa muhula huu yamechapishwa. Fungua akaunti yako kuona zaidi."
        );
    }
}
```
Wrap Africa's Talking in a small `SmsService` library (mirrors the existing
`MinioService.php` pattern) so it's easy to swap providers later.

---

## 11. Testing checklist (manual, end to end)

- [ ] Admin creates a teacher account → teacher can log in, sees only their assigned class/subject
- [ ] Admin registers a student with a photo → photo appears in list, profile, and PDF report card
- [ ] Admin links two guardians to one student (father + mother)
- [ ] Guardian logs in → sees correct child(ren) only, cannot access another guardian's child by guessing a student_id in the URL
- [ ] Teacher posts marks for their assigned class → cannot post marks for a class they're not assigned to (test by editing the URL/form directly)
- [ ] Teacher adds a remark → guardian sees it in the portal
- [ ] Result published → SMS fires to primary guardian
- [ ] Trend chart renders correctly with 2+ terms of data
- [ ] Tip logic triggers correctly on a >10% drop test case

---

## 12. Additional killer features — implementation notes

These were selected from the 15-idea list as the next batch to plan out.
They're independent of the Guardian/Teacher/Photo work above and can be
built in parallel once RBAC (role-aware filter, Section 4) is in place,
since all four need role-scoped access.

### 12.1 Broadcast Center (admin → parents, general announcements)

**Purpose:** send announcements that aren't tied to results — school
closures, holidays, fee deadlines, events — to all parents or a filtered
subset (by class, by session).

**New table `broadcasts`:**
```php
$this->forge->addField([
    'id'             => ['type' => 'CHAR', 'constraint' => 36],
    'title'          => ['type' => 'VARCHAR', 'constraint' => 200],
    'message'        => ['type' => 'TEXT'],
    'target_type'    => ['type' => 'ENUM', 'constraint' => ['all','class_section','individual']],
    'target_id'      => ['type' => 'CHAR', 'constraint' => 36, 'null' => true], // class_section_id or student_id, null if target_type = all
    'channel'        => ['type' => 'ENUM', 'constraint' => ['sms','whatsapp','in_app','all']],
    'created_by'     => ['type' => 'CHAR', 'constraint' => 36], // admin user id
    'sent_at'        => ['type' => 'DATETIME', 'null' => true],
    'created_at'     => ['type' => 'DATETIME'],
]);
```
**New table `broadcast_deliveries`** (per-guardian send log, for delivery
status and so a guardian's in-app inbox can query "my broadcasts"):
```php
$this->forge->addField([
    'id'           => ['type' => 'CHAR', 'constraint' => 36],
    'broadcast_id' => ['type' => 'CHAR', 'constraint' => 36],
    'guardian_id'  => ['type' => 'CHAR', 'constraint' => 36],
    'status'       => ['type' => 'ENUM', 'constraint' => ['pending','sent','failed','read']],
    'sent_at'      => ['type' => 'DATETIME', 'null' => true],
    'read_at'      => ['type' => 'DATETIME', 'null' => true],
]);
```

**Flow:**
1. `BroadcastController::create()` — admin picks target (all / class / one
   student's guardians), writes message, picks channel
2. `BroadcastController::send($id)` — resolves target into a guardian list
   (reuse `GuardianModel::getStudents()` reversed — i.e. get all guardians
   for a class section via `student_guardians` join), inserts a
   `broadcast_deliveries` row per guardian, then dispatches via `SmsService`
   (Section 10) and/or in-app
3. Guardian portal gets a new "Announcements" tab pulling their
   `broadcast_deliveries` rows, marking `read_at` on open
4. Queue the actual sending (don't block the admin's request on hundreds of
   SMS calls) — CodeIgniter has a Queue-able job pattern, or a simple
   `spark` command run via cron for v1 if a proper queue is overkill early

---

### 12.2 Timetable Auto-Generator

**Purpose:** generate a weekly period timetable per class section without
teacher/room double-booking, using the `teacher_assignments` data already
planned in Section 1.4.

**New tables:**
```php
// periods — the fixed daily time slots a school uses
$this->forge->addField([
    'id'         => ['type' => 'CHAR', 'constraint' => 36],
    'label'      => ['type' => 'VARCHAR', 'constraint' => 50], // "Period 1", "08:00-08:40"
    'start_time' => ['type' => 'TIME'],
    'end_time'   => ['type' => 'TIME'],
    'sort_order' => ['type' => 'INT'],
]);

// timetable_slots — the generated/edited assignment of subject+teacher to a class/day/period
$this->forge->addField([
    'id'               => ['type' => 'CHAR', 'constraint' => 36],
    'class_section_id' => ['type' => 'CHAR', 'constraint' => 36],
    'day_of_week'      => ['type' => 'ENUM', 'constraint' => ['mon','tue','wed','thu','fri','sat']],
    'period_id'        => ['type' => 'CHAR', 'constraint' => 36],
    'subject_id'       => ['type' => 'CHAR', 'constraint' => 36],
    'teacher_id'       => ['type' => 'CHAR', 'constraint' => 36],
    'session_id'       => ['type' => 'CHAR', 'constraint' => 36],
]);
$this->forge->addUniqueKey(['class_section_id', 'day_of_week', 'period_id', 'session_id']); // no double-booking a class/slot
```

**Generation algorithm (v1 — constraint satisfaction, keep it simple):**
1. Input: list of `teacher_assignments` (which teacher teaches which
   subject to which class), required periods-per-week per subject (add a
   `periods_per_week` column to a `subjects` master table if not already
   planned), and the fixed `periods` list
2. Greedy/backtracking scheduler: for each class section, for each subject
   needing N periods/week, place them across days avoiding:
   - same teacher double-booked in the same day/period across different classes
   - same class double-booked in the same day/period (enforced by the unique key above)
   - (optional v2) no subject twice in one day for the same class
3. If the greedy pass can't place a slot without conflict, flag it for
   manual resolution rather than silently failing — surface a "N slots
   need manual placement" screen
4. Admin can drag-and-drop edit the generated timetable afterward
   (manual overrides always win over the generator)
5. Teacher dashboard and parent portal both get a **read-only timetable
   view** once generated — teachers see their own weekly schedule, parents
   see their child's class schedule

**Note:** this is the most complex item in this batch — treat it as its own
mini-project, build after Broadcast Center and once `teacher_assignments`
already has real data flowing through it from actual usage.

---

### 12.3 Multi-School / Multi-Tenant

**Purpose:** turn os-15 from a single-school deployment into a SaaS product
sellable to multiple schools, each with isolated data and their own branding.

**Approach: shared database, tenant-scoped rows** (simpler to build and
operate than separate databases per school, and this codebase's scale
doesn't need DB-per-tenant yet).

**New table `schools`:**
```php
$this->forge->addField([
    'id'          => ['type' => 'CHAR', 'constraint' => 36],
    'name'        => ['type' => 'VARCHAR', 'constraint' => 150],
    'subdomain'   => ['type' => 'VARCHAR', 'constraint' => 50, 'unique' => true], // e.g. stmarys.yourproduct.co.tz
    'logo_path'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
    'primary_color' => ['type' => 'VARCHAR', 'constraint' => 7, 'null' => true], // branding hex
    'is_active'   => ['type' => 'ENUM', 'constraint' => ['yes','no'], 'default' => 'yes'],
    'created_at'  => ['type' => 'DATETIME'],
]);
```

`school_id` already exists on `students` (seen in `StudentModel`!) — good,
that part is half-done. Need to add `school_id` to: `users`, `teachers`,
`guardians`, `classes`, `sections`, `exams`, `sessions`, `broadcasts`,
`timetable_slots` — essentially every table that isn't truly global.

**Tenant resolution & isolation:**
1. Resolve `school_id` from subdomain (or a school-picker at login if not
   using subdomains yet) in a `TenantFilter` that runs before every request,
   storing `school_id` in session
2. **Critical:** every model query must be scoped to `school_id` — the
   cleanest way is a global scope in `BaseModel` (`$this->where('school_id',
   session('school_id'))` applied automatically) rather than remembering to
   add it in every controller — a single missed `where` is a cross-tenant
   data leak
3. Super-admin role (above `admin`) manages the `schools` table itself —
   create new school, set branding, activate/deactivate, see cross-tenant
   usage stats (for the SaaS billing side)
4. Branding: pull `logo_path`/`primary_color` from the resolved school and
   inject into the layout template (CSS variable or inline) so each
   school's portal looks like their own

**This is the biggest structural change in the whole plan** — do it after
the Guardian/Teacher/RBAC work is stable, because retrofitting tenant
scoping onto a codebase with live multi-role data is much easier to test
than adding it at the very start blind. Budget real time for a migration
script that backfills `school_id` on every existing row to a "default"
school before enforcing the scope.

---

### 12.4 Kiswahili/English Toggle (full UI, not just docs)

**Purpose:** most parents, especially outside Dar/major towns, are more
comfortable in Kiswahili. CodeIgniter 4 has built-in i18n support — use it
rather than hand-rolling string swapping.

**Setup:**
1. `app/Language/en/` already exists (has `Validation.php`) — add
   `app/Language/sw/` mirroring the same file structure
2. Create language files per feature area: `Language/sw/Students.php`,
   `Language/sw/Results.php`, `Language/sw/Portal.php`, etc. — key-value
   pairs, e.g. `'results' => 'Matokeo'`
3. In views, replace hardcoded strings with `lang('Portal.results')`
4. Store locale preference: a `locale` column on `users` (and `guardians`
   if guardians aren't always `users` yet), default `sw` for guardians
   (parents), `en` or `sw` selectable for admin/teacher
5. A `LocaleFilter` sets `$request->setLocale()` per request based on the
   logged-in user's stored preference, with a simple toggle in the top nav
   that updates it via a small POST endpoint
6. **SMS and broadcast messages also need sw/en templates** — this isn't
   just a UI toggle, the notification content in Section 9/12.1 should pull
   from the same language files so a Kiswahili-preferring parent gets
   Kiswahili SMS too

**Scope warning:** translating the *entire* existing UI (all the Views
listed in `os-15-notes.md`) is a large one-time content task, separate from
the engineering setup above — plan it as translation work, not dev work,
once the i18n plumbing exists.

---

### 12.5 NECTA/EMIS Export Format

**Purpose:** let the school export result data in the format Tanzania's
NECTA/EMIS reporting expects, instead of manual re-entry into government
systems.

**Before building:** this needs a concrete confirmed spec — NECTA and EMIS
have specific CSV/XML column layouts and field codes (subject codes,
school registration numbers, candidate number formats) that must match
exactly or the government system will reject the file. **Get an actual
sample template/spec from NECTA or an existing school that's submitted one
before writing the exporter** — guessing the format wastes the most
implementation time of anything on this list.

**Once the spec is confirmed:**
1. `ExportController::necta($examId)` — pulls `ExamResultModel` /
   `AlevelExamResultModel` data, maps internal fields → required export
   columns (this mapping table is the actual deliverable — keep it in a
   config file, e.g. `app/Config/NectaExportMap.php`, so field changes
   don't require touching controller code)
2. Reuse `phpoffice/phpspreadsheet` (already a dependency) for
   CSV/XLSX export — no new package needed
3. Validate before export: candidate numbers present, no missing subject
   codes, no unpublished results included — surface a pre-export checklist
   screen rather than generating a file that will bounce at submission

---

## Open questions to resolve before coding starts
1. Does a standalone `subjects` master table exist, or are subjects only
   scoped per-exam right now? (Affects `teacher_assignments` design — see note in 1.4)
2. Guardian self-registration (5b) vs admin-initiated (5a) — confirmed
   recommendation is (a), flag if you want otherwise
3. MySQLi vs PostgreSQL — needs resolving before any of these migrations run
   (blocks everything in this plan, not just this feature)
