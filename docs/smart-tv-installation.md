# Smart-TV: Installation

Jedes Gerät bekommt denselben Inhalt und darin dieselbe Adresse. Welche Kita läuft, entscheidet die PIN, die einmal am Fernseher eingegeben wird. Die Adresse ist intern und verwendet `http`, weil es kein Zertifikat gibt.

## Wo der HTML-Code liegt

Der Code liegt nicht als Datei auf dem Fernseher. Es gibt keinen Ordner, den man per Explorer, USB oder Anmeldung am Gerät füllt.

Er steht in einem Inhalt, den du in der Software des Herstellers auf einem PC oder Tablet anlegst. Dieser Rechner und der Fernseher müssen im selben internen Netz sein. Die Software findet den Fernseher über seine IP-Adresse und schickt den Inhalt dorthin. Der Fernseher speichert den Inhalt und zeigt ihn nach dem Einschalten.

Beim Philips B-Line (Beispiel 65BFL2214/12) heißt das so:

- Inhalt bauen: **CMND & Create** auf einem Windows-PC, oder die App **PPDS Publisher** auf einem Tablet.
- Fernseher ansprechen: **CMND & Control**, ebenfalls auf dem PC. Das Programm sieht die Geräte im lokalen Netz.
- Inhaltstyp laut Hersteller: **Smartinfo-Content**.
- Baustein darin: **HTML-Gadget**, bildschirmfüllend.

Die Programme und die Menüpunkte stehen im Handbuch:

https://www.ppds.com/de-de/downloads/products/digital-signage/signage-2000-series--b-line-/65bfl2214-12

Ablauf:

1. Fernseher ins interne Netz hängen. Im Netzwerkmenü des Geräts die IP-Adresse ablesen.
2. Den PC oder das Tablet ins gleiche Netz hängen. Er muss diese IP erreichen.
3. CMND laut Handbuch installieren und den Fernseher darüber aufnehmen.
4. Einen Smartinfo-Inhalt anlegen, ein HTML-Gadget auf die volle Fläche legen und den Code unten einfügen.
5. Den Inhalt an den Fernseher senden und als Startinhalt nach dem Einschalten festlegen.
6. Auf dem Bildschirm erscheint die PIN-Seite. Die vier Ziffern mit der Fernbedienung oder einer USB-Tastatur eingeben. Nach der vierten Ziffer startet der Slider.

Dieselbe Veröffentlichung gilt für jeden weiteren Fernseher. Nur die PIN am Gerät ist eine andere, wenn eine andere Kita gezeigt werden soll.

## Der Code, auf jedem Gerät gleich

`HIER-DIE-INTERNE-ADRESSE` durch den Namen oder die IP des Servers ersetzen, den der Fernseher erreicht. Zum Beispiel `kita-manager.intern` oder `192.168.1.50`.

```html
<iframe width="1920" height="1080" src="http://HIER-DIE-INTERNE-ADRESSE/slider/display" frameborder="0"></iframe>
```

Das ist immer `http://…/slider/display`. Kein `https`, kein Kita-Name in der Adresse. Eine `https`-Adresse scheitert, weil die interne Domain kein Zertifikat hat.

Die Seite im Rahmen füllt die Fläche des Gadgets. Für die B-Line die vom Hersteller genannte Größe 1920×1080 lassen. Auf einem anderen Panel die Breite und Höhe der Inhaltsfläche eintragen, wenn das Gadget feste Pixel verlangt.

## PIN

In der Verwaltung hat jede Kita eine eigene vierstellige PIN. Am Fernseher wird sie einmal eingegeben. Das Gerät merkt sich die Zuordnung und zeigt danach den Slider dieser Kita, auch nach einem Neustart.

Mehrere Fernseher dürfen dieselbe PIN haben, dann zeigen sie denselben Slider. Wird die PIN in der Verwaltung geändert oder gelöscht, erscheint auf den betroffenen Geräten wieder die Eingabe.

Die Zuordnung liegt im Speicher der eingebetteten Seite. Ein normales Cookie hält in diesem Rahmen ohne `https` nicht (und in Edge/Chromium oft auch nicht, wenn die Elternseite eine andere Domain hat), deshalb speichert die Anzeige die PIN selbst und sendet sie bei Bedarf erneut.

Zum Testen am PC: Firefox und Edge verhalten sich hier unterschiedlich. Edge blockiert Third-Party-Cookies im iframe strenger; die PIN-Eingabe darf deshalb nicht von einer Session/CSRF abhängen. Am Fernseher zählt der eingebaute Browser der Signage-Software – dort denselben iframe-Code wie unten verwenden.

## Andere Geräte

Dasselbe Muster, andere Software: den Code in das HTML- oder Web-Widget des jeweiligen Inhaltsprogramms setzen, das Programm im selben Netz den Bildschirm finden lassen, Inhalt hinschicken, als Startinhalt setzen, PIN am Gerät eingeben.

Kann ein Gerät eine Startadresse direkt öffnen und braucht keinen HTML-Rahmen, nur diese Adresse eintragen:

```text
http://HIER-DIE-INTERNE-ADRESSE/slider/display
```

Der eingebaute Browser eines Wohnzimmer-Fernsehers ohne festen Startkanal eignet sich nicht. Dann einen Signage-Player oder ein Zusatzgerät nehmen und dort denselben Inhalt starten.

## Abnahme

- Ein neues Gerät zeigt zuerst die PIN-Seite, nicht schon einen Slider.
- Nach der PIN erscheint der Slider der zugehörigen Kita.
- Ein zweites Gerät mit demselben Code und einer anderen PIN zeigt die andere Kita.
- Nach dem Ausschalten kommt derselbe Slider ohne erneute Eingabe.
- Die Adresse beginnt mit `http://` und der Fernseher meldet keinen Zertifikatsfehler.
- Eine Änderung in der Verwaltung ist nach etwa einer Minute auf dem Bildschirm sichtbar, ohne den Inhalt neu zu veröffentlichen.
