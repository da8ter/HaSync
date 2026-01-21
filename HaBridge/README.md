# HaBridge
Home Assistant MQTT Integration für Echtzeit-Updates

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Home Assistant Konfiguration](#5-home-assistant-konfiguration)
6. [Datenfluss & Topic-Struktur](#6-datenfluss--topic-struktur)


### 1. Funktionsumfang

* Echtzeit-Updates von Home Assistant über MQTT
* Bidirektionale Kommunikation mit Home Assistant
* Verteilt Updates an HaDevice / HaMultiEntityDevice Instanzen (DataFlow)
* Optional: Service Calls an Home Assistant via REST (URL/Token werden in der HaBridge konfiguriert)

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- IP-Symcon **MQTT Server** Instanz (Broker)
- Home Assistant mit MQTT Integration
- HaBridge mit konfigurierter Home Assistant URL/Token (nur für Service Calls)

### 3. Software-Installation

* Über den Module Store **exakt** nach `HaSync` suchen und das Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/da8ter/HaSync.git`

### 4. Einrichten der Instanzen in IP-Symcon

Unter 'Instanz hinzufügen' kann das 'HaBridge'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

#### Physikalische Verbindung (wichtig)

Die **HaBridge muss als Kind** des **IP-Symcon MQTT Server** verbunden sein.

In der Regel passiert das über:
- Erstellung unterhalb des MQTT Servers (empfohlen) oder
- nachträgliches Verbinden (Kontextmenü: „Verbinden mit…“)

Wenn kein Parent verbunden ist, bleibt die HaBridge auf Status **104**.

  __Konfigurationsseite__:
  
  Name                    | Beschreibung
  ----------------------- | ------------------
  Discovery Prefix        | MQTT Topic Prefix (Standard: `homeassistant`). Muss mit `mqtt_statestream.base_topic` aus Home Assistant übereinstimmen.
  Home Assistant URL      | Basis-URL von Home Assistant (z. B. `http://192.168.1.100:8123`)
  Home Assistant Token    | Long-lived Access Token aus dem HA Profil

Technische Properties:
- `ClientID` (Standard: `HaBridge_<InstanceID>`)
- `ha_discovery_prefix` (Standard: `homeassistant`)
- `ha_url`
- `ha_token`

### 5. Home Assistant Konfiguration
 
Es wird der IP‑Symcon MQTT Server als Broker genutzt. Die Einrichtung erfolgt in Home Assistant über die UI:
 
 1. In Home Assistant: **Einstellungen** → **Geräte & Dienste** → **Integration hinzufügen** → „MQTT“ auswählen.
 2. Verbindungstyp: **Externer Broker** (HA verbindet sich zum IP‑Symcon MQTT Server).
 3. Broker-Daten:
    - Host/Adresse: IP/Hostname des IP‑Symcon‑Systems
    - Port: `1883`
    - Benutzername/Passwort: nur falls im IP‑Symcon „MQTT Server“ konfiguriert
 4. Optionen prüfen:
    - Birth Message (optional): Topic `homeassistant/status`, Payload `online`
    - Will Message (optional): Topic `homeassistant/status`, Payload `offline`
 5. Speichern. In IP‑Symcon sicherstellen, dass die **HaBridge** als Kind des **MQTT Server** verbunden ist.

#### MQTT State Stream (configuration.yaml) aktivieren

Damit Zustände und Attribute per MQTT veröffentlicht werden, muss `mqtt_statestream` aktiv sein. Der `base_topic` muss mit dem `Discovery Prefix` in der HaBridge übereinstimmen.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

Starte anschließend Home Assistant neu.

### 6. Datenfluss & Topic-Struktur

#### Überblick

- **MQTT → HaBridge:** Die HaBridge empfängt MQTT Nachrichten vom IP‑Symcon MQTT Server.
- **HaBridge → Devices:** Updates werden per `SendDataToChildren` an HaDevice/HaMultiEntityDevice verteilt.
- **Devices → HaBridge:** Geräte senden Aktionen wie Publish/Service Calls per `ForwardData` an die HaBridge.

#### Topic-Format

Die HaBridge arbeitet mit Topics unterhalb:

`<ha_discovery_prefix>/<domain>/<entity>/<key>`

Beispiele:
- `homeassistant/input_boolean/testschalter/state`
- `homeassistant/input_boolean/testschalter/attributes`
- `homeassistant/light/wohnzimmer/brightness` (Einzel-Attribut)

Die HaBridge akzeptiert alle Subtopics (`/#`) je Entity und baut daraus Updates:
- `state` wird als State-Update verarbeitet
- `attributes` wird als Attribute-Update verarbeitet
- andere Keys werden als Einzel-Attribute unter `attributes.<key>` verarbeitet
