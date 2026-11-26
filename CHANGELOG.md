# Changelog

## 0.2.0 (2026112600)
- New data source **"My team"**: the program allocations of the viewer's own
  subordinates (MuTMS Supervisors and teams, any framework). Nobody else,
  site admins included, gets rows; available only with tool_murelation.
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
