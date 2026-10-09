# Smart-TV: Installation

## Architektur

Die Website bleibt **ganz normal** erreichbar (Login, Verwaltung, Slider-URLs).  
Nur **ein fester Entry** für den Fernseher liefert eine dünne HTML-Seite mit iframe:

```text
SmartInfo / TV
      │
      ▼
http://dpm.drk-coe.de/tv.html     ← statisch aus public/ (mit Deploy)
      │
      │  iframe src="/slider/display"
      ▼
http://dpm.drk-coe.de/slider/display   ← normale Symfony-App (PIN → Slider)

Alles andere unverändert, z.B.:
  /login  /management/…  /slider/kita-…
```

| URL | Rolle |
|---|---|
| `http://dpm.drk-coe.de/tv.html` | Nur SmartInfo-Entry (iframe-Hülle) |
| `http://dpm.drk-coe.de/slider/display` | PIN + Slider (App) |
| Rest der Domain | Normale Website |

**Deploy:** Projekt wie im Repo; Datei liegt unter `public/tv.html`.  
**Nginx:** ein vHost, `root …/public` — siehe `docs/nginx-dpm-smartinfo.conf`.  
**DNS:** `dpm.drk-coe.de` intern auf den App-Server zeigen.

Warum der Extra-Endpoint? Manche SmartInfo-Setups öffnen „schwere“ App-URLs nicht zuverlässig („Smartinfo not available“), eine schlanke HTML-Seite schon — das iframe lädt dann die echte App.

## Hersteller-Weg (CMND)

Smartinfo-Content + HTML-Gadget **oder** Start-URL direkt auf:

`http://dpm.drk-coe.de/tv.html`

Handbuch B-Line (Beispiel 65BFL2214/12):

https://www.ppds.com/de-de/downloads/products/digital-signage/signage-2000-series--b-line-/65bfl2214-12

## Was nicht funktioniert

| Versuch | Ergebnis |
|---|---|
| Nur die App-Root-URL als Smart Card | oft „not available“ |
| USB-Stick → Gadget lokal auf dem TV hosten | geht nicht |
| Separater vHost, der *nur* das Gadget kennt und die App ersetzt | Website wäre weg — nicht gewollt |
| Feste Kita-URL für alle Geräte | unnötig — PIN wählt die Kita |

USB nur als Transport der Datei auf den CMND-PC, nicht als Installationsweg am Fernseher.

## Zwischenlösung ohne CMND

1. Start-URL / Browser: `http://dpm.drk-coe.de/tv.html`
2. Oder PC an HDMI mit derselben URL

## Ablauf

1. App normal deployen (`public/` inkl. `tv.html`).
2. Nginx: `root` auf `…/public`, `server_name dpm.drk-coe.de` (siehe Snippet).
3. Interner DNS: TV erreicht `dpm.drk-coe.de`.
4. SmartInfo → `http://dpm.drk-coe.de/tv.html` (Gadget oder Direkt-URL).
5. PIN am Gerät → Slider.

Gleiches Entry für jeden TV; nur die PIN unterscheidet die Kita.

## Der Code

`public/tv.html` (Kopie auch unter `docs/smart-tv-html-gadget.html`):

```html
<iframe width="1920" height="1080" src="/slider/display" frameborder="0"></iframe>
```

Same-Origin: Entry und App teilen Host `dpm.drk-coe.de`.

## PIN

Vierstellige PIN pro Kita in der Verwaltung; einmal am TV. Speicherung in der eingebetteten Seite (Cookies im iframe unzuverlässig). PIN-POST ohne Session/CSRF.

## Abnahme

- `/login` und Verwaltung funktionieren weiter wie bisher.
- `/tv.html` zeigt die PIN-Seite im iframe.
- Nach PIN der richtige Slider; andere PIN → andere Kita.
- Neustart ohne erneute PIN (soweit Speicherung greift).
- Kein „Smartinfo not available“ für die Entry-URL.
