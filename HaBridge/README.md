# HAmqtt
Home Assistant MQTT Integration für Echtzeit-Updates

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Home Assistant Konfiguration](#5-home-assistant-konfiguration)
6. [Statusvariablen und Profile](#6-statusvariablen-und-profile)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Echtzeit-Updates von Home Assistant über MQTT
* Automatische Geräteerkennung über MQTT Discovery
* Bidirektionale Kommunikation mit Home Assistant
* Integration mit bestehenden HAconnect/HAdevice Instanzen
* Unterstützung für alle Home Assistant Entity-Typen

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- MQTT Server/Broker (z.B. Mosquitto)
- Home Assistant mit MQTT Integration
- HAconnect Modul (für Fallback-Funktionalität)

### 3. Software-Installation

* Über den Module Store das 'HAmqtt'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### 4. Einrichten der Instanzen in IP-Symcon

Unter 'Instanz hinzufügen' kann das 'HAmqtt'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name                    | Beschreibung
----------------------- | ------------------
Discovery Prefix        | MQTT Topic Prefix für Home Assistant Discovery (Standard: homeassistant)
Auto-Discovery         | Automatische Erkennung neuer Geräte über MQTT
Echtzeit State Updates  | Sofortige Aktualisierung bei Änderungen in Home Assistant
HAconnect Instanz      | Verknüpfung mit bestehender HAconnect Instanz für Fallback

### 5. Home Assistant Konfiguration

Fügen Sie folgende Konfiguration zu Ihrer `configuration.yaml` hinzu:

```yaml
mqtt:
  broker: IP_IHRES_MQTT_SERVERS
  port: 1883
  username: mqtt_user
  password: mqtt_password
  discovery: true
  discovery_prefix: homeassistant
  birth_message:
    topic: 'homeassistant/status'
    payload: 'online'
  will_message:
    topic: 'homeassistant/status'
    payload: 'offline'
```

### 6. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name   | Typ     | Beschreibung
------ | ------- | ------------
Status | Integer | Verbindungsstatus zur MQTT Integration

#### Profile

Es werden keine zusätzlichen Profile angelegt.

### 7. PHP-Befehlsreferenz

#### HAmqtt_EnableMQTTForExistingDevices(integer $InstanzID)
Aktiviert MQTT Updates für alle bestehenden HAdevice Instanzen.

**Parameter:**
- $InstanzID: Instanz-ID des HAmqtt Moduls

**Rückgabe:**
- boolean: true bei Erfolg, false bei Fehler

**Beispiel:**
```php
$result = HAmqtt_EnableMQTTForExistingDevices(12345);
if ($result) {
    echo "MQTT erfolgreich für bestehende Geräte aktiviert";
}
```

#### HAmqtt_RunDiscovery(integer $InstanzID)
Führt eine manuelle Discovery-Suche nach neuen Home Assistant Geräten aus.

**Parameter:**
- $InstanzID: Instanz-ID des HAmqtt Moduls

**Beispiel:**
```php
HAmqtt_RunDiscovery(12345);
```

### Fehlerbehebung

**Problem:** Keine MQTT Nachrichten werden empfangen
- Prüfen Sie die MQTT Server Verbindung
- Überprüfen Sie die Home Assistant MQTT Konfiguration
- Kontrollieren Sie die Discovery Prefix Einstellung

**Problem:** Geräte werden nicht automatisch erstellt
- Aktivieren Sie Auto-Discovery in der HAmqtt Konfiguration
- Stellen Sie sicher, dass eine HAconnect Instanz konfiguriert ist
- Prüfen Sie die Home Assistant MQTT Discovery Konfiguration

**Problem:** State Updates funktionieren nicht
- Aktivieren Sie "Echtzeit State Updates"
- Überprüfen Sie die MQTT Topic Struktur in Home Assistant
- Kontrollieren Sie die Debug-Ausgaben in IP-Symcon
