<?php

declare(strict_types=1);

class HomeassistantConfigurator extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        
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
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/configurator_form.json'), true);
        
        // Geräteliste vom Gateway abrufen und einfügen
        $form['actions'][2]['values'] = $this->GetDeviceList();
        
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
     * Initiiert die Geräteerkennung im Gateway
     */
    public function DiscoverDevices()
    {
        // Gateway auffordern, Geräte zu erkennen
        $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}',
            'Buffer' => json_encode(['Command' => 'DiscoverDevices'])
        ]));
        
        // Formular nach kurzer Verzögerung neu laden
        IPS_Sleep(2000);
        $this->ReloadForm();
    }
    
    /**
     * Gibt die Liste der erkannten Geräte im Konfigurator-Format zurück
     */
    private function GetDeviceList()
    {
        // Geräteliste vom Gateway anfordern
        $response = $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}',
            'Buffer' => json_encode(['Command' => 'GetDeviceList'])
        ]));
        
        if ($response === false) {
            $this->SendDebug('GetDeviceList', 'Keine Antwort vom Gateway', 0);
            return [];
        }
        
        $responseData = json_decode($response, true);
        if ($responseData === null) {
            $this->SendDebug('GetDeviceList', 'Ungültige Antwort vom Gateway', 0);
            return [];
        }
        
        // Formatiere die Geräteinformationen für den Konfigurator
        $result = [];
        $discoveredDevices = $responseData['Devices'] ?? [];
        
        // Lese alle bereits erstellten Instanzen
        $instanceIDs = IPS_GetInstanceListByModuleID('{B6E23D9F-27AB-444C-B1F8-184FA69C9C58}'); // HomeassistantDevice-ModulID
        $createdDevices = [];
        
        foreach ($instanceIDs as $instanceID) {
            $deviceID = IPS_GetProperty($instanceID, 'DeviceID');
            if (!empty($deviceID)) {
                $createdDevices[$deviceID] = $instanceID;
            }
        }
        
        // Erstelle Liste für Konfigurator
        foreach ($discoveredDevices as $deviceId => $deviceInfo) {
            $isCreated = isset($createdDevices[$deviceId]);
            
            $entry = [
                'id' => $deviceId,
                'name' => $deviceInfo['name'],
                'component' => $deviceInfo['component'],
                'manufacturer' => $deviceInfo['device']['manufacturer'] ?? '',
                'model' => $deviceInfo['device']['model'] ?? '',
                'area' => $deviceInfo['device']['suggested_area'] ?? '',
                'instanceID' => $isCreated ? $createdDevices[$deviceId] : 0,
                'create' => [
                    'moduleID' => '{B6E23D9F-27AB-444C-B1F8-184FA69C9C58}', // HomeassistantDevice-ModulID
                    'configuration' => [
                        'DeviceID' => $deviceId
                    ],
                    'name' => $deviceInfo['name']
                ]
            ];
            
            $result[] = $entry;
        }
        
        return $result;
    }
} 