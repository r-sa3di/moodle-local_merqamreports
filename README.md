# Almerqam reports (local_merqamreports)

A Moodle 5.3 plugin that adds a **"Program allocations"** data source to
Moodle's Report builder (custom reports), for programs made with the
MuTMS Programs plugin (`tool_muprog`).

A second data source, **"My team"**, shows the same rows limited to the
viewer's own subordinates (MuTMS Supervisors and teams). One "My team"
report can be shared with the managers of every company.

One row is one learner in one program, with:
- program status: Overdue, Open, Not started yet, Ended without completion,
  Completed, Archived (sorted overdue first);
- **required progress**: only what the program needs for completion (an
  optional course in an "At least 1 of 2" set doesn't lower it);
- start, due, end and completion dates, days overdue, how the learner was
  allocated;
- the program certificate code and issue date (tool_certificate);
- the learner's details and the program's course category.

The plugin only reads data. It adds no tables and changes nothing.

## Company separation
The data source only returns programs whose context is a **course
category** in which the viewer holds
`local/merqamreports:viewprogramreports`. The capability is given to no
role by default. Site admins see every category. Draft programs and
programs at site level never appear.

Report builder rules for safe use:
- Give every company report a **Course category** condition.
- Share reports only with that company's managers (audience).
- Schedules for this data source must be sent **as "Schedule recipient"**,
  never as the creator or a selected user.
- Company managers must not get `moodle/reportbuilder:edit`, `editall`,
  `viewall` or `scheduleviewas`.

## Requirements
- Moodle 5.3
- `tool_muprog` 2026111753 or later (MuTMS Programs, 5.3 branch)
- `tool_certificate` 2026111000 (v5.0.12) or later

## Install
Clone a release tag into `public/local/merqamreports` of the Moodle code,
then run the Moodle upgrade.

## License
GNU GPL v3 or later. See LICENSE.
