# Changelog — local_instantcoursecompletion

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
versioning follows [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Fixed (kein Versions-Increment)

- **F4-Test korrigiert.** `test_reconcile_amortises_course_load_across_users()` behauptete,
  der kursbezogene Booker lese *strikt weniger* als eine `book()`-Fassade je Nutzer.
  `make check` widerlegte das empirisch: 1359 gegenüber 1327 Reads für 25 Nutzer — der
  Booker-Pfad las sogar geringfügig mehr. Moodle bedient Kursdatensatz und Kriterienliste
  bereits aus Request-Caches, sodass das in F2 vermiedene Nachladen **keine messbaren
  Datenbank-Reads** kostet. F2 bleibt korrekt und sinnvoll (weniger `completion_info`-Aufbau
  und Kriterien-Neubau je Nutzer), aber der Gewinn ist CPU/Objektaufbau, nicht Reads. Der
  Test misst jetzt den **marginalen Read-Aufwand je Nutzer** und deckelt ihn — ein ehrlicher,
  robuster Regressionsschutz gegen Per-Nutzer-Read-Explosionen. Ersetzt durch
  `test_reconcile_per_user_reads_stay_bounded()`. Damit wird der externe P0-Befund „N+1 in
  DB-Reads / 5000× Kursladevorgang" relativiert; die skeptische Einschätzung aus
  `session-004.md` („real, aber keine Grössenordnung") bestätigt sich.
- **README:** Abschnitt „Booking" ergänzt. Hält die Entscheidung fest, den konsolidierten
  Service `completion_booker` zu nennen (nicht `course_completion_booker`), und dokumentiert,
  dass das Einmal-Laden je Kurs eine strukturelle Vereinfachung ist, keine Read-Ersparnis.

- **Behat nachgezogen.** `tests/behat/settings.feature` prüfte den alten Label-Text
  „Maximum bookings planned per run" (hide_if-Szenario für `maxtasksperrun`). Auf den in
  F3 korrigierten Text „Maximum enrolments examined per run" umgestellt — beide
  Fundstellen (`should see` / `should not see`). PHPUnit lief lokal grün; dieser Test
  bricht nur im CI (Selenium/Chrome), daher erst dort sichtbar.

### Geplant — Session 005

Aus dem externen Review vom 2026-07-09, gegen den Stand 0.4.10 verifiziert.
Vollständige Fassung mit Begründungen: `docs/sessions/session-004.md`.

**Phase F — Konsolidierung des Buchungspfads (0.5.0 → 0.5.2)**

Auslieferung in drei Schritten: 0.5.0 (F1), 0.5.1 (F2, F4), 0.5.2 (F3, F5).

- **F1** — erledigt in 0.5.0 (siehe unten).
- **F2** — erledigt in 0.5.1 (siehe unten).
- **F3** — erledigt in 0.5.2 (siehe unten).
- **F4** — erledigt in 0.5.1 (siehe unten).
- **F5** — erledigt in 0.5.2 (siehe unten). Die drei Alt-Dateien
  (`book_due_completion_task.php`, `due_criteria_test_trait.php`, `generator/lib.php`)
  waren bereits vor Session 005 aus dem Repo gelöscht; offen blieben nur
  `observer::reset_seen()` und `scope_resolver::csv_to_strings()`.

**Phase G — DRY und Betriebssicherheit (Ziel 0.6.0)**

- **G1** `due_candidate_repository`: die Definition eines „fälligen, noch nicht verbuchten
  Nutzers" steht an vier Stellen.
- **G2** `completion_course_repository::get_course_ids_after()` für Discovery und Reconcile.
- **G3** `due_scheduler::schedule_user()` fragt je Kriterium einzeln ab.
- **G4** Lock je `(courseid, criteriaid)` im Batch-Task: `due_user_ids()` filtert auf
  `time() - enrolperiod`, nicht auf den Bucket; bei verzögertem Cron laden zwei fällige
  Bucket-Tasks dieselbe Kohorte.
- **G5** Maxima senken und ein Laufzeitbudget mit Fortsetzung ergänzen.
- **G6** `queue_bucket()` und `queue_continuation()` zu einer privaten `queue_task()`.

**Phase H — Release-Disziplin (Ziel 0.6.x)**

- **H1** `moodle-release.yml`: `phpcpd || true` kann den Build nie brechen. Genau deshalb
  blieb `course_booker` unbemerkt.
- **H2** Release-Job: sauberer Arbeitsbaum erzwingen, Paket aus `git archive`.
- **H3** Workflow-Kopf nennt PHP 8.5 für Moodle 5.2, die Matrix schliesst es aus.
- **H4** Test: jede Definition in `db/caches.php` besitzt einen `cachedef_*`-String.
- **H5** Entscheidungs- und Historienkommentare aus dem Produktivcode entfernen
  (fünf Fundstellen, alle aus Session 004).
- **H6** `set_userid()` entscheiden und den Akteur in `completion_booked` testen.

**Vor 1.0.0 / `MATURITY_STABLE`**

- Lasttest auf echter Instanz: 10.000 Fälligkeiten zum selben Zeitpunkt, Upgrade mit
  bestehenden Cursorn. PHPUnit verifiziert die Deckelungen nicht — der
  `get_fieldset_sql()`-Fehler blieb 0.4.7 bis 0.4.10 unentdeckt.
- Entscheidung zu `composer.json`: für den Betrieb funktionslos.


## [0.5.2] - 2026-07-10

### Fixed — Sprachstrings und README (F3)

- **`cachedef_scopetagids` ergänzt** (EN + DE). Die Cache-Definition existierte in
  `db/caches.php`, der Sprachstring fehlte in beiden Dateien — ein Verstoss gegen den
  Moodle-Standard, dass jede Cache-Definition einen `cachedef_*`-String besitzt.
- **`setting:maxtasksperrun`** hiess „Maximum bookings planned per run" / „Maximale
  Planungen je Lauf". Begrenzt wird die Zahl **geprüfter Einschreibungsdatensätze**, nicht
  geplanter Tasks. Titel entsprechend korrigiert.
- **`setting:schedulingenabled_desc`** beschrieb einen Task „je Kurs, Person und
  Fälligkeitszeitpunkt" (Architektur vor 0.4.9). Tatsächlich: einer je **Kurs, Kriterium
  und Fälligkeitsfenster**. Korrigiert.
- **README:** die Aussage „None of the three grows with the number of courses" traf auf
  `scopecoursemembership` nicht zu — dieser Cache hält einen Eintrag je berührtem Kurs.
  Präzisiert.

### Removed — toter Code (F5)

- **`observer::reset_seen()` entfernt.** Die öffentliche Methode wurde ausschliesslich von
  Tests aufgerufen. Die Dedup-Registry `$seen` ist jetzt `private`; die beiden betroffenen
  Testklassen leeren sie über Reflection (`reset_observer_seen()`).
- **`scope_resolver::csv_to_strings()` entfernt.** Reines Alias auf `split_list()` ohne
  eigene Semantik; die beiden Aufrufstellen rufen `split_list()` direkt.

Die drei in F5 zusätzlich genannten Alt-Dateien waren bereits vor dieser Session gelöscht;
in 0.5.2 gibt es keine weiteren Löschungen.


## [0.5.1] - 2026-07-10

### Changed — Reconcile lädt Kurs und Kriterien einmal je Kurs (F2)

- **`reconcile_task::process_course()` öffnet einen `completion_booker::for_course()`
  je Kurs** und bucht jeden Nutzer über `book_user()`. Zuvor rief es
  `completion_booker::book()` je Nutzer und lud damit Kursdatensatz und Kriterienliste
  erneut — bei 5.000 Nutzern eines Kurses 5.000-mal statt einmal. Ist der Kurs zwischen
  Auswahl und Verarbeitung gelöscht worden oder führt er keine Completion mehr, liefert
  `for_course()` `null` und der Kurs wird übersprungen.

### Added — Query-Count-Regressionstest (F4)

- **`reconcile_task_test::test_reconcile_per_user_reads_stay_bounded()`** ruft
  `reconcile_task::process_course()` für zwei identische Kurse unterschiedlicher
  Kohortengrösse auf und misst mit `$DB->perf_get_reads()`. Der **marginale** Read-Aufwand
  je zusätzlichem Nutzer — die Differenz der Zählungen geteilt durch die
  Kohortendifferenz — hebt alle festen Kosten pro Lauf und pro Kurs auf und muss ein
  kleiner, gedeckelter Konstantwert bleiben. Eine Regression, die die Kohorte je Nutzer
  neu scannt oder je Kriterium ungecacht abfragt, treibt ihn hoch und bricht den Test.
  (Ursprünglich als Strikt-weniger-Vergleich Booker-Pfad vs. Fassade formuliert; siehe
  Korrektur unter [Unreleased].)


## [0.5.0] - 2026-07-10

### Changed — Buchungspfad konsolidiert (F1)

Die Booking-API ist jetzt kursbezogen und zustandsbehaftet. Grund für den MINOR-Bump.

- **`completion_booker` ist ein zustandsbehafteter, kursbezogener Service.**
  `completion_booker::for_course($courseid)` liest Kurs, `completion_info` und die
  Kriterienliste **einmal** und gibt eine Instanz zurück (oder `null`, wenn der Kurs
  gelöscht wurde, der Site-Kurs ist oder keine Completion führt). Jeder Mehr-Nutzer-Aufrufer
  öffnet eine Instanz je Kurs und bucht die Nutzer dagegen, statt Kurs und Kriterien je
  Nutzer neu zu laden. Instanzmethoden: `book_user($userid)`, `book_criterion($criterion, $userid)`,
  `get_course()`, `get_criterion($criteriaid)`. Der `(courseid, userid)`-Lock aus 0.4.10 bleibt
  in beiden Buchungspfaden erhalten.
- **`completion_booker::book($courseid, $userid)` bleibt als dünne Fassade** über
  `for_course()->book_user()` — für Einzelpaar-Aufrufer wie den Sofort-Buchungstask des
  Observers, bei denen es keine Kosten zu amortisieren gibt.
- **`classes/course_booker.php` gelöscht.** Die 248-Zeilen-Klasse war von keiner Zeile
  referenziert, dupliziert die zentrale Buchungslogik und kannte den in 0.4.10 ergänzten
  Lock nicht. Ihre einzige eigene Idee — Kurs/Info/Kriterien einmal je Kurs zu halten — ist
  jetzt der Normalfall von `completion_booker`.
- **`book_due_completion_batch_task` nutzt `for_course()`.** Statt `load_course()` +
  eigenem `new completion_info` + `get_criteria()` öffnet der Task einen Booker je Lauf und
  bucht jede Fälligkeit über `$booker->book_criterion(...)`. `completion_booker::load_course()`
  ist damit nicht mehr Teil der öffentlichen API (entfällt).
- **Tests:** vier Methoden für die neue Instanz-API in `completion_booker_test`
  (`for_course`, `get_criterion`, Instanz-`book_user`, Instanz-`book_criterion`). Die
  bestehenden 20 Fassaden-Tests laufen unverändert, weil `book()` erhalten bleibt.

### Hinweis zu gelöschten Dateien

Ein Patch-ZIP kann Löschungen nicht ausdrücken. In dieser Version manuell zu entfernen:

- `classes/course_booker.php`


## [0.4.10] - 2026-07-09

### Fixed (kein Versions-Increment, iterativ in dieser Session)

- **`due_scheduler.php` war ein inkonsistenter Merge.** Der Produktivcode
  (`discover_due_criteria_task`, `book_due_completion_batch_task`, `settings.php`) ruft
  `BATCH_WINDOW`, `due_bucket()`, `queue_bucket()` und `queue_continuation()` auf; die
  Datei im Repository stammte aus 0.4.8 und kannte keines davon, führte dafür aber die
  Sackgassen `queue()`, `queue_batch()`, `date_due_user_ids()`, `user_can_own_a_task()`
  und `JITTER_WINDOW`. Die Fehlermeldung
  `Undefined constant due_scheduler::BATCH_WINDOW` beim PHPUnit-Init war nur der erste
  Aufschlagpunkt: `settings.php` wird beim Setzen der Default-Einstellungen geladen.
  Ersetzt durch die kohärente Fassung aus 0.4.9.
- **`observer::user_enrolment_created()` und `::user_enrolment_updated()` fehlten**,
  obwohl `db/events.php` sie unverändert registriert. Jede Einschreibung auf einer
  Produktivinstanz hätte eine Exception geworfen. Ursache: in 0.4.8 wurde `observer.php`
  versehentlich aus einem Stand vor 0.4.6 fortgeschrieben, wodurch die
  Einschreibungs-Observer aus 0.4.6 verlorengingen. Wiederhergestellt.
- **Neuer Vertragstest `observer_test::test_every_registered_callback_exists()`**: liest
  `db/events.php` und prüft für jeden der 17 Callbacks, dass die Methode existiert. Genau
  dieser Test hätte den Ausfall gefunden; die Einschreibungs-Observer waren bisher
  überhaupt nicht abgedeckt. Ergänzt um einen funktionalen Test, der ein
  `user_enrolment_created`-Ereignis konstruiert und den geplanten Batch-Task prüft.
- **`tests/book_due_completion_batch_task_test.php`** gegen die tatsächliche API neu
  geschrieben: `queue_bucket()` / `queue_continuation()` statt des nie existierenden
  `queue_batch()`. Der Fälligkeitszeitpunkt wird aus dem Kriterium abgeleitet, nicht aus
  `time()` — `due_bucket()` rundet **auf**, sodass das Fenster von „jetzt" in der Zukunft
  läge und `get_next_adhoc_task()` den Task nicht zurückgäbe. Das erklärt die beiden
  gemeldeten Fehlschläge.
- **`tests/due_scheduler_test.php`** und **`tests/discover_due_criteria_task_test.php`**
  auf den 0.4.9-Stand gebracht; sie prüften noch die per-Nutzer-`customdata` aus 0.4.8.
- `completion_test_trait::enable_due_scheduling()` ergänzt.


Phasen D und E des externen Reviews: Betriebsrobustheit, Einstellungsvalidierung,
begrenztes Fan-out — und die abschliessende Dokumentation.

### Added — Nebenläufigkeit (D1)

- **`completion_booker` nimmt ein Lock je `(courseid, userid)`** und prüft den
  Abschlusszustand **innerhalb** des Locks erneut. Ohne das konnten zwei parallele
  Tasks beide den anfänglich unvollständigen Zustand sehen, beide aggregieren und beide
  `completion_booked` auslösen. Die Core-Daten blieben idempotent, nachgelagerte
  Event-Consumer nicht. Das Lock ist bewusst nicht kursweit: ein solches würde einen
  ganzen Batch hinter demjenigen Nutzer serialisieren, der gerade anderswo gebucht wird.

### Added — Betriebsrobustheit (D2)

- **Systematische Fehler brechen den Lauf ab, Einzelfehler nicht.** `dml_exception` und
  `coding_exception` propagieren jetzt in `reconcile_task`, `discover_due_criteria_task`
  und `book_due_completion_batch_task`; der Cursor bleibt stehen, der nächste Lauf
  wiederholt dieselbe Scheibe, und Cron meldet den Fehler. Bisher wurde jeder
  `Throwable` je Nutzer geschluckt, sodass ein Datenbankfehler den Cursor durch die
  ganze Instanz schob und Nutzer stillschweigend übersprang.
- **Der Batch-Task hatte gar keine Fehlerbehandlung.** Bricht er ab, reiht er zuvor eine
  Fortsetzung ab dem zuletzt tatsächlich verarbeiteten Nutzer ein — es wird niemand
  übersprungen.
- **Fehlerzähler `failed` in allen drei Tasks**, und die Trace-Zeile erscheint bei
  `failed > 0` **auch bei abgeschalteter Protokollierung**. Ein Fehler auf einem
  Produktivsystem war bisher nur im Developer-Debugging sichtbar.

### Changed — Begrenztes Fan-out (D3)

- **`observer::course_completed()` läuft die abhängigen Kurse nicht mehr selbst ab.**
  Ein Grundlagenkurs, der Voraussetzung vieler Programme ist, erzeugte eine unbegrenzte
  Ergebnisliste, eine Scope-Prüfung je Zielkurs und potenziell sehr viele
  Task-Einreihungen in einem einzigen Request. Neu: `notify_dependent_courses_task`
  blättert mit einem `fromcourseid`-Cursor über höchstens 200 abhängige Kurse je Lauf
  und reiht bei voller Seite eine Fortsetzung ein.
- `criteria_index::dependent_course_ids()` nimmt `$fromcourseid` und `$limit` entgegen
  und sortiert aufsteigend.

### Changed — Tag-Auflösung (D4)

- **`core_tag_tag::get_by_name_bulk()`** statt eines `get_by_name()`-Aufrufs je Tagname.
  Bei zehn konfigurierten Tags war das ein N+1 pro geprüftem Kurs.
- Die aufgelösten Tag-IDs werden im neuen MUC-Cache **`scopetagids`** pro
  Konfigurations-Hash abgelegt. Ohne den löste ein Lauf über 200 Kurse unter Cache-Miss
  dieselbe Handvoll Namen 200-mal auf.

### Added — Einstellungsvalidierung (D5)

- **`bounded_int_setting`** (neu): weist Werte ausserhalb eines Bereichs beim Speichern
  zurück. Die Tasks klemmten einen nicht-positiven Wert bisher stillschweigend auf einen
  fest kodierten Default — der Administrator erfuhr nie, dass sein Wert nicht galt.
  Angewandt auf `batchsize` (1–50 000), `maxtasksperrun` (1–100 000) und
  `reconcilebudget` (1–100 000).
- **Konsistenzwarnung für den Planungshorizont.** Ist er kürzer als das stündliche
  Discovery-Intervall zuzüglich des Batch-Fensters, weist die Einstellungsseite darauf
  hin, dass Fälligkeiten in dieser Lücke erst im übernächsten Lauf geplant werden.

### Added — Dokumentation (Phase E)

- **README neu.** Neuer Abschnitt **Compatibility Logic**: eine Tabelle der Stellen, an
  denen Moodle mit sich selbst uneins ist (Tracked Users, Duration-Startzeit,
  Datums-Abschlusszeitpunkt, self/role/unenrol) und welcher Seite dieses Plugin folgt.
  Die Behauptung „It adds no completion logic of its own" ist damit ersetzt durch eine
  präzise Aussage. Ergänzt: Batch-Fenster, Keyset-Cursor, beide Locks, Fan-out-Task,
  Betriebs-Monitoring, sowie die drei Test-Fallstricke.
- **`composer.json`** ergänzt. Die README verwies auf `make`-Ziele und eine CI-Matrix,
  ohne dass sich die Dev-Abhängigkeiten aus dem Paket ergaben.

### Added — Tests

- `notify_dependent_courses_task`: alle abhängigen Kurse werden ausgelöst; ohne
  Abhängige passiert nichts; `fromcourseid` überspringt das bereits Erledigte;
  `dependent_course_ids()` blättert aufsteigend und respektiert das Limit.
- `bounded_int_setting`: Werte im Bereich, unter dem Minimum, über dem Maximum und
  Nicht-Ganzzahlen.
- `observer`: `course_completed` bucht nicht mehr selbst, sondern reiht den Fan-out-Task
  ein; dieser löst dann die abhängigen Kurse aus.

### Fixed

- phpcs: Inline-Kommentar in `tests/fixtures/completion_test_trait.php` und in
  `db/upgrade.php` begann kleingeschrieben mit einem Funktions- bzw. Klassennamen.

## [0.4.9] - 2026-07-09

Phase C des externen Reviews: ein Batch-Task je Kriterium und Fälligkeitsfenster statt
eines Ad-hoc-Tasks je Nutzer. Dazu der lokal aufgetretene PHPUnit-Fehler.

### Changed — Task-Fan-out (C1–C3)

- **`book_due_completion_task` ist ersetzt durch `book_due_completion_batch_task`.**
  Die `customdata` lautet `{courseid, criteriaid, duebucket, lastuserid}`. Ein
  Datums-Kriterium mit 50.000 Teilnehmenden erzeugt jetzt **einen** Task statt 50.000.
- **Fälligkeitsfenster statt Jitter.** `due_scheduler::BATCH_WINDOW` (900 s) rundet jede
  Fälligkeit **auf**. Alle Nutzer eines Fensters teilen sich einen Task. Der Jitter
  `userid % 900` entfällt, ohne dass sich die maximale Verzögerung ändert: sie war
  vorher 0–899 s und ist es weiterhin. Aufrunden statt abrunden garantiert, dass ein
  Task nie vor seiner Fälligkeit läuft.
- **`completion_booker::book_criterion()`** (neu) wertet genau das fällige Kriterium aus
  und aggregiert anschließend über `aggregate_completions()`. Der Batch baut `$course`
  und `completion_info` **einmal** für die ganze Seite; `book()` pro Nutzer hätte den
  Kriterienbestand und den Modul-Cache je Nutzer neu aufgebaut. Beide Einstiege teilen
  sich `mark_criterion()` und `aggregate()`.
- **Discovery kostet für Datums-Kriterien keine Nutzer-Scans mehr.** Ein
  `record_exists_sql()` beantwortet, ob überhaupt noch jemand das Kriterium schuldet;
  danach wird ein Task geplant. Dauer-Kriterien werden weiterhin per Keyset über die
  Nutzer geblättert, weil ihre Fälligkeit nutzerabhängig ist — die Buckets werden dabei
  im Lauf dedupliziert.
- **Fortsetzungs-Tasks.** Ein Batch verbucht `batchsize` Nutzer (Default 500) und reiht
  bei voller Seite einen Nachfolger mit `lastuserid` ein. Der Task ist idempotent: bei
  einem Retry verbucht er nur, was noch keinen `crit_compl`-Datensatz hat.
- **Kein `set_userid()` mehr auf dem Fälligkeitspfad.** Der Batch-Task ist kursweit. Der
  in 0.4.8 nötige Index-Trick über die `userid`-Spalte entfällt, weil es jetzt so wenige
  Zeitkriterien-Tasks gibt, dass der nicht indexierte `customdata`-Vergleich nichts
  kostet. Der ASAP-Task des Observers (`book_completion_task`) behält `set_userid()`.
- Der `JOIN {user}` gegen gesperrte und gelöschte Konten entfällt: er diente allein
  `require_active_user()`, das nur bei gesetzter `userid` greift. Gesperrte Nutzer
  werden damit wieder wie im Core behandelt.

### Added

- Einstellung `batchsize` (Default 500).
- `db/upgrade.php` verwirft anstehende `book_due_completion_task`-Zeilen. Ohne das
  scheiterte der Cron beim Instanziieren der entfernten Klasse.

### Fixed — PHPUnit

- **`test_book_returns_false_when_completion_disabled` schlug lokal fehl.**
  `role_assign()` — seit 0.4.7 Teil von `enrol_user_direct()` — feuert `role_assigned`;
  `local_adele`s Observer ruft dort `require_phpunit_isolation()` und damit `debugging()`.
  Der Test war der einzige, der danach kein `resetDebugging()` mehr aufrief. Der Guard
  steht jetzt im Trait, direkt hinter `role_assign()`, statt in jedem Testfall.

### Added — Tests

- Ein Datums-Kriterium plant genau einen Task, unabhängig von der Kohortengröße.
- Ein bereits verbuchtes Kriterium wird nicht erneut geplant.
- Zwei Nutzer desselben Fensters teilen sich einen Task.
- Der Batch verbucht alle fälligen Nutzer; bei voller Seite entsteht ein
  Fortsetzungs-Task mit `lastuserid > 0`, der den Rest verbucht.
- Die Fortschrittsgarantie aus 0.4.7 ist auf Dauer-Kriterien umgestellt, weil ein
  Datums-Kriterium nun nur noch eine geprüfte Zeile kostet.

### Offen (Phase D–E)

- Locks um `book()`, Fehlerzähler, Einstellungsvalidierung, `get_by_name_bulk()`,
  begrenztes Fan-out abhängiger Kurse.
- README-Abschnitt *Compatibility Logic*, `composer.json`, Lasttests.

## [0.4.8] - 2026-07-09

Phase B des externen Reviews: Queue-Korrektheit ausschliesslich über Core-APIs, ohne
eigene Planungstabelle und damit ohne zusätzliche Privacy-Behandlung.

### Fixed — Unterstützte API für Zukunfts-Tasks (B1)

- `queue_adhoc_task($task, $checkforexisting = true)` ist im Core-Docblock ausdrücklich
  auf ASAP-Tasks beschränkt. Die Fälligkeits-Tasks nutzen jetzt
  **`reschedule_or_queue_adhoc_task()`**.
- **`duetime` ist aus der `customdata` verschwunden**, an seine Stelle tritt `criteriaid`.
  Der Dedup-Schlüssel des Cores ist `classname + component + customdata + userid`;
  `nextruntime` gehört nicht dazu. Verschiebt sich ein Einschreibebeginn, aktualisiert
  `reschedule_or_queue_adhoc_task()` die Laufzeit des vorhandenen Tasks, statt einen
  zweiten mit neuer Fälligkeit daneben zu legen. Der in 0.4.3 bewusst offengelassene
  Stale-Task ist damit strukturell ausgeschlossen.
- `criteriaid` hält zugleich zwei Zeitkriterien desselben Kurses auseinander, die für
  denselben Nutzer zu verschiedenen Zeitpunkten fällig werden.

### Removed — Direkter `{task_adhoc}`-Zugriff (B2)

- **`load_pending_tasks()` ist ersatzlos entfallen**, samt `MAX_PENDING_PREFETCH`,
  `$pending` und `$prefetchcomplete`. Der Vorabruf war unter Retry-Backoff falsch: ein
  fehlgeschlagener Task bekommt vom Core ein verschobenes `nextruntime`, fiel aus dem
  Horizont-Fenster des Hashsets und wurde dupliziert. Das Plugin liest die Tabelle jetzt
  nirgends mehr — nur `db/upgrade.php` räumt einmalig auf.

### Fixed — Kosten des Dedup-Vergleichs

- **`set_userid()` ist zurück.** `{task_adhoc}` besitzt keinen Index auf `customdata`,
  wohl aber einen auf `userid` (Foreign Key `useriduser`). Ohne gesetzte `userid` wäre
  `get_queued_adhoc_task_record()` ein Full Scan **pro Enqueue**; bei 5.000 geprüften
  Zeilen je Discovery-Lauf ist das untragbar. Genau dafür hängt der Core die
  `userid`-Bedingung an. Gilt auch für den ASAP-Task des Observers.
- Die Kehrseite, wegen der `set_userid()` in 0.4.0 entfiel: `queue_adhoc_task()` ruft
  `\core_user::require_active_user($user, true, true)` und wirft bei gelöschten,
  gesperrten oder `nologin`-Konten. Abgesichert auf drei Ebenen:
  `JOIN {user} u ON u.deleted = 0 AND u.suspended = 0` in beiden Discovery-Abfragen,
  `due_scheduler::user_can_own_a_task()` im Observer-Pfad, und ein `try`/`catch` in
  `due_scheduler::queue()`, das einen nicht planbaren Nutzer überspringt statt den Lauf
  abzubrechen.
- Der Task läuft damit wieder unter der Identität der lernenden Person. Das Ereignis
  `course_completed` trägt jetzt dieselbe ID in `userid` wie in `relateduserid`, statt
  der des Cron-Administrators.

### Added — Serialisierung der beiden Planer (B3)

- **`due_scheduler::course_lock()`** über `\core\lock\lock_config`, Schlüssel
  `course_<id>`. `reschedule_or_queue_adhoc_task()` liest und schreibt nicht atomar;
  Discovery-Task und Einschreibungs-Observer planen denselben Kurs und werden nun
  getrennt statt einem Rennen überlassen.
  - Discovery: `get_lock(0)`. Ist der Kurs belegt, bricht der Lauf ab, **ohne den Cursor
    fortzuschreiben** — der nächste Lauf nimmt ihn wieder auf.
  - Observer: `get_lock(2)`. Bekommt er ihn nicht, plant er nicht; die Discovery holt
    den Nutzer innerhalb ihres stündlichen Intervalls nach.
- Der Lock trennt Prozesse, nicht Aufrufstellen: sowohl das PostgreSQL-Advisory-Lock als
  auch MySQLs `GET_LOCK` sind innerhalb einer Datenbanksitzung wiedereintrittsfähig.
  Cron und Webrequest teilen sich keine — dort liegt das Rennen. Aus demselben Grund
  gibt es dafür keinen Unit-Test; ein solcher wäre grün, ohne etwas zu prüfen.

### Changed

- `mtrace()` des Discovery-Tasks meldet `planned` statt `queued`.
  `reschedule_or_queue_adhoc_task()` gibt `void` zurück, und `task_is_scheduled()` ist
  `protected` — ob ein Task neu war oder verschoben wurde, ist über Core-APIs nicht
  feststellbar. Der Fortschritt hängt seit 0.4.7 ohnehin an `scanned`.
- `db/upgrade.php` verwirft die anstehenden `book_due_completion_task`-Zeilen der alten
  `customdata`-Form. Ohne das liefen sie neben ihren Nachfolgern her. Die Discovery
  plant sie binnen eines stündlichen Laufs neu.

### Added — Tests

- `due_scheduler`: verschobener Einschreibebeginn verschiebt den vorhandenen Task,
  statt einen zweiten anzulegen; gesperrtes Konto wird nicht geplant; Lock-Round-Trip.
- `discover_due_criteria_task`: dasselbe Reschedule-Verhalten über den Task-Pfad;
  gesperrtes Konto wird nicht geplant; Fälligkeit wird in `nextruntime` geprüft,
  nicht mehr in der `customdata`.

### Offen (Phase C–E)

- Batch-Task statt eines Ad-hoc-Tasks je Nutzer.
- Locks um `book()`, Fehlerzähler, Einstellungsvalidierung, `get_by_name_bulk()`,
  begrenztes Fan-out abhängiger Kurse.
- README-Abschnitt *Compatibility Logic*, `composer.json`.

## [0.4.7] - 2026-07-09

Phase A des externen Reviews: Fortschrittsgarantie für beide Scheduled Tasks und
Beschränkung auf getrackte Nutzer. Die drei P0-Befunde sind damit behoben.

### Fixed — Fortschrittsgarantie (P0)

- **`discover_due_criteria_task` übersprang den Nutzer-Tail eines Kurses.** Der Cursor
  enthielt nur eine Kurs-ID, und der Fortschritt wurde an *eingereihten Tasks* gemessen.
  Im zweiten Lauf über eine bereits geplante Seite lieferte `$made = 0`, der Kurs galt
  als fertig, der Cursor rückte vor — die Nutzer dahinter wurden nie geplant.
  Neu: zusammengesetzter Keyset-Cursor `{courseid, criteriaid, lastuserid}` mit
  `AND enrolled.id > :fromuserid ORDER BY enrolled.id ASC`. Das Budget zählt jetzt
  **geprüfte Datensätze**; ein bereits geplanter Nutzer verbraucht Budget und rückt den
  Cursor vor. `scanned` und `queued` werden getrennt geführt und protokolliert.
- **`reconcile_task` konnte sich vollständig festfahren.** Der Task misst `$processed`,
  brach bei `$processed >= $limit` ab und schrieb den Cursor nicht fort. Ein Kurs mit
  mindestens `budget` dauerhaft unvollständigen Nutzern — der Normalfall für ein
  Sicherheitsnetz — friert damit jeden Lauf an derselben Stelle ein: weder die Nutzer
  dahinter noch **irgendein nachfolgender Kurs der Instanz** wurden je erreicht.
  Neu: Keyset-Cursor `{courseid, lastuserid}`, Fortschritt je geprüftem Nutzer,
  unabhängig vom Buchungsergebnis. Fehlerzähler `failed` im `mtrace()`, das jetzt auch
  bei abgeschalteter Protokollierung erscheint, sobald ein Fehler auftrat.

### Fixed — Getrackte Nutzer (P0)

- **Nur Nutzer mit `moodle/course:isincompletionreports` werden verarbeitet.**
  `completion_info::is_tracked_user()` und `get_tracked_users()` gaten auf diese
  Capability; wir übergaben `''` an `get_enrolled_sql()`. Eingeschriebene Lehrende
  konnten dadurch Fälligkeits-Tasks und einen `course_completions`-Datensatz erhalten.
  Neu: `due_scheduler::TRACKED_CAPABILITY` in beiden Tasks und in
  `due_scheduler::schedule_user()`, sowie `$info->is_tracked_user()` als Guard in
  `completion_booker::book()` — hinter der Prüfung „bereits abgeschlossen", damit ein
  bestehender Abschluss unabhängig von der heutigen Rolle gemeldet wird.
  Anmerkung: Moodles eigener Kriterien-Cron ist hier lockerer
  (`completion_criteria_duration::cron()` liest `{user_enrolments}` ohne
  Capability-Filter). Wir folgen bewusst der Semantik der Abschlussberichte.

### Added

- Einstellung `reconcilebudget` (Default 5000): Obergrenze der je Abgleichslauf
  geprüften Nutzer. Bisher eine Klassenkonstante und damit weder anpassbar noch testbar.
- `db/upgrade.php`: verwirft die alten ganzzahligen Cursor-Werte. `get_cursor()` würde
  sie ohnehin verwerfen, aber ein stehengelassener Fremdwert ist ein Arbeitsrückstand.

### Changed

- `setting:maxtasksperrun` beschreibt jetzt geprüfte Einschreibungs-Datensätze statt
  eingereihter Tasks — die Semantik hat sich mit der Fortschrittsgarantie geändert.
- `tests/fixtures/due_criteria_test_trait.php` → `tests/fixtures/completion_test_trait.php`.
  Der Trait deckt jetzt alle vier Completion-Testklassen ab und weist bei
  `enrol_user_direct()` eine Rolle zu; ohne Rolle ist niemand getrackt.
- `complete_activity()` nutzt `update_state(..., $isbulkupdate = true)`. Ohne das
  aggregiert Moodle den Kurs sofort selbst, sobald der Nutzer eine Rollenzuweisung hat
  (`mark_course_completions_activity_criteria()` joint `{role_assignments}`) — die Tests
  hätten dann den Core gemessen, nicht das Plugin.

### Added — Regressionstests (P0)

- `discover_due_criteria_task`: fünf Nutzer, Budget 2, drei Läufe — jeder Nutzer genau
  einmal geplant; ein budgetfüllender Kurs blockiert den nächsten Kurs nicht;
  Lehrende werden nicht geplant.
- `reconcile_task`: vier Nutzer, Budget 2, davon drei die nie abschließen — der vierte
  wird im zweiten Lauf erreicht; ein budgetfüllender Kurs blockiert den nächsten nicht;
  Lehrende werden nicht gebucht; Cursor-Reset nach vollständigem Durchlauf.
- `completion_booker`: Lehrende und Eingeschriebene ohne Rolle werden nicht gebucht.
- `due_scheduler`: Lehrende werden nicht geplant.

### Offen (Phase B–E des Reviews)

- Zukunfts-Tasks über `reschedule_or_queue_adhoc_task()` oder eine eigene
  Planungstabelle deduplizieren; der direkte `{task_adhoc}`-Prefetch ist unter
  Retry-Backoff nachweislich falsch.
- Batch-Task statt eines Ad-hoc-Tasks je Nutzer.
- Locks, Fehlerzähler, Einstellungsvalidierung, `get_by_name_bulk()`,
  begrenztes Fan-out abhängiger Kurse.
- README: „It adds no completion logic of its own" durch einen Abschnitt
  *Compatibility Logic* ersetzen. `composer.json` ergänzen.

## [0.4.6] - 2026-07-09

Ereignisgesteuerte Sofortplanung (ursprünglich für 1.1 vorgesehen), CI-Fix für die
Behat-JavaScript-Szenarien, phpcs-Warnung in `db/upgrade.php`.

### Added

- **`due_scheduler`** (neu): gemeinsame Planungslogik für zeitbasierte Kriterien.
  `discover_due_criteria_task` und die Observer nutzen jetzt dieselbe Klasse.
- **Observer auf `user_enrolment_created` und `user_enrolment_updated`.** Eine neue
  Einschreibung legt den Fälligkeitszeitpunkt eines Dauer-Kriteriums fest, ein
  geändertes Startdatum verschiebt ihn. Beides wird sofort geplant, statt bis zum
  nächsten stündlichen Discovery-Lauf zu warten. Der Discovery-Task wird damit zum
  Recovery-Mechanismus für verpasste Events, gelöschte Tasks und Restores.
- `due_scheduler::schedule_user()` prüft vorab über den gecachten Kriterien-Index, ob
  der Kurs überhaupt ein Datums- oder Dauer-Kriterium besitzt, und danach Scope,
  aktive Einschreibung (`is_enrolled(..., $onlyactive = true)`), bereits erfolgten
  Kursabschluss und bereits verbuchte Kriteriums-Datensätze.

### Changed — DRY

- `JITTER_WINDOW`, `horizon_seconds()`, `max_tasks_per_run()`, `time_criteria()` und
  `queue()` sind aus `discover_due_criteria_task` nach `due_scheduler` gewandert.
  Der Task hält nur noch das, was ihn vom Observer unterscheidet: Cursor, Budget,
  Vorab-Hashset.
- `completion_booker::duration_due_time()` nutzt `due_scheduler::time_enrolled()`
  statt einer eigenen Kopie derselben Abfrage.

### Fixed — CI

- **Behat-`@javascript`-Szenarien schlugen fehl** (`net::ERR_CONNECTION_REFUSED`).
  Ursache: `php -S localhost:8000` bindet nur an `127.0.0.1` des Runners, während
  Selenium als GitHub-Actions-Service in einem eigenen Bridge-Netz lief — dort ist
  `localhost` der Container selbst. Die fünf Nicht-JavaScript-Szenarien laufen im
  PHP-internen BrowserKit-Treiber und berührten Chrome nie, daher blieb der Defekt
  bis zur Einführung der ersten `@javascript`-Szenarien in 0.4.4 unentdeckt.
  Behoben in `moodle-ci.yml` und `moodle-release.yml`: Selenium wird nicht mehr als
  Service, sondern per `docker run --network host` gestartet, mit Vorab-Pull und
  Health-Wait. Der Webserver bindet an `0.0.0.0:8000`, und der Start wird mit einer
  echten HTTP-Abfrage abgewartet statt mit `sleep 2`.

### Fixed — Coding standard

- `db/upgrade.php`: `defined('MOODLE_INTERNAL') || die();` entfernt. Die Datei enthält
  ausschliesslich eine Funktionsdefinition, also keine Side Effects; `moodle-phpcs`
  meldet den Guard dort korrekt als überflüssig.

### Added — Tests

- `due_scheduler`: Datums- und Dauer-Kriterium, beide zusammen, Horizont, Setting,
  Kurs ohne Zeitkriterien, fehlende und abgelaufene Einschreibung, Wirkungsbereich,
  bereits abgeschlossener Kurs, bereits verbuchtes Kriterium, Idempotenz,
  `time_enrolled()` mit und ohne `timestart`.
- **`tests/fixtures/due_criteria_test_trait.php`** (neu): gemeinsame Fixtures für die
  beiden Tests rund um zeitbasierte Kriterien.

### Fixed (kein Versions-Increment, iterativ in dieser Session)

- **PHPUnit-Fehler `Only variables should be passed by reference`.**
  `reset($this->queued_tasks())` reicht einen Rückgabewert an einen
  Referenzparameter. Ersetzt durch `single_queued_task()`, das die Liste in eine
  Variable holt und dabei gleich die Anzahl prüft.
- **phpcpd: 63 duplizierte Zeilen** zwischen `discover_due_criteria_task_test` und
  `due_scheduler_test`. Die gemeinsamen Fixtures (`queued_tasks()`,
  `enrol_user_direct()`, `add_date_criterion()`, `add_duration_criterion()`) sind in
  den neuen Trait gewandert, ebenso drei weitere Blöcke, die beim Nachmessen auffielen:
  `mark_course_completed()`, `mark_criterion_completed()` und
  `restrict_scope_to_new_category()`. Längster verbleibender identischer Block: sechs
  Zeilen mit 36 Tokens, deutlich unter der Schwelle von 70.
- **phpcs-Warnung** in `due_scheduler.php`: Inline-Kommentar begann kleingeschrieben
  mit dem Funktionsnamen `queue_adhoc_task()`. Umformuliert.

### Offen

- Freigabe für `MATURITY_STABLE` / 1.0.0 steht aus.

## [0.4.5] - 2026-07-09

Entfernt den Synchron-Modus und schliesst die letzte Testlücke im `adele`-Pfad.
Maturity bleibt `BETA` bis zur Freigabe für 1.0.

### Removed

- **Synchroner Verarbeitungsmodus.** Der Observer reiht ausnahmslos einen Ad-hoc-Task
  ein. Der Modus zog eine vollständige Completion-Auswertung samt `mark_complete()`,
  `course_completed`-Dispatch und `message_send()` in den Webrequest einer lernenden
  Person. Seit der Observer-Verengung in 0.4.1 feuert der Auslöser ohnehin deutlich
  seltener, und der Ad-hoc-Task läuft beim nächsten Cron-Tick — der Latenzvorteil trug
  die zusätzliche Einstellung, den Testpfad und das Lastrisiko nicht mehr.
- Einstellung `processingmode` samt der Sprachstrings `setting:processingmode`,
  `setting:processingmode_desc`, `processingmode:async` und `processingmode:sync`.
- `observer_test::test_sync_mode_does_not_queue()`.

### Added

- **`db/upgrade.php`**: entfernt beim Upgrade den verwaisten Konfigurationswert
  `processingmode` per `unset_config()`.
- **`scope_resolver_test::test_scope_adele_uses_adele_configuration()`**: prüft, dass
  im `adele`-Modus tatsächlich `catfilter`, `includetags` und `excludetags` aus
  `local_adele` gelesen werden und nicht die eigenen Einstellungen. Übersprungen, wenn
  `local_adele` fehlt.
- **`scope_resolver_test::test_scope_adele_mode_is_reported()`**: läuft in jeder
  Umgebung.

### Changed

- **Der `adele`-Skip ist jetzt symmetrisch.** Bisher war nur der Pfad „Plugin fehlt"
  abgedeckt; er wird lokal übersprungen (dort ist `local_adele` installiert), während
  der Delegationspfad in der CI nie laufen konnte, weil dort keine Fremdplugins
  installiert werden. Beide Pfade sind nun je einmal abgedeckt, und jede Umgebung
  überspringt genau einen Test — mit einer Begründung im Skip-Text.
- README: Einstellungstabelle und Architekturbeschreibung ohne Synchron-Modus.
- Behat: Assertion auf „Processing mode" entfernt.

### Offen

- Freigabe für `MATURITY_STABLE` / 1.0.0 steht aus.
- Ereignisgesteuerte Sofortplanung bei Einschreibung oder Kriterienänderung; die
  Discovery wäre danach reiner Recovery-Mechanismus.

## [0.4.4] - 2026-07-09

Release-Vorbereitung. Der Audit-Stack aus 0.4.0–0.4.3 ist abgearbeitet, lokal und in
der CI grün auf Moodle 4.5–5.2 × MariaDB/PostgreSQL.

### Changed

- **Maturity: `MATURITY_ALPHA` → `MATURITY_BETA`.**
- **README vollständig neu.** Beschreibt jetzt, was das Plugin tatsächlich tut: die
  zweistufige Core-Pipeline (`crit_compl` → `aggregate_completions()`), die Tabelle,
  welcher Kriterientyp von wem behandelt wird, das Discovery-/Ad-hoc-Modell mit
  Horizont und Jitter, sowie die beiden Test-Fallstricke (`preventResetByRollback()`
  unter pgsql, Einschreibung per direktem Insert). Die alte Behauptung, das Plugin
  delegiere die Auswertung vollständig an die Core-API, war seit 0.2.2 unzutreffend
  und ist seit 0.4.0 wieder wahr.
- **`discover_due_criteria_task` refaktoriert.** `$pending` und `$prefetchcomplete`
  sind Lauf-Zustand des Tasks und jetzt Instanz-Properties statt Parameter, die durch
  vier Signaturen gereicht wurden. Damit passt jeder Aufruf wieder in eine Zeile.
  Behebt 16 phpcs-Verstösse (`PEAR.Functions.FunctionCallSignature`) und reduziert die
  Parameterzahl der Scheduler-Methoden von sieben auf fünf.

### Added — Behat

- Wirkungsbereich-Auswahl enthält die dokumentierten Modi, aber ohne `adele`, solange
  `local_adele` fehlt.
- Speichern eines Kategorien-Scopes hält die Auswahl.
- Report warnt bei deaktivierter Protokollierung und meldet ein leeres Ergebnis, sobald
  sie aktiv ist.
- `@javascript`: `hide_if` blendet Kategorie- und Tag-Felder ausserhalb des
  Kategorien-Scopes aus, sowie Horizont und Budget bei abgeschalteter Planung.

### Offen

- **Entfernung des Synchron-Modus.** Die einzige verbliebene Einstellung, die
  Completion-Prüfungen in den Webrequest zieht. Vor einem 1.0.0 zu entscheiden.
- Ereignisgesteuerte Sofortplanung bei Einschreibung oder Kriterienänderung; die
  Discovery wäre danach reiner Recovery-Mechanismus.

## [0.4.3] - 2026-07-09

Zeitbasierte Kriterien werden nicht mehr per Vollscan gesucht, sondern im Voraus
geplant. Der Sicherheitsnetz-Task bleibt als Recovery-Mechanismus dahinter.

### Added

- **`discover_due_criteria_task`** (scheduled, stündlich): ermittelt Datums- und
  Dauer-Kriterien, die innerhalb des Planungshorizonts fällig werden, und reiht je
  `(courseid, userid, duetime)` genau einen Ad-hoc-Task ein.
- **`book_due_completion_task`** (adhoc): verbucht genau ein Kurs-Nutzer-Paar zum
  Fälligkeitszeitpunkt. Prüft den Wirkungsbereich erneut, da sich die Einstellungen
  zwischen Planung und Ausführung geändert haben können.
- Einstellungen `schedulingenabled` (Default an), `schedulinghorizon`
  (Default 7 Tage) und `maxtasksperrun` (Default 5000).

### Design

- **Kein unbegrenzter Abruf.** Je Lauf höchstens 200 Kurse (Cursor über Kurs-IDs in
  `config_plugins`) und höchstens `maxtasksperrun` Tasks. Nutzerlisten laufen über
  `get_recordset_sql()` mit `LIMIT`, nie über `get_fieldset_sql()`.
- **Der Horizont ist tragendes Element, nicht Feinschliff.** Ohne ihn stünden auf
  einer Instanz mit 200.000 Einschreibungen und einem Dauer-Kriterium 200.000 Zeilen
  in `{task_adhoc}`, teils Jahre in der Zukunft. Der Horizont muss länger sein als
  der Abstand zweier Discovery-Läufe.
- **Ein Vorab-SELECT statt eines SELECTs je Enqueue.** `queue_adhoc_task($task, true)`
  ruft intern `task_is_scheduled()` und damit ein `get_record_select()` pro Aufruf —
  bei 5.000 Planungen also 5.000 Abfragen. Die Discovery lädt die anstehenden
  `customdata` einmal vorab in ein Hashset und ruft `queue_adhoc_task($task, false)`.
  Der Vorab-Abruf ist auf 50.000 Zeilen gedeckelt: die Länge der Warteschlange hängt
  von der Fälligkeitsdichte ab, nicht von einer Grösse, die dieser Task kontrolliert.
  Wird die Grenze erreicht, ist das Hashset unvollständig und der Lauf fällt für die
  Deduplizierung auf `queue_adhoc_task($task, true)` zurück — langsamer, aber korrekt.
- **Kanonische `customdata`.** `adhoc_task::set_custom_data()` ist `json_encode()`, und
  `\core\task\manager` vergleicht die Zeichenkette exakt. Die Schlüssel werden daher
  in fester Reihenfolge geschrieben (`courseid`, `duetime`, `userid`); eine andere
  Reihenfolge wäre ein anderer Task.
- **Jitter.** Ein Datums-Kriterium wird für alle Teilnehmer zur selben Sekunde fällig.
  `nextruntime` wird deterministisch über `userid % 900` gestreut, damit ein einzelner
  Cron-Lauf nicht eine ganze Kohorte auf einmal abarbeiten muss. Der Jitter steht
  bewusst **nicht** in der `customdata` und stört die Deduplizierung nicht.
- **Überfällige Kriterien** werden mit `nextruntime = jetzt` geplant, nicht übersprungen.
- **Dauer-Kriterien** folgen der Cron-Regel des Cores: früheste Einschreibung,
  `ue.timestart`, ersatzweise `ue.timecreated`. Die Fälligkeitsgrenze wird als
  `HAVING MIN(...) <= :latest` mit `latest = horizont - enrolperiod` geprüft, statt
  auf dem Aggregat zu rechnen — portabel über MariaDB und PostgreSQL.
- **Kein Reschedule bei geänderter Fälligkeit.** Verschiebt sich `duetime`, entsteht ein
  zweiter Task; der alte findet die Kriterien nicht erfüllt und endet folgenlos.
  Ein Aufräum-DELETE auf `{task_adhoc}` wäre riskant und unterbleibt bewusst.

### Added — Tests

- Horizont (innerhalb, ausserhalb, überfällig), Deduplizierung über zwei Läufe,
  Budget-Deckelung, Wirkungsbereich, abgelaufene Einschreibung, bereits abgeschlossener
  Kurs, bereits erfülltes Kriterium, Dauer über `timestart` und über `timecreated`,
  sowie ein End-to-End-Lauf des geplanten Tasks bis zur verbuchten Completion.

### Offen

- Entfernung des Synchron-Modus.
- Ereignisgesteuerte Sofortplanung bei Einschreibung oder Kriterienänderung; die
  Discovery wäre danach reiner Recovery-Mechanismus.

## [0.4.2] - 2026-07-09

Der Scope-Cache skaliert nicht mehr mit der Kursanzahl. Zusätzlich der Fix für die
unter PostgreSQL fehlschlagenden Observer-Tests.

### Changed — Scope-Cache

- **`scope_resolver::get_scope_course_ids()` entfällt.** Bisher wurde die vollständige
  Menge aller Kurs-IDs im Wirkungsbereich materialisiert und als *ein* Cache-Wert
  abgelegt — mit `staticacceleration` in jedem Request im PHP-Heap, bei jeder
  Invalidierung komplett neu aufgebaut, und bei grossen Instanzen als
  Megabyte-Wert in Redis/Memcached. Die Kardinalität lag an der falschen Achse.
- **Neue Struktur, zwei Caches:**
  - `scopecategoryids` — die ausgewählten Kurszweige inklusive Unterkategorien,
    gekeyt über einen Hash der Auswahl. Gross ist hier die Kategorienzahl, nicht die
    Kursanzahl.
  - `scopecoursemembership` — pro Kurs ein `0`/`1`, lazy befüllt, gekeyt über
    Konfigurations-Hash plus Kurs-ID. Der Wert wird als Integer gespeichert, weil ein
    Cache-Miss ebenfalls `false` liefert und ein gecachtes „nicht im Scope" davon
    unterscheidbar bleiben muss.
- **`is_in_scope()`** liest die Kategorie des Kurses über `get_course()` aus dem
  Core-Kurs-Cache und prüft sie gegen das Kategorien-Set; Tag-Filter werden nur dann
  ausgewertet, wenn sie konfiguriert sind, über `core_tag_tag::get_item_tags_array()`.
- **`reconcile_task::eligible_course_ids()`** filtert jetzt über `is_in_scope()` je Kurs
  statt über die materialisierte Menge.
- **Invalidierung:**
  - `course_created` ist kein Invalidierungs-Event mehr — für einen neuen Kurs kann
    nichts gecacht sein.
  - `course_updated` und `course_deleted` verwerfen nur den Eintrag *dieses* Kurses
    (`observer::invalidate_course_scope()`); ein Kurs kann den Wirkungsbereich durch
    einen Kategorienwechsel verlassen.
  - Kategorie- und Tag-Events verwerfen beide Caches.
  - Eine Änderung der Einstellungen ändert den Konfigurations-Hash und damit den
    Schlüssel; veraltete Antworten sind nicht mehr erreichbar.

### Fixed — PHPUnit unter PostgreSQL

- **`observer_test` schlug unter pgsql fehl, unter MariaDB nicht.**
  `advanced_testcase::runBare()` öffnet ausschliesslich für `postgres` und `mssql` eine
  Test-Transaktion (`// Database must allow rollback of DDL, so no mysql here.`).
  `\core\event\manager::process_buffers()` stellt Observer mit `'internal' => false`
  bei offener Transaktion in `$extbuffer` zurück; der Rollback am Testende verwirft sie.
  Die Observer liefen daher unter pgsql nie. Behoben durch `preventResetByRollback()`
  in `observer_test::setUp()`.
  `test_activity_completion_skipped_when_only_activity_criteria` war unter pgsql
  zuvor **falsch grün** — der Test hätte auch ohne die Verengung bestanden.
  Kein Produktivcode betroffen; `internal => false` bleibt korrekt.

### Added — Tests

- `scope_resolver`: negatives Ergebnis wird gecacht und nicht mit einem Miss
  verwechselt; `purge_course()` frischt nur einen Kurs auf; `purge_cache()` frischt
  alles auf; Konfigurationswechsel wechselt den Schlüssel; Exclude-Tag schlägt
  Include-Tag; unbekannte Kurs-ID wirft nicht.

### Offen (unverändert)

- Entfernung des Synchron-Modus.
- Fälligkeitsbasierte Ad-hoc-Task-Architektur für Datums- und Dauer-Kriterien.

## [0.4.1] - 2026-07-09

Verengt die Observer auf die Fälle, die Moodle Core nicht selbst erledigt, schließt
die Lücke bei Voraussetzungskursen und behebt den Duration-Fehlschlag.

### Added

- **`criteria_index`** (neu): gecachte Abfrage, welche Kriterientypen ein Kurs
  konfiguriert hat. MUC-Application-Cache `coursecriteriatypes`, Schlüssel = Kurs-ID,
  TTL 3600 s, invalidiert durch `course_completion_updated`, `course_deleted` und
  `course_reset_ended`. Ein Restore löst keines dieser Events aus — dafür die TTL.
- **Observer auf `\core\event\course_completed`**: schließt ein Kurs ab, werden alle
  Kurse mit einem Voraussetzungs-Kriterium (`COMPLETION_CRITERIA_TYPE_COURSE`) auf
  diesen Kurs neu bewertet. Core wertet Voraussetzungs-Kriterien ausschließlich im
  Cron aus; das war die grösste verbleibende Latenzquelle.
- Fehlende Sprachstrings `cachedef_scopecourseids` und `cachedef_coursecriteriatypes`.

### Changed

- **`course_module_completion_updated`** wird übersprungen, wenn der Kurs
  ausschliesslich Aktivitäts-Kriterien besitzt. `completion_info::internal_set_data()`
  ruft für Einzelaktionen bereits `mark_course_completions_activity_criteria()` und
  `aggregate_completions()` auf, bevor das Event feuert; der Ad-hoc-Task wäre in
  diesem Fall immer mit `already-complete` oder `criteria-not-met` geendet.
- **`user_graded`** wird übersprungen, wenn der Kurs kein Noten-Kriterium besitzt.
  Bisher erzeugte jede Gradebook-Änderung im Wirkungsbereich einen Ad-hoc-Task,
  auch bei Massen-Neuberechnungen.

### Fixed

- **Duration-Kriterien ohne Einschreibe-Startdatum.**
  `completion_criteria_duration::review()` liest ausschliesslich `ue.timestart` und
  liefert bei `timestart = 0` konstant `false`; `completion_criteria_duration::cron()`
  weicht in diesem Fall auf `ue.timecreated` aus. `completion_booker` bildet jetzt die
  Cron-Regel nach (früheste Einschreibung, `timestart` sonst `timecreated`) und
  verbucht den Kriteriums-Datensatz mit `timeenrolled + enrolperiod` statt `time()`.
  Diese Abweichung ist ein Core-Defekt und sollte zusätzlich an Moodle gemeldet werden.

### Added — Tests

- `criteria_index`: Typ-Ermittlung, `has_non_activity_type`, abhängige Kurse
  (inkl. Ausschluss von Kursen ohne aktivierte Completion), gezieltes Purge.
- `observer`: Aktivitätsabschluss wird bei reinen Aktivitäts-Kriterien nicht
  eingereiht, wohl aber bei gemischten; `course_completed` reiht den abhängigen Kurs
  ein; Sync-Modus reiht nichts ein.
- `completion_booker`: Duration mit `timestart`, Duration mit `timestart = 0`,
  Duration noch nicht abgelaufen, Duration ohne Einschreibung.

### Offen (unverändert)

- Entfernung des Synchron-Modus.
- Umbau des Scope-Caches auf Kategorie-IDs statt Kurs-IDs.
- Fälligkeitsbasierte Ad-hoc-Task-Architektur für Datums- und Dauer-Kriterien
  (der `reconcile_task` bleibt bis dahin die einzige Quelle für diese Typen).

## [0.4.0] - 2026-07-09

Audit-Patch: fachliche Korrektheit der Completion-Buchung, Skalierbarkeit des
Reconcile-Tasks, korrekte Moodle-API-Nutzung. Maturity bewusst auf ALPHA
zurückgesetzt, bis die Änderungen in CI und Pilotinstanz bestätigt sind.

### Fixed — Completion-Korrektheit (P0)

- **`completion_booker` verbucht wieder Kriterien-Datensätze.** Die Annahme aus
  Session 002/003, `course_completion_criteria_completion` existiere in Moodle 4.5
  nicht als Tabelle, war eine Verwechslung von Klassen- und Tabellenname: die Klasse
  `completion_criteria_completion` bildet auf `course_completion_crit_compl` ab.
  Die daraus abgeleitete Eigenbau-Aggregation ist entfernt. Neue Pipeline:
  `get_user_completion()` → `review()` → `aggregate_completions()`.
- **Selbstabschluss- und Rollen-Kriterien wurden nie erkannt.** `review()` erhielt
  ein `completion_completion`- statt eines `completion_criteria_completion`-Objekts;
  `completion_criteria_self::review()` und `..._role::review()` werten
  `$completion->is_complete()` aus und lieferten daher nach dem Guard „Kurs bereits
  abgeschlossen" konstant `false`. Beide Typen werden jetzt korrekt aggregiert,
  aber niemals stellvertretend für die Person gesetzt; `unenrol` ebenso.
- **Abschlusszeitpunkt.** `course_completions.timecompleted` entspricht jetzt dem
  spätesten Kriteriums-Zeitpunkt (`aggregate_completions()`), nicht mehr `time()`.
  Datums-Kriterien werden mit ihrem `timeend` verbucht, wie im Core-Cron.
- **`gradefinal`** wird für Noten-Kriterien wieder geschrieben.
- Inkonsistenz `course_completions.timecompleted` gesetzt bei leerem
  `course_completion_crit_compl` ist damit ausgeschlossen.

### Fixed — Einschreibungen und Skalierbarkeit (P0)

- **`reconcile_task` nutzt `get_enrolled_sql(..., $onlyactive = true)`.** Die bisherige
  Hand-SQL ignorierte `ue.timestart`, `ue.timeend` und den Aktivierungszustand des
  Enrolment-Plugins; abgelaufene und künftige Einschreibungen wurden verbucht.
- **Deckelung:** Cursor über Kurs-IDs in `config_plugins`, max. 200 Kurse und 5.000
  (Kurs, Nutzer)-Paare je Lauf, `get_recordset_sql()` statt `get_fieldset_sql()`,
  keine unbegrenzte `IN`-Klausel mehr (Scope-Filter in PHP statt im SQL).

### Fixed — Moodle-API und Semantik (P1)

- **Tag-Filter** lösen Namen über `core_tag_tag::get_by_name()` in Tag-IDs auf
  (Normalisierung, Tag-Collection des Kurs-Tag-Bereichs) und filtern in SQL auf
  `tag_instance.component = 'core'`. Bisher wurde `tag.rawname` verglichen —
  datenbankabhängig case-sensitiv und ohne `component`-Diskriminator.
- **Kategorie-Auflösung** per präfix-verankertem `path LIKE '<path>/%' OR id IN (…)`
  statt `path LIKE '%/<id>/%'`. Der bisherige Ausdruck traf die ausgewählte Kategorie
  selbst nicht und verhinderte jede Indexnutzung.
- **`SCOPE_ADELE` ohne `local_adele`** ergibt jetzt einen leeren Wirkungsbereich statt
  eines stillen Fallbacks auf *alle Kurse*; die Einstellungsseite warnt.
- **`db/events.php`**: alle Observer mit `'internal' => false` — Schreibzugriffe und
  Task-Einreihung dürfen nicht in der auslösenden Transaktion laufen.
  `tag_updated` und `tag_deleted` als Invalidierungs-Events ergänzt.
- **`settings.php`**: `$ADMIN->fulltree`-Guard, `set_updatedcallback()` direkt am
  Setting-Objekt statt über `$settings->settings->{…}`.
- **`report.php`**: `admin_externalpage_setup()`; der ungedeckelte
  `get_events_select_count()` über `{logstore_standard_log}` entfällt; Kurse und
  Nutzer werden in zwei Sammelabfragen statt zwei Abfragen je Zeile geladen.
- **Privacy**: `null_provider` war unzutreffend. Der Provider deklariert nun den
  Subsystem-Link auf `core_log` (das `completion_booked`-Event) und implementiert die
  Request-Provider als No-ops.
- **Ad-hoc-Task** läuft als Systemtask (kein `set_userid()`); `queue_adhoc_task()`
  prüft für gesetzte Nutzer-IDs `require_active_user()` und wirft bei gesperrten
  Konten. Der Task prüft den Wirkungsbereich vor der Ausführung erneut.
- **Tag-Einstellungen** akzeptieren Zeilenumbrüche zusätzlich zu Kommas.
- **Sync-Modus** schreibt kein `mtrace()` mehr in den Webrequest (`CLI_SCRIPT`-Guard).

### Changed

- `db/caches.php`: `simpledata => true` (die Payload besteht aus Skalaren).
- Kommentare, die Entwurfsentscheidungen protokollieren, durch Verhaltensverträge
  ersetzt (`version.php`, `db/*`, `classes/*`).
- Maturity: `MATURITY_BETA` → `MATURITY_ALPHA`.

### Removed

- `tests/generator/lib.php` — von keinem Test verwendet.
- Toter Code: `if (empty($typesatisfied))` in `completion_booker`,
  `if ($event === null)` in `report.php`, doppelter `filter_definition()`-Aufruf und
  doppelte `completion_completion`-Instanziierung.

### Added — Tests

- `completion_booker`: Kriterien-Datensatz-Konsistenz, `gradefinal`, Datums-Zeitstempel,
  Selbstabschluss (nie stellvertretend, aber aggregiert).
- `reconcile_task`: abgelaufene und künftige Einschreibung, Cursor-Reset.
- `scope_resolver`: ausgewählte Unterkategorie, Groß-/Kleinschreibung bei Tags,
  Zeilenumbruch-Trennung, unbekannter Include-Tag, `adele` ohne Plugin.
- `privacy`: Subsystem-Link auf `core_log`.

### Offen (bewusst nicht in diesem Patch)

- Der Observer auf `course_module_completion_updated` ist für Einzelaktionen
  redundant: `completion_info::internal_set_data()` ruft bereits
  `\core_completion\api::mark_course_completions_activity_criteria()` und
  `aggregate_completions()` auf, bevor das Event feuert. Entfernung ist eine
  Produktentscheidung.
- Entfernung des Synchron-Modus.
- Fälligkeitsbasierte Ad-hoc-Task-Architektur für Datums-/Dauer-Kriterien.
- Umbau des Scope-Caches auf Kategorie-IDs statt Kurs-IDs.

## [0.3.1] - 2026-07-09

### Added
- **Admin-Report** `report.php`: listet beschleunigte Kursabschlüsse aus dem Standard-Logstore.
  Erreichbar unter Site-Administration > Berichte > Beschleunigte Kursabschlüsse.
  Zeigt Zeitpunkt, Kurs und Nutzer; begrenzt auf 100 Einträge, neueste zuerst.
  Hinweis bei deaktivierter Protokollierung und fehlendem SQL-Logstore.
- `completion_booked`-Event wird in `completion_booker::log()` jetzt tatsächlich ausgelöst
  (wenn `enablelogging` aktiv und `outcome = 'booked'`); ermöglicht Logstore-Abfragen im Report.
- `completion_booked`-Event: `objectid` = courseid + `get_objectid_mapping()` ergänzt.
- Report-Eintrag unter `reports` in `settings.php` (`admin_externalpage`).
- **Tests — Grade-Criterion** (2 neue Tests in `completion_booker_test.php`):
  `test_book_returns_true_when_grade_criterion_met` und `..._not_met`.
- **Tests — Date-Criterion** (2 neue Tests):
  `test_book_returns_true_when_date_criterion_met` (Datum 2020) und `..._not_met` (Datum 2099).
- Lang-Strings `report:*` (de + en) alphabetisch eingefügt.

### Changed
- Maturity: `MATURITY_ALPHA` → `MATURITY_BETA`.
- README vollständig aktualisiert: CI-Matrix, Supported-Criterion-Types, Report-Abschnitt,
  korrekter Status (Beta, Phase 1 + 2 vollständig).

### Fixed (kein MINOR-Increment, iterativ in dieser Session)
- **Makefile PHPUnit-Reinit**: Bash-Präzedenzfehler behoben — `if ! cd X && Y` band
  `!` nur an `cd`, wodurch der Reinit-Zweig nie erreicht wurde. Korrigiert zu
  `if ! (cd X && Y)`. Moodle 4.5.12 änderte den Exit-Code für „veraltete Umgebung"
  von 135 auf 141, wodurch der Bug erstmals sichtbar wurde.
- **Grade-Criterion-Tests**: Nach zwei fehlgeschlagenen Ansätzen (direkter
  `grade_grades`-Insert, dann ORM-Insert + `set_field()`) stabile Lösung gefunden:
  `completion_criteria_grade::review()` liest den `finalgrade` des Course-Total-Items,
  der bei jedem (auch implizit ausgelösten) Regrade aus echten Sub-Items neu aggregiert
  wird — direkte Manipulation dieses Feldes wird verworfen. Fix: echtes manuelles
  Grade-Item anlegen, `grade_item::update_final_grade()` (öffentliche API) nutzen,
  anschließend `grade_regrade_final_grades()` explizit aufrufen.
- **Date-Criterion-Tests**: `completion_criteria_date::review()` prüft `$this->timeend`,
  nicht `$this->date` (beide Spalten existieren in der DB, nur `timeend` wird
  ausgewertet). Insert-Feld entsprechend korrigiert.
- **PHPUnit-Init-Pfad (Moodle 5.x)**: `admin/tool/phpunit/cli/init.php` liegt ab
  Moodle 5.x unter `public/`. Init-Schritt prüft jetzt `moodle/public/admin/...`
  zuerst, Root-Pfad als Fallback — analog zum bereits bestehenden Behat-Init-Guard.
- **Moodle 5.2 + PHP 8.5**: Moodle 5.2s `composer.lock` pinnt `ezyang/htmlpurifier`
  v4.18.0 und `openspout` v4.28.5, beide mit PHP-8.4-Obergrenze. PHP 8.5 ist für
  Moodle 5.2 aktuell nicht installierbar — CI-Matrix auf PHP 8.3 + 8.4 (statt 8.4 + 8.5)
  angepasst, identisch zu 5.1. Weicht vom ursprünglichen Stufenplan ab; zu
  revidieren, sobald Moodles Lock-File PHP 8.5 unterstützt.

## [0.3.0] - 2026-07-09

### Changed
- CI-Matrix progressiv: PHP 8.2–8.4 gestaffelt nach Moodle-Branch (4.5→8.2/8.3,
  5.0/5.1→8.3/8.4, 5.2→8.3/8.4); DB-Tiers classic (MariaDB 10.11 + PgSQL 15) für
  4.5/5.0, modern (MariaDB 11.4 + PgSQL 17) für 5.1/5.2; DB-Images via
  Matrix-Variablen in Services.
- Behat: GHA Selenium-Service + PHP-Dev-Server-Start statt `--start-servers`;
  versionsbewusste Web-Root-Erkennung (`moodle/public/` für Moodle 5.x).
- MariaDB-Health-Check: `mysqladmin ping` → `healthcheck.sh --su=mysql --connect
  --innodb_initialized` (kompatibel mit MariaDB 10.11 und 11.4; `mysqladmin ping`
  schlug unter 11.4 fehl).
- Behat-init-Guard: prüft `moodle/public/admin/tool/behat/cli/init.php` zuerst,
  Root-Pfad als Fallback (Moodle 5.x public/-Struktur).
- `moodle-release.yml`: identische Korrekturen, PHP-Versionen für 5.1/5.2 auf 8.3+.

### Fixed
- Behat-Config-Fehler ("requested config file does not exist") auf Moodle 5.1/5.2:
  verursacht durch fehlenden `public/`-Fallback im Behat-init-Guard.

## [0.2.2] - 2026-07-09

### Added
- Phase 2 Schritt 2: `reconcile_task::execute()` vollständig implementiert.

### Fixed (kein MINOR-Increment)
- `completion_booker::book()`: Neufassung auf `$criterion->review($completion, false)`.
- Makefile `phpunit`-Target: `util.php --diag` statt PHPUnit-Output-Scan.
- PHPCS-Variablennamen: Unterstriche entfernt.
- `$plugin->supported = [405, 502]` (Range, 2 Elemente) statt Liste.

## [0.2.1] - 2026-07-09

### Added
- Phase 2 Schritt 1: `completion_booker::book()` mit Kursebenen-Aggregation.
- Integration-Tests: guard-2-Test (already-complete), Phase-2 satisfied/not-met.
- Blueprint v3.0: §0.1, CI-Matrix Kap. 12, Phase-2-Status, supported range.

## [0.2.0] - 2026-07-09

### Changed
- CI-Matrix auf Moodle 4.5 / 5.0 / 5.1 / 5.2 erweitert.
- PHP 8.1 für alle Moodle-5.x-Zweige ausgeschlossen.

## [0.1.1] - 2026-07-08

### Fixed
- Lang-Dateien strikt alphabetisch (16 Warnungen bereinigt).
- Observer-PHPDoc auf konkrete Event-Typen gehoben.
- Unit-Tests ohne Einschreibungs-Events.

## [0.1.0]

### Added
- Initialer Stub: Observer, Scope-Resolver, Completion-Booker (Phase-1), Adhoc-Task,
  reconcile_task-Stub, Settings, Privacy, Lang (de/en), PHPUnit, Behat, CI-Workflows.
