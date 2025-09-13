# HaSync - Home Assistant Integration für IP-Symcon

[![Version](https://img.shields.io/badge/version-2.0.0-blue)](https://github.com/your-repo)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-7.1%2B-orange)](https://symcon.de)

Eine professionelle Bibliothek zur Integration von Home Assistant in IP-Symcon mit automatischer Geräteerkennung, Echtzeitaktualisierung und bidirektionaler Kommunikation.

## 🌟 Features

- ✅ **Automatische Geräteerkennung** über REST API Configurator
- ✅ **Echtzeitaktualisierung** über MQTT (optional)
- ✅ **Intelligente Typerkennung** für verschiedene Home Assistant Entitäten
- ✅ **Bidirektionale Kommunikation** - Steuern von HA-Geräten aus IP-Symcon
- ✅ **Saubere Architektur** mit getrennten Modulen für verschiedene Aufgaben
- ✅ **Icon-Mapping** von Home Assistant zu IP-Symcon
- ✅ **Moderne Variablen-Präsentationen** (z. B. Schalter/Slider) passend zur Entität
- ✅ **Zweisprachige Lokalisierung** (DE/EN)

## 📦 Module

### HaConfigurator - REST API Configurator
**Typ:** Configurator (Typ 4)  
**GUID:** `{32D99DCD-A530-4907-3FB0-44D7D472771D}`

- Verbindung zu Home Assistant über REST API
- Automatische Geräteerkennung und Configurator
- Verwaltung der Home Assistant Verbindungsparameter

### HaDevice - Entitäts-Repräsentation
**Typ:** Device (Typ 3)  
**GUID:** `{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}`

- Repräsentiert einzelne Home Assistant Entitäten
- Automatische Variablenerstellung mit intelligenter Typerkennung
- Bidirektionale Kommunikation (Lesen/Schreiben)
- Unterstützt alle gängigen HA-Domains (light, switch, sensor, etc.)

### HaBridge - MQTT Echtzeit-Integration
**Typ:** Splitter (Typ 2)  
**GUID:** `{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}`

- Echtzeitaktualisierung über MQTT
- Automatische Erkennung bestehender HaDevice Instanzen


## 🚀 Installation

### 1. Über IP-Symcon Store
-

### 2. Manuelle Installation

1. Modulecontrol öffnen und folgende URL hinzufügen: https://github.com/da8ter/HaSync.git

## ⚙️ Konfiguration

### Schritt 1: Home Assistant Token erstellen

1. Home Assistant aufrufen → **Profil** → **Long-lived access tokens**
2. **Create Token** → Name vergeben (z.B. "IP-Symcon")
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
   - „Discovery aktivieren“ einschalten (Enable discovery)
   - „Discovery Prefix“: `homeassistant` (Standard belassen)
   - Birth Message (optional, empfohlen):
     - Topic: `homeassistant/status`
     - Payload: `online`
   - Will Message (optional, empfohlen):
     - Topic: `homeassistant/status`
     - Payload: `offline`
5. Speichern/Absenden. Die Integration sollte jetzt verbunden sein.
6. Prüfung:
   - In Home Assistant unter **Einstellungen → Geräte & Dienste → MQTT** sollte der Verbindungsstatus „Verbunden“ anzeigen.
   - In IP‑Symcon in der „MQTT Server“-Instanz sollte Home Assistant als Client erscheinen.
7. In IP‑Symcon die **HaBridge**-Instanz erstellen/prüfen (siehe unten):
   - **Instanz hinzufügen** → **HaBridge**
   - **Parent**: den **MQTT Server** auswählen
   - „Discovery Prefix“: `homeassistant` (Standard)
   - Übernehmen

### Schritt 3: HaConfigurator konfigurieren

1. **Instanz hinzufügen** → **HaConfigurator**
2. **Home Assistant URL** eingeben (z. B. `http://192.168.1.100:8123`)
3. **Long-lived Access Token** einfügen
4. **Übernehmen** → Configurator öffnet sich automatisch
5. Gewünschte Geräte auswählen und **Erstellen** klicken

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

### REST API Polling (HaConfigurator)
- Regelmäßige Abfrage aller Entitätszustände
- Standard: 30 Sekunden Intervall
- Zuverlässig, aber nicht Echtzeit

### MQTT Echtzeit-Updates (HaBridge)
- Sofortige Aktualisierung bei Änderungen
- Automatische Weiterleitung an HaDevice Instanzen
- Unterstützt Discovery-Nachrichten

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

## 🛠️ Troubleshooting

### Verbindungsprobleme
- Home Assistant URL und Token prüfen
- Firewall-Einstellungen überprüfen
- Home Assistant API-Zugriff testen: `curl -H "Authorization: Bearer YOUR_TOKEN" http://YOUR_HA_URL/api/states`

### MQTT funktioniert nicht
- MQTT Server Modul korrekt konfiguriert?
- Home Assistant MQTT Integration aktiv?
- Discovery Topics richtig abonniert?

### Variablen werden nicht erstellt
- Entität in Home Assistant verfügbar?
- HaDevice Status-Variable vorhanden?
- Logs in IP-Symcon prüfen

## 🔗 Links

- [Home Assistant](https://www.home-assistant.io/)
- [IP-Symcon](https://www.symcon.de/)
- [MQTT Integration Guide](https://www.home-assistant.io/integrations/mqtt/)

## 📝 Changelog

### Version 2.0.0
- ✅ Komplette Code-Bereinigung und Optimierung
- ✅ Metadaten-Attribute Ausschluss implementiert
- ✅ MQTT Integration vollständig funktionsfähig
- ✅ Intelligente Typerkennung verbessert
- ✅ Debug-Ausgaben reduziert
- ✅ Dokumentation überarbeitet

## 📄 Lizenz

MIT License - siehe [LICENSE](LICENSE) für Details.

---

**Entwickelt von [Windsurf.io](https://windsurf.io) • Version 2.0.0 • 2025**