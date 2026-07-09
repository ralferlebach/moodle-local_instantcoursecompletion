# Instant course completion (`local_instantcoursecompletion`)

Moodle fires activity completion immediately, but aggregates *course* completion only
from a scheduled cron task. Between the two, a learner who has just met the last
criterion still sees an unfinished course — for up to a cron interval.

This plugin closes that gap. It adds no completion logic of its own: it evaluates the
criteria a course already has, earlier and within a configurable scope, through
Moodle's own completion API.

- **Requires:** Moodle 4.5 (2024100700) or later. No backward compatibility below 4.5.
- **Supported:** Moodle 4.5 – 5.2, PHP 8.2 – 8.4, MariaDB and PostgreSQL.
- **Dependencies:** none. `local_adele` is integrated optionally and detected at runtime.
- **Licence:** GNU GPL v3 or later.

---

## What it actually does

Course completion in Moodle is a two-stage pipeline:

1. A criterion is satisfied → a row appears in `course_completion_crit_compl`, and the
   learner's `course_completions` row is flagged for reaggregation.
2. `aggregate_completions()` applies the per-type and overall aggregation methods and,
   if they pass, marks the course complete with the latest criterion timestamp.

Core runs stage 2 from cron — with one exception: for a single, non-bulk activity
completion, `completion_info::internal_set_data()` runs both stages inline before it
fires `course_module_completion_updated`. Everything else waits for cron.

This plugin runs the same two stages, for the same criteria, at the moment they can
first succeed. It never writes a `course_completions` row directly and never
implements its own aggregation.

### Where the latency actually is

| Criterion type | Handled by core immediately? | Handled by this plugin |
|---|---|---|
| Activity completion (single action) | yes, inline | not needed |
| Activity completion (bulk update) | no | on the next reconcile run |
| Course grade | no | on `user_graded` |
| Prerequisite course | no | on `course_completed` of the prerequisite |
| Date reached | no | planned ahead, booked at the due time |
| Duration since enrolment | no | planned ahead, booked at the due time |
| Self completion, role, unenrolment | written by the user, teacher or unenrol observer | aggregated, never set on the learner's behalf |

---

## Architecture

```text
                    ┌──────────────────────────────────────────┐
  user_graded ──────┤                                          │
  course_completed ─┤  observer  ── scope? ── criteria index?  │
  cm_completion ────┤                                          │
                    └──────────────────┬───────────────────────┘
                                       │ ad-hoc task (deduplicated)
                                       ▼
  discover_due_criteria_task ──▶ book_due_completion_task ──▶ completion_booker
      (hourly, plans ahead)          (at the due time)              │
                                                                    ▼
                        get_user_completion() → review() → aggregate_completions()

  reconcile_task ──────────────────────────────────────────────────▲
      (every 6 h, bounded recovery net)
```

### Observers

Registered with `'internal' => false`, so they run after the triggering transaction
commits — a rolled-back activity completion leaves no queued task behind.

Each observer is narrowed before it does any work:

- `course_module_completion_updated` is skipped when the course has **only** activity
  criteria; core already aggregated them.
- `user_graded` is skipped when the course has no grade criterion.
- `course_completed` looks up the courses that name it as a prerequisite.

The lookup goes through `criteria_index`, a cached map of course → criterion types.

### Scheduling time-based criteria

Date and duration criteria cannot be announced by an event. `discover_due_criteria_task`
runs hourly and plans them:

- It queues one ad-hoc task per `(courseid, userid, duetime)`, deduplicated through the
  canonical JSON of the task's custom data.
- Only due times **within the scheduling horizon** are planned. The horizon bounds how
  many rows the ad-hoc queue can hold; it must be longer than the interval between two
  discovery runs.
- Bookings that fall due at the same instant — every learner in a course with a date
  criterion — are spread over a 15-minute window by a deterministic jitter on
  `nextruntime`. The jitter is not part of the deduplication key.
- Each run is capped: at most 200 courses and `maxtasksperrun` tasks, with a cursor over
  course IDs carried into the next run.

`reconcile_task` remains behind it as a recovery net for anything the planner missed —
a deleted task, a course restore, a period with scheduling switched off. It is bounded
the same way and is off by default.

### Scope

Three modes:

- **all** — every course on the site. Nothing is cached.
- **categories** — selected category branches, resolved to include sub-categories, with
  optional include and exclude course tags.
- **adele** — the category and tag filters configured in `local_adele`. If that plugin
  is absent, the scope is **empty**, not "all courses"; the settings page says so.

Scope membership is cached per course as a `0` or `1`, keyed by a hash of the scope
configuration. The resolved category set is cached separately. Neither cache grows with
the number of courses on the site.

---

## Settings

*Site administration → Plugins → Local plugins → Instant course completion*

| Setting | Default | Notes |
|---|---|---|
| Observer scope | All courses | See above. |
| Category branches | — | Only used in the categories scope. |
| Included / excluded course tags | — | One per line or comma separated. Matched against Moodle's normalised tag names. |
| Processing mode | Asynchronous | Synchronous books inside the web request; use it only for small scopes. |
| Plan time-based criteria in advance | On | Enables the discovery task. |
| Scheduling horizon | 7 days | Must exceed the discovery interval (hourly). |
| Maximum bookings planned per run | 5000 | Upper bound on ad-hoc tasks queued per run. |
| Enable safety-net reconcile task | Off | Bounded recovery scan every 6 hours. |
| Enable logging | Off | Emits a `completion_booked` event per booking, and a cron trace line. |

## Report

*Site administration → Reports → Accelerated completions*

Lists the 100 most recent `completion_booked` events from the standard logstore. It only
has content while **Enable logging** is on.

---

## Privacy

The plugin owns no tables. With logging enabled it emits `completion_booked` events that
the logging subsystem stores; the privacy provider declares that subsystem link. The
records belong to `core_log`, which exports and deletes them.

## Development

```bash
make phpunit     # PHPUnit, reinitialising the environment when needed
make lint-php    # phpcs against the Moodle standard
make check       # everything
```

CI runs PHPUnit and Behat across Moodle 4.5, 5.0, 5.1 and 5.2, on MariaDB and
PostgreSQL, with the PHP version each branch supports.

Two things worth knowing before writing tests:

- Tests that rely on the observers must call `preventResetByRollback()`. On PostgreSQL
  and MSSQL, `advanced_testcase` wraps every test in a transaction it rolls back;
  observers registered with `'internal' => false` are deferred until commit and would
  never run. On MariaDB no transaction is opened, so the omission passes silently.
- Enrol test users by inserting into `{enrol}` and `{user_enrolments}` directly, not via
  `enrol_user()`; the resulting `user_enrolment_created` event trips other plugins'
  observers under PHPUnit.

## Author

Ralf Erlebach, 2026. Licensed under the GNU GPL v3 or later.
