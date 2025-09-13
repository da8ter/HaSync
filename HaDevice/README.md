# HaDevice
Beschreibung des Moduls.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Repräsentiert eine einzelne Home Assistant Entität (z. B. `light.x`, `sensor.y`).
* Legt automatisch eine Status-Variable an und erkennt den passenden Variablentyp.
* Optional: erstellt zusätzliche Attribut-Variablen (gekennzeichnet mit `HAS_...`).
* Unterstützt Echtzeit-Updates über die `HaBridge` (MQTT) sowie Abruf über den `HaConfigurator` (REST).
* Bidirektional: Änderungen an editierbaren Entitäten (z. B. `light`, `switch`, `input_number`) werden an Home Assistant gesendet.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1

### 3. Software-Installation

* Über den Module Store das 'HaDevice'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'HaDevice'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Entity ID | Vollständige Home Assistant Entity-ID (z. B. `light.wohnzimmer`)
Home Assistant Verbindung (automatisch) | Referenz auf `HaConfigurator`-Instanz (wird automatisch gesetzt)
Create additional variables | Wenn aktiv, werden zusätzliche Attribut-Variablen (`HAS_...`) erzeugt

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name   | Typ     | Beschreibung
------ | ------- | ------------
Status | Dynamisch (Boolean/Integer/Float/String) | Hauptstatus der Entität. Typ wird automatisch bestimmt und aktualisiert.
HAS_*  | Variabel | Optionale, versteckte Zusatzvariablen für Attribute (nur wenn aktiviert)

#### Profile

Es werden keine klassischen Variablenprofile gesetzt. Stattdessen nutzt das Modul moderne Variablen-Präsentationen (z. B. Schalter, Slider), die automatisch anhand der Entität/Attribute gewählt werden.

### 6. WebFront

Die Status-Variable ist sichtbar und – sofern editierbar – bedienbar (z. B. Schalter/Slider). Zusätzliche `HAS_*`-Variablen sind standardmäßig verborgen und dienen der Verarbeitung eingehender Attribute.

### 7. PHP-Befehlsreferenz

- `bool HADE_ProcessMQTTStateUpdate(integer $InstanzID, string $JsonPayload);`
  Verarbeitet einen eingehenden MQTT-Zustands- bzw. Attribut-Payload für diese Instanz. Normalerweise wird dieser Befehl automatisch durch die `HaBridge` aufgerufen; ein manueller Aufruf ist nur zu Test-/Diagnosezwecken nötig.

  Beispiel:

  ```php
  $payload = json_encode([
      'entity_id'  => 'light.wohnzimmer',
      'state'      => 'on',
      'attributes' => ['brightness' => 200]
  ]);
  HADE_ProcessMQTTStateUpdate(12345, $payload);
  ```