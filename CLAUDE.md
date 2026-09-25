# CLAUDE.md

Admidio-5-Plugin „Datenkonsistenz-Prüfungen“ (`adm_plugins/data-checks`). Fachliche Beschreibung,
Bedienung und Aufbau stehen in der @README.md und werden hier nicht wiederholt.

Admidio-Quellen:
https://github.com/Admidio/admidio/tree/v5.0

## Programmierrichtlinien

Grundlage sind die Admidio-Programmierrichtlinien:
https://www.admidio.org/dokuwiki/doku.php?id=en:entwickler:programmierrichtlinien


Die wichtigsten Regeln daraus, die in diesem Repo gelten:

- **Einrückung**: 4 Leerzeichen, keine Tabs (PHP, JS, HTML).
- **Klammern**: Öffnende Klammer in derselben Zeile wie `if`/`for`/`while`/`switch`, Leerzeichen
  davor. Immer Klammern setzen, auch bei einzeiligen Blöcken. `} elseif (...) {` und `} else {`
  in einer Zeile.
- **Benennung**: Klassen `PascalCase`, Funktionen und Variablen `camelCase`, Konstanten
  `GROSS_MIT_UNTERSTRICH`. Globale Admidio-Variablen tragen das Präfix `g` (`$gDb`, `$gCurrentUser`),
  Request-Parameter das Präfix `get`/`post` (`$getCheck`). Keine kryptischen Abkürzungen.
- **Parameter** mit Standardwerten stehen am Ende der Signatur.
- **PHP-Tags**: nur `<?php`, nie `<?`. Reine PHP-Dateien ohne schließendes `?>`.
- **Strings**: einfache Anführungszeichen. Doppelte nur für HTML-Attribute innerhalb von Strings.
  In JavaScript doppelte Anführungszeichen.
- **Vergleiche**: immer `===` / `!==`, nie `==` / `!=`.
- **Kommentare**: `//` für ein bis zwei Zeilen, `/* */` ab drei Zeilen. Klassen, Methoden und
  Dateien bekommen einen Doc-Block (Doxygen/PHPDoc-Stil) mit Zweck und Parametern.
- **Dateien**: Kleinbuchstaben mit Unterstrichen (`neue_pruefung.php`), UTF-8 ohne BOM, LF-Zeilenenden.
  Ausnahme: Klassendateien in `classes/` heißen wie die Klasse (`AbstractCheck.php`), damit der
  Autoloader in `index.php` sie findet.
- **Datenbank**: nur parametrisierte Abfragen über die Admidio-Datenbankklasse (`$gDb`, Platzhalter
  `?` mit Parameter-Array). Nie Werte in SQL-Strings einbauen. Tabellennamen über die
  Konstanten `TBL_*`.
- **Eingaben**: alle GET/POST-Parameter über `admFuncVariableIsValid()` prüfen. POST-Formulare mit
  CSRF-Token (`SecurityUtils::validateCsrfToken()`). Ausgaben mit `htmlspecialchars` escapen.
- **Commits**: kurze Beschreibung der Änderung, zugehörige Issue-Nummer angeben (z. B. `#12`),
  falls vorhanden.

## Abweichungen und Ergänzungen für dieses Repo

- **Sprache**: Kommentare, Doc-Blöcke, README und Anzeigetexte sind auf Deutsch (abweichend von
  der Admidio-Richtlinie, die Englisch vorsieht). Bezeichner im Code bleiben Englisch.
- **PHP 8.4+**: `declare`-freie, moderne Syntax ist erwünscht: `readonly`-Properties,
  Constructor Promotion, benannte Argumente, `static fn`, `str_starts_with` usw.
- **Namespace**: alle Klassen liegen unter `AdmDataChecks\` in `classes/`, eine Klasse pro Datei.
  Fertige Prüfklassen erben von `AbstractCheck`, Infrastrukturklassen sind `final`.
- **Prüfungen** in `checks/` sind reine Konfigurationsdateien: Doc-Block mit fachlicher
  Beschreibung, `use`, dann `return new ...Check(...)` mit benannten Argumenten. Dateien mit
  führendem `_` werden ignoriert.
- **Admidio-API**: Klassen aus dem Admidio-Kern per `use Admidio\...` einbinden, keine Kopien.
  Zugriff auf Rollen, Profilfelder und Mitgliedschaften über die Hilfsmethoden von
  `AbstractCheck`, nicht direkt per SQL in den Prüfungen.
- **Stichtag**: jede Prüfung arbeitet mit dem übergebenen `$referenceDate` (`Y-m-d`), nie mit dem
  heutigen Datum.
- **README pflegen**: neue Prüfklassen, neue Dateien in `checks/` oder neue Hilfsmethoden in
  `AbstractCheck` werden auch in der README (Abschnitte „Eigene Prüfung anlegen“ und „Aufbau“)
  nachgetragen.
- **Nicht ins Repo**: `config.php`, IDE-Ordner (siehe `.gitignore`).

