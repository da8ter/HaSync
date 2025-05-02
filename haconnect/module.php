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
        $this->RegisterPropertyInteger('MQTTClientID', 0); // ID der MQTT-Client-Instanz
        
        //Variables
        $this->RegisterVariableString('Status', 'Status', '~TextBox', 0);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Aktualisierung', '~UnixTimestamp', 0);
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

        // Verbindung zum MQTT-Client über die ausgewählte Instanz herstellen
        $mqttClientID = $this->ReadPropertyInteger('MQTTClientID');
        if ($mqttClientID > 0) {
            // Verbindung herstellen und Status setzen
            $this->SetStatus(102); // Aktiv
        } else {
            // Kein MQTT-Client konfiguriert
            $this->SetStatus(201);
            return;
        }

        // Update Zeitstempel
        SetValue($this->GetIDForIdent('LastUpdate'), time());
    }

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ForwardData', $JSONString, 0);
        
        $buffer = json_decode($data->Buffer, true);
        
        if (!isset($buffer['Command'])) {
            return '';
        }
        
        // Befehle verarbeiten
        switch ($buffer['Command']) {
            case 'Publish':
                // MQTT-Nachricht veröffentlichen
                $topic = $buffer['Topic'];
                $payload = $buffer['Payload'];
                $result = $this->PublishMQTT($topic, $payload);
                return json_encode(['Success' => $result]);
                
            case 'Subscribe':
                // MQTT-Topic abonnieren
                $topic = $buffer['Topic'];
                $result = $this->SubscribeMQTT($topic);
                return json_encode(['Success' => $result]);
        }
        
        return '';
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        $this->SendDebug('ReceiveData', $JSONString, 0);
        
        // Update Status
        SetValue($this->GetIDForIdent('Status'), 'Letzte Nachricht: ' . date('Y-m-d H:i:s'));
        SetValue($this->GetIDForIdent('LastUpdate'), time());
        
        // Daten an Splitter weiterleiten
        $this->SendDataToChildren(json_encode([
            'DataID' => '{C24CDA30-82EE-41D6-9D2D-7435C24B6EB6}',
            'Buffer' => json_encode([
                'Topic' => $data->Topic,
                'Payload' => $data->Payload
            ])
        ]));
    }
    
    // MQTT-Nachrichten über den Client veröffentlichen
    private function PublishMQTT($topic, $payload)
    {
        $mqttClientID = $this->ReadPropertyInteger('MQTTClientID');
        if ($mqttClientID <= 0) {
            $this->SendDebug('PublishMQTT', 'Keine MQTT-Client-Instanz konfiguriert', 0);
            return false;
        }
        
        $result = @RequestAction($mqttClientID, 'Publish', [
            'Topic'   => $topic,
            'Payload' => $payload,
            'QoS'     => 0,
            'Retain'  => false
        ]);
        
        if ($result === false) {
            $this->SendDebug('PublishMQTT', 'Fehler beim Veröffentlichen der MQTT-Nachricht', 0);
            return false;
        }
        
        return true;
    }
    
    // MQTT-Topic abonnieren
    private function SubscribeMQTT($topic)
    {
        $mqttClientID = $this->ReadPropertyInteger('MQTTClientID');
        if ($mqttClientID <= 0) {
            $this->SendDebug('SubscribeMQTT', 'Keine MQTT-Client-Instanz konfiguriert', 0);
            return false;
        }
        
        $result = @RequestAction($mqttClientID, 'Subscribe', [
            'Topic' => $topic,
            'QoS'   => 0
        ]);
        
        if ($result === false) {
            $this->SendDebug('SubscribeMQTT', 'Fehler beim Abonnieren des MQTT-Topics', 0);
            return false;
        }
        
        return true;
    }
} 