# HaSync - Home Assistant Integration für IP-Symcon

[![Version](https://img.shields.io/badge/version-0.1.1-blue)](https://github.com/da8ter/HaSync)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-8.1%2B-orange)](https://symcon.de)

Ein Modul zur Integration von Home Assistant in IP-Symcon mit automatischer Geräteerkennung, Echtzeitaktualisierung und bidirektionaler Kommunikation.

## 🌟 Features

- ✅ **Automatische HA Geräteerkennung** über REST API Configurator
- ✅ **Echtzeitaktualisierung** über MQTT
- ✅ **Intelligente Typerkennung** für verschiedene Home Assistant Entitäten
- ✅ **Bidirektionale Kommunikation** - Steuern von HA-Geräten aus IP-Symcon
- ✅ **Icon-Mapping** von Home Assistant zu IP-Symcon
- ✅ **Variablen-Präsentationen** (z. B. Schalter/Slider) passend zur Entität
- ✅ **Zweisprachige Lokalisierung** (DE/EN)

## 📦 Module

### HaConfigurator - REST API Configurator
**Typ:** Configurator (Typ 4)  

- Verbindung zu Home Assistant über REST API
- Automatische Geräteerkennung und Configurator
- Geräteerstellung (HaDevice) und Multi-Entitäten-Assistent

### HaDevice - Entitäts-Repräsentation
**Typ:** Device (Typ 3)  

- Repräsentiert einzelne Home Assistant Entitäten
- Automatische Variablenerstellung mit intelligenter Typerkennung
- Bidirektionale Kommunikation (Lesen/Schreiben)
- Unterstützt alle gängigen HA-Domains (light, switch, sensor, etc.)

### HaMultiEntityDevice - Mehrere Entitäten in einer Instanz
**Typ:** Device (Typ 3)  

- Bündelt mehrere Home Assistant Entitäten in einer Instanz
- Erzeugt pro Entität eine Status-Variable (`STAT_*`)
- Optional zusätzliche Attribut-Variablen (`HAS_*`) inkl. Lokalisierung (DE/EN)

### HaBridge - MQTT Echtzeit-Integration
**Typ:** Splitter (Typ 2)  

- Echtzeitaktualisierung über MQTT
- Zentrale Konfiguration für Home Assistant URL und Token


## 🚀 Installation

### 1. Über den IP-Symcon Module Store

Im Module Store **exakt** nach `HaSync` suchen und das Modul installieren.

### 2. Über Module Control (URL)

In Module Control folgende URL hinzufügen:

`https://github.com/da8ter/HaSync.git`

## ⚙️ Konfiguration

### Schritt 1: Home Assistant Token erstellen

1. Home Assistant aufrufen → **Profil** → **Sicherheit** → **Langlebige Zugriffstoken**
2. **Token erstellen** → Name vergeben (z.B. "IP-Symcon")
3. Token kopieren und sicher aufbewahren

### Schritt 2: MQTT in Home Assistant per UI einrichten

Hinweis: Es wird ausschließlich der IP-Symcon MQTT Server als Broker verwendet. Ein zusätzlicher externer Broker (z. B. Mosquitto) ist nicht erforderlich.

1. In Home Assistant öffnen: **Einstellungen** → **Geräte & Dienste** → **Integration hinzufügen** → nach „MQTT“ suchen und auswählen.
2. Verbindungstyp wählen: **Externer Broker** (Home Assistant verbindet sich als Client zum IP-Symcon MQTT Server).
3. Broker eintragen:
   - Host/Adresse: IP oder Hostname des IP-Symcon-Systems (auf dem der „MQTT Server“ läuft)
   - Port: `1883`
   - Benutzername/Passwort: nur ausfüllen, wenn im IP‑Symcon „MQTT Server“ entsprechende Zugangsdaten konfiguriert sind. Ansonsten leer lassen.
4. Erweiterte Optionen öffnen und prüfen:
   - Birth Message (optional, empfohlen):
     - Topic: `homeassistant/status`
     - Payload: `online`
   - Will Message (optional, empfohlen):
     - Topic: `homeassistant/status`
     - Payload: `offline`
5. Speichern/Absenden. Die Integration sollte jetzt verbunden sein.
6. In IP‑Symcon die **HaBridge**-Instanz erstellen/prüfen (siehe unten):
   - **Instanz hinzufügen** → **HaBridge**
   - **Schnittstelle**: den **MQTT Server** auswählen
   - **Home Assistant URL** (z. B. `http://192.168.1.100:8123`)
   - **Home Assistant Token** (Long-lived Access Token)
   - „Discovery Prefix“: `homeassistant` (Standard)
   - Übernehmen

### Schritt 3: MQTT State Stream (configuration.yaml) aktivieren

Füge in der Home Assistant `configuration.yaml` folgenden Abschnitt ein, damit Zustände und Attribute per MQTT veröffentlicht werden:

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

Anschließend Home Assistant neu starten.

### Schritt 4: HaConfigurator konfigurieren

1. **Instanz hinzufügen** → **HaConfigurator**
2. Gewünschte Geräte auswählen und **Erstellen** klicken

## 🔧 Unterstützte Entitätstypen

| Domain | Variablentyp | Präsentation | Editierbar | Beispiel |
|--------|--------------|--------------|------------|----------|
| `light` | Boolean | Schalter | ✅ | Licht an/aus |
| `switch` | Boolean | Schalter | ✅ | Schalter |
| `input_boolean` | Boolean | Schalter | ✅ | Input Boolean |
| `binary_sensor` | Boolean | Anzeige (read-only) | ❌ | Bewegungsmelder |
| `sensor` | Automatisch¹ | Anzeige (read-only) | ❌ | Temperatur, Feuchtigkeit |
| `input_number` | Float | Slider | ✅ | Schieberegler |
| `number` | Float | Slider | ✅ | Schieberegler |
| `device_tracker` | Boolean | Anzeige (read-only) | ❌ | Anwesenheit |
| `automation` | Boolean | Anzeige (read-only) | ❌ | Automation |

¹ Automatische Erkennung basierend auf Attributen und Werten

## 📊 Intelligente Typerkennung

Das HaDevice Modul erkennt automatisch den korrekten Variablentyp:

- **Temperatur-Attribute** (`temperature`, `current_temperature`) → Float; Anzeige mit passender Einheit
- **Feuchtigkeit** (`humidity`) → Float/Integer; Anzeige mit Einheit  
- **Helligkeit** (`brightness`, `illuminance`) → Integer/Float je nach Quelle
- **Prozent-Werte** (`battery_level`, `position`) → Integer/Float je nach Bereich (0–100/0–255)
- **Boolean-Domains** (light, switch) → Boolean; Schalter falls editierbar
- **Numerische Werte** → Automatische Erkennung (Integer/Float)
- **Fallback** → String

## 🔄 Funktionsweise

### MQTT Echtzeit-Updates (HaBridge)
- Sofortige Aktualisierung bei Änderungen
- Automatische Weiterleitung an HaDevice Instanzen

### Bidirektionale Steuerung
- IP-Symcon → Home Assistant über REST API Service Calls
- Automatische Bestimmung des korrekten Services
- Unterstützt alle editierbaren Entitäten

## 📋 Statuscodes

| Code | Status | Beschreibung |
|------|--------|-------------|
| 102 | ✅ OK | Modul funktioniert korrekt |
| 104 | ⚠️ Fehler | Keine Verbindung zu Home Assistant |
| 201 | ❌ Fehler | Konfigurationsfehler |
| 202 | ⚠️ Warnung | Teilweise Funktionalität |

## 📄 Lizenz

MIT License - siehe [LICENSE](LICENSE) für Details.