# HaBridge
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
* Bidirektionale Kommunikation mit Home Assistant
* Integration mit bestehenden HaConfigurator/HaDevice Instanzen
* Unterstützung für alle Home Assistant Entity-Typen

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- MQTT Server/Broker (z.B. Mosquitto)
- Home Assistant mit MQTT Integration
- HaConfigurator Modul (für Fallback-Funktionalität)

### 3. Software-Installation

* Über den Module Store das 'HaBridge'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### 4. Einrichten der Instanzen in IP-Symcon

Unter 'Instanz hinzufügen' kann das 'HaBridge'-Modul mithilfe des Schnellfilters gefunden werden.  
- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

  __Konfigurationsseite__:
  
  Name                    | Beschreibung
  ----------------------- | ------------------
  Discovery Prefix        | MQTT Topic Prefix (Standard: homeassistant). Muss mit `mqtt_statestream.base_topic` aus Home Assistant übereinstimmen.

### 5. Home Assistant Konfiguration
 
 Es wird ausschließlich der IP‑Symcon MQTT Server als Broker genutzt. Die Einrichtung erfolgt in Home Assistant über die UI:
 
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

Füge in der Home Assistant `configuration.yaml` folgenden Abschnitt ein, damit Zustände und Attribute per MQTT veröffentlicht werden. Der `base_topic` muss mit dem `Discovery Prefix` in der HaBridge übereinstimmen:

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

Starte anschließend Home Assistant neu.

### 6. Statusvariablen und Profile

#### Statusvariablen

Dieses Modul legt keine eigenen Statusvariablen an.

#### Profile

Keine (nicht zutreffend)

### 7. PHP-Befehlsreferenz

#### HAMQ_EnableMQTTForExistingDevices(integer $InstanzID)
Aktiviert MQTT-Updates für alle bestehenden HaDevice-Instanzen (Realtime ist immer aktiv).

**Parameter:**
- $InstanzID: Instanz-ID des HaBridge Moduls

**Rückgabe:**
- boolean: true bei Erfolg, false bei Fehler

**Beispiel:**
```php
$result = HAMQ_EnableMQTTForExistingDevices(12345);
if ($result) {
    echo "MQTT erfolgreich für bestehende Geräte aktiviert";
}
```

### Fehlerbehebung

**Problem:** Keine MQTT Nachrichten werden empfangen
- Prüfen Sie die MQTT Server Verbindung
- Überprüfen Sie die Home Assistant MQTT Konfiguration
- Kontrollieren Sie die Discovery Prefix Einstellung

**Problem:** Geräte werden nicht automatisch erstellt
- Stellen Sie sicher, dass eine HaConfigurator Instanz konfiguriert ist
- Prüfen Sie die Home Assistant MQTT Konfiguration (Broker verbunden, Topics vorhanden)

**Problem:** State Updates funktionieren nicht
- Überprüfen Sie die MQTT Topic Struktur in Home Assistant
- Kontrollieren Sie die Debug-Ausgaben in IP-Symcon
