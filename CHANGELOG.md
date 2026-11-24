# Changelog

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
