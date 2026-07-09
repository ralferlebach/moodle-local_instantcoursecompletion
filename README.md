# Instant course completion (`local_instantcoursecompletion`)

Moodle fires activity completion immediately, but aggregates *course* completion only
from a scheduled cron task. Between the two, a learner who has just met the last
criterion still sees an unfinished course — for up to a cron interval.

This plugin closes that gap. It evaluates the criteria a course already has, earlier
and within a configurable scope, through Moodle's own completion API.

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
first succeed. It never writes a `course_completions` row directly, never implements its
own aggregation, and never evaluates completion inside a web request — the observers only
queue a deduplicated ad-hoc task.

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
  cm_completion ────┤  observer  ── scope? ── criteria index?  │
  enrolment events ─┤                                          │
                    └──────────────────┬───────────────────────┘
                                       │ ad-hoc task (deduplicated, per user)
                                       ▼
                                 completion_booker::book()

  course_completed ──▶ notify_dependent_courses_task ──▶ (per dependent course)
                          (paged fan-out)

  discover_due_criteria_task ──▶ book_due_completion_batch_task ──▶ book_criterion()
      (hourly, plans ahead)          (at the due window, paged)          │
                                                                         ▼
                        get_user_completion() → review() → aggregate_completions()

  reconcile_task ───────────────────────────────────────────────────────▲
      (every 6 h, bounded recovery net)
```

### Observers

Registered with `'internal' => false`, so they run after the triggering transaction
commits — a rolled-back activity completion leaves no queued task behind.

Each observer is narrowed before it does any work:

- `course_module_completion_updated` is skipped when the course has **only** activity
  criteria; core already aggregated them.
- `user_graded` is skipped when the course has no grade criterion.
- `course_completed` queues `notify_dependent_courses_task`, which pages through the
  courses naming the completed one as a prerequisite. A hub course required by many
  programmes would otherwise be an unbounded fan-out inside one request.
- `user_enrolment_created` and `user_enrolment_updated` plan the time-based criteria of
  the affected user at once, rather than waiting for the next discovery run.

The lookups go through `criteria_index`, a cached map of course → criterion types.

### Scheduling time-based criteria

Date and duration criteria cannot be announced by an event. `discover_due_criteria_task`
runs hourly and plans them:

- Every due time is rounded **up** to the start of a 15-minute **batch window**. All
  criteria falling due inside one window share a single `book_due_completion_batch_task`.
  A date criterion with 50 000 participants therefore costs one task, not 50 000.
  Rounding up rather than down guarantees a task never runs before its criterion is
  actually satisfied; the resulting delay is bounded by the window.
- Only due times **within the scheduling horizon** are planned. The horizon bounds how
  many rows the ad-hoc queue can hold; it must be longer than the interval between two
  discovery runs.
- Each run is capped: at most 200 courses and `maxtasksperrun` **enrolment records
  examined**, with a composite keyset cursor `(courseid, criteriaid, lastuserid)` carried
  into the next run. Progress is measured in records examined, never in tasks planned —
  a record that already has a task still consumes budget and still advances the cursor.
- The batch task books `batchsize` users, then queues a continuation carrying the last
  user it processed. It is idempotent: a retry books only what has no
  `course_completion_crit_compl` record yet.

`reconcile_task` sits behind it as a recovery net for anything the planner missed — a
deleted task, a course restore, a period with scheduling switched off. It uses the same
kind of keyset cursor and is off by default.

### Concurrency

- `due_scheduler::course_lock()` serialises the discovery task against the enrolment
  observers for one course. Core's `reschedule_or_queue_adhoc_task()` reads and then
  writes, which is not atomic.
- `completion_booker` takes a per `(course, user)` lock and re-checks completion inside
  it, so two processes cannot both decide they were the one that completed the course
  and both emit `completion_booked`.

Both locks separate **processes**, not call sites: PostgreSQL advisory locks and MySQL's
`GET_LOCK` are re-entrant inside one database session. Cron and a web request never share
one, which is exactly where the race lives.

### Scope

Three modes:

- **all** — every course on the site. Nothing is cached.
- **categories** — selected category branches, resolved to include sub-categories, with
  optional include and exclude course tags.
- **adele** — the category and tag filters configured in `local_adele`. If that plugin is
  absent, the scope is **empty**, not "all courses"; the settings page says so.

Scope membership is cached per course as a `0` or `1`, keyed by a hash of the scope
configuration. The resolved category set and the resolved tag IDs are cached separately.
None of the three grows with the number of courses on the site.

---

## Compatibility logic

The plugin adds no completion *rules*, but it does carry deliberate compatibility code
where Moodle is inconsistent with itself. These are maintained on purpose and should be
re-checked against each supported Moodle release.

| Area | What core does | What this plugin does |
|---|---|---|
| **Tracked users** | `completion_info::is_tracked_user()` and the completion reports gate on `moodle/course:isincompletionreports`. `completion_criteria_duration::cron()` reads `{user_enrolments}` with no capability filter, and `completion_criteria_date::cron()` joins any role. | Follows the **reports** semantics everywhere. A teacher whom core's criteria cron would complete is never completed by this plugin. |
| **Duration start time** | `completion_criteria_duration::review()` reads `ue.timestart` only, and so never completes a user whose enrolment carries no start date. Its own `cron()` falls back to `ue.timecreated`. | Reproduces the `cron()` rule: earliest enrolment, `ue.timestart`, otherwise `ue.timecreated`. |
| **Date completion time** | `completion_criteria_date::cron()` records the criterion as completed at `timeend`. | Records `timeend`, not the evaluation time, so `aggregate_completions()` derives the correct course completion timestamp. |
| **Self, role, unenrol criteria** | Recorded by the user action, the teacher action and the unenrolment observer. Their `review()` cannot decide satisfaction from stored data. | Never marked on anyone's behalf. They are aggregated if a record already exists. |

---

## Settings

*Site administration → Plugins → Local plugins → Instant course completion*

| Setting | Default | Notes |
|---|---|---|
| Observer scope | All courses | See above. |
| Category branches | — | Only used in the categories scope. |
| Included / excluded course tags | — | One per line or comma separated. Matched against Moodle's normalised tag names. |
| Plan time-based criteria in advance | On | Enables the discovery task. |
| Scheduling horizon | 7 days | Must exceed the discovery interval (hourly). The settings page warns if it does not. |
| Users booked per batch task | 500 | 1 – 50 000. |
| Maximum records examined per run | 5000 | 1 – 100 000. Enrolment records, not tasks. |
| Enable safety-net reconcile task | Off | Bounded recovery scan every 6 hours. |
| Maximum users examined per reconcile run | 5000 | 1 – 100 000. |
| Enable logging | Off | Emits a `completion_booked` event per booking, and a cron trace line. |

Out-of-range values are rejected when the form is saved rather than silently clamped by
the next cron run.

## Report

*Site administration → Reports → Accelerated completions*

Lists the 100 most recent `completion_booked` events from the standard logstore. It only
has content while **Enable logging** is on.

## Operational monitoring

Both scheduled tasks always emit a trace line when anything failed, regardless of the
logging setting:

```
local_instantcoursecompletion reconcile_task: courses=12 scanned=4800 booked=37 failed=0
local_instantcoursecompletion discover_due_criteria_task: courses=12 scanned=4800 planned=9 failed=0
```

A `dml_exception` or `coding_exception` aborts the run and propagates, so cron reports the
failure instead of a scan silently limping through the rest of the site. The cursor is
left where it was, so the next run retries the same slice. A single criterion misbehaving
for a single user is counted in `failed` and does not stop the run.

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

CI runs PHPUnit and Behat across Moodle 4.5, 5.0, 5.1 and 5.2, on MariaDB and PostgreSQL,
with the PHP version each branch supports.

Three things worth knowing before writing tests:

- Tests that rely on the observers must call `preventResetByRollback()`. On PostgreSQL and
  MSSQL, `advanced_testcase` wraps every test in a transaction it rolls back; observers
  registered with `'internal' => false` are deferred until commit and would never run. On
  MariaDB no transaction is opened, so the omission passes silently.
- Enrol test users through `completion_test_trait::enrol_user_direct()`, which inserts
  into `{enrol}` and `{user_enrolments}` directly instead of calling `enrol_user()`; the
  resulting `user_enrolment_created` event trips other plugins' observers under PHPUnit.
  It also assigns a role, without which nobody is a tracked user.
- Mark activities complete with `$isbulkupdate = true`. Otherwise core aggregates the
  course inline and the test measures core rather than this plugin.

## Author

Ralf Erlebach, 2026. Licensed under the GNU GPL v3 or later.
