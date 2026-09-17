# os-15 — Tanzania Results Management System — Notes

**Repo:** https://github.com/Qarma-Labs/os-15
**Internal name:** School-results-management-system
**Last commit:** 2025-10-27 (~11 months stale as of Sep 2026)

## What it is
PHP CodeIgniter 4 app for schools to manage O-Level and A-Level student results —
class/section management, student registration (single + bulk), exam setup,
marks entry (single + bulk via Excel), result grading, PDF report cards,
public result search, and an analytics dashboard.

## Stack
- CodeIgniter 4, PHP 8.1+
- Composer packages: `africastalking/africastalking` (SMS, unused so far),
  `dompdf` + `tecnickcom/tcpdf` (PDF), `phpoffice/phpspreadsheet` (bulk Excel),
  `predis/predis` (Redis), `aws/aws-sdk-php`
- Docker: PHP-FPM (AlmaLinux 9), Nginx, MinIO (object storage)
- **Known issue:** `.env.docker` is configured for MySQLi, but
  `docker-compose.yml` / `DOCKER_README.md` describe PostgreSQL 16 + MinIO.
  These don't match — needs to be resolved before deploying.

## What's already built
- Auth (custom login/register, not CodeIgniter Shield despite a note in `docs/CRUSH.md`)
- Class & Section management + class-section allocations (Form 1–6)
- Student management: single + bulk registration
- Exam management: create exam, add subjects, allocate exam to classes
- O-Level and A-Level handled as fully separate flows (A-Level has combinations, e.g. PCM/PCB)
- Bulk marks upload, result grading, result publishing, PDF report cards
- Analytics dashboard (`DataAnalyticsController`)
- Settings / academic sessions (years/terms)
- Public result search (open, no login — `public/results`)
- `user_roles` + `user_user_roles` pivot table model exists (RBAC data model), but **not enforced**

## Gaps found while digging
- **RBAC not enforced** — `AuthGuard` filter only checks `isLoggedIn`; no route
  is restricted by role (Admin / Teacher / Head of School). Any logged-in
  user can reach any route.
- **Database mismatch** — MySQLi vs PostgreSQL configs disagree (see above)
- **No real audit trail** — only an `AuditBackfillSeeder`; no logging of who
  changed which marks
- No automated tests beyond the CodeIgniter starter examples
- No committed `.env` (development) — needs to be created to run locally
- `Queries.txt` and `my.sql` in repo root — old raw SQL, worth checking for
  logic that never made it into migrations

## Feature backlog

### 🔴 Priority 1 — fix before anything else
1. Enforce RBAC on routes (Admin / Teacher / Head of School) using the
   existing `user_roles` data model
2. Resolve the MySQLi vs PostgreSQL config mismatch
3. Build a real audit trail for marks changes (who/when/what)

### 🟡 Priority 2 — high value, foundation already exists
4. SMS notifications to parents via Africa's Talking (dependency already
   installed, unused) — e.g. send result/ranking SMS after publishing
5. Parent/Guardian portal — replace open public search with guardian login
   linked to their specific student(s) + notification history
6. Auto-calculate GPA / NECTA-style Division (I–IV) if not already done in
   `ResultGradingController`
7. Teacher-specific dashboard — restrict a teacher's view to their assigned
   classes/subjects only
8. Term-to-term / class-to-class performance trend comparisons in analytics
9. Excel/CSV export of results (phpspreadsheet already installed for bulk
   upload, not yet used for export)

### 🟢 Priority 3 — later / nice-to-have
10. Student promotion/graduation workflow (auto-advance to next form at year end)
11. Fees/payment module, with results gated on fee status (common requirement in TZ)
12. Multi-school / multi-tenant support (if selling as SaaS)
13. Two-factor or email verification on `AuthController`
14. JSON API endpoints for a future mobile app

## Next step (pending decision)
Discussed starting with either:
- (1) RBAC middleware — most critical gap, or
- (4) SMS integration — quick win, dependency already installed
