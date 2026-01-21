# HaMultiEntityDevice

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Verhalten](#5-statusvariablen-und-verhalten)
6. [WebFront](#6-webfront)

### 1. Funktionsumfang

* Bündelt mehrere Home Assistant Entitäten in **einer** IP-Symcon Instanz.
* Legt für jede konfigurierte Entität eine eigene Status-Variable an.
* Unterstützt Echtzeit-Updates über die `HaBridge` (MQTT Broadcast-System).
* Optional: erstellt zusätzliche Attribut-Variablen (`HAS_*`) pro Entität.

Hinweis: Dieses Modul ist die passende Wahl, wenn du mehrere zusammengehörige Entitäten (z. B. mehrere Sensoren eines Geräts) in **einer** Instanz in IP-Symcon abbilden möchtest.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- Eine eingerichtete `HaBridge` Instanz (MQTT)

### 3. Software-Installation

* Über den Module Store **exakt** nach `HaSync` suchen und das Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/da8ter/HaSync.git`

### 4. Einrichten der Instanzen in IP-Symcon

Unter 'Instanz hinzufügen' kann das 'HaMultiEntityDevice'-Modul mithilfe des Schnellfilters gefunden werden.

Wichtig: Das Modul muss als **Kind** der `HaBridge` verbunden sein.

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Zusätzliche Attribut-Variablen erstellen | Wenn aktiv, werden zusätzliche Attribut-Variablen (`HAS_*`) erzeugt
Entitäten | Liste der Entitäten, die in dieser Instanz zusammengefasst werden

#### Entitäten-Liste

Pro Eintrag:

- `Entity ID`: Home Assistant Entity-ID (z. B. `sensor.wohnzimmer_temperature`)
- `Alias`: Anzeigename in IP-Symcon (optional)

### 5. Statusvariablen und Verhalten

#### Statusvariablen

Für jede Entität wird eine sichtbare Status-Variable erzeugt (Name = Alias oder `Status <entity_id>`). Der Variablentyp wird automatisch aus Domain/Wert/Attributen abgeleitet.

#### Attribut-Variablen (`HAS_*`)

Wenn aktiviert, werden zusätzliche (standardmäßig versteckte) Variablen für relevante Attribute angelegt.

#### Echtzeit-Updates

MQTT Updates werden von der `HaBridge` empfangen und innerhalb der Instanz der passenden Entität zugeordnet.

### 6. WebFront

Die Status-Variablen sind sichtbar und – sofern editierbar – bedienbar (z. B. Schalter/Slider). Zusätzliche `HAS_*`-Variablen sind standardmäßig verborgen und dienen der Verarbeitung eingehender Attribute.
