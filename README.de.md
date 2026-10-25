<!--
 * MD.Viewer — Dokumentation
 * Version: 2.11.3
-->

# MD.Viewer

<!-- md-i18n:switcher -->
**In anderen Sprachen:** [English](README.md) · Deutsch · [中文](README.zh.md) · [हिन्दी](README.hi.md) · [Español](README.es.md) · [Русский](README.ru.md)

> Ein sicherer, selbst gehosteter PHP-Markdown-Viewer und -Browser, der einfache `.md`-Dateien in ausgearbeitete Dokumentationsseiten verwandelt — mit durchsuchbarem Dateibrowser, automatisch erzeugtem Inhaltsverzeichnis, Überschriftennummerierung, Mermaid-Diagrammen, Glossar-Tooltips, responsiven Leseeinstellungen, Dark Mode, integriertem Selbst-Updater, Datei-Upload, Zwischenablage-Vorschau und serverseitiger Konfiguration. Kein Build-Schritt. Keine Datenbank. Kein Framework.

[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![md.php](https://img.shields.io/badge/md.php-v2.18.0-success)](https://github.com/paulmann/MD.Viewer)
[![updater.php](https://img.shields.io/badge/updater.php-v3.14.0-4f46e5)](https://github.com/paulmann/MD.Viewer)

---

## Überblick

MD.Viewer basiert auf **`md.php`** — einem einzigen PHP-Einstiegspunkt, der eine passende Markdown-Datei rendert oder auf einen rekursiven Markdown-Dateibrowser umschaltet, wenn keine passende Datei existiert. Das Projekt ist auf einfaches Deployment ausgelegt: Dateien auf einen PHP-Host kopieren, die `.md`-Dokumente daneben ablegen und `md.php` im Browser öffnen.

Der Viewer konzentriert sich auf selbst gehostete Dokumentation ohne Toolchain. Er bietet lesbare Typografie, Überschriftennummerierung, automatische Erzeugung des Inhaltsverzeichnisses, Mermaid-Rendering mit automatischer Reparatur und englischen Konsolen-Diagnosen, gemeinsam genutzte Kopiersteuerung für Code und Zitate, Glossar-Tooltips, Dark Mode, mobilfreundliche Bedienelemente und ein Einstellungsfenster, das die Viewer-Präferenzen lokal speichert.

**`updater.php`** ist eine begleitende Engine für Selbstaktualisierung und Wartung. Sie prüft GitHub-Raw-Dateien anhand von ETag und SHA-256, legt vor jedem Ersetzen versionierte Sicherungen an, stellt frühere Versionen aus dem Einstellungsfenster oder direkt per URL wieder her, verwaltet einen optionalen Hardlink `index.php`, verarbeitet Uploads von `.md`-Dateien und unterstützt direkte Aktualisierungen und Rollbacks im Browser.

Das serverseitige Verhalten wird über eine Konfigurationsdatei **`.md.ini`** neben `md.php` gesteuert. Diese Datei fixiert Funktionsflags hart, sodass sie weder im Browser noch im Einstellungsfenster überschrieben werden können.

---

## Schnellstart

```bash
# Clone into your web root or any subdirectory
git clone https://github.com/paulmann/MD.Viewer.git /var/www/html/docs

# Create a Markdown file that matches the script name
echo "# Hello, World\n\nThis is my documentation." > /var/www/html/docs/md.md

# Open in a browser
# https://your-domain.com/docs/md.php
```

Minimale Einrichtung:

- **`md.php`** (mit den Ordnern `js/md/` und `css/md/`) neben **`md.md`** ablegen.
- **`md.php`** im Browser öffnen.
- Das Skript sucht automatisch nach einer Markdown-Datei mit demselben Basisnamen.

Optional:

- **`updater.php`** hinzufügen, um Aktualisierungsprüfung in der Anwendung, das Anwenden von Aktualisierungen, Sicherungen, Wiederherstellung, Datei-Uploads und die Verwaltung des `index.php`-Links zu aktivieren.
- In `.md.ini` **`ALLOW_UPDATE = true`** setzen, um das Aktualisierungssystem zu aktivieren (standardmäßig deaktiviert).

### Bootstrap-Installation mit einer einzigen Datei

Am schnellsten installiert man, indem man **nur `updater.php`** auf den Server lädt und sie dann im Browser mit `?update=true` öffnet. Sie wird:

1. `.md.ini` automatisch mit sicheren Standardwerten anlegen.
2. Alle verwalteten Dateien (`md.php`, `js/md/*`, `css/md/*`, `README.md`, `LICENSE`) von GitHub herunterladen.
3. Alle erforderlichen Unterverzeichnisse (`js/md/`, `css/md/`) mit Rechten `0755` anlegen, falls sie nicht existieren.
4. Sich selbst in die endgültige Liste aufnehmen und eine vollständige Ergebnisseite anzeigen.

**Voraussetzung:** Für den Browser-Modus muss vor dem Ausführen `ALLOW_UPDATE = true` in `.md.ini` gesetzt sein (oder man trägt es nach dem automatischen Anlegen manuell in `.md.ini` ein und lädt die Seite neu). Für die Machine-API ist stattdessen `API_KEY` aus `.md.ini` das Zugangsmerkmal — siehe [Machine-API](#4-machine-api-json-für-externe-clients).

```bash
# Upload only updater.php, then:
curl -o updater.php https://raw.githubusercontent.com/paulmann/MD.Viewer/main/updater.php
# Edit .md.ini (auto-created on first hit) and set ALLOW_UPDATE = true
# Then open: https://your-domain.com/updater.php?update=true
#
# Or drive it over the machine API (no ALLOW_UPDATE needed, key from .md.ini):
curl "https://your-domain.com/updater.php?api_key=<key>&action=conflicts&format=json"
curl -X POST "https://your-domain.com/updater.php?api_key=<key>&action=install&format=json&dry_run=1"
```

---

## Voraussetzungen

- PHP **8.3+**.
- PHP-Erweiterungen: `mbstring` und `curl` (erforderlich für Updater und Zwischenablage).
- Ein Webserver wie Apache, Nginx, Caddy oder der in PHP eingebaute Server.
- Internet-Zugang im Browser für CDN-Ressourcen (Mermaid, Syntax-Hervorhebung, Tailwind), sofern diese nicht durch lokale Kopien ersetzt werden.

---

## Installation

### Git-Klon

```bash
git clone https://github.com/paulmann/MD.Viewer.git
cd MD.Viewer
```

### Manuelle Installation

1. Repository oder Release-Archiv herunterladen.
2. `md.php`, `updater.php`, `js/`, `css/` und optional `README.md` in das Zielverzeichnis kopieren.
3. Die eigenen Markdown-Dateien im selben Verzeichnisbaum ablegen.
4. `md.php` im Browser öffnen. `.md.ini` wird beim ersten Aufruf automatisch angelegt.

### Installation mit einer Datei über den Updater

1. Nur `updater.php` in das Serververzeichnis hochladen.
2. `updater.php` einmal im Browser öffnen — sie legt `.md.ini` mit `ALLOW_UPDATE = false` automatisch an.
3. `.md.ini` auf dem Server bearbeiten: `ALLOW_UPDATE = true` setzen.
4. `https://your-domain.com/updater.php?update=true` öffnen.
5. Der Updater lädt alle Dateien, legt `js/md/` und `css/md/` nach Bedarf an und zeigt eine Ergebnisseite.
6. `md.php` öffnen — der MD.Viewer ist einsatzbereit.
7. Optional: installieren, ohne `ALLOW_UPDATE` anzufassen, indem die Machine-API mit 
   `API_KEY` aus `.md.ini` aufgerufen wird (siehe [Machine-API](#4-machine-api-json-für-externe-clients)).

### Eingebauter PHP-Server

```bash
cd /path/to/MD.Viewer
php -S localhost:8080
# then open http://localhost:8080/md.php
```

### Nginx-Beispiel

```nginx
server {
    listen 80;
    server_name docs.example.com;
    root /var/www/html/docs;
    index index.php md.php;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

---

## Dateiaufbau

```text
MD.Viewer/
├── md.php                     # Main viewer / browser script       v2.18.0
├── updater.php                # Self-updater, backup, upload        v3.14.0
├── .md.ini                    # Server-side config (auto-created)
├── md.md                      # Default Markdown file for md.php
├── uploads.md/                # Created automatically for uploaded/saved files
├── md.backup/                 # Created automatically when updates/restores run
│   ├── 2.8.2/
│   ├── 2.8.2-pre-restore/     # Auto-created before each rollback
│   └── .state/                # ETag + SHA-256 per-file cache
├── css/
│   └── md/
│       ├── md.css             # v2.2.2
│       ├── settings.css       # v2.9.0
│       └── tooltips.css       # v2.4.5
├── js/
│   └── md/
│       ├── md.js              # v2.7.0
│       ├── settings.js        # v2.11.0
│       ├── tooltips.js        # v2.4.5
│       └── upload.js          # v2.9.1
├── LICENSE                    # v1.0.0
└── README.md                  # v2.11.3
```

Zentrale Namensregel: `md.php` sucht `md.md`, `docs.php` sucht `docs.md`. Mehrere unabhängige Viewer-Instanzen können sich einen Verzeichnisbaum teilen, ohne zusätzliches Routing.

---

## Serverseitige Konfiguration — `.md.ini`

Beim ersten Aufruf erzeugt `md.php` (oder `updater.php`) die Datei `.md.ini` im selben Verzeichnis. Diese Datei fixiert Funktionsflags hart, sodass sie **nicht** im Browser oder im Einstellungsfenster geändert werden können. Fehlende Schlüssel werden bei jedem Laden automatisch mit sicheren Standardwerten ergänzt.

```ini
; MD.Viewer server-side configuration

; Disable the "Upload .md" button in the file browser (recommended: true)
DISABLE_UPLOAD    = true

; Disable the "Preview Clipboard" button in the file browser
DISABLE_CLIPBOARD = false

; Disable the "Save to File" button in clipboard preview
DISABLE_SAVE_CLIPBOARD_TO_FILE = true

; Allow updating files via updater.php?update=true or the Settings panel
ALLOW_UPDATE = false

; Allow restoring a backup via updater.php?restore=latest or ?restore=[version]
ALLOW_RESTORE = false

; Allow creating/removing the index.php hard link from the Settings panel
ALLOW_CREATE_INDEX_PHP_LINK = true

; Machine API key used by external clients (a control panel, an installer)
; Generated automatically on first run. Send it as ?api_key=<value>.
API_KEY = mdv_your_generated_key_here
```

| Schlüssel | Standard | Wirkung |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Blendet die Schaltfläche Upload aus und deaktiviert sie |
| `DISABLE_CLIPBOARD` | `false` | Blendet die Schaltfläche für die Zwischenablage-Vorschau aus und deaktiviert sie |
| `API_KEY` | automatisch erzeugt | Geheimer Schlüssel für die Machine-API (`?api_key=`); erforderlich für JSON-Installationen |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Blendet Save to File in der Zwischenablage-Vorschau aus |
| `ALLOW_UPDATE` | `false` | Aktiviert das Aktualisierungssystem (Einstellungsfenster + `?update=true`) |
| `ALLOW_RESTORE` | `false` | Aktiviert den Abschnitt Sicherung & Wiederherstellung und den URL-Modus `?restore=` |
| `ALLOW_CREATE_INDEX_PHP_LINK` | `true` | Aktiviert den Abschnitt Indexdatei im Einstellungsfenster |

**Alle Schlüssel werden automatisch ergänzt** (mit ihren Standardwerten), wenn eine vorhandene `.md.ini` das nächste Mal geladen wird — ein Upgrade erfordert nie manuelle Änderungen an `.md.ini`.

### `.md.ini` pro Verzeichnis (v2.12.0 / v3.12.0)

Die `.md.ini` neben `md.php` behält ihre Rolle als Datei auf Installationsebene
(`BROWSE_DIR`, `API_KEY`, `ALLOW_*` und die Standardwerte für die `DISABLE_*`-Flags).
Darüber hinaus darf **jedes Verzeichnis innerhalb des Browse-Roots eine eigene
`.md.ini` tragen**. Die für ein Dokument geltenden Einstellungen ergeben sich aus der
Basisedatei, über die jede `.md.ini` gelegt wird, die auf dem Weg vom Browse-Root bis zum
Verzeichnis des Dokuments gefunden wird — eine tiefer liegende Datei gewinnt immer gegen
eine höher liegende:

```text
ext/mdviewer/.md.ini     base file: installation-level keys, API key
share/.md.ini            applies to everything below share/
share/sub/.md.ini        applies to share/sub/ and below, and wins
```

In einer Verzeichnisdatei werden nur diese drei Schlüssel berücksichtigt:

| Schlüssel | Standard | Wirkung in diesem Verzeichnis |
|---|---|---|
| `DISABLE_UPLOAD` | `true` | Ob **Upload .md** erlaubt ist und wo der Upload landet |
| `DISABLE_CLIPBOARD` | `false` | Ob die Schaltfläche für die Zwischenablage-Vorschau aktiv ist |
| `DISABLE_SAVE_CLIPBOARD_TO_FILE` | `true` | Ob **Save to File** erlaubt ist und wo die Datei landet |

Regeln:

- Die Schlüssel `BROWSE_DIR`, `API_KEY`, `ALLOW_UPDATE`, `ALLOW_RESTORE` und
  `ALLOW_CREATE_INDEX_PHP_LINK` gelten auf Installationsebene. In einer
  Verzeichnisdatei werden sie ignoriert, und es wird ein Hinweis ins PHP-Error-Log
  geschrieben.
- Werte werden so streng geparst wie in der Basisedatei (getrimmt, optionale paarige
  Anführungszeichen entfernt; ein Wert, der ein Nullbyte, einen Zeilenumbruch oder mehr
  als 255 Bytes enthält, wird verworfen). Eine nicht lesbare Datei oder ein unbrauchbarer
  Wert wird mit einem Eintrag im Error-Log übersprungen — das bricht nie die Seite und
  fällt nie auf einen privilegierten Standardwert zurück.
- Eine Verzeichnis-`.md.ini` wird nur gelesen, wenn ihr realer Pfad innerhalb des
  Browse-Roots liegt; ein Pfad, der mit `..` herauszuklettern versucht, wird abgelehnt.
- Im Viewer-Modus (`?file=sub/doc.md`) gilt das Verzeichnis des Dokuments; im
  Dateibrowser-Modus gilt der Browse-Root.
- **Upload .md** und **Save to File** schreiben in das gerade angezeigte Verzeichnis. Die
  Anfrage überträgt es im Parameter `dir` (relativ zum Browse-Root); ein `dir`-Wert mit
  Traversal-Segmenten, ein absoluter Pfad oder ein Nullbyte wird mit `400` beantwortet,
  und auch auf keinem anderen Weg lässt sich das Verzeichnis verlassen.
- Verzeichnis-`.md.ini`-Dateien sind optional. Ohne sie ist das Verhalten genau wie
  zuvor.

---

## Nutzungsmodi

### Viewer-Modus

Wenn eine passende Markdown-Datei existiert, rendert MD.Viewer sie als gestaltete Dokumentseite. Der Seitentitel wird aus der ersten Überschrift der obersten Ebene abgeleitet, die Beschreibung aus dem ersten Absatz gebildet, ein Inhaltsverzeichnis gerendert und die lokalen Viewer-Präferenzen angewendet.

### Dateibrowser-Modus

Wenn keine passende Markdown-Datei existiert, schaltet MD.Viewer auf einen rekursiven Markdown-Browser um. Der Browser durchsucht den aktuellen Verzeichnisbaum, listet Markdown-Dateien in einer durchsuchbaren Tabelle und öffnet die ausgewählte Datei mit `?file=relative/path.md`.

### Zwischenablage-Vorschau-Modus

Der Dateibrowser enthält eine Schaltfläche **Clipboard Preview**. Beliebiges Markdown in das Textfeld einfügen und auf Preview klicken — der Inhalt wird im Viewer-Modus gerendert. Bei `DISABLE_SAVE_CLIPBOARD_TO_FILE = false` erscheint eine Leiste **Save to File** mit Eingabe für den Dateinamen und Speichern-Schaltfläche, die nach `BROWSE_DIR` schreibt (bzw. nach `uploads.md/` neben `md.php`, wenn dieser Schlüssel nicht gesetzt ist).

### URL-Parameter

| Parameter | Bedeutung | Beispiel |
|---|---|---|
| `?file=path/to/doc.md` | Rendert eine bestimmte Markdown-Datei | `md.php?file=docs/api.md` |

---

## Funktionen

### Markdown-Rendering

- Überschriften, Absätze, Kursivschrift, Fettdruck, Inline-Code, Blockzitate, horizontale Linien, eingezäunte Codeblöcke.
- Die automatische Mermaid-Reparatur wandelt Klassennnotizen im PlantUML-Stil, literale `\n`, nicht geschlossene `<br>`, `return` aus PlantUML und nicht in Anführungszeichen gesetzte Klammern um; die Diagnosen sind ausschließlich englisch.
- Geordnete und ungeordnete Listen, einschließlich verschachtelter Listen.
- Tabellen mit gestalteter Ausgabe.
- Aufgabenlisten, Fußnoten, Referenzlinks.
- Bilder und Standardlinks.
- Unterstützung für Emoji-Shortcodes.
- Universelle Inline-Muster für eine ausgearbeitete Dokumentformatierung.

### Navigation und Struktur

- Automatisch erzeugtes Inhaltsverzeichnis.
- Optionale automatische Überschriftennummerierung.
- Titelaufteilung am Doppelpunkt für sauberere Hero-Titel.
- Durchsuchbarer Dateibrowser-Modus.
- Kopieren und Herunterladen der Quell-`.md` des gelesenen Dokuments und jeder Datei im
  Browser (beide Umschalter standardmäßig an).

### Code, Diagramme und umfangreiche Inhalte

- Syntaktisch hervorgehobene eingezäunte Codeblöcke mit Kopierschaltfläche.
- Mermaid-Diagramme.
- Unterstützung für hoch- und tiefgestellten Text.

### Glossar-Tooltips

Inline-Glossar-Tooltips über `js/md/tooltips.js` und `css/md/tooltips.css`. Begriffe zeigen beim Darüberfahren einen Tooltip; die Engine führt abweichende Schreibweisen auf dieselbe Definition zurück. Enthält Touch-Unterstützung, eine Karenzzeit von 2 Sekunden und eine intelligente, viewport-bewusste Positionierung.

### Einstellungsfenster — Tooltips zu Funktionen

Jeder Umschalter im Abschnitt **Features** des Einstellungsfensters zeigt beim Darüberfahren einen ausführlichen Tooltip, der erklärt, was die Funktion tut und wie sie das Rendering beeinflusst. Die Tooltips verwenden dieselbe Sprechblase `.g-tooltip` und dieselbe Positionierungs-Engine wie die Glossar-Tooltips — gleicher visueller Stil, gleiche Dark-Mode-Unterstützung, gleiches Verhalten für Touch und Tastatur. Zusätzliches CSS ist nicht erforderlich.

### Darstellung und Leseeinstellungen

- Dark Mode.
- Einstellbare Seitenbreite, Schriftgröße und Zeilenhöhe.
- Einstellungen zur Sichtbarkeit der Kopfzeilen-Werkzeugleiste (Desktop und Mobil getrennt).
- Dauerhafte Präferenzen, gespeichert in Browser-Cookies.

### Datei-Upload

Bei `DISABLE_UPLOAD = false` in `.md.ini` zeigt der Dateibrowser eine Schaltfläche **Upload .md**. Hochgeladene Dateien werden nach `BROWSE_DIR` gespeichert oder nach `uploads.md/` neben `md.php`, wenn dieser Schlüssel nicht gesetzt ist. Mit Verzeichniseinstellungen landet die Datei stattdessen in dem gerade angezeigten Verzeichnis, und dessen eigene `.md.ini` entscheidet, ob die Aktion erlaubt ist. Der Upload-Schutz ist dreifach gestaffelt:

1. Die Schaltfläche in der Oberfläche ist ausgeblendet bzw. deaktiviert, wenn `DISABLE_UPLOAD = true`.
2. Der JS-Click-Handler kehrt früh zurück, wenn `MDV_CONFIG.disableUpload` true ist.
3. PHP `updater.php`: liest `.md.ini` unabhängig und liefert `403`, wenn deaktiviert.

### Quell-`.md` kopieren und herunterladen

Drei optionale Steuerelemente geben dem Leser die Datei selbst statt der gerenderten Seite.
Alle drei sind standardmäßig an, alle drei sind Funktionsumschalter auf PHP-Seite (siehe
Einstellungsfenster und die Schlüsseltabelle der `.md.ini`), und alle drei sind
**Schnittstellen-Freigaben, keine Zugriffskontrolle**: Eine Anfrage auf dieselbe Datei
bleibt in beiden Fällen beantwortbar, weil der Viewer keine Authentifizierung hat und nie
hatte.

| Umschalter | Wo er erscheint | Standard |
|---|---|---|
| `FEATURE_DOWNLOAD_ICON` | Ein Download-Symbol in jeder Zeile des Dateibrowsers | an |
| `FEATURE_DOWNLOAD_BUTTON` | Eine Download-Schaltfläche in der Werkzeugleiste des Dokuments, links neben den Steuerelementen für Breite und Schrift | an |
| `FEATURE_COPY_BUTTON` | Eine Kopier-Schaltfläche in der Werkzeugleiste des Dokuments, links neben der Download-Schaltfläche | an |

Alle drei führen auf dieselbe Anfrage — `md.php?file=<relative path>&download=1` — und diese
Anfrage wird von genau derselben Funktion geprüft wie die Viewerseite; ein für die Anzeige
abgelehnter Pfad wird also auch hier abgelehnt. Die Antwort überträgt die Bytes der Datei als
`application/octet-stream` mit `X-Content-Type-Options: nosniff` und
`Content-Disposition: attachment`: Eine `.md` ist Text, und sie als Text unter einem vom
Autor des Dokuments selbst gewählten Dateinamen anzukündigen, ist genau der Weg, auf dem sie
in den Augen des Browsers zum Skript würde. Die Kopier-Schaltfläche ruft diese Anfrage beim
Klick ab, statt das Dokument ein zweites Mal in die Seite einzubetten, und legt das Ergebnis
in die Zwischenablage. Die Zwischenablage-Vorschau hat keine Datei hinter sich und erhält
daher keine der beiden Schaltflächen der Werkzeugleiste.

### Zwischenablage-Vorschau und Save to File
Markdown-Text einfügen und sofort rendern, ohne eine Datei zu speichern. Bei `DISABLE_SAVE_CLIPBOARD_TO_FILE = false` erscheint eine Leiste Save to File mit Eingabe für den Dateinamen, Speichern-Schaltfläche und Statusrückmeldung direkt in der Leiste. Der Quelltext wird beim Öffnen der Vorschau in `sessionStorage` abgelegt. Die Datei wird in das gerade angezeigte Verzeichnis geschrieben, und eine verzeichniseigene `.md.ini` kann das Speichern dort deaktivieren oder wieder erlauben.

### Einstellungsfenster

Das Einstellungsfenster (über das Zahnradsymbol in der Kopfzeile) enthält:

- Umschalter für Viewer-Präferenzen (Design, Breite, Schrift, Zeilenhöhe, Inhaltsverzeichnis, Nummerierung).
- Steuerung der Sichtbarkeit der Kopfzeilen-Werkzeugleiste je Gerätetyp.
- Funktionsumschalter auf PHP-Seite mit erklärenden Tooltips beim Darüberfahren (wirksam beim nächsten Seitenaufruf über Cookies).
- Steuerung für Aktualisierungsprüfung und Anwendung von Aktualisierungen (nur sichtbar, wenn `ALLOW_UPDATE = true`).
- Sicherungsliste und Wiederherstellungssteuerung (nur sichtbar, wenn `ALLOW_RESTORE = true`).
- Verwaltung des Hardlinks `index.php` (nur sichtbar, wenn `ALLOW_CREATE_INDEX_PHP_LINK = true`).

---

## Integrierter Updater

`updater.php` bietet vier Wege, Dateien zu aktualisieren und zu verwalten.

### Startseite des Updaters

Der Aufruf von `updater.php` ohne Parameter zeigt eine **Informationsseite** mit:

- Status vorhanden / fehlend für jede verwaltete Datei samt Versionsnummern.
- Jeder Dateiname verlinkt auf die Raw-Datei auf GitHub (öffnet in einem neuen Tab).
- Status von `.md.ini`: zeigt eine Warnung mit Bearbeitungshinweisen, wenn `ALLOW_UPDATE = false`.
- Aktionsschaltflächen (**Check & Apply Updates**, **↺ Force Reinstall All**, **⟲ Restore Latest Backup**) — erscheinen nur, wenn das entsprechende Flag in `.md.ini` aktiviert ist.

### 1. Einstellungsfenster (AJAX)

- Aktualisierungen über alle verwalteten Dateien prüfen, mit Versionsanzeige pro Datei.
- Aktualisierungen anwenden — sichert die aktuellen Dateien und lädt neue Versionen.
- Alle Dateien unabhängig von der Version neu installieren (umgeht den ETag/SHA-256-Cache).
- Sicherungsversionen ansehen und wiederherstellen (nur sichtbar, wenn `ALLOW_RESTORE = true`).

### 2. Direkte Aktualisierung (`?update=true`)

Erfordert `ALLOW_UPDATE = true` in `.md.ini`.

```
https://your-domain.com/updater.php?update=true
https://your-domain.com/updater.php?update=true&force=true   ← force reinstall
```

**Zweiphasiger Bootstrap:**

- **Phase 1** — aktualisiert zuerst `updater.php` selbst. Hat sich die Datei geändert, wird auf `?_phase=2` umgeleitet (mit erhaltenem `&force=true`, falls gesetzt), damit der **neue** Updater den Rest übernimmt.
- **Phase 2** — aktualisiert alle übrigen verwalteten Dateien mit Anzeige des Versionsunterschieds.

**Flag `&force=true`:**

- Überspringt den ETag- und SHA-256-Cache vollständig — jede Datei wird immer von GitHub geladen.
- Nützlich nach einer fehlgeschlagenen Teilaktualisierung oder wenn lokale Dateien beschädigt sind.
- Wird über die Redirect-URL automatisch von Phase 1 an Phase 2 weitergegeben.
- In der Fußzeile der Ergebnisseite erscheint eine bernsteinfarbene Schaltfläche **↺ Force reinstall all**.

**Automatisches Anlegen von Verzeichnissen:**

Der Updater legt fehlende Unterverzeichnisse (`js/md/`, `css/md/`) mit Rechten `0755` an, bevor er Dateien schreibt. Dadurch funktionieren `?update=true` und die Machine-API auch bei einer Installation aus einer einzigen Datei.

**Ergebnisseite:**

- Status-Badges pro Datei: `updated` / `force-updated` / `created` / `current` / `error`
- Versionsunterschied: `2.7.0 → 2.8.2`
- Jeder Dateiname ist ein klickbarer Link auf die Raw-Datei auf GitHub (öffnet in einem neuen Tab)
- Fußzeile: Schaltfläche **↺ Force reinstall all** + Schaltfläche **← Back**

### 3. Direkter Rollback (`?restore=`)

Erfordert `ALLOW_RESTORE = true` in `.md.ini`.

```
https://your-domain.com/updater.php?restore=latest
https://your-domain.com/updater.php?restore=2.7.0
```

| URL | Verhalten |
|---|---|
| `?restore=latest` | Durchsucht `md.backup/` und wählt automatisch die höchste Semver-Version |
| `?restore=X.Y.Z` | Stellt genau die genannte Sicherungsversion wieder her |

**Ablauf des Rollbacks:**

1. Prüft `ALLOW_RESTORE = true` — zeigt eine Fehlerseite, wenn nicht.
2. `?restore=latest` durchsucht `md.backup/`, sortiert nach Semver und wählt die neueste.
3. Prüft die Versionszeichenkette (nur alphanumerische Zeichen, Punkte, Bindestriche).
4. Sichert die aktuellen Dateien vor dem Überschreiben nach `md.backup/[version]-pre-restore/` — ein Rollback des Rollbacks ist immer möglich.
5. Verwirft den ETag-Cache pro Datei (`.state/`), damit die nächste Aktualisierungsprüfung vollständig lädt.
6. Gibt eine HTML-Ergebnisseite mit den Badges `restored` / `skipped` / `error`, dem Versionsunterschied und klickbaren Dateilinks aus.

### 4. Machine-API (JSON, für externe Clients)

Eine schlüsselgeschützte JSON-API erlaubt es einem anderen Programm (einem Control Panel,
einem Installer), die Installation zu steuern und ihren Fortschritt Schritt für Schritt
darzustellen. Sie liefert nie HTML, und PHP-Notices oder -Warnungen gelangen nie in den
Antwortkörper.

`API_KEY` wird beim ersten Aufruf erzeugt und in `.md.ini` gespeichert. Wer dort vor dem
Deployment einen eigenen Wert setzt, kann den Bootstrap automatisieren, ohne die Datei
später zu lesen.

```
GET  updater.php?api_key=<key>&action=status&format=json
GET  updater.php?api_key=<key>&action=conflicts&format=json
POST updater.php?api_key=<key>&action=install&format=json[&dry_run=1][&force=1]
```

| Aktion | Methode | Zweck |
|---|---|---|
| `status` | GET | Lokaler Installationszustand pro Datei (vorhanden / fehlend / Version). Keine Netzwerk-E/A. |
| `conflicts` | GET | Konfliktbericht als Probelauf. Schreibt nie etwas. |
| `install` | POST | Dateien abrufen und schreiben, über die Konfliktsperre vor dem Schreiben. |

Aufbau der Antwort:

```json
{
  "ok": true,
  "action": "install",
  "version": "2.16.0",
  "steps":     [{"step": "install:css/md/md.css", "status": "ok", "ms": 143, "detail": "2.2.2 → 2.2.2"}],
  "files":     [{"path": "css/md/md.css", "status": "current", "version": "2.2.2"}],
  "conflicts": [{"path": "css/md/settings.css", "status": "same_name_elsewhere",
                 "existing_path": "legacy/components/settings.css", "size_bytes": 1932}],
  "errors":    []
}
```

- `conflicts[].status`: `ok` | `exists` (der genaue Pfad existiert bereits) | `same_name_elsewhere`
- `files[].status`: `present` | `missing` | `updated` | `created` | `force-updated` | `current` | `skipped` | `would_write` | `error`
- `steps[].status`: `ok` | `dry-run` | `skip` | `warn` | `error`
- HTTP-Codes: `200` abgeschlossen (Fehler einzelner Dateien halten `"ok": false` im Body), `400` unbekannte Aktion, `403` `unauthorized` oder `api_key_unavailable`, `405` `install` ohne POST

`dry_run=1` endet nach dem Konfliktbericht: Es wird nichts geladen und nichts geschrieben.
`force=1` ersetzt außerdem eine Datei an einem verwalteten Pfad, die nicht wie eine
MD.Viewer-Datei aussieht (andernfalls wird sie übersprungen und in `conflicts` gemeldet),
und umgeht den ETag/SHA-256-Cache.
Die oben genannten Browser-Modi beachten weiterhin `ALLOW_UPDATE` / `ALLOW_RESTORE`; der
API-Schlüssel ist für die JSON-Aktionen das eigene Zugangsmerkmal.
---

## Verwaltete Dateien

Alle vom Updater verwalteten Dateien (geprüft, heruntergeladen, gesichert):

```
md.php                    v2.18.0
updater.php               v3.14.0
js/md/md.js               v2.7.0
js/md/settings.js         v2.11.0
js/md/tooltips.js         v2.4.5
js/md/upload.js           v2.9.1
css/md/md.css             v2.2.2
css/md/settings.css       v2.9.0
css/md/tooltips.css       v2.4.5
README.md                 v2.11.3
LICENSE                   v1.0.0
```

---

## Sicherungen und Wiederherstellung

Vor jedem Ersetzen einer Datei (Aktualisierung oder erzwungene Neuinstallation) wird die aktuelle lokale Datei nach `md.backup/[version]/` kopiert. Das Einstellungsfenster listet Sicherungsversionen, Daten und Dateianzahlen auf (nur sichtbar, wenn `ALLOW_RESTORE = true`). Eine Wiederherstellung sichert zuerst die aktuelle Version (als `[version]-pre-restore`) und schreibt dann die ausgewählten Dateien zurück. `README.md` und `LICENSE` werden heruntergeladen und aktualisiert, aber **nicht** gesichert (sie sind Dokumentation, kein Code).

---

## Sicherheit

Alle sicherheitsrelevanten Aktionen sind auf **drei unabhängigen Ebenen** geschützt: Flag in der serverseitigen `.md.ini`, PHP-API-Prüfung und Unterdrückung in der Oberfläche auf Clientseite.

| Funktion | Flag in `.md.ini` | PHP-Prüfung | Unterdrückung in der Oberfläche |
|---|---|---|---|
| Aktualisierung (Anwenden/Prüfen) | `ALLOW_UPDATE` | `requireAllowUpdate()` | Ausgeblendet, wenn deaktiviert |
| Sicherung wiederherstellen | `ALLOW_RESTORE` | `requireAllowRestore()` | Ausgeblendet, wenn deaktiviert |
| Sicherungen auflisten | `ALLOW_RESTORE` | `requireAllowRestore()` | Wird nicht aufgerufen, wenn deaktiviert |
| Indexlink anlegen | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | Ausgeblendet, wenn deaktiviert |
| Indexlink entfernen | `ALLOW_CREATE_INDEX_PHP_LINK` | `requireAllowIndexLink()` | Ausgeblendet, wenn deaktiviert |
| Indexstatus | `ALLOW_CREATE_INDEX_PHP_LINK` | Liefert `{disabled:true}` | Wird nicht aufgerufen, wenn deaktiviert |
| Datei-Upload | `DISABLE_UPLOAD` | 403, wenn aktiviert | Schaltfläche ausgeblendet |
| Zwischenablage speichern | `DISABLE_SAVE_CLIPBOARD_TO_FILE` | 403, wenn aktiviert | Schaltfläche ausgeblendet |
| Machine-API (`status`, `conflicts`, `install`) | `API_KEY` | 403 `unauthorized` | Ohne Schlüssel nicht aufrufbar |

Zusätzliche Schutzmaßnahmen:

- **`.md.ini`** wird niemals an den Browser ausgeliefert — sie wird ausschließlich serverseitig gelesen.
- **Verzeichnis-`.md.ini`**-Dateien werden nur aus Verzeichnissen innerhalb des Browse-Roots gelesen, und aus ihnen werden nur die `DISABLE_*`-Schlüssel übernommen; Schlüssel der Installationsebene werden ignoriert und protokolliert.
- **Path Traversal** wird bei allen Operationen zum Ausliefern, Hochladen und Speichern von Dateien verhindert.
- **Upload / Save to File** prüfen Dateinamen serverseitig (nur Endung `.md`, keine Schrägstriche, keine Nullbytes).
- Die **CORS-Prüfung** in `updater.php` weist Cross-Origin-Anfragen ab.
- **Alle verändernden Aktionen** erfordern `POST` (außer den direkten URL-Modi, die ihre jeweiligen `ALLOW_*`-Flags benötigen).
- **Versionszeichenketten** in `?restore=` werden ausschließlich gegen `[a-zA-Z0-9.\-]` geprüft.
- Die **Machine-API** antwortet ausschließlich mit JSON (`format=json`), erfordert `API_KEY` und
  meldet jede übersprungene fremde Datei, statt sie zu überschreiben.

---

## Lizenz

Veröffentlicht unter der PolyForm Noncommercial License 1.0.0. Siehe [LICENSE](https://github.com/paulmann/MD.Viewer/blob/main/LICENSE).
