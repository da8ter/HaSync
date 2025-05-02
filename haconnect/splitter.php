<?php

declare(strict_types=1);

class HomeassistantSplitter extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        
        // Verbindung zum Homeassistant Gateway herstellen (IO-Modul)
        $this->RegisterPropertyString('MQTTTopic', 'homeassistant');
        $this->RegisterPropertyInteger('UpdateInterval', 300); // 5 Minuten Standard-Update-Intervall
        
        // Buffer für erkannte Geräte
        $this->RegisterAttributeString('DiscoveredDevices', '{}');
        
        // Buffer für registrierte Geräteinstanzen
        $this->RegisterAttributeString('RegisteredDevices', '{}');
        
        // Verbindung zum Gateway herstellen
        $this->ConnectParent('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}');
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
        
        // Prüfen, ob wir ein Gateway haben
        $parentID = $this->GetParent();
        if ($parentID > 0 && IPS_GetInstance($parentID)['ConnectionID'] > 0) {
            $this->SetStatus(102); // Aktiv
        } else {
            $this->SetStatus(201); // Fehler: Gateway nicht verfügbar
        }
    }
    
    // Daten vom Gateway empfangen
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', $JSONString, 0);
        
        $payload = json_decode($data->Buffer, true);
        
        // Verarbeite die Daten basierend auf dem Typ
        if (isset($payload['Topic']) && isset($payload['Payload'])) {
            $this->ProcessMQTTMessage($payload['Topic'], $payload['Payload']);
        }
    }
    
    // Daten an Gateway senden
    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ForwardData', $JSONString, 0);
        
        $buffer = json_decode($data->Buffer, true);
        
        // Verarbeite Befehle von Geräten und Konfigurator
        if (isset($buffer['Command'])) {
            switch ($buffer['Command']) {
                case 'GetDeviceList':
                    // Gib die Liste der erkannten Geräte zurück
                    return json_encode([
                        'Devices' => json_decode($this->ReadAttributeString('DiscoveredDevices'), true)
                    ]);
                    
                case 'GetDeviceInfo':
                    // Informationen zu einem Gerät zurückgeben
                    $deviceId = $buffer['DeviceId'];
                    $devices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
                    
                    if (isset($devices[$deviceId])) {
                        return json_encode([
                            'DeviceInfo' => $devices[$deviceId]
                        ]);
                    }
                    
                    return json_encode(['Error' => 'Device not found']);
                    
                case 'DiscoverDevices':
                    // Discovery-Anfrage an Gateway senden
                    $this->DiscoverDevices();
                    return json_encode(['Success' => true]);
                    
                case 'RegisterDevice':
                    // Gerät registrieren
                    $deviceId = $buffer['DeviceId'];
                    $instanceID = $buffer['InstanceID'];
                    
                    $registeredDevices = json_decode($this->ReadAttributeString('RegisteredDevices'), true);
                    $registeredDevices[$deviceId] = $instanceID;
                    $this->WriteAttributeString('RegisteredDevices', json_encode($registeredDevices));
                    
                    return json_encode(['Success' => true]);
                    
                case 'SendCommand':
                    // Befehl an Gateway weiterleiten
                    $topic = $buffer['Topic'];
                    $payload = $buffer['Payload'];
                    
                    $result = $this->SendMQTTCommand($topic, $payload);
                    return json_encode(['Success' => $result]);
            }
        }
        
        // Standardmäßig an Gateway weiterleiten
        return $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}',
            'Buffer' => $data->Buffer
        ]));
    }
    
    // MQTT-Nachrichten verarbeiten
    private function ProcessMQTTMessage($topic, $payload)
    {
        $this->SendDebug('ProcessMQTTMessage', 'Topic: ' . $topic . ', Payload: ' . $payload, 0);
        
        // Home Assistant Discovery-Nachrichten verarbeiten
        if (strpos($topic, $this->ReadPropertyString('MQTTTopic') . '/') === 0) {
            // Discovery-Nachrichten verarbeiten
            if (strpos($topic, '/config') !== false) {
                $this->ProcessDiscoveryMessage($topic, $payload);
                return;
            }
            
            // Status-Updates verarbeiten
            $this->ProcessStatusUpdate($topic, $payload);
        }
    }
    
    // Discovery-Nachrichten verarbeiten
    private function ProcessDiscoveryMessage($topic, $payload)
    {
        $data = json_decode($payload, true);
        if (!$data) {
            $this->SendDebug('ProcessDiscoveryMessage', 'Ungültiges JSON-Format', 0);
            return;
        }
        
        // Topic-Format: homeassistant/[component]/[node_id]/[object_id]/config
        $topicParts = explode('/', $topic);
        if (count($topicParts) < 5) {
            $this->SendDebug('ProcessDiscoveryMessage', 'Ungültiges Topic-Format', 0);
            return;
        }
        
        $mqttTopic = $this->ReadPropertyString('MQTTTopic');
        $partIndex = 0;
        
        // Bestimme den Index für die Komponente im Topic-Array
        if ($topicParts[0] === $mqttTopic) {
            $partIndex = 1;
        }
        
        $component = $topicParts[$partIndex];
        $nodeId = $topicParts[$partIndex + 1];
        $objectId = $topicParts[$partIndex + 2];
        $deviceId = $nodeId . '_' . $objectId;
        
        // Geräteinformationen speichern
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
            'supported_features' => $data['supported_features'] ?? 0,
            'discovery_timestamp' => time(),
            'full_data' => $data
        ];
        
        // Speichern der Geräteinformationen
        $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        $discoveredDevices[$deviceId] = $deviceInfo;
        $this->WriteAttributeString('DiscoveredDevices', json_encode($discoveredDevices));
        
        $this->SendDebug('DiscoveredDevice', 'Gerät erkannt: ' . $deviceId, 0);
    }
    
    // Status-Updates verarbeiten und an Geräte weiterleiten
    private function ProcessStatusUpdate($topic, $payload)
    {
        $registeredDevices = json_decode($this->ReadAttributeString('RegisteredDevices'), true);
        $discoveredDevices = json_decode($this->ReadAttributeString('DiscoveredDevices'), true);
        
        foreach ($discoveredDevices as $deviceId => $deviceInfo) {
            // Prüfen, ob dieses Topic für dieses Gerät relevant ist
            if ($deviceInfo['state_topic'] === $topic || $deviceInfo['availability_topic'] === $topic) {
                // Prüfen, ob wir eine Instanz für dieses Gerät haben
                if (isset($registeredDevices[$deviceId])) {
                    $instanceID = $registeredDevices[$deviceId];
                    
                    if ($deviceInfo['state_topic'] === $topic) {
                        // Statusupdate an Gerät weiterleiten
                        $this->SendDataToChildren(json_encode([
                            'DataID' => '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}',
                            'Buffer' => json_encode([
                                'Command' => 'UpdateState',
                                'DeviceId' => $deviceId,
                                'Topic' => $topic,
                                'Payload' => $payload
                            ])
                        ]));
                    } elseif ($deviceInfo['availability_topic'] === $topic) {
                        // Verfügbarkeitsupdate an Gerät weiterleiten
                        $available = (strtolower($payload) === 'online');
                        $this->SendDataToChildren(json_encode([
                            'DataID' => '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}',
                            'Buffer' => json_encode([
                                'Command' => 'UpdateAvailability',
                                'DeviceId' => $deviceId,
                                'Available' => $available
                            ])
                        ]));
                    }
                }
                
                // Gerät gefunden, weitere Suche beenden
                break;
            }
        }
    }
    
    // Discovery starten
    public function DiscoverDevices()
    {
        $mqttTopic = $this->ReadPropertyString('MQTTTopic');
        $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}',
            'Buffer' => json_encode([
                'Command' => 'Publish',
                'Topic' => $mqttTopic . '/status',
                'Payload' => 'online'
            ])
        ]));
        
        $this->SendDebug('DiscoverDevices', 'Discovery-Anfrage gesendet', 0);
    }
    
    // MQTT-Befehl senden
    private function SendMQTTCommand($topic, $payload)
    {
        $result = $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}',
            'Buffer' => json_encode([
                'Command' => 'Publish',
                'Topic' => $topic,
                'Payload' => $payload
            ])
        ]));
        
        return $result !== false;
    }
    
    // Hilfsfunktion zum Ermitteln der übergeordneten Instanz
    private function GetParent()
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return $instance['ConnectionID'];
    }
} 