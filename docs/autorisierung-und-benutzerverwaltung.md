# Autorisierung & Benutzerverwaltung – Testreferenz

Dieses Dokument beschreibt den Soll-Flow der Anwendung rund um **Login, Rollen, Benutzerverwaltung (Admin), zugewiesene Kitas und Slider**. Es dient Anwendern als Anhaltspunkt für Funktionstests.

## Grundprinzip

- Es gibt genau zwei Rollen: `ROLE_ADMIN` (Administrator) und `ROLE_USER` (eine Kita).
- **Jede Kita ist ein Benutzerkonto** – es gibt keine separate Kita-Entität.
- Der Admin verwaltet alle Benutzer und legt fest, **welchen Kitas ein Ersteller Inhalte zuweisen darf** ("Zugewiesene Benutzer" bzw. "Alle Benutzer zuweisen").
- Jede Kita hat genau **einen Slider** (TV-Anzeige), der per 4-stelliger PIN mit einem Gerät gekoppelt wird.

---

## 1. Gesamt-Flow: Login → Rolle → Berechtigungen (Aktivitätsdiagramm)

```mermaid
flowchart TD
    Start([Aufruf /login]) --> Login[Formular-Login\nBenutzername + Passwort]
    Login -->|Anmeldedaten falsch| LoginFehler[Fehlermeldung,\nzurück zum Login]
    LoginFehler --> Login
    Login -->|Anmeldung erfolgreich| RolleCheck{Rolle?}

    RolleCheck -->|ROLE_ADMIN| AdminDash["Weiterleitung:\n/management/admin"]
    RolleCheck -->|ROLE_USER| UserDash["Weiterleitung:\n/management/user"]

    subgraph AdminBereich [Admin-Bereich - nur ROLE_ADMIN]
        AdminDash --> A1[Benutzer / Kita anlegen\nRolle immer ROLE_USER]
        AdminDash --> A2["Benutzer bearbeiten:\n- Passwort\n- Geräte-PIN (TV)\n- zugewiesene Benutzer\n- 'Alle Benutzer zuweisen'"]
        AdminDash --> A3[Benutzer löschen]
        AdminDash --> A4["Zentrale Inhalte anlegen/bearbeiten\n(Bild oder Artikel)"]
        A4 --> Sync
        A2 --> Sync["AudienceSynchronizer:\nslider_item-Zeilen für alle\nerlaubten Empfänger-Kitas\nanlegen/entfernen"]
    end

    subgraph UserBereich [Kita-Bereich - ROLE_USER]
        UserDash --> U1["Eigene Inhalte anlegen/bearbeiten\nZielgruppe nur aus eigenen\nzugewiesenen Benutzern wählbar"]
        UserDash --> U2["Eigenen Slider verwalten:\n- Reihenfolge ändern\n- Slide aktivieren/deaktivieren"]
        UserDash --> U3[Slide-Dauer einstellen]
        UserDash --> U4[Eigenes Passwort ändern]
        U1 --> Sync
    end

    Sync --> Slider["Slider der Empfänger-Kita\nzeigt Inhalt (sofern aktiviert)"]

    Slider --> TV["TV ruft /slider/display auf,\nPIN-Eingabe → Slider der Kita\nwird ohne Login angezeigt"]
```

**Hinweis für Tests:** Ein Admin, der `/management/user` aufruft, wird automatisch auf `/management/admin` umgeleitet – und umgekehrt eine Kita von `/management/admin` auf `/management/user`.

---

## 2. Benutzerverwaltung & Kita-Zuweisung (Sequenzdiagramm)

```mermaid
sequenceDiagram
    actor Admin
    participant App as Anwendung
    participant DB as Datenbank
    actor Kita as Kita-Benutzer

    Admin->>App: Login (ROLE_ADMIN)
    App-->>Admin: Admin-Dashboard /management/admin

    Admin->>App: Neue Kita anlegen (Benutzername, Passwort)
    App->>DB: user speichern (roles = ROLE_USER)
    App-->>Admin: Kita erscheint in Benutzerliste

    Admin->>App: Kita bearbeiten: Benutzer zuweisen<br/>oder "Alle Benutzer zuweisen"
    App->>DB: user_publish_target bzw. publish_to_all aktualisieren
    App->>DB: Slider-Einträge synchronisieren<br/>(Inhalte bei entfernten Zielen zurückziehen,<br/>bei neuen Zielen ausspielen)

    Admin->>App: Geräte-PIN für Kita setzen (4-stellig)
    App->>DB: device_pin speichern

    Kita->>App: Login (ROLE_USER)
    App-->>Kita: Kita-Dashboard /management/user
    Kita->>App: Inhalt anlegen, Zielgruppe wählen
    Note over Kita,App: Auswählbar sind nur die eigene Kita<br/>und vom Admin zugewiesene Benutzer
    App->>DB: content + slider_item je Empfänger anlegen
```

---

## 3. Slider: Wer darf was? (Sequenzdiagramm inkl. TV)

```mermaid
sequenceDiagram
    actor Ersteller as Ersteller (Admin oder Kita)
    actor Konsument as Empfänger-Kita
    participant App as Anwendung
    participant TV as TV-Gerät (ohne Login)

    Ersteller->>App: Inhalt anlegen/bearbeiten/löschen
    Note over Ersteller,App: ContentVoter: nur Ersteller selbst<br/>oder Admin darf EDIT/DELETE

    App->>Konsument: Inhalt erscheint im Slider ("Mein Slider")
    Konsument->>App: Reihenfolge ändern / Slide deaktivieren
    Note over Konsument,App: SliderItemVoter: nur die Empfänger-Kita<br/>selbst oder Admin darf MANAGE.<br/>Deaktivieren wirkt nur auf den eigenen Slider.

    TV->>App: /slider/display aufrufen, PIN eingeben
    App-->>TV: Cookie setzen, Weiterleitung auf /slider/{slug}
    loop regelmäßige Abfrage (tv.js)
        TV->>App: /slider/{slug}/content
        alt PIN stimmt mit Kita überein
            App-->>TV: aktivierte Slides in Reihenfolge
        else PIN geändert/entfernt
            App-->>TV: "unlinked" → zurück zur PIN-Eingabe
        end
    end
```

---

## 4. Datenmodell (Klassendiagramm)

```mermaid
classDiagram
    class User {
        +username
        +roles : ROLE_ADMIN | ROLE_USER
        +password (gehasht)
        +slug (für /slider/slug)
        +durationBetweenSlides
        +devicePin (nur Admin setzt)
        +publishToAll : bool
    }

    class Content {
        +type : Bild | Artikel
        +title
        +imageUrl / content
        +audienceAll : bool
    }

    class SliderItem {
        +displayOrder
        +isEnabled
    }

    User "1" --> "*" Content : erstellt
    User "*" --> "*" User : publishTargets\n(user_publish_target)
    Content "1" --> "*" SliderItem : wird ausgespielt als
    User "1" --> "*" SliderItem : Konsument\n(eigener Slider)
```

---

## 5. Checkliste für Funktionstests

### Login & Rollen
- [ ] Login mit falschen Daten schlägt fehl, Fehlermeldung erscheint.
- [ ] Admin landet nach Login auf `/management/admin`, Kita auf `/management/user`.
- [ ] Kita kann `/management/admin` nicht aufrufen (Umleitung auf eigenes Dashboard).
- [ ] Admin wird von `/management/user` auf das Admin-Dashboard umgeleitet.
- [ ] Logout beendet die Sitzung.

### Benutzerverwaltung (nur Admin)
- [ ] Admin kann neue Kita anlegen; sie erscheint in der Benutzerliste.
- [ ] Admin kann Passwort einer Kita ändern.
- [ ] Admin kann einer Kita eine 4-stellige Geräte-PIN zuweisen; PIN muss eindeutig sein.
- [ ] Admin kann einer Kita "zugewiesene Benutzer" geben oder "Alle Benutzer zuweisen" aktivieren.
- [ ] Admin kann Kita löschen; deren Inhalte/Slider-Einträge verschwinden.

### Inhalte & Zuweisung
- [ ] Kita sieht bei "Sichtbar für" nur sich selbst und die vom Admin zugewiesenen Benutzer.
- [ ] Zentrale Inhalte des Admins erscheinen im Slider aller erlaubten Kitas.
- [ ] Entzieht der Admin einer Kita ein Ziel, verschwinden dort die bereits ausgespielten Inhalte dieses Erstellers.
- [ ] Nur der Ersteller (oder Admin) kann einen Inhalt bearbeiten/löschen.

### Slider
- [ ] Kita kann Reihenfolge der eigenen Slides ändern (hoch/runter).
- [ ] Kita kann einen Slide deaktivieren – er verschwindet nur aus ihrem eigenen Slider, nicht bei anderen Empfängern.
- [ ] Kita kann die Anzeigedauer pro Slide einstellen.
- [ ] Kita kann Slides anderer Kitas nicht verwalten (direkte URL-Aufrufe werden abgelehnt).

### TV-Anzeige (ohne Login)
- [ ] `/slider/display`: korrekte PIN führt zum Slider der richtigen Kita.
- [ ] Falsche PIN wird abgelehnt.
- [ ] Ändert/entfernt der Admin die PIN, zeigt das gekoppelte TV wieder die PIN-Eingabe ("unlinked").
- [ ] Neue/aktivierte Inhalte erscheinen ohne Neuladen auf dem TV (regelmäßige Abfrage).
