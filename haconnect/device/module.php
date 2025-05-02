<?php

declare(strict_types=1);

class HomeassistantDevice extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Verbindung zum Gateway herstellen
        $this->ConnectParent('{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}');
        
        // Eigenschaften registrieren
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyBoolean('AutoUpdate', true);
        
        // Timer registrieren
        $this->RegisterTimer('UpdateTimer', 0, 'HA_RequestUpdate($_IPS[\'TARGET\']);');
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
        
        // Prüfen ob DeviceID gesetzt ist
        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
            $this->SetStatus(201); // Fehler: Keine Geräte-ID
            return;
        }
        
        // Basisinformationen zum Gerät abrufen
        $this->RequestDeviceInfo();
        
        // Auto-Update Timer einrichten (alle 5 Minuten)
        if ($this->ReadPropertyBoolean('AutoUpdate')) {
            $this->SetTimerInterval('UpdateTimer', 5 * 60 * 1000); // 5 Minuten
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }
        
        // MQTT-Topic abonnieren
        $this->SubscribeTopics();
    }
    
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', $JSONString, 0);
        
        $buffer = json_decode($data->Buffer, true);
        
        // Prüfen ob das empfangene Topic für dieses Gerät relevant ist
        $deviceID = $this->ReadPropertyString('DeviceID');
        $topic = $buffer['Topic'];
        
        // Überprüfen ob das Topic zu unserem Gerät gehört
        if (strpos($topic, $deviceID) !== false) {
            $this->ProcessData($topic, $buffer['Payload']);
        }
    }
    
    private function ProcessData($topic, $payload)
    {
        // Payload dekodieren
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        
        $this->SendDebug('ProcessData', 'Topic: ' . $topic . ', Payload: ' . print_r($payload, true), 0);
        
        // Je nach Gerätetyp unterschiedliche Verarbeitung
        $deviceID = $this->ReadPropertyString('DeviceID');
        $deviceType = explode('.', $deviceID)[0]; // z.B. "light" aus "light.living_room"
        
        switch ($deviceType) {
            case 'light':
                $this->ProcessLightData($payload);
                break;
                
            case 'sensor':
                $this->ProcessSensorData($payload);
                break;
                
            case 'switch':
                $this->ProcessSwitchData($payload);
                break;
                
            default:
                // Generische Verarbeitung
                $this->ProcessGenericData($payload);
                break;
        }
    }
    
    private function ProcessLightData($payload)
    {
        // Variablen für ein Licht anlegen/aktualisieren
        if (!IPS_VariableExists($this->GetIDForIdent('State'))) {
            $this->RegisterVariableBoolean('State', 'Zustand', '~Switch', 1);
            $this->EnableAction('State');
        }
        
        if (isset($payload['state'])) {
            $state = ($payload['state'] === 'ON');
            SetValue($this->GetIDForIdent('State'), $state);
        }
        
        // Helligkeit, wenn verfügbar
        if (isset($payload['brightness'])) {
            if (!IPS_VariableExists($this->GetIDForIdent('Brightness'))) {
                $this->RegisterVariableInteger('Brightness', 'Helligkeit', '~Intensity.255', 2);
                $this->EnableAction('Brightness');
            }
            
            $brightness = intval($payload['brightness']);
            SetValue($this->GetIDForIdent('Brightness'), $brightness);
        }
    }
    
    private function ProcessSensorData($payload)
    {
        // Für Sensoren je nach Art unterschiedliche Variablen anlegen
        if (isset($payload['temperature'])) {
            if (!IPS_VariableExists($this->GetIDForIdent('Temperature'))) {
                $this->RegisterVariableFloat('Temperature', 'Temperatur', '~Temperature', 1);
            }
            
            $temp = floatval($payload['temperature']);
            SetValue($this->GetIDForIdent('Temperature'), $temp);
        }
        
        if (isset($payload['humidity'])) {
            if (!IPS_VariableExists($this->GetIDForIdent('Humidity'))) {
                $this->RegisterVariableFloat('Humidity', 'Luftfeuchtigkeit', '~Humidity', 2);
            }
            
            $humidity = floatval($payload['humidity']);
            SetValue($this->GetIDForIdent('Humidity'), $humidity);
        }
    }
    
    private function ProcessSwitchData($payload)
    {
        // Schalter anlegen/aktualisieren
        if (!IPS_VariableExists($this->GetIDForIdent('State'))) {
            $this->RegisterVariableBoolean('State', 'Zustand', '~Switch', 1);
            $this->EnableAction('State');
        }
        
        if (isset($payload['state'])) {
            $state = ($payload['state'] === 'ON');
            SetValue($this->GetIDForIdent('State'), $state);
        }
    }
    
    private function ProcessGenericData($payload)
    {
        // Generische Verarbeitung für unbekannte Gerätetypen
        foreach ($payload as $key => $value) {
            $ident = $this->ConvertToIdent($key);
            
            if (!IPS_VariableExists($this->GetIDForIdent($ident))) {
                // Variablentyp anhand des Werts bestimmen
                switch (gettype($value)) {
                    case 'boolean':
                        $this->RegisterVariableBoolean($ident, $key, '', 0);
                        break;
                        
                    case 'integer':
                        $this->RegisterVariableInteger($ident, $key, '', 0);
                        break;
                        
                    case 'double':
                    case 'float':
                        $this->RegisterVariableFloat($ident, $key, '', 0);
                        break;
                        
                    default:
                        $this->RegisterVariableString($ident, $key, '', 0);
                        break;
                }
            }
            
            // Wert setzen
            SetValue($this->GetIDForIdent($ident), $value);
        }
    }
    
    // Hilfsfunktion zum Konvertieren von Schlüsseln in gültige Idents
    private function ConvertToIdent($key)
    {
        // Nur alphanumerische Zeichen und Unterstriche erlauben
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
        
        // Sicherstellen, dass der Ident mit einem Buchstaben beginnt
        if (!preg_match('/^[a-zA-Z]/', $ident)) {
            $ident = 'v_' . $ident;
        }
        
        return $ident;
    }
    
    // Abonnieren der relevanten Topics
    private function SubscribeTopics()
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
            return false;
        }
        
        // Topic abonnieren
        $data = json_encode([
            'DataID' => '{79DFEF75-988A-5F97-C007-F3B77EAAF075}',
            'Buffer' => json_encode([
                'Command' => 'Subscribe',
                'Topic' => 'homeassistant/' . $deviceID . '/#'
            ])
        ]);
        
        $this->SendDebug('SubscribeTopics', $data, 0);
        $this->SendDataToParent($data);
        
        return true;
    }
    
    // Status vom Gerät anfragen
    public function RequestUpdate()
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
            return false;
        }
        
        // Status anfragen
        $data = json_encode([
            'DataID' => '{79DFEF75-988A-5F97-C007-F3B77EAAF075}',
            'Buffer' => json_encode([
                'Command' => 'Publish',
                'Topic' => 'homeassistant/' . $deviceID . '/get',
                'Payload' => ''
            ])
        ]);
        
        $this->SendDebug('RequestUpdate', $data, 0);
        $this->SendDataToParent($data);
        
        return true;
    }
    
    // Informationen zum Gerät abrufen
    private function RequestDeviceInfo()
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
            return false;
        }
        
        // Informationen anfragen
        $data = json_encode([
            'DataID' => '{79DFEF75-988A-5F97-C007-F3B77EAAF075}',
            'Buffer' => json_encode([
                'Command' => 'Publish',
                'Topic' => 'homeassistant/' . $deviceID . '/info',
                'Payload' => ''
            ])
        ]);
        
        $this->SendDebug('RequestDeviceInfo', $data, 0);
        $this->SendDataToParent($data);
        
        return true;
    }
    
    // Befehl an das Gerät senden
    public function SendCommand($Command, $Value)
    {
        $deviceID = $this->ReadPropertyString('DeviceID');
        if (empty($deviceID)) {
            return false;
        }
        
        // Befehl senden
        $data = json_encode([
            'DataID' => '{79DFEF75-988A-5F97-C007-F3B77EAAF075}',
            'Buffer' => json_encode([
                'Command' => 'Publish',
                'Topic' => 'homeassistant/' . $deviceID . '/set',
                'Payload' => json_encode([
                    $Command => $Value
                ])
            ])
        ]);
        
        $this->SendDebug('SendCommand', $data, 0);
        $this->SendDataToParent($data);
        
        return true;
    }
    
    // Aktion auf eine Variable
    public function RequestAction($Ident, $Value)
    {
        $this->SendDebug('RequestAction', 'Ident: ' . $Ident . ', Value: ' . $Value, 0);
        
        switch ($Ident) {
            case 'State':
                $this->SendCommand('state', $Value ? 'ON' : 'OFF');
                break;
                
            case 'Brightness':
                $this->SendCommand('brightness', intval($Value));
                break;
                
            default:
                throw new Exception('Invalid Ident');
        }
    }
} 