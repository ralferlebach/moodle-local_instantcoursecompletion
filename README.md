# moodle-local_instantcoursecompletion

[![Moodle Plugin CI](https://github.com/ralferlebach/local_instantcoursecompletion/actions/workflows/moodle-ci.yml/badge.svg?branch=main)](https://github.com/ralferlebach/local_instantcoursecompletion/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

This local plugin marks courses complete as soon as their existing completion criteria are
met, instead of leaving learners to wait for the scheduled completion cron task. It adds no
completion rules of its own — it evaluates the criteria a course already has, earlier and
within a configurable scope, through Moodle's own completion API.

## Requirements

This plugin requires Moodle 4.5 (2024100700)+.

It is tested on Moodle 4.5, 5.0, 5.1 and 5.2, on PHP 8.2 to 8.4, and on MariaDB and
PostgreSQL. There is no backward compatibility below Moodle 4.5.

## Motivation for this plugin

Moodle records an *activity* completion the moment it happens, but aggregates *course*
completion only from a scheduled cron task. Between the two, a learner who has just met the
last criterion still sees an unfinished course — for up to a cron interval.

Course completion in Moodle is a two-stage pipeline: a satisfied criterion writes a row to
`course_completion_crit_compl` and flags the learner's `course_completions` row for
reaggregation, and `aggregate_completions()` later applies the aggregation methods and marks
the course complete. Core runs the second stage from cron, with one exception: for a single,
non-bulk activity completion it runs both stages inline. Everything else waits.

This plugin runs the same two stages, for the same criteria, at the moment they can first
succeed. It never writes a `course_completions` row directly, never implements its own
aggregation, and never evaluates completion inside a web request — its observers only queue a
de-duplicated ad-hoc task.

## Installation

Install the plugin like any other plugin to folder `local/instantcoursecompletion`.

See <http://docs.moodle.org/en/Installing_plugins> for details on installing Moodle plugins.

## Usage & Settings

After installing the plugin, it is ready to use: with the default settings it accelerates
completion for every course on the site.

To configure the plugin and its behaviour, please visit:
*Site administration -> Plugins -> Local plugins -> Instant course completion*.

There you find these settings:

| Setting | Default | Notes |
|---|---|---|
| Observer scope | All courses | `all`, `categories` (selected category branches with optional include/exclude course tags) or `adele` (the filters configured in `local_adele`; empty if that plugin is absent). |
| Category branches | — | Only used in the categories scope; resolved to include sub-categories. |
| Included / excluded course tags | — | One per line or comma separated, matched against Moodle's normalised tag names. |
| Plan time-based criteria in advance | On | Enables the discovery task for date and duration criteria. |
| Scheduling horizon | 7 days | Must exceed the discovery interval (hourly); the settings page warns if it does not. |
| Users booked per batch task | 500 | 1 – 2000. |
| Maximum records examined per run | 5000 | 1 – 50000. Enrolment records examined, not tasks planned. |
| Enable safety-net reconcile task | Off | Bounded recovery scan; see below. |
| Maximum users examined per reconcile run | 5000 | 1 – 10000. |
| Enable logging | Off | Emits a `completion_booked` event per booking and a cron trace line. |

Out-of-range values are rejected when the form is saved rather than silently clamped by the
next cron run.

The plugin also provides a report at *Site administration -> Reports -> Accelerated
completions*, which lists the 100 most recent `completion_booked` events from the standard
logstore. It only has content while **Enable logging** is on.

## Capabilities

This plugin does not add any additional capabilities. The report is protected by
`moodle/site:config`, and only users core already treats as tracked
(`moodle/course:isincompletionreports`) are ever booked.

## Scheduled Tasks

This plugin introduces these additional scheduled tasks:

### local_instantcoursecompletion\task\discover_due_criteria_task

Plans the date and duration criteria that no event can announce, rounding each due time up
to a shared 15-minute batch window. By default the task is enabled and runs hourly.

### local_instantcoursecompletion\task\book_due_completion_batch_task

Books all users a criterion has fallen due for, one page at a time. It is an ad-hoc task
queued by the discovery task, not a scheduled one, and runs at the due window.

### local_instantcoursecompletion\task\notify_dependent_courses_task

Re-evaluates the courses that require a just-completed course as a prerequisite, paging
through them so a hub course required by many programmes is not an unbounded fan-out. It is
an ad-hoc task queued on `course_completed`.

### local_instantcoursecompletion\task\reconcile_task

A bounded recovery net that re-checks courses for completions the event path may have missed
— a deleted task, a course restore, a period with scheduling switched off. By default the
task runs every six hours but does nothing until **Enable safety-net reconcile task** is
switched on.

## How this plugin works

```text
                    ┌──────────────────────────────────────────┐
  user_graded ──────┤                                          │
  cm_completion ────┤  observer  ── scope? ── criteria index?  │
  enrolment events ─┤                                          │
                    └──────────────────┬───────────────────────┘
                                       │ ad-hoc task (de-duplicated, system task)
                                       ▼
                                 completion_booker::book()

  course_completed ──▶ notify_dependent_courses_task ──▶ (per dependent course)
                          (paged fan-out)

  discover_due_criteria_task ──▶ book_due_completion_batch_task ──▶ book_criterion()
      (hourly, plans ahead)          (at the due window, paged)          │
                                                                         ▼
                        get_user_completion() → review() → aggregate_completions()

  reconcile_task ───────────────────────────────────────────────────────▲
      (every 6 h, bounded recovery net, off by default)
```

### Booking

All completion booking goes through one class, `completion_booker`. It is course-scoped and
stateful: `completion_booker::for_course($courseid)` reads the course, its `completion_info`
and its criteria once, and the returned instance books every user of that course against them
— `book_user()` for the whole course, `book_criterion()` for a single due criterion. The
static `completion_booker::book($courseid, $userid)` is a thin facade over
`for_course()->book_user()` for callers that only ever touch one user. Loading the course and
criteria once per course rather than once per user avoids rebuilding `completion_info` and the
criteria set for every learner.

### Observers

Observers are registered with `'internal' => false`, so they run after the triggering
transaction commits — a rolled-back activity completion leaves no queued task behind. Each is
narrowed before it does any work: `course_module_completion_updated` is skipped when the
course has only activity criteria (core already aggregated them); `user_graded` is skipped
when the course has no grade criterion; `course_completed` queues the dependent-course
notification; and the enrolment events plan the time-based criteria of the affected user at
once. The lookups go through `criteria_index`, a cached map of course to criterion types.

The booking task runs in the system context, not as the learner. A learner suspended between
the trigger and the run therefore cannot cause core to discard the booking, and the automated
completion is not attributed to them. Identical pending `(course, user)` tasks are
de-duplicated by the queue.

### Scheduling time-based criteria

Date and duration criteria cannot be announced by an event, so the discovery task plans them:

- Every due time is rounded **up** to the start of a 15-minute batch window; all criteria
  falling due inside one window share a single batch task, so a date criterion with 50 000
  participants costs one task, not 50 000. Rounding up keeps a task from running before its
  criterion is satisfied, and the delay is bounded by the window.
- Only due times within the scheduling horizon are planned. The horizon bounds how many rows
  the ad-hoc queue can hold and must be longer than the interval between two discovery runs.
- Each run is bounded by both a record budget and a wall-clock budget, with a composite keyset
  cursor `(courseid, criteriaid, lastuserid)` carried into the next run. The batch and
  reconcile runs are bounded the same way. A run that reaches either budget persists its
  cursor and the next run resumes exactly where it stopped.
- The batch task books `batchsize` users, then queues a continuation carrying the last user it
  processed. It is idempotent: a retry books only what has no `course_completion_crit_compl`
  record yet.

### Concurrency

`due_scheduler::course_lock()` serialises the discovery task against the enrolment observers
for one course, and `book_due_completion_batch_task` takes a per `(course, criterion)` lock so
that overlapping due windows of one criterion do not drain the same cohort twice.
`completion_booker` takes a per `(course, user)` lock and re-checks completion inside it, so
two processes cannot both decide they completed the course and both emit `completion_booked`.
All of these locks separate processes, not call sites: PostgreSQL advisory locks and MySQL's
`GET_LOCK` are re-entrant inside one database session, and cron and a web request never share
one.

### Compatibility logic

The plugin adds no completion rules, but it carries deliberate compatibility code where Moodle
is inconsistent with itself. These are maintained on purpose and re-checked against each
supported release.

| Area | What core does | What this plugin does |
|---|---|---|
| Tracked users | The completion reports gate on `moodle/course:isincompletionreports`, but `completion_criteria_duration::cron()` reads `{user_enrolments}` with no capability filter. | Follows the reports semantics everywhere; a teacher whom core's criteria cron would complete is never completed here. |
| Duration start time | `completion_criteria_duration::review()` reads `ue.timestart` only; its own `cron()` falls back to `ue.timecreated`. | Reproduces the `cron()` rule: earliest enrolment, `ue.timestart`, otherwise `ue.timecreated`. |
| Date completion time | `completion_criteria_date::cron()` records the criterion as completed at `timeend`. | Records `timeend`, not the evaluation time, so the course completion timestamp is correct. |
| Self, role, unenrol criteria | Recorded by the user, teacher or unenrolment observer; `review()` cannot decide them from stored data. | Never marked on anyone's behalf; aggregated only if a record already exists. |

### Operational monitoring

Both scheduled tasks emit a trace line whenever anything failed, regardless of the logging
setting, and swallowed observer failures are additionally traced when running under cron. A
`dml_exception` or `coding_exception` aborts a run and propagates so cron surfaces it; the
cursor is left in place, so the next run retries the same slice. A single criterion
misbehaving for a single user is counted in `failed` and does not stop the run.

### Privacy

The plugin owns no tables. With logging enabled it emits `completion_booked` events that the
logging subsystem stores; the privacy provider declares that subsystem link. The records
belong to `core_log`, which exports and deletes them.

## Theme support

This plugin acts behind the scenes, therefore it should work with all Moodle themes. It is
developed and tested on Moodle Core's Boost theme.

## Plugin repositories

This plugin is not (yet) published in the Moodle plugins repository.

The latest development version can be found on Github:
<https://github.com/ralferlebach/local_instantcoursecompletion>

## Bug and problem reports / Support requests

This plugin is carefully developed and thoroughly tested, but bugs and problems can always
appear.

Please report bugs and problems on Github:
<https://github.com/ralferlebach/local_instantcoursecompletion/issues>

## Feature proposals

Please issue feature proposals on Github:
<https://github.com/ralferlebach/local_instantcoursecompletion/issues>

## Moodle release support

This plugin is maintained for Moodle 4.5, 5.0, 5.1 and 5.2. There may be several weeks after a
new major release of Moodle has been published until a compatibility check is done and
problems are fixed if necessary.

## Translating this plugin

This Moodle plugin is shipped with an english language pack only. A german language pack is
maintained by the author for local needs.

## Right-to-left support

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.

## Maintainers

The plugin is maintained by Ralf Erlebach.

## Copyright

Ralf Erlebach, 2026. Licensed under the GNU GPL v3 or later.
