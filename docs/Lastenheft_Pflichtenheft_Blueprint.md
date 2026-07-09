# Plugin-Konzeption: Event-basierte Kursabschluss-Ermittlung (Arbeitstitel `local_instantcoursecompletion`)

*Dokument 000 — Technische Einschätzung, Lasten-/Pflichtenheft-Skizze, technisches Blueprint*
*Bezug: bestehendes Plugin `local_adele` (Wunderbyte, v0.3.7, Moodle 4.1–4.5)*

---

> **Status dieses Dokuments (aktualisiert):** Der finale Komponentenname ist `local_instantcoursecompletion`. Zwei Punkte sind seit der Erstfassung fixiert:
> - **Lesart A** (§1.3) ist verbindlich: keine eigene Abschlusslogik, ausschließlich observerbasierte, frühere/eingegrenzte Auswertung der vorhandenen Moodle-Completion-Kriterien.
> - **L-Q2**: Mindestversion **Moodle 4.5**, inkl. 5.x; **keine** Abwärtskompatibilität zu 4.1–4.4.


## 1. Technische Einschätzung

### 1.1 Ausgangslage — was Moodle bereits mitbringt

Moodle unterscheidet zwei Ebenen des Abschlusses:

- **Aktivitätsabschluss** (*activity completion*) wird **sofort** im Request aktualisiert. Beim Statuswechsel feuert das Event `\core\event\course_module_completion_updated`.
- **Kursabschluss** (*course completion*) wird dagegen **nicht sofort** berechnet, sondern durch die Scheduled Task `\core\task\completion_regular_task` („Calculate regular completion data") aggregiert. Die Moodle-Doku hält ausdrücklich fest, dass der Kursabschluss über Cron läuft und *nicht* unmittelbar erfolgt wie der Aktivitätsabschluss.

Daraus ergeben sich in der Praxis zwei bekannte Probleme, an denen das Plugin ansetzt:

1. **Latenz.** Zwischen dem Erfüllen der letzten Bedingung und der Verbuchung des Kursabschlusses liegt das Cron-Intervall (oft Minuten). Erst danach feuert `\core\event\course_completed` und werden Folgeprozesse (Zertifikate, Freischaltungen, `local_adele`-Lernpfadfortschritt) ausgelöst.
2. **Last.** Die reguläre Completion-Task rechnet instanzweit. Auf großen Instanzen ist das ein spürbarer, wiederkehrender Kostenblock (dokumentierte Laufzeiten von >2 Minuten bei ~500 Kursen/50.000 Nutzern sind keine Seltenheit).

### 1.2 Zielbild und Mehrwert

Das Plugin schließt genau diese Lücke: Es beobachtet die Events, die einen Kursabschluss auslösen *können*, prüft **eng eingegrenzt** (nur die konfigurierten Kurse/Zweige/Tags) und **nur für den betroffenen Nutzer** den Kursabschluss und verbucht ihn **umgehend** über die reguläre Moodle-Completion-API. Statt instanzweit periodisch zu rechnen, wird punktuell und ereignisgesteuert genau dort gerechnet, wo sich etwas geändert hat.

### 1.3 Klärungsbedarf — Bedeutung von „ermitteln **und verbuchen**"

Das ist die wichtigste offene Frage vor Umsetzungsbeginn, weil sie den Umfang maßgeblich bestimmt:

- **Lesart A (empfohlen, Standardannahme):** „Verbuchen" = Moodles eigenen Kursabschluss (Tabelle `course_completions`) früher und lastschonender berechnen. Das Plugin *beschleunigt und begrenzt* die vorhandene Kernmechanik, erfindet keine neue Abschlusslogik.
- **Lesart B:** „Verbuchen" meint eine **zusätzliche** Verbuchung außerhalb des Kerns — z. B. Meldung an ein Drittsystem/ERPNext, Zertifikatsausstellung, Einschreibung in Folgekurse, oder Fortschreibung im `local_adele`-Lernpfad.
- **Lesart C:** Das Plugin soll Abschluss **nach eigenen Kriterien** definieren (z. B. „abgeschlossen, wenn getaggte Aktivitäten X erledigt"), unabhängig von den Kurseinstellungen.

> **Wichtig:** Lesart C ist ein deutlich größeres Projekt (eigene Kriterien-Engine) und wird von diesem Blueprint bewusst *nicht* abgedeckt. Voraussetzung von Lesart A ist außerdem, dass im jeweiligen Kurs **Abschlussverfolgung aktiviert und Abschlusskriterien definiert** sind — das Plugin kann keinen Abschluss „aus dem Nichts" erzeugen, sondern nur vorhandene Kriterien früher auswerten. Diese Punkte sind vor Umsetzung mit dem Auftraggeber zu bestätigen.

### 1.4 Machbarkeit und empfohlener Ansatz

Machbar und Moodle-konform, ohne Kern-Patches. Empfohlene Architektur (Begründung siehe §4.4/§4.7):

**Observer (schlank, synchron)** → **Scope-Prüfung gegen MUC-Cache** → **deduplizierte Adhoc-Task** → **Completion-API (asynchron via Cron)**.

Der Observer selbst tut im Web-Request nur das Nötigste (Cache-Lookup + Task einreihen); die eigentliche Kriterienauswertung erfolgt in einer Adhoc-Task außerhalb des Nutzer-Requests. Das erfüllt die Performanz-Vorgabe am zuverlässigsten. Optional konfigurierbar ist ein **synchroner Modus** (sofortige Verbuchung im Request) für kleine Scopes, in denen minimale Latenz wichtiger ist als Request-Kosten.

### 1.5 Zu nutzende Moodle-APIs (maximale Kern-Nutzung)

| Zweck | API / Baustein |
|---|---|
| Ereignisregistrierung | `db/events.php` (Observer), `\core\event\*` |
| Abschluss lesen/prüfen | `completion_info` (`is_enabled`, `is_course_complete`, `get_criteria`, `get_completions`) |
| Kriterien auswerten | `completion_criteria::review()` je Kriteriumsobjekt |
| Kursabschluss verbuchen | `completion_completion::mark_complete()` |
| Asynchrone Verarbeitung | `\core\task\adhoc_task`, `\core\task\manager::queue_adhoc_task()` |
| Konfiguration | `admin_settingpage`, `admin_setting_config*`, `settings.php` |
| Kategoriebaum | `core_course_category::make_categories_list()`, Pfad-Auflösung über `course_categories.path` |
| Caching | Cache-API (`\cache::make`, `db/caches.php`, MUC application cache) |
| Datenschutz | `\core_privacy\...\null_provider` (das Plugin speichert i. d. R. keine personenbezogenen Daten) |
| Sprache/Logs | `lang/en/local_instantcoursecompletion.php`, ggf. `classes/event/*` für eigene Log-Events |

### 1.6 Zentrale Risiken (und Gegenmaßnahmen)

- **Event-Stürme / Doppelverarbeitung** bei vielen Aktivitätsabschlüssen im selben Request → Deduplizierung pro `(userid, courseid)` (§4.4).
- **Observer darf nie fatal werfen** (Moodle-Vorgabe) → alles in `try/catch`, defensive Snapshots, keine schwere Logik synchron.
- **Version-Abhängigkeit der Completion-Interna** (4.1 vs. 4.5) → nur öffentliche API-Methoden nutzen, exakte Aufrufsequenz je Zielversion durch Integrationstests absichern.
- **Rückkopplung mit `local_adele`** (das ebenfalls `course_completed` beobachtet) → keine Schleifen, klare Zuständigkeit, siehe §4.6.
- **Scope-Cache-Invalidierung** bei Kurs-/Kategorie-/Tag-Änderungen → definierte Invalidierungs-Trigger (§4.3).

---

## 2. Lastenheft (Auftraggebersicht — „was", knappe Skizze)

**L-F Funktionale Anforderungen**

- **L-F1** Das Plugin erkennt ereignisgesteuert, wenn ein Nutzer die Abschlusskriterien eines Kurses erfüllt, und verbucht den Kursabschluss zeitnah (ohne auf den regulären Completion-Cron zu warten).
- **L-F2** Läuft als **local-Plugin** und ist vollständig über die Plugin-Settings konfigurierbar.
- **L-F3** Der Wirkungsbereich (Scope) der Observer ist per Einstellung wählbar:
  - **L-F3a** *alle Kurse* der Instanz,
  - **L-F3b** *auswählbare Kurszweig(e)* (Kategorien inkl. Unterkategorien),
  - **L-F3c** *Übernahme der `local_adele`-Einstellungen* (Kategorien/Tags), sofern `local_adele` installiert ist.
- **L-F4** Ist `local_adele` nicht installiert, ist Modus L-F3c nicht wählbar bzw. deaktiviert; die anderen Modi bleiben verfügbar.
- **L-F5** Ein Kursabschluss wird nicht doppelt verbucht; bereits abgeschlossene Kurse werden übersprungen.

**L-Q Nicht-funktionale Anforderungen**

- **L-Q1** Die Performanz der Instanz darf nicht merklich beeinträchtigt werden (im Web-Request nur minimale, gecachte Operationen).
- **L-Q2** Kompatibel zu den von `local_adele` unterstützten Moodle-Versionen (4.1–4.5).
- **L-Q3** Keine Änderung an Moodle-Kerndateien; ausschließlich dokumentierte APIs.
- **L-Q4** Robustheit: Fehler in einem Ereignis dürfen weder den Nutzer-Request noch andere Observer stören.
- **L-Q5** Nachvollziehbarkeit (optionales Logging/eigene Log-Events) und DSGVO-Konformität.
- **L-Q6** Wartbarkeit gemäß Moodle Coding Guidelines (Code-Checker, PHPUnit, CI wie im `local_adele`-Repo vorhanden).

---

## 3. Pflichtenheft (Auftragnehmersicht — „wie", knappe Skizze)

**P1 (→L-F1/L-F5):** Observer auf abschlussrelevante Events; Auswertung über `completion_info`/`completion_criteria::review()`; Verbuchung über `completion_completion::mark_complete()`; Idempotenz durch Vorabprüfung `is_course_complete()`.

**P2 (→L-F2/L-Q3):** Standard-`settings.php` mit `admin_settingpage` unter *Local plugins*; keine Kernänderungen.

**P3 (→L-F3):** Setting `scopemode` mit Optionen `all_courses | own_categories | adele`. Bei `own_categories` Mehrfachauswahl der Kategorien (Kurszweige) via `admin_setting_configmultiselect`; Unterkategorien werden über den `path` aufgelöst.

**P4 (→L-F3c/L-F4):** Modus `adele` liest `get_config('local_adele', …)` (`catfilter`, `includetags`, `excludetags`, `selectconfig`) und wendet dieselbe Filtersemantik an. Die Option wird nur angeboten, wenn `local_adele` installiert ist (Prüfung via `\core\plugin_manager` / Klassenexistenz).

**P5 (→L-Q1):** Scope wird zu einer Menge in-Scope-Kurs-IDs bzw. zu Kategorie-Pfad-/Tag-Regeln aufgelöst und in einem MUC-Application-Cache gehalten; Observer macht nur einen Cache-Lookup. Schwere Auswertung ausgelagert in Adhoc-Tasks; optionaler Synchronmodus.

**P6 (→L-Q4):** Vollständiges `try/catch` in jedem Callback; Nutzung von Record-Snapshots; keine Exceptions nach außen.

**P7 (→L-Q5):** `null_provider` (keine eigene PII-Speicherung); optionale eigene Log-Events (`\local_instantcoursecompletion\event\completion_booked`).

**P8 (→L-Q6):** PHPUnit-Unit- und -Integrationstests je Scope-Modus und Zielversion; Anbindung an bestehende `moodle-plugin-ci`.

**Abnahmekriterien (Auszug):** Abschluss eines getaggten Kurses wird ≤1 Cron-Intervall (bzw. sofort im Synchronmodus) verbucht; ein außerhalb des Scopes liegender Kurs wird nachweislich *nicht* durch das Plugin verbucht; Observer-Kosten im Request messbar vernachlässigbar (Profiling); keine Doppelverbuchung.

---

## 4. Technisches Blueprint

### 4.1 Komponenten- und Dateistruktur

```
local/ccobserver/
├── version.php                     # component=local_instantcoursecompletion, requires/supported wie local_adele
├── settings.php                    # Admin-Settings (Scope-Modus, Kategorien, Tags, sync/async)
├── db/
│   ├── events.php                  # Observer-Registrierung
│   ├── caches.php                  # Definition des Scope-Caches (MUC application)
│   └── tasks.php                   # (optional) periodische Reconcile-Task
├── classes/
│   ├── observer.php                # schlanke Event-Callbacks
│   ├── scope_resolver.php          # Scope-Modi → in-Scope-Prüfung (+ Cache)
│   ├── completion_booker.php       # Kriterienauswertung + Verbuchung (Kern-API-Wrapper)
│   ├── task/book_completion_task.php   # Adhoc-Task (userid, courseid)
│   ├── task/reconcile_task.php     # (optional) Sicherheitsnetz-Scheduled-Task
│   ├── event/completion_booked.php # (optional) eigenes Log-Event
│   └── privacy/provider.php        # null_provider
└── lang/en/local_instantcoursecompletion.php
```

### 4.2 Zu beobachtende Events

| Event | Auslöser | Rolle im Plugin |
|---|---|---|
| `\core\event\course_module_completion_updated` | Aktivitätsabschluss ändert sich | **Primärtrigger** — häufigster Weg zum Kursabschluss |
| `\core\event\user_graded` | Bewertung im Gradebook | Trigger für notenbasierte Kriterien (*grade to pass*) |
| `\core\event\course_completed` | Kursabschluss bereits verbucht | **Kein Trigger** — nur zur Deduplizierung/Beobachtung (Kern hat schon verbucht) |
| *(optional)* manuelle/rollenbasierte Kriterien-Events | Lehrende markieren Abschluss | je nach genutzten Kriterientypen ergänzen |

> Hinweis: Zeit-/datumsbasierte Kriterien (*duration*, *date*) lassen sich ereignisgesteuert *nicht* zuverlässig erkennen. Dafür bleibt Moodles regulärer Completion-Cron zuständig; das Plugin ergänzt ihn, ersetzt ihn nicht. Ein optionaler `reconcile_task` (§4.1) kann als Sicherheitsnetz periodisch, aber scope-begrenzt nacharbeiten.

### 4.3 Scope-Auflösung und Caching

`scope_resolver` liefert eine Methode `is_in_scope(int $courseid): bool` sowie `get_scope_course_ids(): array`.

- **`all_courses`:** trivial `true` (sichtbare Kurse, ohne Frontpage).
- **`own_categories`:** eigene Kategorienauswahl; Unterkategorien über `course_categories.path LIKE '%/<catid>/%'` (analog `local_adele`).
- **`adele`:** liest `get_config('local_adele')` und wendet dieselbe Semantik an — Kategorien (`catfilter`), Include-/Exclude-Tags (`includetags`/`excludetags`). *Wichtig:* nur die **Filterdefinition** wird übernommen; die scope-Auflösung erfolgt über eine **eigene, leichte, systemweite** Abfrage. Die UI-orientierte `learning_path_courses::get_availablecourses()` wird bewusst **nicht** aufgerufen (sie ist nutzer-/rollengebunden — `only_subscribed` — und lädt zusätzlich Kursbilder).

**Cache:** MUC-Application-Cache `scope_courseids` (auflösung → Set von Kurs-IDs oder kompakte Regelrepräsentation). Invalidierung bei: Änderung der Plugin-Settings, Änderung der `local_adele`-Settings (im `adele`-Modus), sowie bei Kurs-/Kategorie-/Tag-Änderungen (Observer auf `course_created/updated/deleted`, `course_category_*`, `tag_*` — nur Cache leeren, keine Berechnung). So kostet der Primärpfad im Request einen reinen Cache-Lookup.

### 4.4 Verarbeitungsfluss (Sequenz)

```
[Nutzer erfüllt Aktivitätskriterium im Request]
        │
        ▼
\core\event\course_module_completion_updated
        │
        ▼
observer::course_module_completion_updated()          (synchron, schlank)
        │  try { …
        │  1) completion enabled? (Cache/leichtprüfung) sonst return
        │  2) scope_resolver::is_in_scope(courseid)?    (MUC-Lookup) sonst return
        │  3) Dedup pro (userid,courseid) in diesem Request
        │  4) queue_adhoc_task(book_completion_task, {userid,courseid}, unique)
        │  } catch (\Throwable $e) { debugging(...) }   (nie fatal)
        │
        ▼
[Cron, kurz darauf]  book_completion_task::execute()   (asynchron, schwer)
        │  1) completion_info::is_course_complete(user)? → wenn ja: fertig (idempotent)
        │  2) je Kriterium: completion_criteria::review(completion)
        │  3) Aggregation erfüllt? → completion_completion::mark_complete()
        │  4) (optional) completion_booked-Event / Log
        │
        ▼
\core\event\course_completed  (vom Kern gefeuert)  →  local_adele etc. reagieren
```

Im **Synchronmodus** entfällt Schritt 4 des Observers; stattdessen ruft der Observer `completion_booker` direkt auf (nur für kleine Scopes empfohlen).

**Deduplizierung:** Innerhalb eines Requests werden mehrfache `(userid,courseid)`-Trigger über eine statische Registry zusammengefasst; zusätzlich wird die Adhoc-Task mit Eindeutigkeitsmerkmal (`custom`/`userid`/`courseid`) eingereiht, sodass ausstehende Duplikate kollabieren.

### 4.5 Einstellungen (`settings.php`)

| Setting-Key | Typ | Bedeutung |
|---|---|---|
| `scopemode` | `configselect` | `all_courses` \| `own_categories` \| `adele` (letzteres nur bei installiertem `local_adele`) |
| `categories` | `configmultiselect` | Kurszweige (nur relevant bei `own_categories`); Auswahl aus `make_categories_list()` |
| `includetags` / `excludetags` | `configtextarea` (validiert) | optionale eigene Tag-Filter (nur bei `own_categories`); Validierung analog `admin_setting_course_tags` |
| `processingmode` | `configselect` | `async` (Standard, Adhoc-Task) \| `sync` (im Request) |
| `enablelogging` | `configcheckbox` | eigenes Log-Event bei Verbuchung |
| `reconcile_enabled` | `configcheckbox` | optionaler scope-begrenzter Sicherheitsnetz-Cron |

Sichtbarkeits-/Verfügbarkeitslogik: `adele`-Option und die Übernahme-Hinweise nur zeigen, wenn `local_adele` vorhanden ist; eigene Kategorie-/Tag-Felder nur im Modus `own_categories` relevant (Anzeige über `hide_if`).

### 4.6 Interoperabilität mit `local_adele`

- `local_adele` beobachtet selbst `\core\event\course_completed` und propagiert Abschlüsse in Lernpfade. Indem `local_instantcoursecompletion` den Abschluss **früher** verbucht, feuert `course_completed` früher → `local_adele` reagiert früher. **Synergie, keine Konkurrenz.**
- **Keine Schleifen:** `local_instantcoursecompletion` triggert *nicht* auf `course_completed` (nur Beobachtung/Dedup). Verbucht wird ausschließlich der reguläre Kursabschluss über die Kern-API — es entstehen keine plugin-eigenen Events, auf die `local_adele` oder das Plugin selbst rückkoppeln könnten.
- **Konsistenz der Scope-Definition:** Im `adele`-Modus dieselben Config-Keys/Filterregeln wie `local_adele`, damit „was `local_adele` als relevant ansieht" und „was `local_instantcoursecompletion` verbucht" deckungsgleich sind.
- **Lose Kopplung:** Zugriff auf `local_adele` nur defensiv (Existenzprüfung, `get_config`), keine harte Abhängigkeit in `version.php` — das Plugin funktioniert eigenständig.

### 4.7 Performanz-Maßnahmen (Zusammenfassung)

- Web-Request-Pfad: nur `completion`-Enabled-Check + MUC-Scope-Lookup + Task-Enqueue. Keine schweren Joins, keine Kursbilder, keine Kriterienauswertung synchron.
- Schwere Arbeit ausgelagert in Adhoc-Tasks (Cron), scope-begrenzt und pro `(userid,courseid)` idempotent/dedupliziert.
- Scope als Cache; Invalidierung nur bei strukturellen Änderungen.
- Früher Ausstieg: nicht abschlussfähige/-aktivierte Kurse und bereits abgeschlossene Nutzer werden sofort verworfen.
- Kein instanzweites Rechnen — im Gegensatz zur regulären Completion-Task wird nur der tatsächlich betroffene Fall behandelt.

### 4.8 Datenschutz

Das Plugin speichert selbst keine personenbezogenen Daten (es nutzt Kern-Tabellen). Umsetzung über `\core_privacy\local\metadata\null_provider`. Falls in Lesart B externe Verbuchung/Export hinzukommt, ist ein vollwertiger Privacy-Provider samt Export/Delete vorzusehen — dann Anforderung neu bewerten.

### 4.9 Tests und Qualitätssicherung

- **Unit:** `scope_resolver` je Modus (all/own/adele), inkl. Unterkategorie-Auflösung und Tag-Include/Exclude; Dedup-Logik.
- **Integration (PHPUnit, generator-basiert):** Kurs mit Abschlusskriterien anlegen, Kriterien via API erfüllen, prüfen dass `is_course_complete()` nach Task-Lauf true ist; Negativfall außerhalb Scope.
- **Versionsmatrix:** Aufrufsequenz der Completion-API gegen 4.1 und 4.5 verifizieren.
- **CI:** Einklinken in `moodle-plugin-ci` (wie im `local_adele`-Repo: codechecker, phpunit, phpdoc, mustache/…); Zielprofil Moodle 4.1 LTS und 4.5.

### 4.10 Rollout und Betrieb

- Auslieferung Standard-Local-Plugin (ZIP/Installer), Maturity zunächst `ALPHA`/`BETA`.
- Empfohlene Ersteinführung: Modus `own_categories` auf einem begrenzten Zweig, `async`, Logging an → Messung, dann Ausweitung.
- Betriebshinweis: Der reguläre Completion-Cron bleibt aktiviert (Sicherheitsnetz für zeitbasierte Kriterien); das Plugin ergänzt, ersetzt ihn nicht.

---

## 5. Offene Punkte / nächste Schritte

1. **Bedeutung von „verbuchen" bestätigen** (Lesart A/B/C, §1.3) — größter Scope-Hebel.
2. **Genutzte Abschluss-Kriterientypen** klären (Aktivität, Note, Rolle/manuell, Zeit/Datum) → bestimmt die zu beobachtenden Events und die Reichweite des `reconcile_task`.
3. **Sync vs. async als Default** festlegen (Latenz- vs. Last-Präferenz).
4. **Zielversionen final fixieren** (4.1 LTS? 4.5? künftig 5.x?).
5. **local_adele-Modus:** Übernahme nur der Filterdefinition bestätigen; Verhalten bei `only_subscribed` im Hintergrundkontext festlegen.
6. Danach: Prototyp `scope_resolver` + Observer + Adhoc-Task auf einer 4.x-Testinstanz, Profiling gegen die Performanz-Vorgabe (L-Q1).

*Alle in §1.5/§4 genannten internen Completion-Aufrufsequenzen sind vor Implementierung gegen die konkrete Zielversion zu verifizieren, da die Interna zwischen 4.1 und 4.5 abweichen können.*
