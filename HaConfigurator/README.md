# HaConfigurator
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

* Stellt die Verbindung zu Home Assistant per REST API her.
* Listet alle Entitäten in einem Configurator auf (inkl. Name und Status).
* Erstellt auf Wunsch automatisch `HaDevice`-Instanzen für ausgewählte Entitäten.
* Verwaltet Verbindungsparameter (URL, Long-lived Access Token).

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- Home Assistant mit aktivierter REST API

### 3. Software-Installation

* Über den Module Store das 'HaConfigurator'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'HaConfigurator'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Home Assistant URL | Basis-URL von Home Assistant (z. B. `http://192.168.1.10:8123`)
Home Assistant Token | Long-lived Access Token aus dem HA-Profil
Geräte (Configurator) | Liste der gefundenen Entitäten mit Möglichkeit zur Erstellung von `HaDevice`-Instanzen

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Dieses Modul legt keine eigenen Statusvariablen an.

#### Profile

Keine (nicht zutreffend)

### 6. Visualisierung

Der Configurator erscheint im Formular der Instanz. Über die Spalte „Erstellen“ können `HaDevice`-Instanzen pro Entität angelegt werden.

### 7. PHP-Befehlsreferenz

Die nachfolgenden Befehle stehen (u. a.) zur Verfügung:

- `mixed HACO_FetchDevices(integer $InstanzID);`
  Ruft alle Entitäten aus Home Assistant ab (entspricht `/api/states`).

- `mixed HACO_GetEntityState(integer $InstanzID, string $EntityID);`
  Ruft den Zustand einer einzelnen Entität ab (entspricht `/api/states/{entity_id}`).

- `void HACO_UpdateConfigurator(integer $InstanzID);`
  Aktualisiert die Anzeige des Configurators in der Instanz.