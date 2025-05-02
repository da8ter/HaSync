<?php

declare(strict_types=1);

class HomeassistantConfigurator extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // Verbindung zum Gateway herstellen
        $this->ConnectParent('{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}');
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
        // Konfigurationsformular erstellen
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // Überprüfen, ob eine Verbindung zum Gateway besteht
        $gatewayID = $this->GetParent();
        if ($gatewayID === false) {
            // Kein Gateway verbunden
            $this->SendDebug('GetConfigurationForm', 'Kein Gateway verbunden', 0);
            return json_encode($form);
        }
        
        // Topic vom Gateway abrufen
        $topic = IPS_GetProperty($gatewayID, 'MQTTTopic');
        
        // Alle verfügbaren Geräte ermitteln
        $devices = $this->DiscoverDevices($topic);
        
        // Geräteliste befüllen
        $form['actions'][0]['values'] = $devices;
        
        return json_encode($form);
    }
    
    private function GetParent()
    {
        $instance = IPS_GetInstance($this->InstanceID);
        if ($instance['ConnectionID'] > 0) {
            return $instance['ConnectionID'];
        }
        return false;
    }
    
    private function DiscoverDevices($topic)
    {
        $devices = [];
        
        // Nachricht an das Gateway senden um Discovery zu starten
        $data = json_encode([
            'DataID' => '{79DFEF75-988A-5F97-C007-F3B77EAAF075}',
            'Buffer' => json_encode([
                'Command' => 'Subscribe',
                'Topic' => $topic . '/#'
            ])
        ]);
        
        $this->SendDataToParent($data);
        
        // Hier würde normalerweise eine Logik folgen, die auf eingehende Discovery-Nachrichten reagiert
        // und die verfügbaren Geräte auflistet. Für diese Beispielimplementierung 
        // verwenden wir eine Dummy-Liste.
        
        $devices[] = [
            'name' => 'Licht Wohnzimmer',
            'instanceID' => 0,
            'type' => 'light',
            'uid' => 'light.living_room',
            'create' => [
                'moduleID' => '{CB5950B3-593C-4126-9F0F-8655A3944419}',
                'configuration' => [
                    'DeviceID' => 'light.living_room'
                ]
            ]
        ];
        
        $devices[] = [
            'name' => 'Temperatur Schlafzimmer',
            'instanceID' => 0,
            'type' => 'sensor',
            'uid' => 'sensor.bedroom_temperature',
            'create' => [
                'moduleID' => '{CB5950B3-593C-4126-9F0F-8655A3944419}',
                'configuration' => [
                    'DeviceID' => 'sensor.bedroom_temperature'
                ]
            ]
        ];
        
        return $devices;
    }
    
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', $JSONString, 0);
        
        // Hier würden die empfangenen Discovery-Informationen verarbeitet werden
    }
    
    public function CreateDeviceInstance($DeviceUID)
    {
        // Diese Funktion wird über die Schaltfläche in der Konfigurationsform aufgerufen
        $this->SendDebug('CreateDeviceInstance', 'Erstelle Gerät: ' . $DeviceUID, 0);
        
        // Basierend auf dem Typ des Geräts das entsprechende Modul instanziieren
        $deviceType = explode('.', $DeviceUID)[0]; // z.B. "light" aus "light.living_room"
        
        $moduleID = '{CB5950B3-593C-4126-9F0F-8655A3944419}'; // HomeassistantDevice
        $instanceID = IPS_CreateInstance($moduleID);
        
        if ($instanceID !== false) {
            IPS_SetName($instanceID, 'Homeassistant ' . $DeviceUID);
            IPS_SetProperty($instanceID, 'DeviceID', $DeviceUID);
            IPS_ApplyChanges($instanceID);
            
            // Mit dem Gateway verbinden
            $gatewayID = $this->GetParent();
            if ($gatewayID !== false) {
                IPS_ConnectInstance($instanceID, $gatewayID);
            }
            
            return true;
        }
        
        return false;
    }
} 