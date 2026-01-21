# HaConfigurator

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
* Unterstützt die Erstellung eines `HaMultiEntityDevice` aus einer Auswahl.

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- Home Assistant mit aktivierter REST API

### 3. Software-Installation

* Über den Module Store **exakt** nach `HaSync` suchen und das Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/da8ter/HaSync.git`

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'HaConfigurator'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Geräte (Configurator) | Liste der gefundenen Entitäten mit Möglichkeit zur Erstellung von `HaDevice`-Instanzen
Multi-Entitäten-Gerät erstellen | Assistent zum Erstellen eines `HaMultiEntityDevice` aus mehreren Entitäten

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Dieses Modul legt keine eigenen Statusvariablen an.

#### Profile

Keine (nicht zutreffend)