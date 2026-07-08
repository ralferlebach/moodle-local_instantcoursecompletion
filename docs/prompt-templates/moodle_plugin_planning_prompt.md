# Moodle Plugin — Strukturierter Planungs-Prompt

Dieses Dokument ist ein Gesprächs-Einstieg für die **Planungsphase** eines neuen
Moodle-Plugins. Es deckt alle Entscheidungen ab, die spätere Debugging-Iterationen
vermeiden oder zumindest klar dokumentieren.

Verwendung: Diesen Prompt zu Beginn einer neuen Planungssession einfügen und
die Lücken gemeinsam mit dem KI-Assistenten füllen.

---

## Prompt-Text (zum Einfügen)

```
Ich möchte ein neues Moodle-Plugin entwickeln. Bitte führe mich strukturiert
durch die Planungsphase und stelle alle nötigen Fragen, bevor wir mit der
Implementierung beginnen.

Arbeite die folgenden Abschnitte in dieser Reihenfolge durch.
Für jeden Abschnitt: Stelle Rückfragen, wenn etwas unklar ist.
Dokumentiere alle Entscheidungen sofort im laufenden Blueprint.

---

## 1. Plugin-Identität

Klärungsbedarf:
- Plugin-Typ: block / local / mod / auth / report / filter / ... ?
- Komponentenname (frankenstyle): {typ}_{name} — Moodle-Konvention: nur
  Kleinbuchstaben, keine Bindestriche, Underscores erlaubt.
  Beispiel: `block_catquiz_statistics` → Verzeichnis `blocks/catquiz_statistics/`
- Moodle-Mindestversion (requires): z.B. 2024100700 für Moodle 4.5
- PHP-Mindestversion: 8.1 (readonly, named args) oder 8.2?
- Unterstützte Moodle-Versionen (supported): [405, 405] oder breiter?
- Autor, Copyright-Jahr, Lizenz (i.d.R. GPL v3)
- Maturity: ALPHA / BETA / STABLE
- Git-Repository: privat oder öffentlich? Branch-Name?

Ergebnis: `version.php`, Plugin-Verzeichnisstruktur.

---

## 2. Abhängigkeiten — exakte Versionen

WICHTIG: Immer Tags (nicht Branches) für externe Dependencies angeben,
sobald kompatible Versionen bekannt sind. Branches können jederzeit
breaking changes einführen.

Klärungsbedarf für jede Dependency:
- Komponentenname + GitHub-Repository
- Exakter Tag oder Hash (nicht `main` / `master`!)
- Mindestversion für `$plugin->dependencies`
- Bekannte Inkompatibilitäten zwischen Dependency-Versionen?
  (Beispiel: mod_adaptivequiz und adaptivequizcatmodel_catquiz können
   dieselbe DB-Tabelle doppelt anlegen, wenn falsche Tags kombiniert werden)

Ergebnis: `version.php` dependencies, CI add-plugin-Befehle.

---

## 3. Funktionsumfang — Phasen definieren

Entwickle einen Phasenplan. Phasen strukturieren sowohl die Implementierung
als auch die spätere Test- und Export-Architektur.

Klärungsbedarf:
- Was ist der absolute MVP (Phase 1)? Was MUSS funktionieren?
- Was kommt in Phase 2, 3, ...?
- Gibt es Features, die Datenschutzgrundlagen erfordern (opt-in, DSGVO)?
- Werden Daten gespeichert (eigene Tabellen) oder nur gelesen (fremde Tabellen)?
  → bestimmt Privacy-Provider-Typ: `metadata\provider` vs. `plugin\provider`

Ergebnis: Phasenplan, Feature-Liste, Privacy-Entscheidung.

---

## 4. Datenquellen und DB-Schema

Klärungsbedarf:
- Welche Tabellen werden gelesen? Von welchem Plugin gehören sie?
- Gibt es JSON-Blobs in diesen Tabellen? Wenn ja: welche Felder sind garantiert,
  welche sind optional oder versionsabhängig?
- Welche Join-Pfade sind nötig? Gibt es alternative Pfade?
- Gibt es Einstellungen in anderen Plugins, die das Verhalten steuern
  (z.B. store_debug_info, SE-Validitätsschwellen)?
- Müssen eigene Tabellen angelegt werden? → `db/install.xml` nötig

Ergebnis: Kanonischer Join-Pfad, DTO-Struktur, Repository-Schicht.

---

## 5. Capability-Modell

WICHTIG: Das Capability-Modell am Anfang vollständig durchdenken.
Spätere Änderungen ziehen Umbenennungen in DB, Lang-Dateien, Behat,
Zugriffsklassen und Tests nach sich.

Klärungsbedarf für jede Capability:
- Name: `{plugintype}/{pluginname}:{capability}` (Slashform)
  z.B. `block/my_plugin:viewdetails`
- Kontext: CONTEXT_BLOCK / CONTEXT_COURSE / CONTEXT_SYSTEM
- Cap-Typ: `read` / `write`
- Risiko: RISK_PERSONAL / RISK_SPAM / RISK_XSS / ... ?
- Standard-Archetypen: welche Rollen bekommen sie per Default?
- Duale Capabilities: Muss eine externe Plugin-Capability zusätzlich
  geprüft werden (z.B. `local/catquiz:view_users_feedback`)?
  → Wenn ja: in einem zentralen Guard-Helper kapseln

Ergebnis: `db/access.php`, `classes/access.php` Guard-Methoden.

---

## 6. Oberflächen und Navigation

Klärungsbedarf:
- Welche Einstiegspunkte gibt es? (Block-Widget, Report-Seite, Admin-Seite)
- Wie navigiert der Nutzer dorthin? (Block-Link, Berichte-Tab, Admin-Menü)
- Soll der Link im Moodle-Berichte-Tab erscheinen? → `lib.php`-Callback nötig
  Bedingung: wann soll der Link sichtbar sein?
- Welche Seiten sind rollenabhängig (nur Manager, nur Lehrende)?

Ergebnis: Entry-Points, `lib.php`, Block-Klasse, Routing-Konzept.

---

## 7. Testing-Strategie

WICHTIG: Klare Trennung zwischen PHPUnit und Behat vermeidet spätere
CI-Probleme.

PHPUnit-Faustregel:
- Capability-Logik (has_view, has_viewdetails, ...) → PHPUnit
- Repository-Logik (Datenbankabfragen, JSON-Parsing) → PHPUnit
- Export-Logik (Spalten, Datenformat) → PHPUnit
- Alles, was einen DB-Zugriff macht: `\advanced_testcase`
- Alles ohne DB: `\basic_testcase`

Behat-Faustregel:
- Sichtbarkeit von UI-Elementen → Behat
- Navigation zwischen Seiten → Behat
- KEIN Behat für: Permission-Denial als Browser-Test
  (Moodle's `look_for_exceptions()` wertet `required_capability_exception`
   als Testfehler, auch wenn das Verhalten korrekt ist)
- `@javascript` NUR für echte JavaScript-Interaktionen (AJAX, dynamische UI)
  — nicht für simple Seitennavigation + Textcheck

Klärungsbedarf:
- Welche kritischen Pfade müssen in PHPUnit abgedeckt sein?
- Welche User-Flows in Behat?
- Welche Moodle-Rollen werden in Behat-Hintergründen gebraucht?
- Werden externe Plugin-Capabilities in Behat benötigt?
  → Falls ja: entweder Admin-User oder explizite Capability-Grant im Background

Ergebnis: Test-Dateien, phpunit.xml mit Bootstrap, Feature-Files.

---

## 8. CI-Konfiguration

Klärungsbedarf:
- Welche Moodle-Versionen sollen getestet werden?
- Welche PHP-Versionen?
- Welche Datenbanken (mariadb, pgsql)?
- Job-Struktur: Lint → PHPUnit + Behat → Gate?
  - PHPUnit muss nur nach lint-php warten
  - Behat muss nach lint-php UND lint-js warten
- Welche Branch-Strategie? (dev-branches CI, main release-CI)

WICHTIG für phpunit.xml:
```xml
<phpunit bootstrap="../../lib/phpunit/bootstrap.php">
```
Der Pfad ist relativ zur phpunit.xml-Position. Für Plugins zwei Ebenen tief
(z.B. `blocks/myplugin/`) ist der Pfad immer `../../lib/phpunit/bootstrap.php`.

WICHTIG für CI vs. lokal:
- `moodle-plugin-ci` ignoriert lokale Config (phpcs.xml, makefile-Flags)
- Makefile-`--ignore=tools/` wirkt NUR lokal; im CI werden tools/ gescannt
- Developer-Tools in tools/: `// phpcs:disable moodle.Files.MoodleInternal`
  ermöglicht Standalone-Nutzung ohne MOODLE_INTERNAL-Guard

Ergebnis: `.github/workflows/moodle-ci.yml`, `phpunit.xml`.

---

## 9. Export-Architektur (falls nötig)

Klärungsbedarf:
- Welche Formate? CSV / JSON / Excel / ODS
- Single-Sheet oder Multi-Sheet?
- Für Multi-Sheet: Welche Blätter? Was enthält jedes Blatt?
- Ad-hoc-Export (synchron) oder queued Adhoc-Task (für große Datensätze)?
- Gibt es Datenschutz-Anforderungen beim Export?

Ergebnis: Exporter-Klassen, base_exporter, Factory-Pattern.

---

## 10. Blueprint erstellen

Nach dem Durcharbeiten aller Punkte:
Erstelle ein vollständiges `Lastenheft_Pflichtenheft_Blueprint.md` mit:
- Plugin-Steckbrief
- Abhängigkeiten (mit exakten Versionen)
- Capability-Modell (Tabelle)
- Datenquellen und Join-Pfade
- Auswertungsfunktionen nach Phasen (KEINE internen "Modul a/b/c"-Bezeichnungen —
  stattdessen Funktionsnamen: "Testergebnisse", "Testverlauf" etc.)
- Architektur-Übersicht (Klassenbaum)
- CI-Matrix
- Bekannte Einschränkungen / zurückgestellte Entscheidungen

---

## Bekannte Moodle-Stolperfallen (immer prüfen)

Vor Beginn der Implementierung diese Punkte explizit klären:

### Code-Konventionen
- Membervariablen: **all lowercase**, keine Underscores im Variablennamen
  → `$personabilityafterattempt`, nicht `$personabilityAfterAttempt`
- Lang-Dateien: **alphabetisch sortiert** nach Schlüssel, **keine** Kommentar-Trennzeilen
- Öffnende `{` von Klassen/Funktionen: **keine** Leerzeile danach
- Inline-Kommentare: **Großbuchstabe** am Anfang, **Punkt** am Ende
- `// TODO` ohne MDL-Tracker-Nummer ist ein PHPCS-Fehler → in PHPDoc `@todo` oder
  beschreibenden Text umwandeln
- `defined('MOODLE_INTERNAL') || die()` in Klassendateien ist PHPCS-Warnung
  (single artifact, no side effects) → weglassen

### PHPUnit
- `phpunit.xml` IMMER mit `bootstrap`-Attribut → sonst `advanced_testcase` nicht gefunden
- Reine Unit-Tests (kein DB-Zugriff): `\basic_testcase` statt `\advanced_testcase`
- DB-Tests: `$this->resetAfterTest(true)` in `setUp()`, `tearDown()` für State-Reset
- `field_exists($table, $col)` → besser: `field_exists($table, new \xmldb_field($col))`

### Behat
- `@javascript` nur für echte JS-Interaktionen (nicht für Seitennavigation)
- Capability-Denial nie per Browser-Test — immer in PHPUnit
- `is_catquiz_available()` o.ä. via `\core_plugin_manager` statt `class_exists()`

### Dependency-Management
- Tags statt Branches in CI: `--branch wb-0.9.0-rc2` statt `--branch catmodel_main`
- Inkompatible Tabellen-Definitionen zwischen Abhängigkeiten testen vor erstem CI-Lauf

### ZIP/Deployment
- Zip ohne `-FS`-Flag bauen (`-FS` vergleicht Timestamps und überspringt Änderungen)
- Nach Umbenennung: ALLE Stellen prüfen: component, class, lang, capabilities (Slash-Form!),
  CSS-Klassen, URL-Pfade, DB-blockname-Wert, Behat-Steps
```

---

## Hinweise zur Nutzung

**Zeitaufwand:** Eine vollständige Planungssession dauert 60–120 Minuten.
Das ist gut investierte Zeit: Jede ungeklärte Entscheidung in der Planungsphase
erzeugt typischerweise 3–5 Iterations-Runden beim CI-Debugging.

**Reihenfolge ist wichtig:** Abschnitte 1–3 (Identität, Dependencies, Scope)
müssen abgeschlossen sein, bevor Abschnitt 5 (Capabilities) sinnvoll bearbeitet
werden kann.

**Nicht alles in Session 1:** Phasen 3+ können in späteren Sessions detailliert
werden, sobald Phase 1 implementiert und CI-grün ist.
