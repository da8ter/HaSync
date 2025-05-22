<?php

declare(strict_types=1);

class HAconnect extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();
        
        // Properties für Home Assistant Verbindung
        $this->RegisterPropertyString('ha_url', '');
        $this->RegisterPropertyString('ha_token', '');
        
        // Optionen für Echtzeit-Updates
        $this->RegisterPropertyBoolean('use_realtime', false);
        $this->RegisterPropertyInteger('websocket_id', 0);
        
        // Polling-Einstellungen (Fallback-Option)
        $this->RegisterPropertyBoolean('use_polling', true);
        $this->RegisterPropertyInteger('polling_interval', 30);
        
        // Timer für regelmäßiges Polling
        $this->RegisterTimer('PollingTimer', 0, 'HACO_PollStates($_IPS["TARGET"]);');
        
        // Message Handler registrieren
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        
        // Attribute für IDs
        $this->RegisterAttributeInteger('ws_id_counter', 0);
    }

    public function Destroy()
    {
        // Polling deaktivieren
        $this->SetTimerInterval('PollingTimer', 0);
        
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();
        
        // Polling aktivieren oder deaktivieren
        $this->SetupPolling();
    }

    /**
     * Wird aufgerufen, wenn eine registrierte Nachricht eingeht
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELSTARTED) {
            $this->SetupPolling();
        }
    }
    
    /**
     * Aktiviert oder deaktiviert das Polling je nach Konfiguration
     */
    private function SetupPolling()
    {
        $usePolling = $this->ReadPropertyBoolean('use_polling');
        $interval = $this->ReadPropertyInteger('polling_interval');
        
        if ($usePolling && $interval > 0) {
            $this->SetTimerInterval('PollingTimer', $interval * 1000);
            $this->SendDebug('Polling', 'Polling aktiviert, Intervall: ' . $interval . ' Sekunden', 0);
        } else {
            $this->SetTimerInterval('PollingTimer', 0);
            $this->SendDebug('Polling', 'Polling deaktiviert', 0);
        }
    }
    
    /**
     * Ruft Zustände von Home Assistant ab und aktualisiert alle HAdevice-Instanzen
     */
    public function PollStates()
    {
        // Prüfe, ob Polling aktiviert ist
        if (!$this->ReadPropertyBoolean('use_polling')) {
            return;
        }
        
        $this->SendDebug('PollStates', 'Starte Abfrage aller Gerätezustände', 0);
        
        // HAdevice-Instanzen ermitteln
        $moduleId = $this->GetHAdeviceModuleID();
        if ($moduleId === '') {
            $this->LogMessage('HAdevice-Modul-ID konnte nicht ermittelt werden', IPS_ERROR);
            return;
        }
        
        $instances = IPS_GetInstanceListByModuleID($moduleId);
        if (count($instances) === 0) {
            $this->SendDebug('PollStates', 'Keine HAdevice-Instanzen gefunden', 0);
            return;
        }
        
        // Für jede Instanz den Status von Home Assistant abrufen
        foreach ($instances as $instanceID) {
            $entityId = IPS_GetProperty($instanceID, 'entity_id');
            if ($entityId === '') {
                continue;
            }
            
            // Status abrufen
            $state = $this->GetEntityState($entityId);
            if ($state !== false) {
                // State aktualisieren und alle Attribute
                $this->SendDebug('PollStates', 'Aktualisiere Instanz ' . $instanceID . ' für Entity ' . $entityId, 0);
                IPS_RequestAction($instanceID, 'UpdateFromHA', json_encode($state));
            }
        }
        
        $this->SendDebug('PollStates', 'Abfrage aller Gerätezustände abgeschlossen', 0);
    }
    
    /**
     * Ruft den Status einer Entity von Home Assistant ab
     * @param string $entityId Die ID der Entity
     * @return array|false Der Status oder false im Fehlerfall
     */
    private function GetEntityState($entityId)
    {
        $url = $this->ReadPropertyString('ha_url');
        $token = $this->ReadPropertyString('ha_token');
        
        if ($url === '' || $token === '') {
            $this->LogMessage('Home Assistant Verbindung nicht möglich: URL oder Token fehlt', IPS_ERROR);
            return false;
        }
        
        // URL für die API-Anfrage erstellen
        $apiUrl = rtrim($url, '/') . '/api/states/' . urlencode($entityId);
        
        // cURL-Anfrage vorbereiten
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        
        // Anfrage senden und Antwort verarbeiten
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->SendDebug('GetEntityState', 'Fehler beim Abrufen von ' . $entityId . ': HTTP ' . $httpCode, 0);
            return false;
        }
        
        // JSON-Antwort dekodieren
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->SendDebug('GetEntityState', 'Ungültige Antwort für ' . $entityId, 0);
            return false;
        }
        
        return $data;
    }
    
    /**
     * Ruft alle Geräte von Home Assistant ab
     * @return array|false Liste aller Geräte oder false im Fehlerfall
     */
    public function FetchDevices()
    {
        $url = $this->ReadPropertyString('ha_url');
        $token = $this->ReadPropertyString('ha_token');
        
        if ($url === '' || $token === '') {
            $this->LogMessage('Home Assistant Verbindung nicht möglich: URL oder Token fehlt', IPS_ERROR);
            return false;
        }
        
        // URL für die API-Anfrage erstellen
        $apiUrl = rtrim($url, '/') . '/api/states';
        
        // cURL-Anfrage vorbereiten
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);
        
        // Anfrage senden und Antwort verarbeiten
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->LogMessage('Fehler beim Abrufen der Geräte: HTTP ' . $httpCode, IPS_ERROR);
            return false;
        }
        
        // JSON-Antwort dekodieren
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->LogMessage('Ungültige Antwort von Home Assistant', IPS_ERROR);
            return false;
        }
        
        return $data;
    }
    
    /**
     * Ermittelt die HAdevice-Modul-ID
     * @return string Die Modul-ID oder leer im Fehlerfall
     */
    private function GetHAdeviceModuleID()
    {
        // Lese module.json von HAdevice aus
        $path = dirname(__DIR__) . '/HAdevice/module.json';
        if (!file_exists($path)) {
            return '';
        }
        $data = json_decode(file_get_contents($path), true);
        return $data['id'] ?? '';
    }
    
    /**
     * Aktualisiert den Configurator mit allen Geräten von Home Assistant
     */
    public function UpdateConfigurator()
    {
        $this->SendDebug('UpdateConfigurator', 'Starte Update des Configurators', 0);
        $this->ReloadForm();
    }
    
    /**
     * Liefert das Formular für die Konfigurationsseite
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        
        // Geräte von Home Assistant abrufen
        $devices = $this->FetchDevices();
        
        $listData = [];
        if (is_array($devices)) {
            foreach ($devices as $entity) {
                $entityId = $entity['entity_id'] ?? '';
                if ($entityId === '') {
                    continue;
                }
                
                // Entity-Informationen
                $listData[] = [
                    'entity_id' => $entityId,
                    'friendly_name' => $entity['attributes']['friendly_name'] ?? $entityId,
                    'state' => $entity['state'] ?? 'unbekannt',
                    'create' => [
                        'moduleID' => '{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}',
                        'configuration' => [
                            'entity_id' => $entityId,
                            'parent_id' => $this->InstanceID
                        ],
                        'name' => $entity['attributes']['friendly_name'] ?? $entityId
                    ]
                ];
            }
        }
        
        // Configurator-Element befüllen
        foreach ($form['actions'] as &$action) {
            if (isset($action['type']) && $action['type'] === 'Configurator' && $action['name'] === 'DeviceConfigurator') {
                $action['values'] = $listData;
            }
        }
        
        return json_encode($form);
    }
    
    /**
     * Erstellt eine neue HAdevice-Instanz für das ausgewählte Gerät.
     * @param array $selectedRows Die ausgewählten Zeilen aus dem Configurator
     */
    public function CreateHAdeviceInstance(array $selectedRows)
    {
        $this->LogMessage('--- Starte Instanz-Erstellung ---', IPS_MESSAGE);
        $this->SendDebug('CreateHAdeviceInstance', 'Anzahl ausgewählter Zeilen: ' . count($selectedRows), 0);
        
        foreach ($selectedRows as $row) {
            $entityId = $row['entity_id'] ?? '';
            $name = $row['friendly_name'] ?? $entityId;
            
            $this->LogMessage('Erstelle HAdevice für: ' . $entityId, IPS_MESSAGE);
            $this->SendDebug('CreateHAdeviceInstance', 'Erstelle Instanz für: ' . $entityId, 0);
            
            if ($entityId === '') {
                $this->SendDebug('CreateHAdeviceInstance', 'entity_id fehlt.', 0);
                $this->LogMessage('Fehler: entity_id fehlt', IPS_WARNING);
                continue;
            }
            
            // Prüfe, ob Instanz bereits existiert
            $existingInstances = IPS_GetInstanceListByModuleID('{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}');
            $existingInstanceID = 0;
            
            foreach ($existingInstances as $instanceID) {
                $instanceEntityId = IPS_GetProperty($instanceID, 'entity_id');
                if ($instanceEntityId === $entityId) {
                    $existingInstanceID = $instanceID;
                    break;
                }
            }
            
            if ($existingInstanceID > 0) {
                $this->SendDebug('CreateHAdeviceInstance', 'Instanz existiert bereits: ' . $existingInstanceID, 0);
                $this->LogMessage('Instanz für ' . $entityId . ' existiert bereits mit ID ' . $existingInstanceID, IPS_INFO);
                continue;
            }
            
            // Neue Instanz erstellen
            $instanceID = IPS_CreateInstance('{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}');
            if ($instanceID === false) {
                $this->SendDebug('CreateHAdeviceInstance', 'Fehler beim Erstellen der Instanz', 0);
                $this->LogMessage('Fehler beim Erstellen der Instanz für ' . $entityId, IPS_ERROR);
                continue;
            }
            
            // Instanz konfigurieren
            IPS_SetName($instanceID, $name);
            IPS_SetProperty($instanceID, 'entity_id', $entityId);
            IPS_SetProperty($instanceID, 'parent_id', $this->InstanceID);
            IPS_ApplyChanges($instanceID);
            
            $this->SendDebug('CreateHAdeviceInstance', 'Instanz ' . $instanceID . ' erfolgreich erstellt', 0);
            $this->LogMessage('Instanz für ' . $entityId . ' erfolgreich erstellt: ID ' . $instanceID, IPS_INFO);
        }
        
        $this->LogMessage('--- Instanz-Erstellung abgeschlossen ---', IPS_MESSAGE);
        return true;
    }
}
