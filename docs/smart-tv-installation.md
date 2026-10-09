# Smart-TV: Installation

Jedes Gerät bekommt denselben Inhalt und darin dieselbe Adresse. Welche Kita läuft, entscheidet die PIN, die einmal am Fernseher eingegeben wird. Die Adresse ist intern und verwendet `http`, weil es kein Zertifikat gibt.

Der Hersteller (PPDS / Philips B-Line) bestätigt den Weg: **Smartinfo-Content** anlegen und die Webseite im **HTML-Gadget per iframe** einbinden. Das ist ein Umweg gegenüber einer direkten Smart-Card-URL – und der richtige.

Handbuch B-Line (Beispiel 65BFL2214/12):

https://www.ppds.com/de-de/downloads/products/digital-signage/signage-2000-series--b-line-/65bfl2214-12

## Was nicht funktioniert

| Versuch | Ergebnis |
|---|---|
| Server-URL als **Smart Card** / Smart-Card-Quelle eintragen | „Smart card not available“ – Smart Card ist kein allgemeiner Webbrowser |
| HTML-Gadget **per USB-Stick direkt auf den Fernseher** kopieren | Geht so nicht. Der TV hat keinen Ordner, den man per Stick, Explorer oder Anmeldung befüllt |
| `https://…` auf die interne Domain | Scheitert ohne Zertifikat |
| Feste Kita-URL `/slider/kita-…` für alle Geräte | Möglich, aber unnötig – dann bräuchte jedes Gerät einen anderen Inhalt |

### USB-Stick – was er kann und was nicht

Den Stick in den Fernseher stecken und das HTML-Gadget „lokal hosten“ **geht nicht**. Die B-Line startet von USB keinen Smartinfo-Inhalt und keinen eingebetteten Webserver. USB am TV ist höchstens für Medien (Bilder/Video) oder Firmware gedacht.

Sinnvoller Einsatz des Sticks:

- Datei auf den **PC** kopieren (CMND & Create / späteres Einfügen ins Gadget)
- Datei auf einem **PC im gleichen Netz** per Browser oder kleinem HTTP-Server bereitstellen (Zwischenlösung, siehe unten)
- PC per **HDMI** am TV, Browser Vollbild mit der HTML-Datei oder der Display-URL

Der Stick ersetzt weder CMND noch den Kitamanager-Server. Das iframe braucht immer Netz zu `http://dpm.drk-coe.de` (bzw. der internen Ersatzadresse).

## Zwischenlösung ohne CMND / cmnd.io

Solange der Zugang zu CMND bzw. cmnd.io fehlt, reicht zum Testen oder Übergangsbetrieb einer der Wege:

1. **Direkt-URL** (wenn der TV einen Browser oder eine Start-URL hat):  
   `http://dpm.drk-coe.de/slider/display`
2. **HTML über eine LAN-IP** (entspricht dem Weg, der oft schon mit einer einfachen HTML-Seite + Link funktioniert hat): `docs/smart-tv-html-gadget.html` auf einen erreichbaren Host legen (PC im Netz, optional unter `/tv.html` auf dem App-Server) und am TV diese HTTP-Adresse öffnen – nicht als Smart Card.
3. **PC an HDMI**: HTML-Datei oder Display-URL im Browser Vollbild.

Beispiel Mini-Server auf dem PC (Stick-Inhalt nach `docs` bzw. Ordner mit der HTML-Datei):

```text
php -S 0.0.0.0:8080
```

Am TV dann z. B. `http://IP-DES-PCS:8080/smart-tv-html-gadget.html`.

Dauerhaft nach dem Einschalten ohne Zusatzgerät bleibt der Hersteller-Weg über CMND (nächster Abschnitt).

## Richtiger Weg: Smartinfo + HTML-Gadget

Der HTML-Code liegt in einem Inhalt, den du in der Hersteller-Software auf einem PC oder Tablet anlegst. Dieser Rechner und der Fernseher müssen im selben internen Netz sein. Die Software findet den Fernseher über seine IP und schickt den Inhalt dorthin. Der Fernseher speichert ihn und zeigt ihn nach dem Einschalten.

Beim Philips B-Line:

- Inhalt bauen: **CMND & Create** (Windows) oder **PPDS Publisher** (Tablet)
- Fernseher ansprechen: **CMND & Control**
- Inhaltstyp: **Smartinfo-Content**
- Baustein: **HTML-Gadget**, bildschirmfüllend (1920×1080)

Ablauf:

1. Fernseher ins interne Netz. IP-Adresse im Netzwerkmenü ablesen.
2. PC/Tablet ins gleiche Netz – muss diese IP erreichen.
3. CMND laut Handbuch installieren und den Fernseher aufnehmen.
4. Smartinfo-Inhalt anlegen, HTML-Gadget auf die volle Fläche legen, Code unten einfügen (oder Inhalt aus `docs/smart-tv-html-gadget.html`).
5. Inhalt per CMND & Control an den Fernseher senden und als **Startinhalt** nach dem Einschalten festlegen.
6. PIN-Seite erscheint. Vier Ziffern mit Fernbedienung oder USB-Tastatur eingeben → Slider startet.

Dieselbe Veröffentlichung für jeden weiteren Fernseher. Nur die PIN am Gerät ist eine andere, wenn eine andere Kita gezeigt werden soll.

## Der Code (auf jedem Gerät gleich)

Hersteller-Demo (nur als Muster):

```html
<iframe width="1920" height="1080" src="https://kita-manager.timothy-roth.de/slider/kita-regenbogen" frameborder="0"></iframe>
```

Produktiv intern (DRK) – Abweichungen bewusst:

- **`http`**, nicht `https`
- **`/slider/display`**, nicht `/slider/kita-…` (PIN wählt die Kita)

```html
<iframe width="1920" height="1080" src="http://dpm.drk-coe.de/slider/display" frameborder="0"></iframe>
```

Fertige Datei: `docs/smart-tv-html-gadget.html` (vollständiges HTML um den iframe; im Gadget reicht oft auch nur die iframe-Zeile).

DNS auf dem TV fehlt? Nur den Host ersetzen, Pfad bleibt:

```html
<iframe width="1920" height="1080" src="http://HIER-DIE-INTERNE-ADRESSE/slider/display" frameborder="0"></iframe>
```

Der Fernseher muss `http://dpm.drk-coe.de` (bzw. die Ersatzadresse) aus dem internen Netz erreichen.

## PIN

In der Verwaltung hat jede Kita eine eigene vierstellige PIN. Am Fernseher wird sie einmal eingegeben. Das Gerät merkt sich die Zuordnung und zeigt danach den Slider dieser Kita, auch nach einem Neustart.

Mehrere Fernseher dürfen dieselbe PIN haben → gleicher Slider. Wird die PIN in der Verwaltung geändert oder gelöscht, erscheint auf den betroffenen Geräten wieder die Eingabe.

Die Zuordnung liegt im Speicher der eingebetteten Seite. Ein normales Cookie hält in diesem iframe ohne `https` oft nicht, deshalb speichert die Anzeige die PIN selbst und sendet sie bei Bedarf erneut. Die PIN-Eingabe hängt nicht an einer Session/CSRF (nötig für Chromium/Edge im fremden Rahmen).

## Test am PC

Zum Ausprobieren darf eine lokale HTML-Datei (`file:///…`) denselben iframe einbetten. Die App darf dafür **kein** `Content-Security-Policy: frame-ancestors *` senden – `*` gilt nur für `http`/`https`, nicht für `file:`. Am Fernseher denselben iframe-Code verwenden; der Auslieferungsweg bleibt CMND (Smartinfo), nicht USB aufs Gerät.

## Andere Geräte

Dasselbe Muster, andere Software: Code ins HTML-/Web-Widget des Inhaltsprogramms, Programm im selben Netz den Bildschirm finden lassen, Inhalt senden, als Startinhalt setzen, PIN eingeben.

Kann ein Gerät eine Startadresse direkt öffnen und braucht keinen HTML-Rahmen:

```text
http://dpm.drk-coe.de/slider/display
```

Der eingebaute Browser eines Wohnzimmer-Fernsehers ohne festen Startkanal eignet sich nicht. Dann Signage-Player oder Zusatzgerät nehmen.

## Abnahme

- Neues Gerät zeigt zuerst die PIN-Seite, nicht schon einen Slider.
- Nach der PIN erscheint der Slider der zugehörigen Kita.
- Zweites Gerät mit demselben Inhalt und anderer PIN zeigt die andere Kita.
- Nach dem Ausschalten kommt derselbe Slider ohne erneute Eingabe.
- Adresse beginnt mit `http://`, kein Zertifikatsfehler.
- Änderung in der Verwaltung ist nach etwa einer Minute auf dem Bildschirm sichtbar, ohne den Inhalt neu zu veröffentlichen.
- Kein „Smart card not available“ – Inhalt läuft als Smartinfo/HTML-Gadget, nicht als Smart Card.
