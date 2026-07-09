# Lastenheft · Pflichtenheft · Technisches Blueprint

**Plugin:** `local_instantcoursecompletion` — „Sofortiger Kursabschluss"
**Zielplattform:** Moodle **4.5+** (inkl. 5.x bis 5.2) · PHP 8.1+ (8.2+ auf Moodle 5.x)
**Autor / Lizenz:** Ralf Erlebach · GNU GPL v3 or later
**Dokumentversion:** 3.0 (ausführlich) · Stand: Chat-Session 002

---

## 0. Zweck und Geltung dieses Dokuments

Dieses Dokument ist die verbindliche fachlich-technische Grundlage der Entwicklung.
Es besteht aus drei Teilen:

- **Lastenheft** (Kap. 3) — *was* der Auftraggeber verlangt (Anforderungen).
- **Pflichtenheft** (Kap. 4) — *wie* der Auftragnehmer die Anforderungen umsetzt,
  inkl. Abnahmekriterien.
- **Technisches Blueprint** (Kap. 5–10) — die konkrete Architektur.

Zwei Festlegungen sind dem gesamten Dokument übergeordnet:

- **Lesart A (verbindlich):** Das Plugin implementiert **keine eigene**
  Abschlusslogik. Es wertet die **vorhandenen** Moodle-Completion-Kriterien nur
  *früher* und *eingegrenzt* aus. Vgl. Kap. 2.3.
- **L-Q2:** Mindestversion **Moodle 4.5**; **keine** Abwärtskompatibilität zu 4.1–4.4.

### §0.1 Versions- und Session-Konvention (verbindlich)

**Versionsnummer:** `0.MAJOR.MINOR` — MINOR-Increment pro Iterations-Patch;
reine Fixes (Linting / PHPUnit / Behat) erhalten kein eigenes MINOR-Increment.

**Session-Dokumente:** Ein Claude-Chat = genau ein Session-Dokument.
Dateipfad: `docs/sessions/session-NNN.md` (Bindestrich, dreistellige Nummer).
Sub-Dokumente einer Session werden nach Abschluss in eine einzige Datei zusammengeführt.

**CI-Matrix (verbindlich):** Moodle 4.5 / 5.0 / 5.1 / 5.2 × PHP 8.1–8.3
(PHP 8.1 nur für Moodle 4.5) × MariaDB 10.11 + PostgreSQL 16; PHPUnit + Behat.

---

## 1. Ausgangslage

Moodle unterscheidet zwei Ebenen des Abschlusses:

- **Aktivitätsabschluss** (*activity completion*) — wird **sofort** im Request
  aktualisiert; beim Statuswechsel feuert `\core\event\course_module_completion_updated`.
- **Kursabschluss** (*course completion*) — wird **nicht** sofort berechnet, sondern
  von der Scheduled Task `\core\task\completion_regular_task` („Calculate regular
  completion data") per Cron aggregiert. Die Moodle-Dokumentation hält ausdrücklich
  fest, dass Kursabschluss über Cron läuft und nicht unmittelbar erfolgt.

Daraus zwei praktische Probleme:

1. **Latenz.** Zwischen dem Erfüllen der letzten Bedingung und der Verbuchung des
   Kursabschlusses liegt das Cron-Intervall. Erst danach feuert
   `\core\event\course_completed`, erst danach laufen Folgeprozesse (Zertifikate,
   Freischaltungen, `local_adele`-Lernpfadfortschritt).
2. **Last.** Die reguläre Completion-Task rechnet instanzweit. Auf großen Instanzen
   ist das ein wiederkehrender, spürbarer Kostenblock.

---

## 2. Zielbestimmung

### 2.1 Musskriterien

Ereignisgesteuerte, **eng eingegrenzte** und **pro Nutzer sofortige** Auswertung und
Verbuchung des Kursabschlusses über die reguläre Moodle-Completion-API, konfigurierbar
als local-Plugin, ohne merkliche Beeinträchtigung der Instanz-Performanz.

### 2.2 Abgrenzung (Kannkriterien / Nicht-Ziele)

- **Nicht-Ziel:** eigene Abschlussdefinition oder -kriterien (das wäre nicht Lesart A).
- **Nicht-Ziel:** Ersatz des Completion-Cron. Das Plugin ergänzt ihn (Sicherheitsnetz
  für zeit-/datumsbasierte Kriterien bleibt der Cron).
- **Kann (Phase 2):** optionaler, scope-begrenzter Reconcile-Task; optionales Log-Event.

### 2.3 Bedeutung von „ermitteln und verbuchen" (Lesart A)

„Verbuchen" bedeutet ausschließlich: Moodles eigenen Kursabschluss (Tabelle
`course_completions`) **früher und lastschonender** berechnen. Das Plugin
*beschleunigt und begrenzt* die vorhandene Kernmechanik. Voraussetzung ist, dass im
Kurs Abschlussverfolgung aktiviert und Kriterien definiert sind — das Plugin kann
keinen Abschluss „aus dem Nichts" erzeugen.

---

## 3. Lastenheft (Auftraggebersicht — „was")

### 3.1 Funktionale Anforderungen

| ID | Anforderung |
|---|---|
| **L-F1** | Erkennt ereignisgesteuert, wenn ein Nutzer die Abschlusskriterien eines Kurses erfüllt, und verbucht den Kursabschluss zeitnah — ohne auf den regulären Completion-Cron zu warten. |
| **L-F2** | Läuft als local-Plugin und ist vollständig über die Plugin-Settings konfigurierbar. |
| **L-F3** | Der Wirkungsbereich (Scope) der Observer ist per Einstellung wählbar: (a) alle Kurse, (b) auswählbare Kurszweig(e) inkl. Unterkategorien, (c) Übernahme der `local_adele`-Einstellungen (Kategorien/Tags), sofern installiert. |
| **L-F4** | Ist `local_adele` nicht installiert, ist Modus (c) nicht wählbar; die übrigen Modi bleiben verfügbar. |
| **L-F5** | Kein Kursabschluss wird doppelt verbucht; bereits abgeschlossene Kurse werden übersprungen (Idempotenz). |
| **L-F6** | Verarbeitung wahlweise asynchron (Hintergrund, Standard) oder synchron (im Request). |

### 3.2 Nicht-funktionale Anforderungen

| ID | Anforderung |
|---|---|
| **L-Q1** | Die Performanz der Instanz darf nicht merklich beeinträchtigt werden (im Request nur minimale, gecachte Operationen). |
| **L-Q2** | Mindestversion **Moodle 4.5** (inkl. 5.x). **Keine** Abwärtskompatibilität zu 4.1–4.4. |
| **L-Q3** | Keine Änderung an Moodle-Kerndateien; ausschließlich dokumentierte APIs. |
| **L-Q4** | Robustheit: Fehler in einem Ereignis dürfen weder den Nutzer-Request noch andere Observer stören. |
| **L-Q5** | Nachvollziehbarkeit (optionales Logging/eigenes Log-Event) und DSGVO-Konformität. |
| **L-Q6** | Wartbarkeit gemäß Moodle Coding Guidelines (phpcs, phpdoc, PHPUnit, Behat, CI). |
| **L-Q7** | Keine harten Fremd-Plugin-Abhängigkeiten; eigenständige Lauffähigkeit. |

---

## 4. Pflichtenheft (Auftragnehmersicht — „wie")

### 4.1 Umsetzungszuordnung

| ID | Umsetzung |
|---|---|
| **P1** (→L-F1/L-F5/L-F6) | Observer auf abschlussrelevante Events; Auswertung über `completion_info` und `completion_criteria::review()`; Verbuchung über `completion_completion::mark_complete()` (Phase 2); Idempotenz durch Vorabprüfung `is_course_complete()`. |
| **P2** (→L-F2/L-Q3) | Standard-`settings.php` mit `admin_settingpage` unter *Local plugins*; keine Kernänderungen. |
| **P3** (→L-F3) | Setting `scopemode` (`all` \| `categories` \| `adele`); bei `categories` Mehrfachauswahl der Kategorien + Include-/Exclude-Tags; Unterkategorien via `course_categories.path`. |
| **P4** (→L-F3c/L-F4) | Modus `adele` liest `get_config('local_adele', …)` (`catfilter`, `includetags`, `excludetags`) und wendet dieselbe Filtersemantik an. Option nur sichtbar, wenn `local_adele` installiert ist. |
| **P5** (→L-Q1) | Scope wird zu einer Menge in-Scope-Kurs-IDs aufgelöst und in einem MUC-Application-Cache gehalten; Observer macht nur einen Cache-Lookup. Schwere Auswertung in Adhoc-Task; optionaler Synchronmodus. |
| **P6** (→L-Q4) | Vollständiges `try/catch` in jedem Callback; `debugging()` statt Exception; keine Fehler nach außen. |
| **P7** (→L-Q5) | `null_provider` (kein eigener PII-Bestand); optionales Log-Event `completion_booked`; Logging-Schalter. |
| **P8** (→L-Q6) | PHPUnit- und Behat-Tests; Makefile spiegelt CI; GitHub-Actions-Matrix 4.5/5.0 × PHP 8.1–8.3 × MariaDB/PostgreSQL. |
| **P9** (→L-Q7) | Keine `dependencies` in `version.php`; `local_adele` zur Laufzeit erkannt. |

### 4.2 Abnahmekriterien

- Abschluss eines in-Scope-Kurses wird ≤ 1 Cron-Intervall (bzw. sofort im
  Synchronmodus) verbucht (nach Umsetzung von Phase 2).
- Ein außerhalb des Scopes liegender Kurs wird nachweislich **nicht** durch das Plugin
  verarbeitet (Test `observer_test::test_out_of_scope_trigger_enqueues_nothing`).
- Ein in-Scope-Trigger reiht **genau einen** deduplizierten Adhoc-Task ein
  (Test `test_in_scope_trigger_enqueues_task`, `test_repeated_trigger_is_deduplicated`).
- Keine Doppelverbuchung (`is_course_complete()`-Guard).
- phpcs (Moodle-Standard, `--max-warnings 0`), phpdoc, PHPUnit und Behat grün auf 4.5
  und 5.0.
- Messbar vernachlässigbare Observer-Kosten im Request (Profiling).

---

## 5. Blueprint — Komponenten- und Dateistruktur

```
local/instantcoursecompletion/
├── version.php                 # component, requires 4.5, supported [405,500,501,502], keine deps
├── settings.php                # Scope-Modus, Kategorien, Tags, sync/async, Reconcile, Logging
├── lib.php                     # Cache-Purge-Callback (Settings-Update)
├── db/
│   ├── events.php              # Observer-Registrierung (Trigger + Cache-Invalidierung)
│   ├── caches.php              # MUC-Definition scopecourseids
│   └── tasks.php               # Scheduled reconcile_task (Sicherheitsnetz)
├── classes/
│   ├── observer.php            # schlanke Callbacks + handle_completion_trigger()
│   ├── scope_resolver.php      # Scope-Modi + Subtree/Tags + Cache + adele-Erkennung
│   ├── completion_booker.php   # Kern-API-Wrapper (Lesart A)
│   ├── task/book_completion_task.php   # Adhoc-Task (courseid, userid)
│   ├── task/reconcile_task.php # optionaler Sicherheitsnetz-Cron
│   ├── event/completion_booked.php     # optionales Log-Event
│   └── privacy/provider.php    # null_provider
├── lang/{en,de}/local_instantcoursecompletion.php
├── tests/                      # PHPUnit + generator + behat
├── tools/                      # fix_phpdoc.php, mustache_check.php (nicht ausgeliefert)
├── .github/workflows/          # moodle-ci.yml, moodle-release.yml
└── docs/                       # materials, prompt-templates, sessions
```

---

## 6. Blueprint — Zu beobachtende Events

| Event | Auslöser | Rolle |
|---|---|---|
| `\core\event\course_module_completion_updated` | Aktivitätsabschluss ändert sich | **Primärtrigger** |
| `\core\event\user_graded` | Bewertung im Gradebook | Trigger für notenbasierte Kriterien |
| `\core\event\course_completed` | Kursabschluss bereits verbucht | **kein** Trigger (nur Beobachtung, Kern hat verbucht) |
| `course_created/updated/deleted` | Kursänderung | Scope-Cache invalidieren |
| `course_category_created/updated/deleted` | Kategorieänderung | Scope-Cache invalidieren |
| `tag_added` / `tag_removed` | Tag-Änderung | Scope-Cache invalidieren |

> Zeit-/datumsbasierte Kriterien (*duration*, *date*) sind ereignisgesteuert nicht
> zuverlässig erkennbar; dafür bleibt der Completion-Cron zuständig (bzw. optional der
> scope-begrenzte `reconcile_task`).

---

## 7. Blueprint — Scope-Auflösung und Caching

`scope_resolver` bietet `is_in_scope(int $courseid): bool`, `get_scope_course_ids(): array`,
`get_mode(): string`, `adele_available(): bool`, `purge_cache(): void`.

- **`all`** — `is_in_scope()` liefert direkt `true` (Site-Kurs ausgenommen); es wird
  bewusst keine vollständige Kursliste materialisiert.
- **`categories`** — eigene Kategorienauswahl; Unterkategorien über
  `course_categories.path LIKE '%/<catid>/%'`; optional Include-/Exclude-Tags via
  `tag_instance`/`tag` (EXISTS/NOT EXISTS).
- **`adele`** — liest `get_config('local_adele')` und wendet dieselbe Semantik an; ruft
  bewusst **nicht** `learning_path_courses::get_availablecourses()` auf (nutzer-/
  rollengebunden, lädt Kursbilder — für einen Hintergrund-Scope ungeeignet).

**Cache:** MUC-Application-Cache `scopecourseids`, Schlüssel = Hash der
scope-relevanten Konfiguration. Invalidierung bei strukturellen Änderungen (Observer)
und bei Settings-Änderungen (Update-Callback in `lib.php`). Der synchrone Request-Pfad
macht nur einen Cache-Lookup.

---

## 8. Blueprint — Verarbeitungsfluss

```
[Nutzer erfüllt Aktivitätskriterium im Request]
        │
        ▼
\core\event\course_module_completion_updated
        │
        ▼
observer::course_module_completion_updated()      (synchron, schlank, wirft nie)
        │  → observer::handle_completion_trigger(courseid, userid)
        │     1) Guard (ids gültig, nicht Site)
        │     2) scope_resolver::is_in_scope(courseid)?  (MUC-Lookup) sonst return
        │     3) Dedup pro (courseid,userid) im Request
        │     4) async: queue_adhoc_task(book_completion_task, {courseid,userid}, unique)
        │        sync : completion_booker::book(courseid, userid)
        │
        ▼
[Cron, kurz darauf]  book_completion_task::execute()   (asynchron, schwer)
        │  → completion_booker::book(courseid, userid)
        │     1) completion_info::is_enabled()?      sonst return false
        │     2) is_course_complete(user)?           wenn ja: fertig (idempotent)
        │     3) je Kriterium: completion_criteria::review()     (Pass 1)
        │     4) Aggregation ALL/ANY je Typ + gesamt → mark_complete() (Pass 2)
        │
        ▼
\core\event\course_completed  (vom Kern gefeuert)  →  local_adele etc. reagieren
```

**Deduplizierung:** statische Registry pro `(courseid,userid)` im Request; zusätzlich
`queue_adhoc_task($task, true)` (kollabiert identische ausstehende Tasks). In Tests wird
die Registry über `observer::reset_seen()` (setUp) zurückgesetzt.

---

## 9. Blueprint — Einstellungen

| Setting-Key | Typ | Bedeutung |
|---|---|---|
| `scopemode` | select | `all` \| `categories` \| `adele` (letzteres nur bei installiertem `local_adele`) |
| `categories` | multiselect | Kurszweige (nur bei `categories`); `make_categories_list()` |
| `includetags` / `excludetags` | textarea | optionale Tag-Filter (nur bei `categories`) |
| `processingmode` | select | `async` (Standard) \| `sync` |
| `reconcile_enabled` | checkbox | optionaler Sicherheitsnetz-Cron (Standard aus) |
| `enablelogging` | checkbox | Trace/Log bei Auswertung/Verbuchung (Standard aus) |

Sichtbarkeit über `hide_if` (Kategorie-/Tag-Felder nur im Modus `categories`).

---

## 10. Blueprint — Querschnitt

### 10.1 Performanz (L-Q1)
Request-Pfad nur: gültigkeits-/Scope-Check (MUC) + Task-Enqueue. Keine schweren Joins,
keine Kursbilder, keine Kriterienauswertung synchron. Schwere Arbeit im Cron,
scope-begrenzt, idempotent/dedupliziert. Kein instanzweites Rechnen.

### 10.2 Robustheit (L-Q4)
Jeder Callback in `try/catch`; Fehler → `debugging()` (DEBUG_DEVELOPER), nie Exception
nach außen (Observer-Regel von Moodle).

### 10.3 Datenschutz (L-Q5)
`null_provider` — kein eigener personenbezogener Datenbestand; Verbuchung erfolgt in
kerneigene Tabellen mit eigenen Privacy-Providern. Bei späterer externer Verbuchung
(Nicht-Ziel dieser Fassung) wäre ein vollwertiger Provider samt Export/Delete nötig.

### 10.4 Interoperabilität mit `local_adele`
`local_adele` beobachtet selbst `course_completed`; durch frühere Verbuchung reagiert
es früher (Synergie, keine Konkurrenz). Keine Rückkopplung: das Plugin triggert nicht
auf `course_completed`. Zugriff auf `local_adele` nur defensiv (Existenzprüfung,
`get_config`), keine harte Dependency.

### 10.5 Tests (L-Q6)
- `scope_resolver_test` — all/categories/Subtree/Tags/adele-Fallback.
- `observer_test` — Trigger-Plumbing direkt über `handle_completion_trigger()`
  (in-Scope reiht 1 Task, Dedup, out-of-Scope 0, sync 0). Bewusst **ohne** Einschreibung/
  Aktivitätsabschluss, um fremde Plugin-Observer unter PHPUnit nicht auszulösen.
- `completion_booker_test` — Guards (ungültige Eingaben, Completion aus, keine Kriterien,
  Idempotenz) + Phase-2-Integrationstests (alle Kriterien erfüllt → mark_complete(),
  Kriterien nicht erfüllt → false). Tests verwenden direkte DB-Fixture-Insertion (kein
  `enrol_user()`), um `user_enrolment_created` und damit fremde Plugin-Observer unter
  PHPUnit zu vermeiden.
- `privacy_test` — null_provider.
- Behat — Settings-Seite.

### 10.6 Betrieb / Rollout
Standard-Local-Plugin-Install (ZIP), Maturity ALPHA. Empfohlene Einführung: Modus
`categories` auf begrenztem Zweig, `async`, Logging an → Messung, dann Ausweitung. Der
Completion-Cron bleibt aktiviert (Sicherheitsnetz für zeitbasierte Kriterien).

---

## 11. Phasenplan / offene Punkte

- **Phase 1 ✓ (umgesetzt):** Scope-Resolver, Observer-Plumbing, Adhoc-Task, Settings,
  Privacy, Tests, Infrastruktur. CI grün auf 4.5 / 5.0.
- **Phase 2 (teilweise umgesetzt):**
  - ✓ Kursebenen-Aggregation + `completion_completion::mark_complete()` in
    `completion_booker::book()`: Pass 1 (criteria review), Pass 2 (ALL/ANY-Aggregation
    je Kriteriumstyp + gesamt), Integrationstests mit DB-Fixture-Insertion.
    Stabil auf 4.5 / 5.0 / 5.1 / 5.2 (öffentliche Completion-API stabil über alle Versionen).
  - ○ `reconcile_task`-Implementierung für datums-/dauerbasierte Kriterien.
  - ○ Optionaler Admin-Report beschleunigter Abschlüsse (bei aktivem Logging).

---

## 12. CI-Matrix (verbindlich)

| Moodle | PHP | Datenbank | Jobs |
|---|---|---|---|
| 4.5 | 8.1, 8.2, 8.3 | MariaDB 10.11 + PostgreSQL 16 | PHPUnit + Behat |
| 5.0 | 8.2, 8.3 | MariaDB 10.11 + PostgreSQL 16 | PHPUnit + Behat |
| 5.1 | 8.2, 8.3 | MariaDB 10.11 + PostgreSQL 16 | PHPUnit + Behat |
| 5.2 | 8.2, 8.3 | MariaDB 10.11 + PostgreSQL 16 | PHPUnit + Behat |

PHP 8.1 ist für alle Moodle-5.x-Zweige ausgeschlossen (PHP-Mindestanforderung 8.2).
