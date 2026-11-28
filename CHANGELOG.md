# Changelog

## 0.3.0 (2026112800)
- New page **Company overview** (`/local/merqamreports/overview.php`): four
  tiles (completion rate, overdue, due in the next 30 days, certificates
  issued) and two core charts (allocations by status; status per program)
  for one company. It counts the "Program allocations" rows of programs
  placed directly in the company category, without archived programs or
  allocations.
- New setting **Company categories**: top-level course categories only
  (other ids refused on save; saved ids re-checked on every use, with an
  admin warning for deleted or moved ones).
- **Fail closed for site-wide grants:** a non-admin who holds
  `local/merqamreports:viewprogramreports` in the system context now gets
  no rows in "Program allocations" and "My team", and the overview refuses
  them.
- A configured company category that is no longer top-level (moved under
  another category) is removed, with everything below it, from what
  non-admins may see, so another company's managers never inherit it.
  A normal settings save keeps such a category saved. Change company
  categories only on the settings page (not by admin preset or CLI).
- The overview refuses "Log in as" sessions, and gives one permission
  error for any wrong, missing or tampered company parameter.
- `status::get_sql()` takes an optional reference time.
- Tests for the overview, the site-wide refusal in both data sources and
  parity with a real company report.

## 0.2.0 (2026112600)
- New data source **"My team"**: the program allocations of the viewer's own
  current subordinates. A row needs the company lock (as in the company
  reports) **and** a supervisor link in a Supervisors-mode framework that
  restricts both sides by cohort, with both people currently in those
  cohorts and the viewer not suspended. Teams-mode teams and frameworks
  without cohorts grant nothing. Site admins get no rows unless they are a
  supervisor themselves. Available only with tool_murelation installed at
  a supported version.
- The company data source now decides access in one overridable method.
- Percentages shown as whole numbers ("75%", not "75.0%").
- Arabic: the capability name says تصنيف (category), as فئة now means cohort.
- Tests for "My team"; CI also pins tool_murelation.

## 0.1.0 (2026112400)
- First version: "Program allocations" Report builder data source with
  program, allocation, certificate, course category and user entities.
- Status as a sortable number (overdue first) with English and Arabic
  labels.
- Required progress (only items needed for completion).
- Company separation through the capability
  `local/merqamreports:viewprogramreports` (base condition).
- Unit tests for the progress calculation; database tests for company
  separation, status, certificate rows and the Report builder stress tests.
- GitHub Actions CI pinned to the demo server versions (Moodle v5.3.1,
  MuTMS commits, tool_certificate v5.0.12), plus MOODLE_503_STABLE.
- Filters whose options are not limited by company (category select,
  user select) are left out.
