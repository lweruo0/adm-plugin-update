# Update eigene GitHub-Plugins (Admidio 5)

Kleines Admidio-Plugin, das eigene, auf GitHub gehostete Admidio-Plugins direkt aus dem
Admin-Bereich aktualisiert.

## Installation

1. Ordner nach `adm_plugins/update` kopieren.
2. `config_sample.php` nach `config.php` kopieren und `github_owner`, ggf. `github_token`
   sowie die Plugin-Liste anpassen.
3. `adm_plugins/update/update.php` als Administrator aufrufen oder wie unten beschrieben im
   Admidio-Menü verlinken.

### Menüpunkt in Admidio anlegen

1. Als Administrator anmelden und in der Navigation **Administration → Menü** öffnen.
2. Oben rechts auf **Menüpunkt anlegen** klicken.
3. Felder ausfüllen:

   | Feld | Wert |
   |---|---|
   | Name | z. B. `Plugin-Updates` |
   | Beschreibung | optional, z. B. `Eigene GitHub-Plugins aktualisieren` |
   | Übergeordneter Menüpunkt | `Administration` (oder ein eigener Bereich wie `Plugins`) |
   | URL | `/adm_plugins/update/update.php` |
   | Icon | `bi-cloud-download` (beliebiges [Bootstrap Icon](https://icons.getbootstrap.com/)) |
   | Sichtbar für | Rolle `Administrator` |

   Die URL wird relativ zum Admidio-Stammverzeichnis angegeben und beginnt mit `/`. Liegt der
   Plugin-Ordner unter einem anderen Namen, den Pfad entsprechend anpassen.
4. Speichern. Der Menüpunkt erscheint sofort in der Navigation; mit den Pfeilen in der
   Menüübersicht lässt sich seine Position verschieben.

Das Plugin prüft die Administrator-Rechte zusätzlich selbst. Die Einschränkung über
„Sichtbar für" blendet den Eintrag lediglich für andere Rollen aus.

## Ablauf eines Updates

1. Das Repository wird über die GitHub-API als ZIP geladen
   (`/repos/{owner}/{repo}/zipball[/{ref}]`). Ohne `ref` wird der Default-Branch verwendet.
2. Das ZIP wird in ein Temp-Verzeichnis unterhalb von `adm_my_files` entpackt und auf
   Pfadausbrüche geprüft.
3. Die in `preserve_files` gelisteten Dateien (Standard: `config.php`) werden aus dem
   installierten Plugin in den neuen Ordner übernommen.
4. Der bestehende Ordner wird nach `<ordner>-old` verschoben und der neue an seine Stelle
   gesetzt. Schlägt das fehl, wird der alte Ordner zurückgeholt.
5. Nach erfolgreichem Tausch werden Backup und Temp-Verzeichnis gelöscht.

Updates werden ausschließlich per POST mit Admidio-CSRF-Token ausgelöst und sind
Administratoren vorbehalten.

## Voraussetzungen

- Admidio 5, PHP 8.1 oder neuer
- PHP-Erweiterungen `curl` und `zip`
- Schreibrechte des Webservers auf `adm_plugins` und `adm_my_files`
