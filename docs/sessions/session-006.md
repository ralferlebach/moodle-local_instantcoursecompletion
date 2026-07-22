## Session-Ende – local_instantcoursecompletion · Session 006

**Datum:** 2026-07-22
**Dauer:** ca. 1 Session (Diagnose local_adele-Durchreichung → 1.1.0)
**Ausgangsstand:** 1.0.0 / MATURITY_STABLE

---

### Auslöser

Kurs mit **einem Grade-Kriterium** (Kurs-Gesamtnote ≥ gradepass) wurde in der Kern-Ansicht als
100 % abgeschlossen angezeigt, der `local_adele`-Lernpfad blieb aber bei 0 %. **Cron war
deaktiviert.** Wurzelanalyse (gegen Moodle-4.5-Quelltext verifiziert):

- `local_adele` beobachtet `course_completed` **vollständig synchron** (kein eigener Task, kein
  Cron): `observer.php` → `completion::completed()` → `learning_path_update::trigger_user_path_update()`.
- Das Plugin reihte bei `user_graded` einen Ad-hoc-`book_completion_task` ein, der ohne Cron nie
  lief ⇒ `course_completed` feuerte nie ⇒ `local_adele` bekam nichts.
- Aktivitätskurse funktionierten, weil der Kern sie **inline** aggregiert und `course_completed`
  synchron feuert; Grade/Self/Role waren an den Cron gebunden.
- Die Entfernung des Synchron-Modus in 0.4.5 beruhte auf der Annahme „der Ad-hoc-Task läuft ohnehin
  beim nächsten Cron-Tick" — die bei deaktiviertem/langsamem Cron nicht trägt.

---

### Was wurde erledigt? (1.1.0, MINOR)

- [x] **Synchrone Verbuchung als Standard wieder eingeführt.** `user_graded` und
  `course_module_completion_updated` buchen per Default synchron über `completion_booker::book()`
  im auslösenden Request; `course_completed` feuert damit ohne Cron.
- [x] **Einstellung `processingmode` (`sync`/`async`), Default `sync`.** `async` reiht weiterhin
  als Systemtask ein (Rückfallebene für sehr große Wirkungsbereiche). `settings.php`,
  `lang/en+de` (je 4 Strings, strikt alphabetisch, EN/DE-Parität), `db/upgrade.php`
  (Schritt `2026072200` setzt `sync` explizit für Bestandsinstallationen ohne gespeicherten Wert).
- [x] **Hook für den Selbstabschluss via `course_viewed`.** Der Self-Completion-Block verlinkt auf
  `course/togglecompletion.php` (kein Event) und leitet danach auf den Kurs um → `course_viewed`
  feuert synchron. Neuer Observer `observer::course_viewed()`, eng gegated:
  `courseid>0/!=SITEID` → `criteria_index::has_type(SELF)` (Cache) → `scope_resolver::is_in_scope`
  → `has_pending_reaggregation()` (indizierter `reaggregate`-Read) → `completion_booker::book()`.
  Bucht **immer synchron** (unabhängig von `processingmode`), da der Selbstabschluss inhärent der
  Instant-Pfad ist. `db/events.php`: `course_viewed` registriert (`internal => false`).
- [x] **`reaggregate`-Flag** wird nach der Verbuchung durch Kern-`aggregate_completions()`
  (lib/completionlib.php, unconditional am Funktionsende) zurückgesetzt — kein manueller Reset,
  kein Race. Verifiziert und per Test belegt.
- [x] **Fremdabschluss (role) bleibt cron-basiert** (Reconcile). Kein Kern-Event, kein Signal im
  Request (Lehrkraft markiert im Bericht, lernende Person nicht anwesend). In README dokumentiert.
- [x] **README:** neue Tabelle „When each completion is booked" (Auslöser · Kern-Event · Verbucher ·
  Latenz), Observers-Abschnitt auf sync-Default/async-Fallback umgeschrieben, `processingmode`-Zeile
  in der Settings-Tabelle. **CHANGELOG:** Abschnitt `[1.1.0]`.
- [x] **`version.php`:** `version 2026071100 → 2026072200`, `release '1.0.0' → '1.1.0'`
  (`supported = [405, 502]`, `maturity STABLE` unverändert).
- [x] **Tests (`tests/observer_test.php`):** 5 neue Tests + Helfer (`enrol_tracked_user`,
  `set_course_grade`, `mark_self_criterion`, `reaggregate_flag`, `view_course`); `add_criterion`
  gibt jetzt die Kriterium-ID zurück; `setUp` pinnt die queue-prüfenden Bestandstests auf `async`.

---

### Verifizierte Kern-Fakten (Moodle 4.5, für sessionstart.txt)

- **Self-Completion** (`blocks/selfcompletion`) verlinkt nur auf
  `course/togglecompletion.php?course=<id>` → `mark_complete()` (schreibt crit_compl + setzt
  `reaggregate` via `mark_inprogress()`, **kein Event**) → `redirect(.../course/view.php)` →
  `course_view()` (course/lib.php:3372) → **`course_viewed` feuert synchron**. Der einzige im
  Klick-Flow abfangbare Auslöser.
- **`aggregate_completions($id)`** setzt `course_completions.reaggregate` am Funktionsende
  **unconditional** auf 0 (`set_field_select(...)`) — auch wenn der Kurs dabei nicht komplett wird.
- **`mark_inprogress()`** setzt `reaggregate = time()`.
- **Fremd- vs. Selbstabschluss:** beide über `togglecompletion.php`, beide ohne Event; kein
  dediziertes self/role-Completion-Event in Core 4.5 **noch** 5.2 (404 geprüft). Rollen-Kriterium
  hat kein `cron()`.

---

### Entwurfsentscheidungen

- **Umgekehrt (0.4.5):** Synchron-Modus wieder eingeführt und zum Default gemacht. Async bleibt als
  bewusste Rückfallebene erhalten (nicht entfernt), inkl. der gesamten Adhoc-/Discovery-/Reconcile-
  Maschinerie für zeit-/batchbasierte Kriterien.
- **`course_viewed`-Observer auf dem heißesten Event:** akzeptiert, weil der Gate für Nicht-Self-
  Kurse nur zwei Cache-Lookups kostet und `local_adele` `course_viewed` ohnehin selbst beobachtet.
- **Kein manueller `reaggregate`-Reset:** bewusst dem Kern (`aggregate_completions()`) überlassen —
  Lesart-A-konform (keine eigene Logik, die Kern-Semantik dupliziert) und race-frei.

---

### Offene Punkte für die nächste Session

- [ ] **Absoluter Großlast-/Multi-DB-Instanztest** (feste Read-Budgets, MariaDB vs PostgreSQL,
  EXPLAIN) — weiterhin die letzte Freigabe hinter grünem `make check` vor einem Tag.
- [ ] **`course_viewed`-Lastprofil real bestätigen** (Gate-Kosten auf einer großen Instanz mit
  vielen Kursaufrufen; Erwartung: zwei Cache-Reads für Nicht-Self-Kurse).
- [ ] **CI H3b:** `workflow_call`-Matrix-Konsolidierung bleibt offen.
- [ ] **Optional:** Rollen-Kriterium; volles Monitoring — je eigenes Paket.

---

### Testlauf-Ergebnis (bei Ralf, Moodle 4.5.12 / PHP 8.2 / MariaDB 10.11)

```
PHPCS:   OK (0 Fehler, 0 Warnungen — Kommentar-Großschreibung in observer_test.php nachgezogen)
PHPDoc:  OK (local_moodlecheck: keine Beanstandungen)
PHPCPD:  OK (No clones found)
PHPUnit: OK, 132 Tests / 342 Assertions / 1 Skip — nach zwei Anpassungen:
         - observer_test::setUp und notify_dependent_courses_task_test::setUp auf
           processingmode = async gepinnt (beide Klassen prüfen die Ad-hoc-Queue, die nur
           der async-Modus füllt; der sync-Default hätte in-place gebucht).
Behat:   unverändert (keine UI-Änderung außer einer Einstellung)
```

> Ursache der beiden ursprünglichen PHPUnit-Fehler: `notify_dependent_courses_task` bucht
> abhängige Kurse über `observer::handle_completion_trigger()`. Unter dem neuen sync-Default
> verbucht das synchron statt einzureihen, sodass die queue-prüfenden Tests eine leere Queue
> sahen. Fix: dieselbe async-Pinnung wie in `observer_test`.

---

### Verzeichnis-Snapshot (changed files – patch-1.1.0.zip)

```
classes/observer.php
db/events.php
db/upgrade.php
settings.php
lang/en/local_instantcoursecompletion.php
lang/de/local_instantcoursecompletion.php
version.php
tests/observer_test.php
tests/notify_dependent_courses_task_test.php
CHANGELOG.md
README.md
docs/sessions/session-006.md                                 (neu, nicht im Patch-ZIP)
```

---

### Für die nächste Session einfügen in sessionstart.txt

**Aktueller Entwicklungsstand:**
> v1.1.0, MATURITY_STABLE (`version.php`: `version = 2026072200`, `supported = [405, 502]`,
> `requires = 2024100700`). Synchrone Verbuchung ist wieder Standard (`processingmode`, Default
> `sync`; `async` als Rückfallebene). Neuer `course_viewed`-Hook aggregiert den Selbstabschluss im
> Request (Gate: `has_type(SELF)` + Scope + `reaggregate`). Fremdabschluss (role) bleibt
> cron-basiert (Reconcile) — kein Kern-Event, in README dokumentiert. Prinzip „Lesart A"
> unverändert.

**Neue Pitfalls / Fakten:**
> - Self-Completion feuert kein Event; nur der Redirect danach löst `course_viewed` aus.
> - `aggregate_completions()` setzt `reaggregate` selbst zurück — nicht manuell nachziehen.
> - `observer_test::setUp` pinnt queue-prüfende Tests auf `processingmode = async`; sync-Tests
>   setzen den Modus explizit.
