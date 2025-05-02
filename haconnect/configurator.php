<?php

declare(strict_types=1);

class HomeassistantConfigurator extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        
        // Verbindung zum Homeassistant Gateway herstellen
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
     * Initiiert die Geräteerkennung im Gateway
     */
    public function DiscoverDevices()
    {
        // Gateway auffordern, Geräte zu erkennen
        $this->SendDataToParent(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}', // Sender Interface
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
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}', // Sender Interface
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
        $instanceIDs = IPS_GetInstanceListByModuleID('{AB2A57B2-A987-41C3-BEF3-12CA2EC4C0EB}'); // HomeassistantDevice-ModulID
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
                    'moduleID' => '{AB2A57B2-A987-41C3-BEF3-12CA2EC4C0EB}', // HomeassistantDevice-ModulID
                    'configuration' => [
                        'DeviceId' => $deviceId
                    ],
                    'name' => $deviceInfo['name']
                ]
            ];
            
            $result[] = $entry;
        }
        
        return $result;
    }
} 