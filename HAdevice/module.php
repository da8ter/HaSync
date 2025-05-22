<?php

declare(strict_types=1);
	class HAdevice extends IPSModule
	{
		public function Create()
		{
			//Never delete this line!
			parent::Create();
			$this->RegisterPropertyString('entity_id', '');
			$this->RegisterPropertyInteger('parent_id', 0);
		}

		public function GetConfigurationForm()
		{
			$form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
			$haConfig = $this->GetHAConfig();
			$devices = $this->FetchDevices($haConfig);
			$listData = [];
			if (is_array($devices)) {
				foreach ($devices as $entity) {
					$listData[] = [
						'entity_id' => $entity['entity_id'] ?? '',
						'friendly_name' => $entity['attributes']['friendly_name'] ?? '',
						'state' => $entity['state'] ?? ''
					];
				}
			}
			foreach ($form['elements'] as &$element) {
				if (isset($element['name']) && $element['name'] === 'DeviceSelect') {
					$element['values'] = $listData;
				}
			}
			return json_encode($form);
		}

		public function CreateSymconDevice(array $selectedRows)
		{
			if (count($selectedRows) !== 1) {
				$this->SendDebug('CreateSymconDevice', 'Bitte genau ein Gerät auswählen.', 0);
				return;
			}
			$entityId = $selectedRows[0]['entity_id'] ?? '';
			if ($entityId === '') {
				$this->SendDebug('CreateSymconDevice', 'entity_id fehlt.', 0);
				return;
			}
			$haConfig = $this->GetHAConfig();
			$devices = $this->FetchDevices($haConfig);
			$device = null;
			foreach ($devices as $entity) {
				if (($entity['entity_id'] ?? '') === $entityId) {
					$device = $entity;
					break;
				}
			}
			if ($device === null) {
				$this->SendDebug('CreateSymconDevice', 'Gerät nicht gefunden.', 0);
				return;
			}
			// Haupt-Statusvariable
			$this->RegisterVariableString('State', 'Status');
			$this->SetValue('State', $device['state'] ?? '');
			// Attribute als Variablen
			if (isset($device['attributes']) && is_array($device['attributes'])) {
				foreach ($device['attributes'] as $key => $value) {
					$ident = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
					$this->RegisterVariableString($ident, $key);
					$this->SetValue($ident, is_scalar($value) ? (string)$value : json_encode($value));
				}
			}
		}

		private function GetHAConfig()
		{
			// Property parent_id verwenden statt ConnectionID
			$parentID = $this->ReadPropertyInteger('parent_id');
			$this->SendDebug('GetHAConfig', 'Parent ID aus Property: ' . $parentID, 0);
			
			if ($parentID === 0) {
				$this->SendDebug('GetHAConfig', 'Keine parent_id gesetzt!', 0);
				return ['url' => '', 'token' => ''];
			}
			
			if (!IPS_InstanceExists($parentID)) {
				$this->SendDebug('GetHAConfig', 'Parent-Instanz mit ID ' . $parentID . ' existiert nicht!', 0);
				return ['url' => '', 'token' => ''];
			}
			
			$parentInfo = IPS_GetInstance($parentID);
			$this->SendDebug('GetHAConfig', 'Parent info: ' . json_encode($parentInfo), 0);
			$url = @IPS_GetProperty($parentID, 'ha_url');
			$token = @IPS_GetProperty($parentID, 'ha_token');
			if ($url === false || $token === false) {
				$this->SendDebug('GetHAConfig', 'Konnte URL oder Token vom Parent nicht lesen!', 0);
				return ['url' => '', 'token' => ''];
			}
			return ['url' => $url, 'token' => $token];
		}

		private function FetchDevices($haConfig)
		{
			if ($haConfig['url'] === '' || $haConfig['token'] === '') {
				return [];
			}
			$url = $haConfig['url'] ?? '';
			$token = $haConfig['token'] ?? '';
			if (!is_string($url) || $url === '' || !is_string($token) || $token === '') {
				$this->SendDebug('FetchDevices', 'URL oder Token nicht gesetzt oder ungültig', 0);
				return [];
			}
			$apiUrl = rtrim($url, '/') . '/api/states';
			$opts = [
				'http' => [
					'header' => [
						'Authorization: Bearer ' . $token,
						'Content-Type: application/json'
					],
					'method' => 'GET',
					'timeout' => 10
				]
			];
			$context = stream_context_create($opts);
			$result = @file_get_contents($apiUrl, false, $context);
			if ($result === false) {
				$this->SendDebug('FetchDevices', 'Fehler beim Abrufen der Geräte', 0);
				return [];
			}
			$data = json_decode($result, true);
			if (!is_array($data)) {
				$this->SendDebug('FetchDevices', 'Antwort ist kein gültiges JSON', 0);
				return [];
			}
			return $data;
		}


		public function Destroy()
		{
			//Never delete this line!
			parent::Destroy();
		}

		/**
		 * Bestimmt den passenden Variablentyp basierend auf dem Inhalt und Attributnamen
		 * @param string $attributeName Name des Attributs
		 * @param mixed $value Wert des Attributs
		 * @param string $entityDomain Domain des Geräts (z.B. 'light', 'switch')
		 * @return array [Variablentyp, Wert konvertiert, Variablenprofil, Bearbeitbar]
		 */
		private function DetermineVariableType($attributeName, $value, $entityDomain = '')
		{
			// Standard: String ohne Profil, nicht bearbeitbar
			$varType = 3; // VARIABLETYPE_STRING
			$convertedValue = is_scalar($value) ? (string)$value : json_encode($value);
			$profile = '';
			$editable = false; // Standardmäßig nicht bearbeitbar
			
			// Erst prüfen, ob der Wert ein boolescher Wert sein könnte
			if (is_string($value)) {
				$lowerValue = strtolower($value);
				
				// Boolean für on/off, true/false
				if (in_array($lowerValue, ['on', 'off', 'true', 'false', 'yes', 'no', '1', '0', 'active', 'inactive'])) {
					$varType = 0; // VARIABLETYPE_BOOLEAN
					$convertedValue = in_array($lowerValue, ['on', 'true', 'yes', '1', 'active']);
					$profile = '~Switch';
					
					// Steuerbare Entitäten
					if (in_array($entityDomain, ['light', 'switch', 'input_boolean']) && $attributeName === 'state') {
						$editable = true; // Status von Licht/Schaltern ist steuerbar
					}
				}
				// Hauptstate von Lichtern immer als Boolean
				elseif ($attributeName === 'state' && in_array($entityDomain, ['light', 'switch', 'binary_sensor'])) {
					$varType = 0; // VARIABLETYPE_BOOLEAN
					$convertedValue = ($lowerValue === 'on');
					$profile = '~Switch';
					
					// Licht und Schalter sind steuerbar, Sensoren nicht
					if (in_array($entityDomain, ['light', 'switch', 'input_boolean'])) {
						$editable = true;
					}
				}
			}
			
			// Prüfe numerische Werte
			if (is_numeric($value)) {
				// Float mit Nachkommastellen
				if (strpos($value, '.') !== false) {
					$varType = 2; // VARIABLETYPE_FLOAT
					$convertedValue = (float)$value;
					
					// Spezifische Profile für bekannte Attributnamen
					if (stripos($attributeName, 'temp') !== false) {
						$profile = '~Temperature';
					} elseif (stripos($attributeName, 'humid') !== false) {
						$profile = '~Humidity';
					} elseif (stripos($attributeName, 'pressure') !== false) {
						$profile = '~AirPressure';
					} elseif (stripos($attributeName, 'bright') !== false) {
						$profile = '~Illumination';
					}
				} else {
					// Integer ohne Nachkommastellen
					$varType = 1; // VARIABLETYPE_INTEGER
					$convertedValue = (int)$value;
					
					// Spezifische Profile für bekannte Attributnamen
					if (stripos($attributeName, 'percent') !== false || stripos($attributeName, 'level') !== false 
						|| stripos($attributeName, 'brightness') !== false || stripos($attributeName, 'volume') !== false) {
						$profile = '~Intensity.100';
					}
				}
			}
			
			// Steuerbare Attribute
			if ($attributeName === 'brightness' && $entityDomain === 'light') {
				$editable = true; // Helligkeit ist bei Lichtern steuerbar
			} elseif ($attributeName === 'color_temp' && $entityDomain === 'light') {
				$editable = true; // Farbtemperatur ist bei Lichtern steuerbar
			} elseif ($attributeName === 'volume_level' && in_array($entityDomain, ['media_player'])) {
				$editable = true; // Lautstärke ist bei Media Playern steuerbar
			}
			
			// Spezielle Behandlung nach Attributnamen
			switch (strtolower($attributeName)) {
				case 'state':
					// Domains, die numerische Werte haben
					if (in_array($entityDomain, ['sensor', 'climate'])) {
						if (is_numeric($value)) {
							if (strpos($value, '.') !== false) {
								$varType = 2; // VARIABLETYPE_FLOAT
								$convertedValue = (float)$value;
							} else {
								$varType = 1; // VARIABLETYPE_INTEGER
								$convertedValue = (int)$value;
							}
						}
					}
					break;
					
				case 'temperature':
					$varType = 2; // VARIABLETYPE_FLOAT
					$convertedValue = (float)$value;
					$profile = '~Temperature';
					break;
					
				case 'humidity':
					if (is_numeric($value)) {
						$varType = 2; // VARIABLETYPE_FLOAT
						$convertedValue = (float)$value;
						$profile = '~Humidity';
					}
					break;
					
				case 'brightness':
				case 'brightness_pct':
					if (is_numeric($value)) {
						$varType = 1; // VARIABLETYPE_INTEGER
						$convertedValue = (int)$value;
						$profile = '~Intensity.100';
						if ($entityDomain === 'light') {
							$editable = true;
						}
					}
					break;
			}
			
			return [$varType, $convertedValue, $profile, $editable];
		}
		
		/**
		 * Mappt ein Home Assistant Icon auf ein passendes IP-Symcon Icon
		 * @param string $haIcon Das Home Assistant Icon (ohne mdi: Präfix)
		 * @return string Symcon Icon ID
		 */
		private function MapHAIconToSymcon($haIcon)
		{
			// HA Icon aus mdi:xxx extrahieren
			if (strpos($haIcon, 'mdi:') === 0) {
				$haIcon = substr($haIcon, 4); // "mdi:" entfernen
			}
			
			// Mapping der häufigsten Icons
			$iconMap = [
				// Licht/Lampen
				'lightbulb' => 'Light',
				'ceiling-light' => 'Light',
				'lamp' => 'Light',
				'floor-lamp' => 'Light',
				
				// Sensoren
				'thermometer' => 'Temperature',
				'temperature-celsius' => 'Temperature',
				'water-percent' => 'Humidity',
				'weather-sunny' => 'Sun',
				'weather-cloudy' => 'Cloud',
				'weather-rainy' => 'Rainfall',
				'weather-windy' => 'WindSpeed',
				
				// Geräte
				'television' => 'TV',
				'speaker' => 'Speaker',
				'air-conditioner' => 'Temperature',
				'fan' => 'Fan',
				'power-socket' => 'Power',
				'power-plug' => 'Power',
				'power' => 'Power',
				
				// Personen/Sicherheit
				'account' => 'User',
				'account-multiple' => 'Group',
				'shield-home' => 'Alert',
				'bell' => 'Warning',
				'lock' => 'Lock',
				'door' => 'Door',
				'window-open' => 'Window',
				'window-closed' => 'Window',
				
				// Sonstiges
				'home' => 'HouseA',
				'water' => 'Waterdrop',
				'battery' => 'Battery',
				'battery-10' => 'Battery',
				'battery-20' => 'Battery',
				'battery-50' => 'Battery',
				'battery-80' => 'Battery',
				'battery-100' => 'Battery',
				'battery-charging' => 'Battery',
				'state-machine' => 'Status',
				'timer' => 'Clock'
			];
			
			// Prüfe, ob Icon im Mapping vorhanden
			if (isset($iconMap[$haIcon])) {
				return $iconMap[$haIcon];
			}
			
			// Standardwert zurückgeben
			return 'Status';
		}
		
		/**
		 * Erstellt die Variablen für ein Gerät direkt ohne auf ApplyChanges zu warten.
		 * Diese Methode kann direkt nach dem Setzen der Properties aufgerufen werden.
		 */
		public function CreateVariables()
		{
			$entityId = $this->ReadPropertyString('entity_id');
			$this->SendDebug('CreateVariables', 'entity_id: ' . var_export($entityId, true), 0);

			if ($entityId === '') {
				$this->SendDebug('CreateVariables', 'Keine entity_id gesetzt, Variablen werden entfernt.', 0);
				IPS_LogMessage('HAdevice', 'entity_id nicht gesetzt, keine Variablen erstellt.');
				$this->UnregisterAllVariables();
				return;
			}
			
			// Mindestens die Status-Variable erstellen, auch wenn keine Verbindung zu Home Assistant besteht
			$this->RegisterVariableString('State', 'Status');
			$this->SetValue('State', 'Warte auf Daten / Waiting for data');
			$this->SendDebug('CreateVariables', 'Basis-State-Variable erstellt', 0);

			$haConfig = $this->GetHAConfig();
			$this->SendDebug('CreateVariables', 'HAConfig: ' . json_encode($haConfig), 0);
			if (!is_array($haConfig) || $haConfig['url'] === '' || $haConfig['token'] === '') {
				$this->SendDebug('CreateVariables', 'HAConfig ungültig, Variablen werden entfernt.', 0);
				IPS_LogMessage('HAdevice', 'Home Assistant Konfiguration ungültig oder nicht verbunden, keine Variablen erstellt.');
				$this->UnregisterAllVariables();
				return;
			}

			$devices = $this->FetchDevices($haConfig);
			$this->SendDebug('CreateVariables', 'Anzahl gefundener Devices: ' . count($devices), 0);
			$device = null;
			foreach ($devices as $entity) {
				if (($entity['entity_id'] ?? '') === $entityId) {
					$device = $entity;
					break;
				}
			}
			if ($device === null) {
				$this->SendDebug('CreateVariables', 'Gerät mit entity_id nicht gefunden: ' . $entityId, 0);
				IPS_LogMessage('HAdevice', 'Gerät nicht gefunden: ' . $entityId);
				$this->UnregisterAllVariables();
				return;
			}
			$this->SendDebug('CreateVariables', 'Gerät gefunden: ' . ($device['attributes']['friendly_name'] ?? $entityId), 0);

			// Entity-Domain extrahieren (z.B. 'light' aus 'light.bedroom')
			$entityDomain = '';
			if (strpos($entityId, '.') !== false) {
				$entityDomain = substr($entityId, 0, strpos($entityId, '.'));
				$this->SendDebug('CreateVariables', 'Entity-Domain: ' . $entityDomain, 0);
			}

			// Icon extrahieren, falls vorhanden
			$icon = '';
			if (isset($device['attributes']['icon'])) {
				$icon = $this->MapHAIconToSymcon($device['attributes']['icon']);
				$this->SendDebug('CreateVariables', 'Icon gemappt: ' . $device['attributes']['icon'] . ' -> ' . $icon, 0);
			}

			// Haupt-Statusvariable mit automatischer Typerkennung
			$varInfo = $this->DetermineVariableType('state', $device['state'] ?? '', $entityDomain);
			$varType = $varInfo[0];
			$convertedValue = $varInfo[1];
			$profile = $varInfo[2];
			$editable = $varInfo[3];
			$this->SendDebug('CreateVariables', 'Status-Variable Typ: ' . $varType . ', Profil: ' . $profile, 0);
			
			// Variable passend zum ermittelten Typ registrieren
			$stateVarId = 0;
			switch ($varType) {
				case 0: // Boolean
					$stateVarId = $this->RegisterVariableBoolean('State', 'Status', $profile);
					break;
				case 1: // Integer
					$stateVarId = $this->RegisterVariableInteger('State', 'Status', $profile);
					break;
				case 2: // Float
					$stateVarId = $this->RegisterVariableFloat('State', 'Status', $profile);
					break;
				case 3: // String
				default:
					$stateVarId = $this->RegisterVariableString('State', 'Status', $profile);
					break;
			}
			
			// Wert setzen
			$this->SetValue('State', $convertedValue);
			
			// Für editierbare Variablen EnableAction aktivieren
			if ($editable) {
				$this->EnableAction('State');
				$this->SendDebug('CreateVariables', 'EnableAction für State aktiviert', 0);
			}
			
			// Icon setzen, falls vorhanden
			if ($icon !== '') {
				IPS_SetIcon($stateVarId, $icon);
				$this->SendDebug('CreateVariables', 'Icon für Status-Variable gesetzt: ' . $icon, 0);
			}

			// Attribute als Variablen (Icon-Attribut überspringen)
			if (isset($device['attributes']) && is_array($device['attributes'])) {
				foreach ($device['attributes'] as $key => $value) {
					// Icon-Attribut nicht als Variable erstellen
					if ($key === 'icon') {
						$this->SendDebug('CreateVariables', 'Icon-Attribut wird nicht als Variable erstellt', 0);
						continue;
					}
					
					// Variablen-Ident generieren
					$ident = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
					
					// Typ automatisch bestimmen
					$varInfo = $this->DetermineVariableType($key, $value, $entityDomain);
					$varType = $varInfo[0];
					$convertedValue = $varInfo[1];
					$profile = $varInfo[2];
					$editable = $varInfo[3];
					$this->SendDebug('CreateVariables', 'Variable "' . $key . '" Typ: ' . $varType . ', Profil: ' . $profile, 0);
					
					// Variable basierend auf Typ registrieren
					switch ($varType) {
						case 0: // Boolean
							$this->RegisterVariableBoolean($ident, $key, $profile);
							break;
						case 1: // Integer
							$this->RegisterVariableInteger($ident, $key, $profile);
							break;
						case 2: // Float
							$this->RegisterVariableFloat($ident, $key, $profile);
							break;
						case 3: // String
						default:
							$this->RegisterVariableString($ident, $key, $profile);
							break;
					}
					
					// Wert setzen
					$this->SetValue($ident, $convertedValue);
					
					// Für editierbare Variablen EnableAction aktivieren
					if ($editable) {
						$this->EnableAction($ident);
						$this->SendDebug('CreateVariables', 'EnableAction für ' . $ident . ' aktiviert', 0);
					}
					
					$this->SendDebug('CreateVariables', 'Variable gesetzt: ' . $ident . ' = ' . (is_scalar($convertedValue) ? (string)$convertedValue : json_encode($convertedValue)), 0);
				}
			} else {
				$this->SendDebug('CreateVariables', 'Keine Attribute gefunden, keine weiteren Variablen erstellt.', 0);
			}
			IPS_LogMessage('HAdevice', 'Variablen für "' . $entityId . '" erstellt.');
		}

		public function ApplyChanges()
		{
			parent::ApplyChanges();
			// Rufe die CreateVariables-Methode auf
			$this->CreateVariables();
		}
		
		/**
		 * Empfängt eine RequestAction vom Parent oder scripten
		 * @param string $ident Die zu behandelnde Aktion
		 * @param mixed $value Der Wert (optional)
		 * @return boolean Erfolgsstatus
		 */
		public function RequestAction($ident, $value)
		{
			$this->SendDebug('RequestAction', 'Ident: ' . $ident . ', Value: ' . var_export($value, true), 0);
			
			// Prüfe auf CreateVariables-Aufruf (für Kompatibilität)
			if ($ident === 'CreateVariables') {
				$this->CreateVariables();
				return true;
			}
			
			// Prüfe auf UpdateFromHA-Aufruf (Aktualisierung von Home Assistant)
			if ($ident === 'UpdateFromHA') {
				$this->SendDebug('RequestAction', 'UpdateFromHA aufgerufen mit Daten', 0);
				$this->UpdateFromHA($value);
				return true;
			}
			
			// Sonst: Lokalen Wert setzen
			$this->SetValue($ident, $value);
			
			$entityId = $this->ReadPropertyString('entity_id');
			if ($entityId === '') {
				$this->SendDebug('RequestAction', 'Keine entity_id gesetzt, kann Wert nicht an Home Assistant senden', 0);
				return false;
			}
			
			// Bestimme Service basierend auf Variable und Wert
			$service = '';
			$data = [];
			
			// Domain aus entity_id extrahieren (z.B. "light" aus "light.living_room")
			$domain = '';
			if (strpos($entityId, '.') !== false) {
				$domain = substr($entityId, 0, strpos($entityId, '.'));
			}
			
			// Für die Status-Variable
			if ($ident === 'State') {
				// Service für Domäne und Wert bestimmen
				if (in_array($domain, ['light', 'switch', 'input_boolean'])) {
					// Boolean-Werte für on/off Services
					$service = $domain . '.' . ($value ? 'turn_on' : 'turn_off');
				}
			}
			// Für Attributvariablen
			elseif (strpos($ident, 'attr_') === 0) {
				// Attributname aus Ident extrahieren
				$attr = substr($ident, 5); // entferne 'attr_'
				
				// Spezifische Services für bestimmte Attribute
				if ($domain === 'light') {
					switch ($attr) {
						case 'brightness':
						case 'brightness_pct':
							$service = 'light.turn_on';
							$data = [
								'entity_id' => $entityId,
								$attr => $value
							];
							break;
							
						case 'color_temp':
							$service = 'light.turn_on';
							$data = [
								'entity_id' => $entityId,
								'color_temp' => $value
							];
							break;
					}
				}
				elseif ($domain === 'media_player') {
					switch ($attr) {
						case 'volume_level':
							$service = 'media_player.volume_set';
							$data = [
								'entity_id' => $entityId,
								'volume_level' => $value
							];
							break;
					}
				}
			}
			
			// An Home Assistant senden, falls Service definiert
			if ($service !== '') {
				// Wenn keine Daten gesetzt wurden, setze entity_id
				if (empty($data)) {
					$data = ['entity_id' => $entityId];
				}
				
				$this->SendDebug('RequestAction', 'Sende an Home Assistant - Service: ' . $service . ', Data: ' . json_encode($data), 0);
				
				// Home Assistant API aufrufen
				$result = $this->CallHomeAssistantService($service, $data);
				return $result !== false;
			}
			
			$this->SendDebug('RequestAction', 'Kein passender Service für ' . $ident . ' gefunden', 0);
			return false;
		}
		
		/**
		 * Aktualisiert die Variablen mit Daten von Home Assistant (WebSocket-Update)
		 * @param string $stateJson JSON-String mit den Statusdaten von Home Assistant
		 * @return boolean Erfolgsstatus
		 */
		public function UpdateFromHA($stateJson)
		{
			$this->SendDebug('UpdateFromHA', 'Empfangene Daten: ' . $stateJson, 0);
			
			// Daten parsen
			$stateData = json_decode($stateJson, true);
			if (!is_array($stateData)) {
				$this->SendDebug('UpdateFromHA', 'Ungültiges JSON', 0);
				return false;
			}
			
			// Entity ID prüfen
			$entityId = $this->ReadPropertyString('entity_id');
			if ($entityId === '') {
				$this->SendDebug('UpdateFromHA', 'Keine entity_id gesetzt', 0);
				return false;
			}
			
			// Domain extrahieren
			$entityDomain = '';
			if (strpos($entityId, '.') !== false) {
				$entityDomain = substr($entityId, 0, strpos($entityId, '.'));
			}
			
			// Hauptstatus aktualisieren
			$state = $stateData['state'] ?? '';
			$this->SendDebug('UpdateFromHA', 'Neuer Status: ' . $state, 0);
			
			// Typ bestimmen und Wert konvertieren
			$varInfo = $this->DetermineVariableType('state', $state, $entityDomain);
			$varType = $varInfo[0];
			$convertedValue = $varInfo[1];
			
			// Status-Variable aktualisieren (ohne Ereignis auszulösen, wenn Wert gleich ist)
			$this->SetValue('State', $convertedValue);
			
			// Attribute aktualisieren
			if (isset($stateData['attributes']) && is_array($stateData['attributes'])) {
				foreach ($stateData['attributes'] as $key => $value) {
					// Icon-Attribut überspringen
					if ($key === 'icon') {
						continue;
					}
					
					// Variablen-Ident generieren
					$ident = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
					
					// Prüfen, ob wir diese Variable haben
					$varId = @$this->GetIDForIdent($ident);
					if ($varId === false) {
						$this->SendDebug('UpdateFromHA', 'Variable ' . $ident . ' nicht gefunden, wird übersprungen', 0);
						continue;
					}
					
					// Typ bestimmen und Wert konvertieren
					$varInfo = $this->DetermineVariableType($key, $value, $entityDomain);
					$convertedValue = $varInfo[1];
					
					// Variable aktualisieren
					$this->SetValue($ident, $convertedValue);
					$this->SendDebug('UpdateFromHA', 'Attribut aktualisiert: ' . $ident . ' = ' . (is_scalar($convertedValue) ? (string)$convertedValue : json_encode($convertedValue)), 0);
				}
			}
			
			$this->SendDebug('UpdateFromHA', 'Update abgeschlossen', 0);
			return true;
		}
		
		/**
		 * Ruft einen Service in Home Assistant auf
		 * @param string $service Der aufzurufende Service (z.B. "light.turn_on")
		 * @param array $data Die zu übergebenden Daten (JSON)
		 * @return mixed Antwort oder false bei Fehler
		 */
		private function CallHomeAssistantService($service, $data)
		{
			$haConfig = $this->GetHAConfig();
			if (!is_array($haConfig) || $haConfig['url'] === '' || $haConfig['token'] === '') {
				$this->SendDebug('CallHomeAssistantService', 'Home Assistant Konfiguration ungültig', 0);
				return false;
			}
			
			// URL für den Service-Aufruf erstellen
			$url = rtrim($haConfig['url'], '/') . '/api/services/' . str_replace('.', '/', $service);
			
			// HTTP-Request vorbereiten
			$opts = [
				'http' => [
					'header' => [
						'Authorization: Bearer ' . $haConfig['token'],
						'Content-Type: application/json'
					],
					'method' => 'POST',
					'content' => json_encode($data),
					'timeout' => 10
				]
			];
			
			$context = stream_context_create($opts);
			
			// Request senden
			$result = @file_get_contents($url, false, $context);
			if ($result === false) {
				$this->SendDebug('CallHomeAssistantService', 'Fehler beim Aufruf von ' . $service, 0);
				return false;
			}
			
			// Antwort verarbeiten
			$response = json_decode($result, true);
			return $response;
		}
		
		private function UnregisterAllVariables()
		{
			$vars = IPS_GetChildrenIDs($this->InstanceID);
			foreach ($vars as $varID) {
				if (IPS_GetObject($varID)['ObjectType'] === 2) { // 2 = Variable
					IPS_DeleteVariable($varID);
				}
			}
		}
	}