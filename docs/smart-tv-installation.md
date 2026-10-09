# Smart-TV: Installation

## Architektur

```text
SmartInfo / Fernseher
        │
        ▼
http://dpm.drk-coe.tv/                   ← NUR Entry-HTML
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
| `dpm.drk-coe.tv` | Fernseher / SmartInfo | nur `smart-tv-html-gadget.html` |

Dateien: `docs/smart-tv-html-gadget.html`, `docs/nginx-dpm-smartinfo.conf`.

Am App-vHost kein `X-Frame-Options: SAMEORIGIN` (sonst blockiert `.tv` das iframe).

### DNS

Beide Namen müssen vom TV und vom VPN aus auflösbar sein (typisch dieselbe Server-IP).

**Hinweis:** `.tv` ist eine echte Top-Level-Domain. Entweder Zone `drk-coe.tv` bei euch (Registrar oder **interner DNS** nur im VPN/LAN), oder ein anderer Entry-Name. Zum Gegentest weiter möglich: öffentlicher Name (z. B. früher `dpm.timothy-roth.de`) parallel als `server_name`.

Empfehlung Intranet: interner DNS für `.de` und `.tv` auf den App-Server; SmartInfo-URL = `http://dpm.drk-coe.tv/`.

## Hersteller / CMND

Start-URL oder HTML-Gadget:

`http://dpm.drk-coe.tv/`

Handbuch B-Line:

https://www.ppds.com/de-de/downloads/products/digital-signage/signage-2000-series--b-line-/65bfl2214-12

## Was nicht funktioniert

| Versuch | Ergebnis |
|---|---|
| SmartInfo direkt auf `dpm.drk-coe.de` (App-Root) | oft „Smartinfo not available“ |
| USB → Gadget lokal auf dem TV | geht nicht |
| Relatives iframe auf dem `.tv`-Host | `.tv` hat keine App — absolut auf `.de` |

## Setup

1. App deployen → vHost `dpm.drk-coe.de` → `…/public`.  
2. `/var/www/html/dpm-entry/` + HTML aus `docs/smart-tv-html-gadget.html`.  
3. Nginx wie Snippet; DNS für `.de` und `.tv`.  
4. SmartInfo → `http://dpm.drk-coe.tv/`.  
5. PIN am TV.

## PIN

Vierstellige PIN pro Kita; einmal am Gerät. Speicherung in der eingebetteten Seite; PIN-POST ohne Session/CSRF.

## Abnahme

- Browser: `http://dpm.drk-coe.de/` = normale Website.  
- SmartInfo: `http://dpm.drk-coe.tv/` öffnet, darin PIN/Slider von `.de`.  
- Andere PIN → andere Kita.  
- Kein „Smartinfo not available“ für die `.tv`-URL.
