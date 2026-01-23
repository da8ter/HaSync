<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/HaRestHelper.php';

/**
 * HaBridge - Home Assistant MQTT Bridge für IP-Symcon
 * Erhält MQTT Updates und verteilt sie an HaDevice Instanzen
 * 
 * @version 2.0.0
 * @author Windsurf.io
 */
class HaBridge extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->SetVisualizationType(1);
        
        // Properties
        $this->RegisterPropertyString('ClientID', 'HaBridge_' . $this->InstanceID);
        $this->RegisterPropertyString('ha_discovery_prefix', 'homeassistant');
        $this->RegisterPropertyString('ha_url', '');
        $this->RegisterPropertyString('ha_token', '');
        
        // Attributes
        $this->RegisterAttributeString('SubscribedTopics', json_encode([]));
        $this->RegisterAttributeString('EntityMapping', '{}');
        $this->RegisterAttributeString('EntityStateCache', '{}');
        
        // Neutralize legacy discovery timer (kept with 0 interval and no-op callback)
        $this->RegisterTimer('DiscoveryTimer', 0, ';');
    }

    public function GetCompatibleParents()
    {
        return '{"type": "connect", "moduleIDs": ["{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}"]}';
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $connID = 0;
        try {
            $connID = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        } catch (Exception $e) {
            $connID = 0;
        }

        if ($connID === 0) {
            $this->SetStatus(104);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->SetStatus(200);
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
        // MQTT Server liefert Payloads i.d.R. hexkodiert -> dekodieren
        $payload = $this->DecodePayload($data['Payload']);
        
        if (empty($topic)) {
            return '';
        }
        
        try {
            if ($this->IsRelevantTopic($topic)) {
                // Unterstützt nun auch attribute-spezifische Topics wie /brightness, /xy_color, ...
                [$entityId, $key] = $this->ExtractEntityIdAndKeyFromTopic($topic);
                if ($entityId) {
                    if ($key === 'state') {
                        $decoded = json_decode($payload, true);
                        $val = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $payload;
                        $update = ['state' => $val];
                        $this->UpdateEntityStateCache($entityId, $update);
                        $this->BroadcastStateUpdate($entityId, $update);
                    } elseif ($key === 'attributes') {
                        $decoded = json_decode($payload, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            return '';
                        }
                        $update = [];
                        if (is_array($decoded) && isset($decoded['attributes']) && is_array($decoded['attributes'])) {
                            $update['attributes'] = $decoded['attributes'];
                            if (array_key_exists('state', $decoded)) {
                                $update['state'] = $decoded['state'];
                            }
                        } elseif (is_array($decoded)) {
                            $update['attributes'] = $decoded;
                        } else {
                            $update['attributes'] = ['value' => $decoded];
                        }
                        $this->UpdateEntityStateCache($entityId, $update);
                        $this->BroadcastStateUpdate($entityId, $update);
                    } else {
                        // Einzel-Attribut: baue Payload { attributes: { <key>: <value> } }
                        $decoded = json_decode($payload, true);
                        $val = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $payload;
                        $update = ['attributes' => [$key => $val]];
                        $this->UpdateEntityStateCache($entityId, $update);
                        $this->BroadcastStateUpdate($entityId, $update);
                    }
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
                case 'UpdateSubscriptions':
                    // Refresh topic subscriptions (e.g., after creating Multi-Entity devices)
                    $this->SubscribeToTopics();
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

        $url = (string)$this->ReadPropertyString('ha_url');
        $token = (string)$this->ReadPropertyString('ha_token');
        if ($url !== '' && $token !== '') {
            $result['url'] = $url;
            $result['token'] = $token;
            return $result;
        }

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
        $r = HaRestHelper::PostJson($ha['url'], $ha['token'], '/api/services/' . ltrim($service, '/'), $data, 15, 5);
        if (!($r['ok'] ?? false)) {
            $this->SendDebug('CallHAService', 'cURL error: ' . (string)($r['error'] ?? ''), 0);
            return false;
        }
        $http = (int)($r['http'] ?? 0);
        $ok = ($http >= 200 && $http < 300);
        if (!$ok) {
            $this->SendDebug('CallHAService', 'HTTP code ' . $http . ', response: ' . substr((string)($r['body'] ?? ''), 0, 500), 0);
        }
        return $ok;
    }
    
    /**
     * Subscribe to relevant MQTT topics
     */
    protected function SubscribeToTopics()
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug('SubscribeToTopics', 'No active MQTT parent - cannot (re)subscribe', 0);
            return;
        }
        $discoveryPrefix = rtrim($this->ReadPropertyString('ha_discovery_prefix'), '/');
        
        // Subscribe to all subtopics per Entity (state + attribute-spezifische Topics)
        $entities = $this->GetAllManagedEntities();
        foreach ($entities as $entityId => $instanceId) {
            $base = $discoveryPrefix . '/' . str_replace('.', '/', $entityId);
            $ok = $this->SubscribeTopic($base . '/#');
            if (!$ok) {
                $this->SubscribeTopic($base . '/state');
                $this->SubscribeTopic($base . '/attributes');
            }
        }
        
        // Store subscribed topics
        $topics = [];
        foreach (array_keys($entities) as $eId) {
            $base = $discoveryPrefix . '/' . str_replace('.', '/', $eId);
            $topics[] = $base . '/#';
        }
        
        $this->WriteAttributeString('SubscribedTopics', json_encode($topics));
    }
    
    /**
     * Subscribe to MQTT topic via MQTT Server
     */
    protected function SubscribeTopic($topic): bool
    {
        $topic = (string)$topic;
        if ($topic === '') {
            return false;
        }

        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        $parentStatus = 0;
        if ($parentId > 0) {
            try {
                $parentStatus = (int)(@IPS_GetInstance($parentId)['InstanceStatus'] ?? 0);
            } catch (Exception $e) {
                $parentStatus = 0;
            }
        }

        $data = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'       => 8,
            'Topic'            => $topic,
            'QualityOfService' => 0,
            'Retain'           => false
        ];

        $result = @$this->SendDataToParent(json_encode($data, JSON_UNESCAPED_SLASHES));
        if ($result === false) {
            $this->SendDebug('SubscribeTopic', 'Failed to subscribe to: ' . $topic . ' (ParentID=' . $parentId . ', ParentStatus=' . $parentStatus . ')', 0);
            return false;
        }
        return true;
    }
    
    /**
     * Check if topic is a state topic
     */
    protected function IsStateTopic($topic): bool
    {
        // Only accept topics that end exactly with '/state'
        return (bool)preg_match('#/state$#', (string)$topic);
    }
    
    /**
     * Accept both '/state' and '/attributes' topics
     */
    protected function IsRelevantTopic($topic): bool
    {
        // Akzeptiere alle Topics unterhalb <prefix>/<domain>/<entity>/...
        $prefix = rtrim($this->ReadPropertyString('ha_discovery_prefix'), '/');
        $rx = '#^' . preg_quote($prefix, '#') . '/[^/]+/[^/]+/.+#';
        return (bool)preg_match($rx, (string)$topic);
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
        $this->BroadcastStateUpdate($entityId, $this->DecodePayload($payload));
    }

    /**
     * Broadcast state update to all child devices via DataFlow
     */
    protected function BroadcastStateUpdate(string $entityId, $payload)
    {
        // Normalize payload to array { state, attributes? }
        if (is_array($payload)) {
            $data = $payload;
        } else {
            $data = json_decode((string)$payload, true);
            if (!is_array($data)) {
                $data = ['state' => $payload];
            }
        }
        $packet = [
            'DataID'   => '{C78CF679-C945-4AEE-BE58-A5616D85A6B8}',
            'EntityID' => $entityId,
            'Payload'  => $data
        ];
        
        $json = json_encode($packet);
        if ($json === false) {
            $this->SendDebug('BroadcastStateUpdate', 'JSON Encode failed: ' . json_last_error_msg(), 0);
            return;
        }
        $this->SendDataToChildren($json);
    }

    protected function LoadEntityStateCache(): array
    {
        $j = $this->ReadAttributeString('EntityStateCache');
        $arr = json_decode($j, true);
        return is_array($arr) ? $arr : [];
    }

    protected function SaveEntityStateCache(array $cache): void
    {
        $this->WriteAttributeString('EntityStateCache', json_encode($cache));
    }

    protected function UpdateEntityStateCache(string $entityId, array $update): array
    {
        $cache = $this->LoadEntityStateCache();
        $cur = isset($cache[$entityId]) && is_array($cache[$entityId]) ? $cache[$entityId] : [];

        if (array_key_exists('state', $update)) {
            $cur['state'] = $update['state'];
        }

        if (isset($update['attributes']) && is_array($update['attributes'])) {
            $attrs = isset($cur['attributes']) && is_array($cur['attributes']) ? $cur['attributes'] : [];
            foreach ($update['attributes'] as $k => $v) {
                $attrs[$k] = $v;
            }
            $cur['attributes'] = $attrs;
        }

        $cur['ts'] = time();
        $cache[$entityId] = $cur;
        $this->SaveEntityStateCache($cache);
        return $cur;
    }
    
    // Auto-Discovery support removed; no extraction from discovery topics.
    
    /**
     * Extract entity ID from state topic
     */
    protected function ExtractEntityIdFromStateTopic($topic): ?string
    {
        $prefix = rtrim($this->ReadPropertyString('ha_discovery_prefix'), '/');
        $rx = '#^' . preg_quote($prefix, '#') . '/([^/]+)/([^/]+)/state$#';
        if (preg_match($rx, (string)$topic, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        return null;
    }
    
    /**
     * Extract entity ID from both '/state' and '/attributes' topics
     */
    protected function ExtractEntityIdFromTopic($topic): ?string
    {
        $prefix = rtrim($this->ReadPropertyString('ha_discovery_prefix'), '/');
        $rx = '#^' . preg_quote($prefix, '#') . '/([^/]+)/([^/]+)/(state|attributes)$#';
        if (preg_match($rx, (string)$topic, $matches)) {
            return $matches[1] . '.' . $matches[2];
        }
        return null;
    }
    
    /**
     * Extract entity ID and trailing key (state or attribute name)
     */
    protected function ExtractEntityIdAndKeyFromTopic($topic): array
    {
        $prefix = rtrim($this->ReadPropertyString('ha_discovery_prefix'), '/');
        $rx = '#^' . preg_quote($prefix, '#') . '/([^/]+)/([^/]+)/([^/]+)$#';
        if (preg_match($rx, (string)$topic, $m)) {
            return [$m[1] . '.' . $m[2], $m[3]];
        }
        return [null, null];
    }
    
    /**
     * LEGACY: Find HaDevice instance by entity ID
     * @deprecated No longer used - broadcast system is active
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
     * LEGACY: Forward state update to HaDevice instance
     * @deprecated No longer used - broadcast system via BroadcastStateUpdate() is active
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
     * LEGACY FALLBACK: Update variable directly when broadcast fails
     * @deprecated Only used as last resort fallback in ForwardStateUpdate (which itself is deprecated)
     * Normal flow: BroadcastStateUpdate() → HaDevice.ReceiveData() → ProcessMQTTStateUpdate()
     */
    protected function UpdateVariableDirect($instanceId, $payload)
    {
        if (!is_array($payload)) {
            return;
        }
        
        $statusVarId = @IPS_GetObjectIDByIdent('Status', $instanceId);
        if ($statusVarId === false) {
            return;
        }
        
        try {
            // 1) Status aktualisieren, wenn vorhanden
            if (isset($payload['state'])) {
                $varInfo = IPS_GetVariable($statusVarId);
                $value = $payload['state'];
                $rawStr = is_scalar($value) ? strtolower(trim((string)$value)) : '';
                $isUnknown = in_array($rawStr, ['unavailable','unknown','none','null','']);

                if (!($isUnknown && in_array($varInfo['VariableType'], [VARIABLETYPE_BOOLEAN, VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true))) {
                    switch ($varInfo['VariableType']) {
                        case VARIABLETYPE_BOOLEAN:
                            $value = is_bool($value) ? $value : in_array($rawStr, ['on','true','1','yes','home']);
                            break;
                        case VARIABLETYPE_INTEGER:
                            if (!is_numeric($value)) {
                                $this->SendDebug('UpdateVariableDirect', 'Ignored non-numeric state for INTEGER variable: ' . (string)$value, 0);
                                $value = null; // skip set
                            } else {
                                $value = (int)$value;
                            }
                            break;
                        case VARIABLETYPE_FLOAT:
                            if (!is_numeric($value)) {
                                $this->SendDebug('UpdateVariableDirect', 'Ignored non-numeric state for FLOAT variable: ' . (string)$value, 0);
                                $value = null; // skip set
                            } else {
                                $value = (float)$value;
                            }
                            break;
                        default:
                            $value = (string)$value;
                            break;
                    }

                    if ($value !== null) {
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
                            // Only set presentation if none exists yet
                            $meta = @IPS_GetVariable($statusVarId);
                            $hasCustom = is_array($meta) && isset($meta['VariableCustomPresentation']) && is_array($meta['VariableCustomPresentation']) && !empty($meta['VariableCustomPresentation']);
                            if (!$hasCustom) {
                                $presentation = $this->CreateBinarySensorValuePresentationByDeviceClass($deviceClass);
                                if (!empty($presentation)) {
                                    IPS_SetVariableCustomPresentation($statusVarId, $presentation);
                                    // Set icon only if none set yet
                                    if (isset($presentation['ICON'])) {
                                        $obj = @IPS_GetObject($statusVarId);
                                        $currentIcon = is_array($obj) ? ($obj['ObjectIcon'] ?? '') : '';
                                        if ($currentIcon === '') {
                                            IPS_SetIcon($statusVarId, (string)$presentation['ICON']);
                                        }
                                    }
                                } else {
                                    // Fallback: Value presentation only
                                    IPS_SetVariableCustomPresentation($statusVarId, ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}']);
                                }
                            }
                        }

                        $this->SetIpsValueIfChanged($statusVarId, $value);
                    }
                }
            }

            // 2) Attribute-Variablen aktualisieren, falls vorhanden
            if (isset($payload['attributes']) && is_array($payload['attributes'])) {
                foreach ($payload['attributes'] as $key => $val) {
                    $ident = 'HAS_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key);
                    $varId = @IPS_GetObjectIDByIdent($ident, $instanceId);
                    if ($varId === false) {
                        continue; // keine Neuanlage im Fallback
                    }
                    $info = IPS_GetVariable($varId);
                    switch ($info['VariableType']) {
                        case VARIABLETYPE_BOOLEAN:
                            $bool = is_bool($val) ? $val : in_array(strtolower((string)$val), ['true','on','1','yes','home']);
                            $this->SetIpsValueIfChanged($varId, $bool);
                            break;
                        case VARIABLETYPE_INTEGER:
                            $this->SetIpsValueIfChanged($varId, (int)$val);
                            break;
                        case VARIABLETYPE_FLOAT:
                            $this->SetIpsValueIfChanged($varId, (float)$val);
                            break;
                        default:
                            $this->SetIpsValueIfChanged($varId, is_scalar($val) ? (string)$val : json_encode($val));
                    }
                }
            }
            
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
        $options = [
            [
                'Value' => false,
                'Caption' => $this->Translate($falseCaption),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ],
            [
                'Value' => true,
                'Caption' => $this->Translate($trueCaption),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ]
        ];
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

    /**
     * Collect all managed entities across HaDevice and HaMultiEntityDevice instances
     * Returns map entity_id => instanceId (owning instance)
     */
    protected function GetAllManagedEntities(): array
    {
        $out = $this->GetHaDeviceInstances();
        // Include HaMultiEntityDevice entities
        $multiIds = @IPS_GetInstanceListByModuleID('{5E0B3C3A-FD10-4E32-95D3-1B4EAA9A7C77}');
        if (is_array($multiIds)) {
            foreach ($multiIds as $id) {
                if (!IPS_InstanceExists($id)) {
                    continue;
                }
                try {
                    $j = @IPS_GetProperty($id, 'entities');
                    $arr = json_decode((string)$j, true);
                    if (is_array($arr)) {
                        foreach ($arr as $e) {
                            $eid = isset($e['entity_id']) ? (string)$e['entity_id'] : '';
                            if ($eid !== '') {
                                $out[$eid] = $id;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // ignore
                }
            }
        }
        return $out;
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
     * Decode hex-encoded MQTT payloads from the Symcon MQTT Server.
     * Falls der Payload bereits plain/text ist, wird er unverändert zurückgegeben.
     */
    protected function DecodePayload($payload)
    {
        if (!is_string($payload)) {
            return $payload;
        }
        // Rein numerische Strings (z.B. "37", "100") NICHT als Hex interpretieren
        if (preg_match('/^-?\d+\.?\d*$/', $payload)) {
            return $payload;
        }
        // Erkennen: nur Hex-Zeichen und gerade Länge
        if (preg_match('/^[0-9a-fA-F]+$/', $payload) && (strlen($payload) % 2 === 0)) {
            $bin = @hex2bin($payload);
            if ($bin !== false) {
                return $bin;
            }
        }
        return $payload;
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
     * Set variable value by VarID only if it has changed (with float tolerance).
     * Returns true if value was updated, false if unchanged or variable invalid.
     */
    protected function SetIpsValueIfChanged(int $varId, $newValue): bool
    {
        if ($varId <= 0 || !IPS_VariableExists($varId)) {
            return false;
        }
        $var = IPS_GetVariable($varId);
        $type = $var['VariableType'];
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                $normalized = (bool)$newValue;
                $current = (bool)GetValue($varId);
                if ($current === $normalized) {
                    return false;
                }
                SetValue($varId, $normalized);
                return true;
            case VARIABLETYPE_INTEGER:
                $normalized = (int)$newValue;
                $current = (int)GetValue($varId);
                if ($current === $normalized) {
                    return false;
                }
                SetValue($varId, $normalized);
                return true;
            case VARIABLETYPE_FLOAT:
                $normalized = (float)$newValue;
                $current = (float)GetValue($varId);
                if (abs($current - $normalized) < 1e-6) {
                    return false;
                }
                SetValue($varId, $normalized);
                return true;
            default:
                $normalized = is_string($newValue) ? $newValue : (string)$newValue;
                $current = (string)GetValue($varId);
                if ($current === $normalized) {
                    return false;
                }
                SetValue($varId, $normalized);
                return true;
        }
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
                    $entityId = @IPS_GetProperty($instanceId, 'entity_id');
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

    public function GetVisualizationTile()
    {
        return <<<'HTML'
<div id="root" style="font-family: var(--ha-font-family, Arial, sans-serif); padding: 12px;">
    <script src="/icons.js"></script>
    <style>
        .hasync-row { display:flex; gap: 8px; align-items:center; margin: 6px 0; }
        .hasync-kv { display:flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .hasync-k { font-weight: 600; }
        .hasync-v { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        .hasync-actions { display:flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        button { padding: 6px 10px; border-radius: 6px; border: 1px solid rgba(0,0,0,0.2); background: rgba(255,255,255,0.7); }
        button:hover { background: rgba(255,255,255,0.95); }
        .hasync-muted { opacity: 0.75; }
        .hasync-list { margin-top: 10px; }
        .hasync-list table { width:100%; border-collapse: collapse; }
        .hasync-list th, .hasync-list td { padding: 6px; border-bottom: 1px solid rgba(0,0,0,0.08); text-align:left; }
        .hasync-badge { display:inline-block; padding: 2px 8px; border-radius: 999px; border: 1px solid rgba(0,0,0,0.2); }
    </style>

    <div class="hasync-row">
        <i class="fa-kit fa-network-wired"></i>
        <h2 style="margin:0;">HaBridge Diagnostics</h2>
    </div>

    <div id="info" class="hasync-muted">Loading…</div>

    <div id="kv" style="margin-top: 10px;"></div>

    <div class="hasync-actions">
        <button onclick="requestAction('Diag_GetSnapshot', 0)">Refresh</button>
        <button onclick="requestAction('Diag_ClearCache', 0)">Clear Cache</button>
    </div>

    <div class="hasync-list">
        <div class="hasync-row" style="margin-top:12px;">
            <i class="fa-kit fa-list"></i>
            <div style="font-weight:600;">Cache Entries</div>
            <span id="count" class="hasync-badge">0</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Entity</th>
                    <th>Age (s)</th>
                    <th>State</th>
                    <th>Attributes</th>
                </tr>
            </thead>
            <tbody id="entities"></tbody>
        </table>
    </div>

    <script>
        function fmt(v) {
            if (v === null || v === undefined) return "";
            if (typeof v === 'object') {
                try { return JSON.stringify(v); } catch(e) { return String(v); }
            }
            return String(v);
        }

        function setKV(data) {
            const kv = document.getElementById('kv');
            kv.innerHTML = '';

            const rows = [
                ['Instance ID', data.instance_id],
                ['Instance Status', data.instance_status],
                ['MQTT Parent', data.parent_id],
                ['MQTT Parent Status', data.parent_status],
                ['Subscribed Topics', data.subscribed_topics_count],
                ['Managed Entities', data.managed_entities_count],
                ['Cache Entries', data.cache_entries_count],
                ['Newest Cache TS', data.cache_ts_max]
            ];

            rows.forEach(r => {
                const div = document.createElement('div');
                div.className = 'hasync-kv';
                div.innerHTML = `<div class="hasync-k">${translate(r[0])}</div><div class="hasync-v">${fmt(r[1])}</div>`;
                kv.appendChild(div);
            });
        }

        function setEntities(list) {
            const body = document.getElementById('entities');
            body.innerHTML = '';
            const count = document.getElementById('count');
            count.textContent = String(list.length);

            list.forEach(e => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${fmt(e.entity_id)}</td>
                    <td>${fmt(e.age_s)}</td>
                    <td>${fmt(e.state)}</td>
                    <td>${fmt(e.attributes_count)}</td>
                `;
                body.appendChild(tr);
            });
        }

        function handleMessage(data) {
            try {
                if (typeof data === 'string') {
                    data = JSON.parse(data);
                }
            } catch (e) {
                // ignore
            }
            if (!data || typeof data !== 'object') {
                return;
            }
            document.getElementById('info').textContent = translate('Last update') + ': ' + (data.now || '');
            setKV(data);
            setEntities(Array.isArray(data.cache_entries) ? data.cache_entries : []);
        }

        requestAction('Diag_GetSnapshot', 0);
    </script>
</div>
HTML;
    }

    public function RequestAction($Ident, $Value)
    {
        switch ((string)$Ident) {
            case 'Diag_GetSnapshot':
                $this->SendVisualizationSnapshot();
                return;
            case 'Diag_ClearCache':
                $this->WriteAttributeString('EntityStateCache', '{}');
                $this->SendVisualizationSnapshot();
                return;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    protected function SendVisualizationSnapshot(): void
    {
        $snapshot = $this->BuildVisualizationSnapshot();
        $this->UpdateVisualizationValue(json_encode($snapshot));
    }

    protected function BuildVisualizationSnapshot(): array
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        $parentStatus = 0;
        if ($parentId > 0 && IPS_InstanceExists($parentId)) {
            $parentStatus = (int)IPS_GetInstance($parentId)['InstanceStatus'];
        }

        $topics = json_decode($this->ReadAttributeString('SubscribedTopics'), true);
        $topicsCount = is_array($topics) ? count($topics) : 0;

        $managed = $this->GetAllManagedEntities();
        $managedCount = is_array($managed) ? count($managed) : 0;

        $cache = $this->LoadEntityStateCache();
        $cacheCount = is_array($cache) ? count($cache) : 0;
        $tsMax = 0;
        $entries = [];

        if (is_array($cache)) {
            foreach ($cache as $eid => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $ts = (int)($item['ts'] ?? 0);
                if ($ts > $tsMax) {
                    $tsMax = $ts;
                }
                $entries[] = [
                    'entity_id' => (string)$eid,
                    'age_s' => $ts > 0 ? (time() - $ts) : null,
                    'state' => array_key_exists('state', $item) ? $item['state'] : null,
                    'attributes_count' => isset($item['attributes']) && is_array($item['attributes']) ? count($item['attributes']) : 0
                ];
            }
        }

        usort($entries, function ($a, $b) {
            return ((int)($a['age_s'] ?? 0)) <=> ((int)($b['age_s'] ?? 0));
        });

        $entries = array_slice($entries, 0, 50);

        return [
            'now' => date('Y-m-d H:i:s'),
            'instance_id' => $this->InstanceID,
            'instance_status' => (int)($instance['InstanceStatus'] ?? 0),
            'parent_id' => $parentId,
            'parent_status' => $parentStatus,
            'subscribed_topics_count' => $topicsCount,
            'managed_entities_count' => $managedCount,
            'cache_entries_count' => $cacheCount,
            'cache_ts_max' => $tsMax,
            'cache_entries' => $entries
        ];
    }
}
