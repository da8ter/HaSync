<?php

declare(strict_types=1);

/**
 * HaBridge - Home Assistant MQTT Integration für IP-Symcon
 * Automatische MQTT-basierte Echtzeitaktualisierung von HaDevice Instanzen
 * 
 * @version 2.0.0
 * @author Windsurf.io
 */
class HaBridge extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // MQTT Server connection
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        
        // Properties
        $this->RegisterPropertyString('ClientID', 'HaBridge_' . $this->InstanceID);
        $this->RegisterPropertyString('ha_discovery_prefix', 'homeassistant');
        
        // Attributes
        $this->RegisterAttributeString('SubscribedTopics', json_encode([]));
        $this->RegisterAttributeString('EntityMapping', '{}');
        
        // Neutralize legacy discovery timer (kept with 0 interval and no-op callback)
        $this->RegisterTimer('DiscoveryTimer', 0, ';');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        if (!$this->HasActiveParent()) {
            $this->SetStatus(104);
            return;
        }
        
        try {
            $this->SubscribeToTopics();
            
            $this->SetStatus(102);
            
        } catch (Exception $e) {
            $this->SetStatus(201);
            $this->LogMessage('Error in ApplyChanges: ' . $e->getMessage(), KL_ERROR);
        }
    }
    
    /**
     * Receive data from MQTT Server
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['DataID']) || 
            $data['DataID'] != '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}') {
            return '';
        }
        
        if (!isset($data['Topic']) || !isset($data['Payload'])) {
            return '';
        }
        
        $topic = trim($data['Topic']);
        $payload = $data['Payload'];
        
        if (empty($topic)) {
            return '';
        }
        
        try {
            if ($this->IsStateTopic($topic)) {
                // Extract entity and broadcast to children
                $entityId = $this->ExtractEntityIdFromStateTopic($topic);
                if ($entityId) {
                    $this->BroadcastStateUpdate($entityId, $payload);
                }
            }
        } catch (Exception $e) {
            $this->SendDebug('ReceiveData', 'Error processing message: ' . $e->getMessage(), 0);
        }
        
        return '';
    }

    /**
     * Forward data from child devices to parent (MQTT) or other backends
     */
    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['DataID'])) {
            return '';
        }
        // Device -> Bridge TX GUID
        if ($data['DataID'] === '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}') {
            $action = $data['Action'] ?? '';
            switch ($action) {
                case 'MQTTPublish':
                    $topic = (string)($data['Topic'] ?? '');
                    $payload = $data['Payload'] ?? '';
                    $retain = (bool)($data['Retain'] ?? false);
                    if ($topic !== '') {
                        $this->PublishMQTT($topic, $payload, $retain);
                    }
                    break;
                case 'CallService':
                    $service = (string)($data['Service'] ?? '');
                    $svcData = isset($data['Data']) && is_array($data['Data']) ? $data['Data'] : [];
                    if ($service !== '') {
                        $ok = $this->CallHAService($service, $svcData);
                        if (!$ok) {
                            $this->SendDebug('ForwardData', 'CallService failed for ' . $service, 0);
                        }
                    }
                    break;
                default:
                    // Unknown action - ignore for now
                    break;
            }
        }
        return '';
    }

    /**
     * Try to resolve Home Assistant URL and token from HaConfigurator instances
     */
    protected function GetHAConfig(): array
    {
        $result = ['url' => '', 'token' => ''];
        try {
            $moduleID = '{32D99DCD-A530-4907-3FB0-44D7D472771D}'; // HaConfigurator
            $ids = @IPS_GetInstanceListByModuleID($moduleID);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (!IPS_InstanceExists($id)) {
                        continue;
                    }
                    $url = @IPS_GetProperty($id, 'ha_url');
                    $token = @IPS_GetProperty($id, 'ha_token');
                    if (is_string($url) && $url !== '' && is_string($token) && $token !== '') {
                        $result['url'] = $url;
                        $result['token'] = $token;
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return $result;
    }

    /**
     * Execute Home Assistant service via REST API
     */
    protected function CallHAService(string $service, array $data): bool
    {
        $ha = $this->GetHAConfig();
        if ($ha['url'] === '' || $ha['token'] === '') {
            $this->SendDebug('CallHAService', 'Missing HA URL or token', 0);
            return false;
        }
        $apiUrl = rtrim($ha['url'], '/') . '/api/services/' . ltrim($service, '/');
        $headers = [
            'Authorization: Bearer ' . $ha['token'],
            'Content-Type: application/json'
        ];
        $payload = json_encode($data);
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $result = curl_exec($curl);
        $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($result === false) {
            $this->SendDebug('CallHAService', 'cURL error: ' . curl_error($curl), 0);
        }
        curl_close($curl);
        $ok = ($result !== false && $http >= 200 && $http < 300);
        if (!$ok) {
            $this->SendDebug('CallHAService', 'HTTP code ' . $http . ', response: ' . substr((string)$result, 0, 500), 0);
        }
        return $ok;
    }
    
    /**
     * Subscribe to relevant MQTT topics
     */
    protected function SubscribeToTopics()
    {
        $discoveryPrefix = $this->ReadPropertyString('ha_discovery_prefix');
        
        // Subscribe to state topics for existing devices
        $devices = $this->GetHaDeviceInstances();
        foreach ($devices as $entityId => $instanceId) {
            $stateTopic = $discoveryPrefix . '/' . str_replace('.', '/', $entityId) . '/state';
            $this->SubscribeTopic($stateTopic);
        }
        
        // Store subscribed topics
        $topics = [];
        foreach (array_keys($devices) as $eId) {
            $topics[] = $discoveryPrefix . '/' . str_replace('.', '/', $eId) . '/state';
        }
        
        $this->WriteAttributeString('SubscribedTopics', json_encode($topics));
    }
    
    /**
     * Subscribe to MQTT topic via MQTT Server
     */
    protected function SubscribeTopic($topic)
    {
        $data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType' => 8, // PT_SUBSCRIBE
            'Topic' => $topic
        ];
        
        $result = @$this->SendDataToParent(json_encode($data));
        
        if ($result === false) {
            $this->SendDebug('SubscribeTopic', 'Failed to subscribe to: ' . $topic, 0);
        }
    }
    
    /**
     * Check if topic is a state topic
     */
    protected function IsStateTopic($topic): bool
    {
        // Only accept topics that end exactly with '/state'
        return (bool)preg_match('#/state$#', (string)$topic);
    }
    
    // Auto-Discovery support removed; discovery messages are no longer processed.
    
    /**
     * Process state updates from Home Assistant
     */
    protected function ProcessStateUpdate($topic, $payload)
    {
        $entityId = $this->ExtractEntityIdFromStateTopic($topic);
        if (!$entityId) {
            return;
        }
        // Backward-compatible method retained; now broadcast instead of targeting instance
        $this->BroadcastStateUpdate($entityId, $payload);
    }

    /**
     * Broadcast state update to all child devices via DataFlow
     */
    protected function BroadcastStateUpdate(string $entityId, $payload)
    {
        // Normalize payload to array { state, attributes? }
        $data = json_decode((string)$payload, true);
        if (!is_array($data)) {
            $data = ['state' => $payload];
        }
        $packet = [
            'DataID'   => '{C78CF679-C945-4AEE-BE58-A5616D85A6B8}',
            'EntityID' => $entityId,
            'Payload'  => $data
        ];
        $this->SendDataToChildren(json_encode($packet));
    }
    
    // Auto-Discovery support removed; no extraction from discovery topics.
    
    /**
     * Extract entity ID from state topic
     */
    protected function ExtractEntityIdFromStateTopic($topic): ?string
    {
        if (preg_match('#homeassistant/([^/]+)/([^/]+)/state$#', (string)$topic, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        return null;
    }
    
    /**
     * Find HaDevice instance by entity ID
     */
    protected function FindHaDeviceByEntityId($entityId): ?int
    {
        // Check entity mapping first
        $mapping = json_decode($this->ReadAttributeString('EntityMapping'), true);
        if (isset($mapping[$entityId])) {
            $instanceId = $mapping[$entityId];
            if (IPS_InstanceExists($instanceId)) {
                return $instanceId;
            }
        }
        
        // Search all HaDevice instances
        $instanceIds = IPS_GetInstanceListByModuleID('{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}');
        foreach ($instanceIds as $instanceId) {
            if (IPS_InstanceExists($instanceId)) {
                try {
                    $instanceEntityId = @IPS_GetProperty($instanceId, 'entity_id');
                    if ($instanceEntityId === $entityId) {
                        // Update mapping
                        $mapping[$entityId] = $instanceId;
                        $this->WriteAttributeString('EntityMapping', json_encode($mapping));
                        return $instanceId;
                    }
                } catch (Exception $e) {
                    // Property doesn't exist - skip instance
                }
            }
        }
        
        return null;
    }
    
    /**
     * Forward state update to HaDevice instance
     */
    protected function ForwardStateUpdate($instanceId, $payload)
    {
        if (!IPS_InstanceExists($instanceId)) {
            return;
        }
        
        try {
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                $data = ['state' => $payload];
            }
            
            // Get entity_id for this instance
            $entityId = @IPS_GetProperty($instanceId, 'entity_id');
            $data['entity_id'] = $entityId;
            
            // Forward to HaDevice via RequestAction
            $result = @IPS_RequestAction($instanceId, 'ProcessMQTTStateUpdate', $data);
            
            if ($result) {
                $this->SendDebug('ForwardStateUpdate', 'Successfully forwarded to instance ' . $instanceId, 0);
            } else {
              //  $this->SendDebug('ForwardStateUpdate', 'Failed to forward to instance ' . $instanceId, 0);
                // Fallback: direct variable update
                $this->UpdateVariableDirect($instanceId, $data);
            }
            
        } catch (Exception $e) {
            $this->SendDebug('ForwardStateUpdate', 'Error: ' . $e->getMessage(), 0);
        }
    }
    
    /**
     * Fallback: Update variable directly
     */
    protected function UpdateVariableDirect($instanceId, $payload)
    {
        if (!is_array($payload) || !isset($payload['state'])) {
            return;
        }
        
        $statusVarId = @IPS_GetObjectIDByIdent('Status', $instanceId);
        if ($statusVarId === false) {
            return;
        }
        
        try {
            $varInfo = IPS_GetVariable($statusVarId);
            $value = $payload['state'];
            $rawStr = is_scalar($value) ? strtolower(trim((string)$value)) : '';
            $isUnknown = in_array($rawStr, ['unavailable','unknown','none','null','']);
            
            if ($isUnknown && in_array($varInfo['VariableType'], [VARIABLETYPE_BOOLEAN, VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                // Ignore unknown/unavailable for numeric/bool to keep last known value
                $this->SendDebug('UpdateVariableDirect', 'Ignored state ' . (string)$value . ' for numeric/bool variable', 0);
                return;
            }
            
            switch ($varInfo['VariableType']) {
                case VARIABLETYPE_BOOLEAN:
                    $value = is_bool($value) ? $value : 
                            in_array($rawStr, ['on','true','1','yes','home']);
                    break;
                case VARIABLETYPE_INTEGER:
                    if (!is_numeric($value)) {
                        $this->SendDebug('UpdateVariableDirect', 'Ignored non-numeric state for INTEGER variable: ' . (string)$value, 0);
                        return;
                    }
                    $value = (int)$value;
                    break;
                case VARIABLETYPE_FLOAT:
                    if (!is_numeric($value)) {
                        $this->SendDebug('UpdateVariableDirect', 'Ignored non-numeric state for FLOAT variable: ' . (string)$value, 0);
                        return;
                    }
                    $value = (float)$value;
                    break;
                default:
                    $value = (string)$value;
                    break;
            }

            // Enforce Value presentation for binary_sensor (no switch)
            $entityDomain = '';
            if (isset($payload['entity_id']) && is_string($payload['entity_id'])) {
                $dot = strpos($payload['entity_id'], '.');
                if ($dot !== false) {
                    $entityDomain = substr($payload['entity_id'], 0, $dot);
                }
            }
            if ($varInfo['VariableType'] === VARIABLETYPE_BOOLEAN && $entityDomain === 'binary_sensor') {
                $deviceClass = '';
                if (isset($payload['attributes']) && is_array($payload['attributes']) && isset($payload['attributes']['device_class'])) {
                    $deviceClass = (string)$payload['attributes']['device_class'];
                }
                $presentation = $this->CreateBinarySensorValuePresentationByDeviceClass($deviceClass);
                if (!empty($presentation)) {
                    IPS_SetVariableCustomPresentation($statusVarId, $presentation);
                    if (isset($presentation['ICON'])) {
                        IPS_SetIcon($statusVarId, (string)$presentation['ICON']);
                    }
                } else {
                    // Fallback: Value presentation only
                    IPS_SetVariableCustomPresentation($statusVarId, ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}']);
                }
            }
            
            SetValue($statusVarId, $value);
            
        } catch (Exception $e) {
            $this->SendDebug('UpdateVariableDirect', 'Error: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Create Value presentation for binary_sensor based on device_class mapping.
     * Returns presentation array including ICON and boolean OPTIONS captions.
     */
    protected function CreateBinarySensorValuePresentationByDeviceClass(string $deviceClass): array
    {
        if ($deviceClass === '') {
            return [];
        }
        // device_class => [TRUE Caption, FALSE Caption, Icon]
        $map = [
            'battery'           => ['Batterie niedrig', 'Batterie ok', 'battery-alert'],
            'battery_charging'  => ['lädt', 'lädt nicht', 'battery-bolt'],
            'carbon_monoxide'   => ['CO erkannt', 'kein CO', 'cloud-bolt'],
            'cold'              => ['kalt', 'normal', 'snowflake'],
            'connectivity'      => ['verbunden', 'getrennt', 'wifi'],
            'door'              => ['offen', 'geschlossen', 'door-open'],
            'garage_door'       => ['offen', 'geschlossen', 'garage-open'],
            'gas'               => ['Gas erkannt', 'kein Gas', 'cloud-bolt'],
            'heat'              => ['heiß', 'normal', 'fire'],
            'light'             => ['Licht erkannt', 'kein Licht', 'lightbulb-on'],
            'lock'              => ['entsperrt', 'gesperrt', 'lock-open'],
            'moisture'          => ['nass', 'trocken', 'droplet'],
            'motion'            => ['Bewegung erkannt', 'keine Bewegung', 'person-running'],
            'moving'            => ['in Bewegung', 'stillstehend', 'person-running'],
            'occupancy'         => ['belegt', 'frei', 'house-person-return'],
            'opening'           => ['offen', 'geschlossen', 'up-right-from-square'],
            'plug'              => ['eingesteckt', 'ausgesteckt', 'plug'],
            'power'             => ['Strom erkannt', 'kein Strom', 'bolt'],
            'presence'          => ['anwesend', 'abwesend', 'user'],
            'problem'           => ['Problem erkannt', 'kein Problem', 'triangle-exclamation'],
            'running'           => ['läuft', 'gestoppt', 'play'],
            'safety'            => ['unsicher/gefährlich', 'sicher', 'shield-exclamation'],
            'smoke'             => ['Rauch erkannt', 'kein Rauch', 'fire-smoke'],
            'sound'             => ['Geräusch erkannt', 'kein Geräusch', 'volume-high'],
            'tamper'            => ['Manipulation erkannt', 'keine Manipulation', 'hand'],
            'update'            => ['Update verfügbar', 'aktuell', 'arrows-rotate'],
            'vibration'         => ['Vibration erkannt', 'keine Vibration', 'chart-fft'],
            'window'            => ['offen', 'geschlossen', 'window-open'],
        ];
        if (!isset($map[$deviceClass])) {
            return [];
        }
        [$trueCaption, $falseCaption, $icon] = $map[$deviceClass];
        $options = json_encode([
            [
                'Value' => false,
                'Caption' => $falseCaption,
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ],
            [
                'Value' => true,
                'Caption' => $trueCaption,
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ]
        ]);
        return [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'OPTIONS'      => $options,
            'ICON'         => $icon
        ];
    }
    
    
    // Auto-Discovery support removed; no discovery-based device removal.
    
    /**
     * Get all HaDevice instances
     */
    protected function GetHaDeviceInstances(): array
    {
        $devices = [];
        $instanceIds = IPS_GetInstanceListByModuleID('{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}');
        
        foreach ($instanceIds as $instanceID) {
            if (IPS_InstanceExists($instanceID)) {
                try {
                    $entityId = @IPS_GetProperty($instanceID, 'entity_id');
                    if (!empty($entityId)) {
                        $devices[$entityId] = $instanceID;
                    }
                } catch (Exception $e) {
                    // Property doesn't exist - skip instance
                }
            }
        }
        
        return $devices;
    }
    
    // Auto-Discovery run method removed; realtime state updates are always active.
    
    /**
     * Publish MQTT message via MQTT Server
     */
    protected function PublishMQTT($topic, $payload, $retain = false)
    {
        $payloadStr = is_string($payload) ? $payload : json_encode($payload);
        
        $data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType' => 3, // PT_PUBLISH
            'QualityOfService' => 0,
            'Retain' => $retain,
            'Topic' => $topic,
            'Payload' => bin2hex($payloadStr)
        ];
        
        @$this->SendDataToParent(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    
    /**
     * Check if active MQTT parent exists
     */
    protected function HasActiveParent(): bool
    {
        $parentId = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if (!$parentId || !IPS_InstanceExists($parentId)) {
            return false;
        }
        
        $parentStatus = IPS_GetInstance($parentId)['InstanceStatus'];
        return $parentStatus === 102;
    }
    
    /**
     * Enable MQTT updates for all existing HaDevice instances
     */
    public function EnableMQTTForExistingDevices()
    {
        $instanceIds = IPS_GetInstanceListByModuleID('{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}');
        $count = 0;
        
        foreach ($instanceIds as $instanceId) {
            if (IPS_InstanceExists($instanceId)) {
                try {
                    $entityId = IPS_GetProperty($instanceId, 'entity_id');
                    if (!empty($entityId)) {
                        $mapping = json_decode($this->ReadAttributeString('EntityMapping'), true);
                        $mapping[$entityId] = $instanceId;
                        $this->WriteAttributeString('EntityMapping', json_encode($mapping));
                        $count++;
                    }
                } catch (Exception $e) {
                    // Property doesn't exist - skip
                }
            }
        }
        
        return true;
    }
}
