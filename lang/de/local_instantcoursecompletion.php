<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German language strings for local_instantcoursecompletion.
 *
 * @package    local_instantcoursecompletion
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cachedef_coursecriteriatypes'] = 'Je Kurs konfigurierte Abschlusskriterien-Typen';
$string['cachedef_scopecategoryids'] = 'Ausgewählte Kurszweige inklusive aller Unterkategorien';
$string['cachedef_scopecoursemembership'] = 'Ob ein Kurs im Wirkungsbereich der Observer liegt';
$string['event:completion_booked'] = 'Kursabschluss verbucht (sofort)';
$string['pluginname'] = 'Sofortiger Kursabschluss';
$string['privacy:metadata:log'] = 'Das Plugin „Sofortiger Kursabschluss" löst bei aktivierter Protokollierung ein completion_booked-Ereignis aus, wenn es einen Kursabschluss verbucht. Das Ereignis enthält Kurs, betroffene Person und Zeitpunkt und wird vom Protokollierungs-Subsystem gespeichert.';
$string['processingmode:async'] = 'Asynchron (Ad-hoc-Task, empfohlen)';
$string['processingmode:sync'] = 'Synchron (im Request)';
$string['report:col:course'] = 'Kurs';
$string['report:col:time'] = 'Zeitpunkt';
$string['report:col:user'] = 'Nutzer';
$string['report:loggingdisabled'] = 'Protokollierung ist aktuell deaktiviert. Aktivieren Sie die Einstellung „Protokollierung aktivieren", damit beschleunigte Abschlüsse aufgezeichnet werden.';
$string['report:noevents'] = 'Es wurden noch keine beschleunigten Abschlüsse aufgezeichnet.';
$string['report:nostorewarning'] = 'Kein SQL-kompatibler Logstore verfügbar. Aktivieren Sie den Standard-Logstore, um diesen Bericht zu nutzen.';
$string['report:rowcount'] = 'Es werden die {$a} neuesten Ereignisse angezeigt.';
$string['report:title'] = 'Beschleunigte Kursabschlüsse';
$string['report:unknowncourse'] = '[Gelöschter Kurs {$a}]';
$string['report:unknownuser'] = '[Gelöschter Nutzer {$a}]';
$string['scope:adele'] = 'Einstellungen von local_adele verwenden (Kurszweige/Tags)';
$string['scope:all'] = 'Alle Kurse dieser Instanz';
$string['scope:categories'] = 'Ausgewählte Kurszweige';
$string['setting:categories'] = 'Kurszweige';
$string['setting:categories_desc'] = 'Kurse in diesen Kategorien (und deren Unterkategorien) liegen im Wirkungsbereich. Nur relevant, wenn als Scope „Ausgewählte Kurszweige" gewählt ist.';
$string['setting:enablelogging'] = 'Protokollierung aktivieren';
$string['setting:enablelogging_desc'] = 'Zeichnet bei jeder Verbuchung ein completion_booked-Ereignis auf und schreibt im Cron eine Trace-Zeile. Nützlich für Audits; im Produktivbetrieb bei Bedarf aktivieren.';
$string['setting:excludetags'] = 'Ausgeschlossene Kurs-Tags';
$string['setting:excludetags_desc'] = 'Kurs-Tags, einer pro Zeile oder durch Kommas getrennt. Kurse mit einem dieser Tags werden aus dem Wirkungsbereich ausgeschlossen.';
$string['setting:includetags'] = 'Eingeschlossene Kurs-Tags';
$string['setting:includetags_desc'] = 'Kurs-Tags, einer pro Zeile oder durch Kommas getrennt. Ist die Liste gesetzt, liegen nur Kurse mit mindestens einem dieser Tags im Wirkungsbereich.';
$string['setting:processingmode'] = 'Verarbeitungsmodus';
$string['setting:processingmode_desc'] = 'Asynchron reiht einen deduplizierten Ad-hoc-Task ein (geringste Request-Kosten, empfohlen). Synchron verbucht den Abschluss sofort im Request — nur für kleine Scopes sinnvoll, wenn minimale Latenz wichtiger ist als Request-Kosten.';
$string['setting:reconcile'] = 'Sicherheitsnetz-Task aktivieren';
$string['setting:reconcile_desc'] = 'Prüft periodisch Kurse im Wirkungsbereich auf Abschlüsse, die per Event nicht erkennbar sind (z. B. datums-/dauerbasierte Kriterien). Jeder Lauf verarbeitet einen begrenzten Ausschnitt und setzt dort fort, wo der vorige Lauf endete. Standardmäßig aus.';
$string['setting:scopemode'] = 'Wirkungsbereich der Observer';
$string['setting:scopemode_desc'] = 'Auf welche Kurse die Completion-Observer wirken. „Einstellungen von local_adele verwenden" ist nur verfügbar, wenn local_adele installiert ist.';
$string['task:bookcompletion'] = 'Kursabschluss verbuchen (sofort)';
$string['task:reconcile'] = 'Kursabschlüsse abgleichen (Sicherheitsnetz)';
$string['warning:adelemissing'] = 'Der Wirkungsbereich ist auf „Einstellungen von local_adele verwenden" gesetzt, local_adele ist jedoch nicht installiert. Derzeit liegt kein Kurs im Wirkungsbereich. Bitte wählen Sie einen anderen Wirkungsbereich.';
