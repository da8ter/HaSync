<?php

declare(strict_types=1);

class HomeassistantDevice extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        
        // Haupteigenschaften
        $this->RegisterPropertyString('DeviceId', '');
        $this->RegisterPropertyString('DeviceType', '');
        
        // Speicherorte für Geräteinformationen
        $this->RegisterAttributeString('DeviceInfo', '{}');
        
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
        
        // Beim ersten Start oder bei Änderungen die Geräteinformationen vom Gateway abrufen
        $deviceId = $this->ReadPropertyString('DeviceId');
        if (!empty($deviceId)) {
            $this->UpdateDeviceInfo($deviceId);
        }
    }
    
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', $JSONString, 0);
        
        $payload = json_decode($data->Buffer, true);
        
        // Nur Nachrichten für dieses Gerät verarbeiten
        if (isset($payload['DeviceId']) && $payload['DeviceId'] === $this->ReadPropertyString('DeviceId')) {
            $this->ProcessMessage($payload);
        }
    }
    
    /**
     * Verarbeitet Nachrichten vom Gateway
     */
    private function ProcessMessage($payload)
    {
        if (isset($payload['Command'])) {
            switch ($payload['Command']) {
                case 'UpdateState':
                    $this->UpdateState($payload['State']);
                    break;
                
                case 'UpdateVariables':
                    $this->UpdateVariables($payload['Variables']);
                    break;
                
                case 'UpdateAvailability':
                    $this->UpdateAvailability($payload['Availability']);
                    break;
            }
        }
    }
    
    /**
     * Aktualisiert die Geräteinformationen
     */
    private function UpdateDeviceInfo($deviceId)
    {
        // Geräteinformationen vom Gateway abrufen
        $response = $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}', // Sender Interface
            'Buffer' => json_encode([
                'Command' => 'GetDeviceInfo',
                'DeviceId' => $deviceId
            ])
        ]));
        
        if ($response === false) {
            $this->SendDebug('UpdateDeviceInfo', 'Keine Antwort vom Gateway', 0);
            return;
        }
        
        $responseData = json_decode($response, true);
        if ($responseData === null || !isset($responseData['DeviceInfo'])) {
            $this->SendDebug('UpdateDeviceInfo', 'Ungültige Antwort vom Gateway', 0);
            return;
        }
        
        $deviceInfo = $responseData['DeviceInfo'];
        
        // Geräteinformationen speichern
        $this->WriteAttributeString('DeviceInfo', json_encode($deviceInfo));
        
        // Gerätetyp aktualisieren
        IPS_SetProperty($this->InstanceID, 'DeviceType', $deviceInfo['component']);
        
        // Variablen erstellen basierend auf dem Gerätetyp
        $this->CreateVariables($deviceInfo);
        
        // Benachrichtigung an Gateway senden, dass Gerät einsatzbereit ist
        $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}', // Sender Interface
            'Buffer' => json_encode([
                'Command' => 'RegisterDevice',
                'DeviceId' => $deviceId,
                'InstanceID' => $this->InstanceID
            ])
        ]));
    }
    
    /**
     * Erstellt die Variablen basierend auf dem Gerätetyp
     */
    private function CreateVariables($deviceInfo)
    {
        // Gemeinsame Grundvariablen für alle Geräte
        $this->RegisterVariableBoolean('Available', 'Verfügbar', '~Switch', 0);
        $this->RegisterVariableString('Info', 'Info', '', 0);
        
        // Erstelle gerätespezifische Variablen basierend auf dem Komponententyp
        switch ($deviceInfo['component']) {
            case 'light':
                $this->CreateLightVariables($deviceInfo);
                break;
                
            case 'switch':
                $this->CreateSwitchVariables($deviceInfo);
                break;
                
            case 'sensor':
                $this->CreateSensorVariables($deviceInfo);
                break;
                
            case 'climate':
                $this->CreateClimateVariables($deviceInfo);
                break;
                
            // weitere Gerätetypen hier...
            
            default:
                // Generische Variablen für unbekannte Gerätetypen
                $this->RegisterVariableString('State', 'Status', '', 0);
                break;
        }
    }
    
    /**
     * Erstellt Variablen für Lichtgeräte
     */
    private function CreateLightVariables($deviceInfo)
    {
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 10);
        $this->EnableAction('State');
        
        if (isset($deviceInfo['supported_features']) && ($deviceInfo['supported_features'] & 1)) {
            // Unterstützt Dimmen
            $this->RegisterVariableInteger('Brightness', 'Helligkeit', '~Intensity.100', 20);
            $this->EnableAction('Brightness');
        }
        
        if (isset($deviceInfo['supported_features']) && ($deviceInfo['supported_features'] & 16)) {
            // Unterstützt Farbtemperatur
            $this->RegisterVariableInteger('ColorTemperature', 'Farbtemperatur', '', 30);
            $this->EnableAction('ColorTemperature');
        }
        
        if (isset($deviceInfo['supported_features']) && ($deviceInfo['supported_features'] & 4)) {
            // Unterstützt RGB-Farbe
            $this->RegisterVariableInteger('Color', 'Farbe', '~HexColor', 40);
            $this->EnableAction('Color');
        }
    }
    
    /**
     * Erstellt Variablen für Schalter
     */
    private function CreateSwitchVariables($deviceInfo)
    {
        $this->RegisterVariableBoolean('State', 'Status', '~Switch', 10);
        $this->EnableAction('State');
    }
    
    /**
     * Erstellt Variablen für Sensoren
     */
    private function CreateSensorVariables($deviceInfo)
    {
        $profile = '';
        $varType = VARIABLETYPE_FLOAT;
        
        // Bestimme den Variablentyp und das Profil basierend auf der Geräteklasse
        if (isset($deviceInfo['device_class'])) {
            switch ($deviceInfo['device_class']) {
                case 'temperature':
                    $profile = '~Temperature';
                    break;
                    
                case 'humidity':
                    $profile = '~Humidity.F';
                    break;
                    
                case 'pressure':
                    $profile = '~AirPressure';
                    break;
                    
                case 'power':
                    $profile = '~Watt';
                    break;
                    
                case 'energy':
                    $profile = '~Electricity';
                    break;
                    
                case 'voltage':
                    $profile = '~Volt';
                    break;
                    
                case 'current':
                    $profile = '~Ampere';
                    break;
                    
                case 'battery':
                    $profile = '~Battery.100';
                    $varType = VARIABLETYPE_INTEGER;
                    break;
                    
                default:
                    $profile = '';
                    break;
            }
        }
        
        // Wenn keine Einheit angegeben ist, verwende einen generischen Namen
        $name = isset($deviceInfo['unit_of_measurement']) ? 'Wert' : 'Status';
        
        // Erstelle die Variable basierend auf dem Typ
        switch ($varType) {
            case VARIABLETYPE_FLOAT:
                $this->RegisterVariableFloat('State', $name, $profile, 10);
                break;
                
            case VARIABLETYPE_INTEGER:
                $this->RegisterVariableInteger('State', $name, $profile, 10);
                break;
                
            default:
                $this->RegisterVariableString('State', $name, '', 10);
                break;
        }
    }
    
    /**
     * Erstellt Variablen für Klimageräte
     */
    private function CreateClimateVariables($deviceInfo)
    {
        $this->RegisterVariableFloat('CurrentTemperature', 'Aktuelle Temperatur', '~Temperature', 10);
        $this->RegisterVariableFloat('TargetTemperature', 'Zieltemperatur', '~Temperature', 20);
        $this->EnableAction('TargetTemperature');
        
        // HVAC-Modus (Heizen, Kühlen, Auto, Aus)
        $this->RegisterVariableInteger('Mode', 'Modus', '', 30);
        $this->EnableAction('Mode');
    }
    
    /**
     * Aktualisiert den Zustand der Variablen
     */
    private function UpdateState($state)
    {
        // Prüfe, ob die Variable existiert und aktualisiere sie
        foreach ($state as $ident => $value) {
            if (IPS_VariableExists(IPS_GetObjectIDByIdent($ident, $this->InstanceID))) {
                switch (IPS_GetVariable(IPS_GetObjectIDByIdent($ident, $this->InstanceID))['VariableType']) {
                    case VARIABLETYPE_BOOLEAN:
                        SetValueBoolean(IPS_GetObjectIDByIdent($ident, $this->InstanceID), boolval($value));
                        break;
                        
                    case VARIABLETYPE_INTEGER:
                        SetValueInteger(IPS_GetObjectIDByIdent($ident, $this->InstanceID), intval($value));
                        break;
                        
                    case VARIABLETYPE_FLOAT:
                        SetValueFloat(IPS_GetObjectIDByIdent($ident, $this->InstanceID), floatval($value));
                        break;
                        
                    case VARIABLETYPE_STRING:
                        SetValueString(IPS_GetObjectIDByIdent($ident, $this->InstanceID), strval($value));
                        break;
                }
            }
        }
    }
    
    /**
     * Aktualisiert die Verfügbarkeit des Geräts
     */
    private function UpdateAvailability($availability)
    {
        if (IPS_VariableExists(IPS_GetObjectIDByIdent('Available', $this->InstanceID))) {
            SetValueBoolean(IPS_GetObjectIDByIdent('Available', $this->InstanceID), $availability);
        }
    }
    
    /**
     * Wird aufgerufen, wenn eine Variable über die Weboberfläche geändert wird
     */
    public function RequestAction($Ident, $Value)
    {
        $deviceId = $this->ReadPropertyString('DeviceId');
        $deviceInfo = json_decode($this->ReadAttributeString('DeviceInfo'), true);
        
        $this->SendDebug('RequestAction', 'Ident: ' . $Ident . ', Value: ' . $Value, 0);
        
        // Befehl an Gateway senden
        $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}', // Sender Interface
            'Buffer' => json_encode([
                'Command' => 'SendCommand',
                'DeviceId' => $deviceId,
                'Ident' => $Ident,
                'Value' => $Value,
                'DeviceType' => $deviceInfo['component'] ?? ''
            ])
        ]));
        
        // Variable lokal aktualisieren
        switch (IPS_GetVariable(IPS_GetObjectIDByIdent($Ident, $this->InstanceID))['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                SetValueBoolean(IPS_GetObjectIDByIdent($Ident, $this->InstanceID), boolval($Value));
                break;
                
            case VARIABLETYPE_INTEGER:
                SetValueInteger(IPS_GetObjectIDByIdent($Ident, $this->InstanceID), intval($Value));
                break;
                
            case VARIABLETYPE_FLOAT:
                SetValueFloat(IPS_GetObjectIDByIdent($Ident, $this->InstanceID), floatval($Value));
                break;
                
            case VARIABLETYPE_STRING:
                SetValueString(IPS_GetObjectIDByIdent($Ident, $this->InstanceID), strval($Value));
                break;
        }
    }
} 