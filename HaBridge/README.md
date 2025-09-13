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
* Automatische Geräteerkennung über MQTT Discovery
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
  Discovery Prefix        | MQTT Topic Prefix für Home Assistant Discovery (Standard: homeassistant)
  Auto-Discovery         | Automatische Erkennung neuer Geräte über MQTT
  Echtzeit State Updates  | Sofortige Aktualisierung bei Änderungen in Home Assistant

### 5. Home Assistant Konfiguration
 
 Es wird ausschließlich der IP‑Symcon MQTT Server als Broker genutzt. Die Einrichtung erfolgt in Home Assistant über die UI:
 
 1. In Home Assistant: **Einstellungen** → **Geräte & Dienste** → **Integration hinzufügen** → „MQTT“ auswählen.
 2. Verbindungstyp: **Externer Broker** (HA verbindet sich zum IP‑Symcon MQTT Server).
 3. Broker-Daten:
    - Host/Adresse: IP/Hostname des IP‑Symcon‑Systems
    - Port: `1883`
    - Benutzername/Passwort: nur falls im IP‑Symcon „MQTT Server“ konfiguriert
 4. Optionen prüfen:
    - „Discovery aktivieren“ einschalten
    - „Discovery Prefix“: `homeassistant`
    - Birth Message (optional): Topic `homeassistant/status`, Payload `online`
    - Will Message (optional): Topic `homeassistant/status`, Payload `offline`
 5. Speichern. In IP‑Symcon sicherstellen, dass die **HaBridge** als Kind des **MQTT Server** verbunden ist.

### 6. Statusvariablen und Profile

#### Statusvariablen

Dieses Modul legt keine eigenen Statusvariablen an.

#### Profile

Keine (nicht zutreffend)

### 7. PHP-Befehlsreferenz

#### HAMQ_EnableMQTTForExistingDevices(integer $InstanzID)
Aktiviert MQTT Updates für alle bestehenden HaDevice Instanzen.

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

#### HAMQ_RunDiscovery(integer $InstanzID)
Führt eine manuelle Discovery-Suche nach neuen Home Assistant Geräten aus.

**Parameter:**
- $InstanzID: Instanz-ID des HaBridge Moduls

**Beispiel:**
```php
HAMQ_RunDiscovery(12345);
```

### Fehlerbehebung

**Problem:** Keine MQTT Nachrichten werden empfangen
- Prüfen Sie die MQTT Server Verbindung
- Überprüfen Sie die Home Assistant MQTT Konfiguration
- Kontrollieren Sie die Discovery Prefix Einstellung

**Problem:** Geräte werden nicht automatisch erstellt
- Aktivieren Sie Auto-Discovery in der HaBridge Konfiguration
- Stellen Sie sicher, dass eine HaConfigurator Instanz konfiguriert ist
- Prüfen Sie die Home Assistant MQTT Discovery Konfiguration

**Problem:** State Updates funktionieren nicht
- Aktivieren Sie "Echtzeit State Updates"
- Überprüfen Sie die MQTT Topic Struktur in Home Assistant
- Kontrollieren Sie die Debug-Ausgaben in IP-Symcon
