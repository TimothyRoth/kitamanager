# Smart-TV: Installation

## Architektur

```text
SmartInfo / Fernseher
        │
        ▼
http://tv-dpm.drk-coe.de/                ← NUR Entry-HTML
        │
        │  iframe → http://dpm.drk-coe.de/slider/display
        ▼
http://dpm.drk-coe.de/slider/display     ← App (PIN → Slider)

Browser (ohne iframe), alle Geräte:
http://dpm.drk-coe.de/                   ← volle Website
```

| Host | Für wen | Inhalt |
|---|---|---|
| `dpm.drk-coe.de` | alle Geräte / Browser | Symfony-App (`…/public`) |
| `tv-dpm.drk-coe.de` | Fernseher / SmartInfo | nur `smart-tv-html-gadget.html` |

Dateien: `docs/smart-tv-html-gadget.html`, `docs/nginx-dpm-smartinfo.conf`.

Am App-vHost kein `X-Frame-Options: SAMEORIGIN` (sonst blockiert der TV-Entry das iframe).

### DNS

Beide Namen unter Zone `drk-coe.de`, vom TV und VPN aus auflösbar (typisch dieselbe Server-IP).

SmartInfo-URL: `http://tv-dpm.drk-coe.de/`

## Hersteller / CMND

Start-URL oder HTML-Gadget:

`http://tv-dpm.drk-coe.de/`

Handbuch B-Line:

https://www.ppds.com/de-de/downloads/products/digital-signage/signage-2000-series--b-line-/65bfl2214-12

## Was nicht funktioniert

| Versuch | Ergebnis |
|---|---|
| SmartInfo direkt auf `dpm.drk-coe.de` (App-Root) | oft „Smartinfo not available“ |
| USB → Gadget lokal auf dem TV | geht nicht |
| Relatives iframe auf dem TV-Entry-Host | Entry hat keine App — absolut auf `dpm.drk-coe.de` |

## Setup

1. App deployen → vHost `dpm.drk-coe.de` → `…/public`.  
2. `/var/www/html/dpm-entry/` + HTML aus `docs/smart-tv-html-gadget.html`.  
3. Nginx wie Snippet; DNS für `dpm` und `tv-dpm`.  
4. SmartInfo → `http://tv-dpm.drk-coe.de/`.  
5. PIN am TV.

## PIN

Vierstellige PIN pro Kita; einmal am Gerät. Speicherung in der eingebetteten Seite; PIN-POST ohne Session/CSRF.

## Abnahme

- Browser: `http://dpm.drk-coe.de/` = normale Website.  
- SmartInfo: `http://tv-dpm.drk-coe.de/` öffnet, darin PIN/Slider von `dpm.drk-coe.de`.  
- Andere PIN → andere Kita.  
- Kein „Smartinfo not available“ für die TV-Entry-URL.
