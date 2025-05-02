<?php

declare(strict_types=1);

class HomeassistantGateway extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        //Properties
        $this->RegisterPropertyString('MQTTTopic', 'homeassistant');
        $this->RegisterPropertyString('MQTTBroker', '');
        $this->RegisterPropertyInteger('MQTTBrokerInstanceID', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 300); // 5 Minuten Standard-Update-Intervall
        $this->RegisterPropertyBoolean('AutoCreateDevices', false); // Neue Eigenschaft: Geräte automatisch erstellen?
        $this->RegisterPropertyString('DeviceId', ''); // Gerät-ID für Konfigurator
        
        //Variables
        $this->RegisterVariableString('Status', 'Status', '~TextBox', 0);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 0);
        
        //Connect to available MQTT broker
        $this->ConnectParent('{D5C0D7CE-6A00-BDFC-2880-1ED4C08055E0}');
        
        //Registriere Timer für automatische Aktualisierung
        $this->RegisterTimer('UpdateDevices', 0, 'HA_UpdateDevices($_IPS[\'TARGET\']);');
        
        // Buffer für erkannte Geräte
        $this->RegisterAttributeString('DiscoveredDevices', '{}');
        
        // Buffer für registrierte Geräteinstanzen
        $this->RegisterAttributeString('RegisteredDevices', '{}');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Filter for MQTT messages
        $this->SetReceiveDataFilter('.*' . $this->ReadPropertyString('MQTTTopic') . '.*');
        
        //Setze Timer für automatische Aktualisierung
        $updateInterval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('UpdateDevices', $updateInterval * 1000);
        
        // Prüfe, ob wir eine Geräte-ID haben (für Konfigurator-erstellte Geräte)
        $deviceId = $this->ReadPropertyString('DeviceId');
        if (!empty($deviceId)) {
            // Bei Geräten aus dem Konfigurator: Prüfe, ob wir die Geräteinformationen haben
            // und aktualisiere entsprechend die Geräteinstanz
            $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
            if (isset($discoveredDevices[$deviceId])) {
                $deviceInfo = $discoveredDevices[$deviceId];
                $this->SendDebug('ApplyChanges', 'Gerät aus Konfigurator aktualisiert: ' . $deviceId, 0);
                
                // Gerätespezifische Einstellungen hier vornehmen
                // ...
            }
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', $JSONString, 0);
        
        //Process received MQTT data
        $topic = $data->Topic;
        $payload = $data->Payload;
        
        //Update status variable
        SetValue($this->GetIDForIdent('Status'), 'Letzte Nachricht: ' . date('Y-m-d H:i:s'));
        
        //Process the MQTT message
        $this->ProcessMQTTMessage($topic, $payload);
    }

    private function ProcessMQTTMessage($topic, $payload)
    {
        $this->SendDebug('ProcessMQTTMessage', 'Topic: ' . $topic . ' Payload: ' . $payload, 0);
        
        // Verarbeite Home Assistant Discovery Nachrichten
        if (strpos($topic, 'homeassistant/') === 0) {
            $this->ProcessDiscoveryMessage($topic, $payload);
            return;
        }
        
        // Verarbeite Status-Updates
        if (strpos($topic, 'homeassistant/status') === 0) {
            $this->ProcessStatusUpdate($payload);
            return;
        }
        
        // Verarbeite Kommandos
        if (strpos($topic, 'homeassistant/command') === 0) {
            $this->ProcessCommand($topic, $payload);
            return;
        }

        // Verarbeite Status-Updates von Geräten
        $this->ProcessDeviceStatusUpdate($topic, $payload);
    }

    private function ProcessDiscoveryMessage($topic, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            $this->SendDebug('ProcessDiscoveryMessage', 'Ungültiges JSON-Format', 0);
            return;
        }

        // Extrahiere Geräte-ID und Komponente aus dem Topic
        // Format: homeassistant/[component]/[node_id]/[object_id]/config
        $topicParts = explode('/', $topic);
        if (count($topicParts) < 5) {
            $this->SendDebug('ProcessDiscoveryMessage', 'Ungültiges Topic-Format', 0);
            return;
        }

        $component = $topicParts[1];
        $nodeId = $topicParts[2];
        $objectId = $topicParts[3];
        $deviceId = $nodeId . '_' . $objectId;

        $this->SendDebug('ProcessDiscoveryMessage', 'Komponente: ' . $component . ', Node: ' . $nodeId . ', Object: ' . $objectId, 0);

        // Speichere die Geräteinformationen statt sie sofort zu erstellen
        $deviceInfo = [
            'component' => $component,
            'node_id' => $nodeId,
            'object_id' => $objectId,
            'name' => $data['name'] ?? $deviceId,
            'unique_id' => $data['unique_id'] ?? $deviceId,
            'state_topic' => $data['state_topic'] ?? '',
            'command_topic' => $data['command_topic'] ?? '',
            'availability_topic' => $data['availability_topic'] ?? '',
            'device' => $data['device'] ?? [],
            'device_class' => $data['device_class'] ?? '',
            'unit_of_measurement' => $data['unit_of_measurement'] ?? '',
            'value_template' => $data['value_template'] ?? '',
            'state_class' => $data['state_class'] ?? '',
            'icon' => $data['icon'] ?? '',
            // Zusätzliche Felder
            'manufacturer' => $data['device']['manufacturer'] ?? '',
            'model' => $data['device']['model'] ?? '',
            'sw_version' => $data['device']['sw_version'] ?? '',
            'hw_version' => $data['device']['hw_version'] ?? '',
            'suggested_area' => $data['device']['suggested_area'] ?? '',
            'connections' => $data['device']['connections'] ?? [],
            'identifiers' => $data['device']['identifiers'] ?? [],
            'via_device' => $data['device']['via_device'] ?? '',
            'configuration_url' => $data['device']['configuration_url'] ?? '',
            'entity_id' => $data['entity_id'] ?? '',
            'entity_category' => $data['entity_category'] ?? '',
            'enabled_by_default' => $data['enabled_by_default'] ?? true,
            'discovery_timestamp' => time(),
            'full_data' => $data, // Speichere alle Daten für spätere Verwendung
            'supported_features' => $data['supported_features'] ?? 0
        ];

        // Speichere die Geräteinformationen im Attribut
        $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        $discoveredDevices[$deviceId] = $deviceInfo;
        $this->WriteAttributeString('DiscoveredDevices', json_encode($discoveredDevices));
        
        $this->SendDebug('DiscoveredDevice', 'Gerät erkannt: ' . $deviceId, 0);
        
        // Abonniere Home Assistant Topics für dieses Gerät
        if (!empty($deviceInfo['state_topic'])) {
            $this->SendDebug('Subscribe', 'Topic: ' . $deviceInfo['state_topic'], 0);
            $this->Subscribe($deviceInfo['state_topic']);
        }
        
        if (!empty($deviceInfo['availability_topic'])) {
            $this->SendDebug('Subscribe', 'Topic: ' . $deviceInfo['availability_topic'], 0);
            $this->Subscribe($deviceInfo['availability_topic']);
        }
    }

    private function ProcessDeviceRegistry($deviceInstanceID, $deviceInfo)
    {
        // Erstelle oder aktualisiere die Device Registry Informationen
        $deviceRegistry = [
            'name' => $deviceInfo['name'],
            'manufacturer' => $deviceInfo['manufacturer'],
            'model' => $deviceInfo['model'],
            'sw_version' => $deviceInfo['sw_version'],
            'hw_version' => $deviceInfo['hw_version'],
            'suggested_area' => $deviceInfo['suggested_area'],
            'connections' => $deviceInfo['connections'],
            'identifiers' => $deviceInfo['identifiers'],
            'via_device' => $deviceInfo['via_device'],
            'configuration_url' => $deviceInfo['configuration_url']
        ];

        // Speichere die Device Registry Informationen
        IPS_SetProperty($deviceInstanceID, 'DeviceRegistry', json_encode($deviceRegistry));
        
        // Erstelle Variablen für wichtige Device Registry Informationen
        if (!empty($deviceInfo['manufacturer'])) {
            $this->CreateVariable($deviceInstanceID, 'Manufacturer', 'Hersteller', '~String', '');
            SetValue($this->GetIDForIdent('Manufacturer'), $deviceInfo['manufacturer']);
        }
        
        if (!empty($deviceInfo['model'])) {
            $this->CreateVariable($deviceInstanceID, 'Model', 'Modell', '~String', '');
            SetValue($this->GetIDForIdent('Model'), $deviceInfo['model']);
        }
        
        if (!empty($deviceInfo['sw_version'])) {
            $this->CreateVariable($deviceInstanceID, 'Firmware', 'Firmware', '~String', '');
            SetValue($this->GetIDForIdent('Firmware'), $deviceInfo['sw_version']);
        }
    }

    private function ProcessEntityRegistry($deviceInstanceID, $deviceInfo)
    {
        // Erstelle oder aktualisiere die Entity Registry Informationen
        $entityRegistry = [
            'entity_id' => $deviceInfo['entity_id'],
            'entity_category' => $deviceInfo['entity_category'],
            'enabled_by_default' => $deviceInfo['enabled_by_default'],
            'disabled' => $deviceInfo['disabled'],
            'hidden' => $deviceInfo['hidden'],
            'options' => $deviceInfo['options'],
            'original_name' => $deviceInfo['original_name'],
            'original_icon' => $deviceInfo['original_icon'],
            'supported_features' => $deviceInfo['supported_features'],
            'attributes' => $deviceInfo['attributes']
        ];

        // Speichere die Entity Registry Informationen
        IPS_SetProperty($deviceInstanceID, 'EntityRegistry', json_encode($entityRegistry));
        
        // Erstelle Variablen für wichtige Entity Registry Informationen
        if (!empty($deviceInfo['entity_id'])) {
            $this->CreateVariable($deviceInstanceID, 'EntityID', 'Entity ID', '~String', '');
            SetValue($this->GetIDForIdent('EntityID'), $deviceInfo['entity_id']);
        }
        
        if (!empty($deviceInfo['entity_category'])) {
            $this->CreateVariable($deviceInstanceID, 'EntityCategory', 'Entity Kategorie', '~String', '');
            SetValue($this->GetIDForIdent('EntityCategory'), $deviceInfo['entity_category']);
        }
        
        // Verarbeite unterstützte Features
        $this->ProcessSupportedFeatures($deviceInstanceID, $deviceInfo['supported_features']);
        
        // Verarbeite Attribute
        $this->ProcessAttributes($deviceInstanceID, $deviceInfo['attributes']);
        
        // Verarbeite Optionen
        $this->ProcessOptions($deviceInstanceID, $deviceInfo['options']);
    }

    private function ProcessSupportedFeatures($deviceInstanceID, $supportedFeatures)
    {
        // Erstelle eine Variable für die unterstützten Features
        $this->CreateVariable($deviceInstanceID, 'SupportedFeatures', 'Unterstützte Features', '~String', '');
        
        $features = [];
        
        // Überprüfe die unterstützten Features basierend auf dem Gerätetyp
        $deviceInfo = json_decode(IPS_GetInfo($deviceInstanceID), true);
        $component = $deviceInfo['component'] ?? '';
        
        switch ($component) {
            case 'light':
                if ($supportedFeatures & 1) $features[] = 'Helligkeit';
                if ($supportedFeatures & 2) $features[] = 'Farbe';
                if ($supportedFeatures & 4) $features[] = 'Farbtemperatur';
                if ($supportedFeatures & 8) $features[] = 'Effekte';
                if ($supportedFeatures & 16) $features[] = 'Flash';
                if ($supportedFeatures & 32) $features[] = 'Transitions';
                break;
                
            case 'climate':
                if ($supportedFeatures & 1) $features[] = 'Temperatur';
                if ($supportedFeatures & 2) $features[] = 'Feuchtigkeit';
                if ($supportedFeatures & 4) $features[] = 'Modus';
                if ($supportedFeatures & 8) $features[] = 'Fanspeed';
                if ($supportedFeatures & 16) $features[] = 'Swing';
                if ($supportedFeatures & 32) $features[] = 'Auxiliary Heat';
                break;
        }
        
        SetValue($this->GetIDForIdent('SupportedFeatures'), implode(', ', $features));
    }

    private function SubscribeToTopics($deviceInstanceID, $deviceInfo)
    {
        $topics = [];
        
        // State Topic
        if (!empty($deviceInfo['state_topic'])) {
            $topics[] = $deviceInfo['state_topic'];
        }
        
        // Availability Topic
        if (!empty($deviceInfo['availability_topic'])) {
            $topics[] = $deviceInfo['availability_topic'];
        }
        
        // Komponentenspezifische Topics
        switch ($deviceInfo['component']) {
            case 'light':
                if (!empty($deviceInfo['brightness_state_topic'])) {
                    $topics[] = $deviceInfo['brightness_state_topic'];
                }
                if (!empty($deviceInfo['color_state_topic'])) {
                    $topics[] = $deviceInfo['color_state_topic'];
                }
                break;
                
            case 'climate':
                if (!empty($deviceInfo['current_temperature_topic'])) {
                    $topics[] = $deviceInfo['current_temperature_topic'];
                }
                if (!empty($deviceInfo['mode_state_topic'])) {
                    $topics[] = $deviceInfo['mode_state_topic'];
                }
                break;
        }
        
        // Abonniere alle Topics
        foreach ($topics as $topic) {
            $this->SendDebug('SubscribeToTopics', 'Abonniere Topic: ' . $topic, 0);
            $this->SetReceiveDataFilter('.*' . $topic . '.*');
        }
    }

    private function GetDeviceCategory($data)
    {
        // Bestimme die Kategorie basierend auf der Komponente
        if (isset($data['component'])) {
            return $data['component'];
        }
        return 'unknown';
    }

    private function CreateOrUpdateDevice($deviceId, $category, $data)
    {
        $instanceID = @IPS_GetObjectIDByIdent($deviceId, $this->InstanceID);
        
        if ($instanceID === false) {
            // Erstelle neues Gerät
            $instanceID = IPS_CreateInstance('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}');
            IPS_SetParent($instanceID, $this->InstanceID);
            IPS_SetIdent($instanceID, $deviceId);
            IPS_SetName($instanceID, $data['name'] ?? $deviceId);
            
            // Setze Icon basierend auf Home Assistant Icon
            if (isset($data['icon'])) {
                $symconIcon = $this->MapHomeAssistantIconToSymcon($data['icon']);
                IPS_SetIcon($instanceID, $symconIcon);
            } else {
                // Fallback: Setze Icon basierend auf Gerätekategorie
                $categoryIcon = $this->GetCategoryIcon($category);
                IPS_SetIcon($instanceID, $categoryIcon);
            }
            
            IPS_SetInfo($instanceID, json_encode($data));
        } else {
            // Aktualisiere bestehendes Gerät
            IPS_SetName($instanceID, $data['name'] ?? $deviceId);
            
            // Aktualisiere Icon falls vorhanden
            if (isset($data['icon'])) {
                $symconIcon = $this->MapHomeAssistantIconToSymcon($data['icon']);
                IPS_SetIcon($instanceID, $symconIcon);
            }
            
            IPS_SetInfo($instanceID, json_encode($data));
        }
        
        return $instanceID;
    }

    private function CreateOrUpdateVariables($deviceInstanceID, $data)
    {
        $deviceType = $this->GetDeviceCategory($data);
        
        // Erstelle Basis-Variablen
        if (isset($data['state_topic'])) {
            $this->CreateVariable($deviceInstanceID, 'State', 'Status', '~String', $data['state_topic']);
        }
        
        if (isset($data['command_topic'])) {
            $this->CreateVariable($deviceInstanceID, 'Command', 'Befehl', '~String', $data['command_topic']);
        }

        // Erstelle gerätespezifische Variablen
        switch ($deviceType) {
            case 'light':
                $this->CreateLightVariables($deviceInstanceID, $data);
                break;
            case 'switch':
                $this->CreateSwitchVariables($deviceInstanceID, $data);
                break;
            case 'sensor':
                $this->CreateSensorVariables($deviceInstanceID, $data);
                break;
            case 'climate':
                $this->CreateClimateVariables($deviceInstanceID, $data);
                break;
        }
    }

    private function CreateVariable($parentID, $ident, $name, $profile, $topic, $icon = '')
    {
        $variableID = @IPS_GetVariableIDByName($name, $parentID);
        
        if ($variableID === false) {
            $variableID = IPS_CreateVariable(0);
            IPS_SetParent($variableID, $parentID);
            IPS_SetIdent($variableID, $ident);
            IPS_SetName($variableID, $name);
            IPS_SetVariableCustomProfile($variableID, $profile);
            
            // Setze Icon wenn angegeben
            if (!empty($icon)) {
                $symconIcon = $this->MapHomeAssistantIconToSymcon($icon);
                IPS_SetIcon($variableID, $symconIcon);
            }
            
            IPS_SetVariableCustomAction($variableID, 10001);
        }
        
        return $variableID;
    }

    private function ProcessStatusUpdate($payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            return;
        }
        
        // Aktualisiere die Statusvariable
        SetValue($this->GetIDForIdent('Status'), 'Status: ' . $data['status'] . ' - ' . date('Y-m-d H:i:s'));
    }

    private function ProcessCommand($topic, $payload)
    {
        $this->SendDebug('ProcessCommand', 'Befehl empfangen: ' . $payload, 0);
        
        // Extrahiere Geräte-ID und Kommando aus dem Topic
        $topicParts = explode('/', $topic);
        if (count($topicParts) < 3) {
            $this->SendDebug('ProcessCommand', 'Ungültiges Topic-Format: ' . $topic, 0);
            return;
        }
        
        $deviceId = $topicParts[1];
        $command = $topicParts[2];
        
        // Finde die Geräte-Instanz
        $deviceInstanceID = @IPS_GetObjectIDByIdent($deviceId, $this->InstanceID);
        if ($deviceInstanceID === false) {
            $this->SendDebug('ProcessCommand', 'Gerät nicht gefunden: ' . $deviceId, 0);
            return;
        }
        
        // Verarbeite das Kommando basierend auf dem Gerätetyp
        $deviceInfo = json_decode(IPS_GetInfo($deviceInstanceID), true);
        if (!$deviceInfo) {
            $this->SendDebug('ProcessCommand', 'Ungültige Geräteinformationen: ' . $deviceId, 0);
            return;
        }
        
        $deviceType = $this->GetDeviceCategory($deviceInfo);
        $this->ProcessDeviceCommand($deviceInstanceID, $deviceType, $command, $payload);
    }

    private function ProcessDeviceCommand($deviceInstanceID, $deviceType, $command, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            $this->SendDebug('ProcessDeviceCommand', 'Ungültiges JSON-Format: ' . $payload, 0);
            return;
        }

        $commandHandlers = [
            'light' => [
                'state' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'State', $data, 'state');
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Brightness', $data, 'brightness');
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Color', $data, 'color');
                },
                'brightness' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Brightness', $data, 'brightness');
                },
                'color' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Color', $data, 'color');
                }
            ],
            'switch' => [
                'state' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'State', $data, 'state');
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Power', $data, 'power');
                }
            ],
            'climate' => [
                'state' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Temperature', $data, 'current_temperature');
                    $this->UpdateVariableIfSet($deviceInstanceID, 'TargetTemperature', $data, 'temperature');
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Mode', $data, 'mode');
                },
                'temperature' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'TargetTemperature', $data, 'temperature');
                },
                'mode' => function($data) use ($deviceInstanceID) {
                    $this->UpdateVariableIfSet($deviceInstanceID, 'Mode', $data, 'mode');
                }
            ]
        ];

        if (isset($commandHandlers[$deviceType][$command])) {
            $commandHandlers[$deviceType][$command]($data);
        } else {
            $this->ProcessGenericCommand($deviceInstanceID, $command, $payload);
        }
    }

    private function UpdateVariableIfSet($deviceInstanceID, $ident, $data, $key)
    {
        if (isset($data[$key])) {
            $this->UpdateVariable($deviceInstanceID, $ident, $data[$key]);
        }
    }

    private function ProcessGenericCommand($deviceInstanceID, $command, $payload)
    {
        // Erstelle eine Variable für das Kommando, falls noch nicht vorhanden
        $this->CreateVariable($deviceInstanceID, 'Command_' . $command, $command, '~String', '');
        
        // Aktualisiere den Wert
        $this->UpdateVariable($deviceInstanceID, 'Command_' . $command, $payload);
    }

    private function ProcessDeviceStatusUpdate($topic, $payload)
    {
        // Finde heraus, zu welchem Gerät diese Nachricht gehört
        $registeredDevices = json_decode($this->ReadAttributeString('RegisteredDevices'), true);
        $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        
        foreach ($discoveredDevices as $deviceId => $deviceInfo) {
            // Prüfe, ob dieses Topic für dieses Gerät relevant ist
            if ($deviceInfo['state_topic'] === $topic || $deviceInfo['availability_topic'] === $topic) {
                // Prüfe, ob wir eine Instanz für dieses Gerät haben
                if (isset($registeredDevices[$deviceId])) {
                    $instanceID = $registeredDevices[$deviceId];
                    
                    // Unterscheide zwischen Status und Verfügbarkeit
                    if ($deviceInfo['state_topic'] === $topic) {
                        // Status-Update
                        $this->SendDebug('ProcessDeviceStatusUpdate', 'Status-Update für Gerät: ' . $deviceId . ', Payload: ' . $payload, 0);
                        
                        // Verarbeite den Status basierend auf dem Gerätetyp
                        $state = $this->ParseDeviceState($deviceInfo['component'], $payload, $deviceInfo);
                        
                        // Sende die Daten an die Geräteinstanz
                        $this->SendDataToChildren(json_encode([
                            'DataID' => '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}', // Receiver Interface
                            'Buffer' => json_encode([
                                'Command' => 'UpdateState',
                                'DeviceId' => $deviceId,
                                'State' => $state
                            ])
                        ]));
                    } else if ($deviceInfo['availability_topic'] === $topic) {
                        // Verfügbarkeits-Update
                        $this->SendDebug('ProcessDeviceStatusUpdate', 'Verfügbarkeits-Update für Gerät: ' . $deviceId . ', Payload: ' . $payload, 0);
                        
                        // True, wenn Payload "online" ist, sonst false
                        $available = (strtolower($payload) === 'online');
                        
                        // Sende die Daten an die Geräteinstanz
                        $this->SendDataToChildren(json_encode([
                            'DataID' => '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}', // Receiver Interface
                            'Buffer' => json_encode([
                                'Command' => 'UpdateAvailability',
                                'DeviceId' => $deviceId,
                                'Availability' => $available
                            ])
                        ]));
                    }
                }
                
                // Wir haben das richtige Gerät gefunden, weitere Suche stoppen
                break;
            }
        }
    }
    
    /**
     * Wertet den Status eines Geräts basierend auf seinem Typ aus
     */
    private function ParseDeviceState($deviceType, $payload, $deviceInfo)
    {
        $state = [];
        
        // Falls eine Value-Template vorhanden ist, dieses anwenden
        if (!empty($deviceInfo['value_template'])) {
            $payload = $this->ProcessValueTemplate($payload, $deviceInfo['value_template'], $deviceInfo);
        }
        
        // Je nach Gerätetyp den Status unterschiedlich auswerten
        switch ($deviceType) {
            case 'light':
                if ($payload === 'ON' || $payload === 'OFF') {
                    $state['State'] = ($payload === 'ON');
                } else {
                    // Versuche JSON zu parsen
                    $data = json_decode($payload, true);
                    if ($data !== null) {
                        if (isset($data['state'])) {
                            $state['State'] = ($data['state'] === 'ON');
                        }
                        
                        if (isset($data['brightness'])) {
                            // Konvertiere 0-255 zu 0-100
                            $state['Brightness'] = intval(($data['brightness'] / 255.0) * 100);
                        }
                        
                        if (isset($data['color_temp'])) {
                            $state['ColorTemperature'] = $data['color_temp'];
                        }
                        
                        if (isset($data['rgb_color'])) {
                            // Konvertiere RGB zu HexColor
                            $rgb = $data['rgb_color'];
                            $hex = sprintf('#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2]);
                            $state['Color'] = hexdec($hex);
                        }
                    }
                }
                break;
                
            case 'switch':
                $state['State'] = ($payload === 'ON');
                break;
                
            case 'sensor':
                // Für Sensoren den Wert direkt zuweisen
                $state['State'] = $payload;
                break;
                
            case 'climate':
                $data = json_decode($payload, true);
                if ($data !== null) {
                    if (isset($data['current_temperature'])) {
                        $state['CurrentTemperature'] = $data['current_temperature'];
                    }
                    
                    if (isset($data['temperature'])) {
                        $state['TargetTemperature'] = $data['temperature'];
                    }
                    
                    if (isset($data['mode'])) {
                        $modes = ['off' => 0, 'heat' => 1, 'cool' => 2, 'auto' => 3];
                        $state['Mode'] = $modes[$data['mode']] ?? 0;
                    }
                }
                break;
                
            default:
                // Für unbekannte Gerätetypen den Payload direkt als State zuweisen
                $state['State'] = $payload;
                break;
        }
        
        return $state;
    }

    private function UpdateAvailability($deviceInstanceID, $payload)
    {
        $availability = strtolower($payload) === 'online';
        
        // Erstelle oder aktualisiere die Verfügbarkeitsvariable
        $this->CreateVariable($deviceInstanceID, 'Availability', 'Verfügbarkeit', '~Switch', '');
        SetValue($this->GetIDForIdent('Availability'), $availability);
        
        // Aktualisiere den Status des Geräts
        if (!$availability) {
            $this->SendDebug('UpdateAvailability', 'Gerät nicht verfügbar: ' . $deviceInstanceID, 0);
            // Setze alle Variablen auf einen Fehlerzustand
            $this->SetDeviceErrorState($deviceInstanceID);
        }
    }

    private function SetDeviceErrorState($deviceInstanceID)
    {
        $variables = IPS_GetChildrenIDs($deviceInstanceID);
        foreach ($variables as $variableID) {
            $variable = IPS_GetVariable($variableID);
            switch ($variable['VariableType']) {
                case 0: // String
                    SetValue($variableID, 'N/A');
                    break;
                case 1: // Integer
                    SetValue($variableID, 0);
                    break;
                case 2: // Float
                    SetValue($variableID, 0.0);
                    break;
                case 3: // Boolean
                    SetValue($variableID, false);
                    break;
            }
        }
    }

    private function ProcessError($deviceInstanceID, $error)
    {
        // Erstelle oder aktualisiere die Fehlervariable
        $this->CreateVariable($deviceInstanceID, 'Error', 'Fehler', '~String', '');
        SetValue($this->GetIDForIdent('Error'), $error);
        
        // Setze den Fehlerstatus
        $this->SetDeviceErrorState($deviceInstanceID);
    }

    private function ProcessValueTemplate($value, $template, $deviceInfo)
    {
        if (empty($template)) {
            return $value;
        }

        // Ersetze Platzhalter im Template
        $processedValue = $template;
        $processedValue = str_replace('{{ value }}', $value, $processedValue);
        
        // Verarbeite JSON-Pfade
        if (strpos($template, 'value_json') !== false) {
            $jsonData = json_decode($value, true);
            if ($jsonData) {
                // Extrahiere den Pfad aus dem Template (z.B. value_json.temperature)
                preg_match('/value_json\.([a-zA-Z0-9_]+)/', $template, $matches);
                if (isset($matches[1]) && isset($jsonData[$matches[1]])) {
                    $processedValue = $jsonData[$matches[1]];
                }
            }
        }

        return $processedValue;
    }

    private function UpdateDeviceVariables($deviceInstanceID, $topic, $payload)
    {
        $deviceInfo = json_decode(IPS_GetInfo($deviceInstanceID), true);
        if (!$deviceInfo) {
            return;
        }

        $deviceType = $this->GetDeviceCategory($deviceInfo);
        $value = $payload;
        
        // Wende Value Template an, falls vorhanden
        if (!empty($deviceInfo['value_template'])) {
            $value = $this->ProcessValueTemplate($payload, $deviceInfo['value_template'], $deviceInfo);
        }
        
        // Verarbeite State Class
        if (!empty($deviceInfo['state_class'])) {
            switch ($deviceInfo['state_class']) {
                case 'measurement':
                    // Messwerte (z.B. Temperatur, Leistung)
                    $value = floatval($value);
                    break;
                case 'total':
                case 'total_increasing':
                    // Zählerstände
                    $value = floatval($value);
                    break;
                case 'binary':
                    // Binäre Zustände (ON/OFF)
                    $value = strtolower($value) === 'on' ? true : false;
                    break;
            }
        }
        
        switch ($deviceType) {
            case 'light':
                $this->UpdateLightVariables($deviceInstanceID, $topic, $value);
                break;
            case 'switch':
                $this->UpdateSwitchVariables($deviceInstanceID, $topic, $value);
                break;
            case 'sensor':
                $this->UpdateSensorVariables($deviceInstanceID, $topic, $value);
                break;
            case 'climate':
                $this->UpdateClimateVariables($deviceInstanceID, $topic, $value);
                $this->UpdateClimateVariables($deviceInstanceID, $topic, $payload);
                break;
        }
    }

    private function UpdateLightVariables($deviceInstanceID, $topic, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            return;
        }

        // Aktualisiere Status
        if (isset($data['state'])) {
            $this->UpdateVariable($deviceInstanceID, 'State', $data['state']);
        }

        // Aktualisiere Helligkeit
        if (isset($data['brightness'])) {
            $this->UpdateVariable($deviceInstanceID, 'Brightness', $data['brightness']);
        }

        // Aktualisiere Farbe
        if (isset($data['color'])) {
            $this->UpdateVariable($deviceInstanceID, 'Color', $data['color']);
        }
    }

    private function UpdateSwitchVariables($deviceInstanceID, $topic, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            return;
        }

        // Aktualisiere Status
        if (isset($data['state'])) {
            $this->UpdateVariable($deviceInstanceID, 'State', $data['state']);
        }

        // Aktualisiere Leistung
        if (isset($data['power'])) {
            $this->UpdateVariable($deviceInstanceID, 'Power', $data['power']);
        }
    }

    private function UpdateSensorVariables($deviceInstanceID, $topic, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            return;
        }

        // Aktualisiere Wert
        if (isset($data['value'])) {
            $this->UpdateVariable($deviceInstanceID, 'Value', $data['value']);
        }

        // Aktualisiere Einheit
        if (isset($data['unit_of_measurement'])) {
            $this->UpdateVariable($deviceInstanceID, 'Unit', $data['unit_of_measurement']);
        }
    }

    private function UpdateClimateVariables($deviceInstanceID, $topic, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            return;
        }

        // Aktualisiere Temperatur
        if (isset($data['current_temperature'])) {
            $this->UpdateVariable($deviceInstanceID, 'Temperature', $data['current_temperature']);
        }

        // Aktualisiere Soll-Temperatur
        if (isset($data['temperature'])) {
            $this->UpdateVariable($deviceInstanceID, 'TargetTemperature', $data['temperature']);
        }

        // Aktualisiere Modus
        if (isset($data['mode'])) {
            $this->UpdateVariable($deviceInstanceID, 'Mode', $data['mode']);
        }
    }

    private function UpdateVariable($deviceInstanceID, $ident, $value)
    {
        $variableID = @IPS_GetVariableIDByName($ident, $deviceInstanceID);
        if ($variableID !== false) {
            SetValue($variableID, $value);
        }
    }

    private function CreateLightVariables($deviceInstanceID, $data)
    {
        $this->CreateVariable($deviceInstanceID, 'State', 'Status', '~Switch', '', 'mdi:lightbulb');
        $this->CreateVariable($deviceInstanceID, 'Brightness', 'Helligkeit', '~Intensity.100', '', 'mdi:brightness-percent');
        $this->CreateVariable($deviceInstanceID, 'Color', 'Farbe', '~HexColor', '', 'mdi:palette');
    }

    private function CreateSwitchVariables($deviceInstanceID, $data)
    {
        $this->CreateVariable($deviceInstanceID, 'State', 'Status', '~Switch', '', 'mdi:power');
        $this->CreateVariable($deviceInstanceID, 'Power', 'Leistung', '~Watt', '', 'mdi:flash');
    }

    private function CreateSensorVariables($deviceInstanceID, $data)
    {
        $this->CreateVariable($deviceInstanceID, 'Value', 'Wert', '~Float', '', 'mdi:gauge');
        $this->CreateVariable($deviceInstanceID, 'Unit', 'Einheit', '~String', '', 'mdi:ruler');
    }

    private function CreateClimateVariables($deviceInstanceID, $data)
    {
        $this->CreateVariable($deviceInstanceID, 'Temperature', 'Temperatur', '~Temperature', '', 'mdi:thermometer');
        $this->CreateVariable($deviceInstanceID, 'TargetTemperature', 'Soll-Temperatur', '~Temperature', '', 'mdi:thermostat');
        $this->CreateVariable($deviceInstanceID, 'Mode', 'Modus', '~String', '', 'mdi:air-conditioner');
    }

    public function SendMQTTMessage($topic, $payload)
    {
        $MQTTBrokerInstanceID = $this->ReadPropertyInteger('MQTTBrokerInstanceID');
        if ($MQTTBrokerInstanceID > 0) {
            $result = @RequestAction($MQTTBrokerInstanceID, 'Publish', [
                'Topic'   => $topic,
                'Payload' => $payload,
                'QoS'     => 0,
                'Retain'  => false
            ]);
            if ($result === false) {
                $this->SendDebug('SendMQTTMessage', 'Fehler beim Senden der MQTT-Nachricht', 0);
                return false;
            }
            return true;
        }
        return false;
    }

    public function SendCommand($deviceId, $command, $value)
    {
        $deviceInstanceID = @IPS_GetObjectIDByIdent($deviceId, $this->InstanceID);
        if ($deviceInstanceID === false) {
            $this->SendDebug('SendCommand', 'Gerät nicht gefunden: ' . $deviceId, 0);
            return false;
        }

        $deviceInfo = json_decode(IPS_GetInfo($deviceInstanceID), true);
        if (!$deviceInfo) {
            $this->SendDebug('SendCommand', 'Keine Geräteinformationen gefunden', 0);
            return false;
        }

        // Erstelle das Kommando basierend auf dem Gerätetyp
        $deviceType = $this->GetDeviceCategory($deviceInfo);
        $payload = $this->CreateCommandPayload($deviceType, $command, $value);
        
        if (!$payload) {
            $this->SendDebug('SendCommand', 'Ungültiges Kommando für Gerätetyp: ' . $deviceType, 0);
            return false;
        }

        // Sende das Kommando
        $topic = $deviceInfo['command_topic'] ?? '';
        if (empty($topic)) {
            $this->SendDebug('SendCommand', 'Kein Kommando-Topic gefunden', 0);
            return false;
        }

        return $this->SendMQTTMessage($topic, $payload);
    }

    private function CreateCommandPayload($deviceType, $command, $value)
    {
        switch ($deviceType) {
            case 'light':
                switch ($command) {
                    case 'State':
                    case 'ON':
                    case 'OFF':
                        return $value ? 'ON' : 'OFF';
                        
                    case 'Brightness':
                        // Konvertiere 0-100 zu 0-255
                        $brightness = intval(($value / 100.0) * 255);
                        return json_encode(['brightness' => $brightness]);
                        
                    case 'Color':
                        // Konvertiere HexColor zu RGB
                        $rgb = $this->HexToRGB($value);
                        return json_encode(['rgb_color' => [$rgb['r'], $rgb['g'], $rgb['b']]]);
                        
                    case 'ColorTemperature':
                        return json_encode(['color_temp' => $value]);
                }
                break;
                
            case 'switch':
                return $value ? 'ON' : 'OFF';
                
            case 'climate':
                switch ($command) {
                    case 'TargetTemperature':
                        return json_encode(['temperature' => $value]);
                        
                    case 'Mode':
                        $modes = ['off', 'heat', 'cool', 'auto'];
                        return json_encode(['mode' => $modes[$value] ?? 'off']);
                }
                break;
        }
        
        // Standard: gib den Wert als String zurück
        return strval($value);
    }
    
    /**
     * Konvertiert einen Hexadezimal-Farbwert zu RGB
     */
    private function HexToRGB($hex)
    {
        $hex = str_replace('#', '', $hex);
        
        if (strlen($hex) == 3) {
            $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
            $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
            $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }
        
        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    private function ProcessAttributes($deviceInstanceID, $attributes)
    {
        if (empty($attributes)) {
            return;
        }

        // Erstelle eine Variable für die Attribute
        $this->CreateVariable($deviceInstanceID, 'Attributes', 'Attribute', '~String', '');
        
        $attributeList = [];
        foreach ($attributes as $key => $value) {
            // Erstelle eine Variable für jedes wichtige Attribut
            if (is_scalar($value)) {
                $this->CreateVariable($deviceInstanceID, 'Attr_' . $key, $key, '~String', '');
                SetValue($this->GetIDForIdent('Attr_' . $key), (string)$value);
            }
            $attributeList[] = $key . ': ' . (is_scalar($value) ? $value : json_encode($value));
        }
        
        SetValue($this->GetIDForIdent('Attributes'), implode("\n", $attributeList));
    }

    private function ProcessOptions($deviceInstanceID, $options)
    {
        if (empty($options)) {
            return;
        }

        // Erstelle eine Variable für die Optionen
        $this->CreateVariable($deviceInstanceID, 'Options', 'Optionen', '~String', '');
        
        $optionList = [];
        foreach ($options as $key => $value) {
            // Erstelle eine Variable für jede wichtige Option
            if (is_scalar($value)) {
                $this->CreateVariable($deviceInstanceID, 'Opt_' . $key, $key, '~String', '');
                SetValue($this->GetIDForIdent('Opt_' . $key), (string)$value);
            }
            $optionList[] = $key . ': ' . (is_scalar($value) ? $value : json_encode($value));
        }
        
        SetValue($this->GetIDForIdent('Options'), implode("\n", $optionList));
    }

    public function UpdateDevices()
    {
        $this->SendDebug('UpdateDevices', 'Starte automatische Aktualisierung', 0);
        
        // Hole alle Geräte-Instanzen
        $deviceInstances = IPS_GetChildrenIDs($this->InstanceID);
        
        foreach ($deviceInstances as $deviceInstanceID) {
            $deviceInfo = json_decode(IPS_GetInfo($deviceInstanceID), true);
            if (!$deviceInfo) {
                continue;
            }
            
            // Sende Status-Anfrage für jedes Gerät
            if (!empty($deviceInfo['state_topic'])) {
                $this->SendMQTTMessage($deviceInfo['state_topic'], '');
            }
            
            // Sende Verfügbarkeits-Anfrage
            if (!empty($deviceInfo['availability_topic'])) {
                $this->SendMQTTMessage($deviceInfo['availability_topic'], '');
            }
        }
        
        // Aktualisiere Zeitstempel
        SetValue($this->GetIDForIdent('LastUpdate'), time());
    }

    public function SynchronizeDevices()
    {
        $this->SendDebug('SynchronizeDevices', 'Starte Synchronisation mit Home Assistant', 0);
        
        // Sende Discovery-Anfrage
        $this->SendMQTTMessage('homeassistant/status', 'online');
        
        // Warte kurz, damit Home Assistant die Discovery-Nachrichten senden kann
        IPS_Sleep(1000);
        
        // Starte automatische Aktualisierung
        $this->UpdateDevices();
    }

    private function MapHomeAssistantIconToSymcon($haIcon)
    {
        // Entferne 'mdi:' Prefix falls vorhanden
        $icon = str_replace('mdi:', '', $haIcon);
        
        // Mapping-Array für Icons
        $iconMapping = [
            // Beleuchtung
            'lightbulb' => 'Light',
            'lightbulb-outline' => 'LightOff',
            'lamp' => 'Lamp',
            'ceiling-light' => 'CeilingLight',
            'floor-lamp' => 'FloorLamp',
            
            // Schalter & Steckdosen
            'power-socket' => 'PowerSocket',
            'power-plug' => 'PowerPlug',
            'switch' => 'Switch',
            'toggle-switch' => 'ToggleSwitch',
            
            // Klima & Heizung
            'thermometer' => 'Temperature',
            'thermostat' => 'Thermostat',
            'radiator' => 'Radiator',
            'air-conditioner' => 'AirConditioner',
            'fan' => 'Fan',
            
            // Sensoren
            'motion-sensor' => 'MotionDetector',
            'water-percent' => 'Humidity',
            'brightness-percent' => 'Brightness',
            'weather-sunny' => 'Sun',
            'weather-cloudy' => 'Cloud',
            'weather-rainy' => 'Rain',
            
            // Fenster & Türen
            'window-open' => 'WindowOpen',
            'window-closed' => 'WindowClosed',
            'door-open' => 'DoorOpen',
            'door-closed' => 'DoorClosed',
            'blinds' => 'Blind',
            'blinds-open' => 'BlindOpen',
            
            // Sicherheit
            'shield' => 'Security',
            'cctv' => 'Camera',
            'lock' => 'Lock',
            'lock-open' => 'LockOpen',
            
            // Multimedia
            'television' => 'TV',
            'speaker' => 'Speaker',
            'music' => 'Music',
            'play' => 'Play',
            'pause' => 'Pause',
            
            // Status
            'check' => 'Ok',
            'alert' => 'Alert',
            'close' => 'Error',
            'information' => 'Info',
            
            // Energie
            'flash' => 'Energy',
            'battery' => 'Battery',
            'battery-charging' => 'BatteryCharging',
            'solar-power' => 'Solar',
            
            // Räume
            'home' => 'Home',
            'sofa' => 'LivingRoom',
            'bed-empty' => 'Bedroom',
            'pot-steam' => 'Kitchen',
            'shower' => 'Bathroom',
            'garage' => 'Garage'
        ];
        
        // Rückgabe des gemappten Icons oder eines Standard-Icons
        return isset($iconMapping[$icon]) ? $iconMapping[$icon] : 'Status';
    }

    private function GetCategoryIcon($category)
    {
        $categoryIcons = [
            'light' => 'Light',
            'switch' => 'Switch',
            'sensor' => 'Temperature',
            'binary_sensor' => 'Status',
            'climate' => 'Thermostat',
            'cover' => 'Blind',
            'fan' => 'Fan',
            'lock' => 'Lock',
            'media_player' => 'TV',
            'camera' => 'Camera',
            'alarm_control_panel' => 'Security',
            'weather' => 'Sun'
        ];
        
        return isset($categoryIcons[$category]) ? $categoryIcons[$category] : 'Status';
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // Fülle den Konfigurator mit erkannten Geräten
        $form['actions'][1]['values'] = $this->GetDeviceList();
        
        return json_encode($form);
    }
    
    /**
     * Aktualisiert die Geräteliste und das Konfigurationsformular
     */
    public function RefreshDeviceList()
    {
        $this->ReloadForm();
    }
    
    /**
     * Gibt die Liste der erkannten Geräte im Konfigurator-Format zurück
     */
    private function GetDeviceList()
    {
        $result = [];
        $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        
        // Lese alle bereits erstellten Instanzen
        $instanceIDs = IPS_GetInstanceListByModuleID('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}');
        $createdDevices = [];
        
        foreach ($instanceIDs as $instanceID) {
            $info = json_decode(IPS_GetInfo($instanceID), true);
            if (!empty($info['unique_id'])) {
                $createdDevices[$info['unique_id']] = $instanceID;
            }
        }
        
        // Erstelle Liste für Konfigurator
        foreach ($discoveredDevices as $deviceId => $deviceInfo) {
            $isCreated = isset($createdDevices[$deviceInfo['unique_id']]);
            
            $entry = [
                'id' => $deviceId,
                'name' => $deviceInfo['name'],
                'component' => $deviceInfo['component'],
                'manufacturer' => $deviceInfo['manufacturer'],
                'model' => $deviceInfo['model'],
                'area' => $deviceInfo['suggested_area'],
                'instanceID' => $isCreated ? $createdDevices[$deviceInfo['unique_id']] : 0,
                'create' => [
                    'moduleID' => '{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}',
                    'configuration' => [
                        'DeviceId' => $deviceId
                    ],
                    'name' => $deviceInfo['name'],
                    'location' => ''
                ]
            ];
            
            $result[] = $entry;
        }
        
        return $result;
    }
    
    /**
     * Wird aufgerufen, wenn ein Gerät über den Konfigurator erstellt wird
     */
    public function CreateDeviceInstance(string $DeviceId)
    {
        $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        
        if (!isset($discoveredDevices[$DeviceId])) {
            $this->SendDebug('CreateDeviceInstance', 'Gerät nicht gefunden: ' . $DeviceId, 0);
            return false;
        }
        
        $deviceInfo = $discoveredDevices[$DeviceId];
        $component = $deviceInfo['component'];
        $data = $deviceInfo['full_data'];
        
        // Erstelle das Gerät
        $deviceInstanceID = $this->CreateOrUpdateDevice($DeviceId, $component, $data);
        
        if ($deviceInstanceID) {
            // Verarbeite Device Registry Informationen
            $this->ProcessDeviceRegistry($deviceInstanceID, $deviceInfo);
            
            // Verarbeite Entity Registry Informationen
            $this->ProcessEntityRegistry($deviceInstanceID, $deviceInfo);
            
            IPS_SetInfo($deviceInstanceID, json_encode($deviceInfo));
            
            // Erstelle die entsprechenden Variablen
            $this->CreateOrUpdateVariables($deviceInstanceID, $data);
            
            // Abonniere die relevanten Topics
            $this->SubscribeToTopics($deviceInstanceID, $deviceInfo);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Wird für die initiale Discovery verwendet
     */
    public function DiscoverDevices()
    {
        // Sende Discovery-Anfrage an Home Assistant
        $this->SendMQTTMessage($this->ReadPropertyString('MQTTTopic') . '/status', 'online');
        
        // Aktualisiere die Geräteanzeige
        $this->ReloadForm();
    }

    /**
     * Empfängt Nachrichten von Child-Instanzen (Konfigurator und Geräte)
     */
    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ForwardData', $JSONString, 0);
        
        $buffer = json_decode($data->Buffer, true);
        
        if (!isset($buffer['Command'])) {
            return '';
        }
        
        // Verarbeite Anfragen vom Konfigurator oder Geräten
        switch ($buffer['Command']) {
            case 'GetDeviceList':
                // Gib die Liste aller erkannten Geräte zurück
                return json_encode([
                    'Devices' => json_decode($this->ReadAttributeString('DiscoveredDevices'), true)
                ]);
                
            case 'GetDeviceInfo':
                // Gib Informationen zu einem bestimmten Gerät zurück
                $deviceId = $buffer['DeviceId'];
                $devices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
                
                if (isset($devices[$deviceId])) {
                    return json_encode([
                        'DeviceInfo' => $devices[$deviceId]
                    ]);
                }
                
                return json_encode(['Error' => 'Device not found']);
                
            case 'DiscoverDevices':
                // Starte den Discovery-Prozess
                $this->DiscoverDevices();
                return json_encode(['Success' => true]);
                
            case 'RegisterDevice':
                // Registriere ein Gerät, das erstellt wurde
                $deviceId = $buffer['DeviceId'];
                $instanceID = $buffer['InstanceID'];
                
                $registeredDevices = json_decode($this->ReadAttributeString('RegisteredDevices'), true);
                $registeredDevices[$deviceId] = $instanceID;
                $this->WriteAttributeString('RegisteredDevices', json_encode($registeredDevices));
                
                return json_encode(['Success' => true]);
                
            case 'SendCommand':
                // Sende einen Befehl an ein Home Assistant Gerät
                $deviceId = $buffer['DeviceId'];
                $ident = $buffer['Ident'];
                $value = $buffer['Value'];
                $deviceType = $buffer['DeviceType'];
                
                // Rufe die generische Befehlsfunktion auf
                $success = $this->SendDeviceCommand($deviceId, $deviceType, $ident, $value);
                
                return json_encode(['Success' => $success]);
        }
        
        return '';
    }

    /**
     * Sendet einen Befehl an ein Home Assistant Gerät
     * 
     * @param string $deviceId Die ID des Geräts
     * @param string $deviceType Der Typ des Geräts (light, switch, etc.)
     * @param string $ident Die Variable, für die der Befehl gesendet wird
     * @param mixed $value Der Wert, der gesendet werden soll
     * @return bool Erfolgsstatus
     */
    private function SendDeviceCommand($deviceId, $deviceType, $ident, $value)
    {
        $devices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        
        if (!isset($devices[$deviceId])) {
            $this->SendDebug('SendDeviceCommand', 'Gerät nicht gefunden: ' . $deviceId, 0);
            return false;
        }
        
        $deviceInfo = $devices[$deviceId];
        $commandTopic = $deviceInfo['command_topic'] ?? '';
        
        if (empty($commandTopic)) {
            $this->SendDebug('SendDeviceCommand', 'Kein Command-Topic für Gerät: ' . $deviceId, 0);
            return false;
        }
        
        // Bereite den Payload basierend auf dem Gerätetyp und der Variable vor
        $payload = $this->CreateCommandPayload($deviceType, $ident, $value);
        
        // Sende den Befehl über MQTT
        $this->SendDebug('SendDeviceCommand', 'Topic: ' . $commandTopic . ', Payload: ' . $payload, 0);
        $this->SendMQTTMessage($commandTopic, $payload);
        
        return true;
    }
} 