<?php

declare(strict_types=1);

/**
 * HaDevice - Home Assistant Device Integration für IP-Symcon
 * Repräsentiert eine einzelne Home Assistant Entität mit automatischer Variablenerstellung
 * 
 * @version 2.0.0
 * @author Windsurf.io
 */
class HaDevice extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // Entity Configuration
        $this->RegisterPropertyString('entity_id', '');
        $this->RegisterPropertyInteger('parent_id', 0);
        $this->RegisterPropertyBoolean('create_additional_vars', false);
        
        // MQTT Integration
        $this->RegisterPropertyBoolean('mqtt_enabled', false);
        $this->RegisterAttributeString('LastMQTTUpdate', '');
        $this->RegisterAttributeBoolean('Initialized', false);

        // Create or reuse exactly one HaBridge (Splitter) for all HaDevices
        $bridgeModuleID = '{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}';
        $sem = 'HaSync_HaBridge_Create';
        if (@IPS_SemaphoreEnter($sem, 10000)) {
            try {
                $bridges = @IPS_GetInstanceListByModuleID($bridgeModuleID);
                if (is_array($bridges) && count($bridges) > 0) {
                    // Reuse first existing HaBridge
                    @IPS_ConnectInstance($this->InstanceID, (int)$bridges[0]);
                } else {
                    // Explicitly create one HaBridge and connect this device
                    $bridgeId = IPS_CreateInstance($bridgeModuleID);
                    @IPS_SetName($bridgeId, 'HaBridge');
                    @IPS_ConnectInstance($this->InstanceID, $bridgeId);
                }
            } finally {
                @IPS_SemaphoreLeave($sem);
            }
        } else {
            // Fallback: do not create in contention; connect to existing if any
            $bridges = @IPS_GetInstanceListByModuleID($bridgeModuleID);
            if (is_array($bridges) && count($bridges) > 0) {
                @IPS_ConnectInstance($this->InstanceID, (int)$bridges[0]);
            }
        }
    }

    /**
     * Remove all additional variables created by this module (prefixed with HAS_)
     * Keeps the main Status variable intact.
     */
    protected function CleanupAdditionalVariables(): void
    {
        try {
            $children = IPS_GetChildrenIDs($this->InstanceID);
            foreach ($children as $id) {
                $obj = IPS_GetObject($id);
                if ($obj['ObjectType'] !== 2 /* otVariable */) {
                    continue;
                }
                $ident = $obj['ObjectIdent'] ?? '';
                if ($ident === 'Status') {
                    continue;
                }
                if (strpos($ident, 'HAS_') === 0) {
                    @IPS_DeleteVariable($id);
                }
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    /**
     * Hint the management console to auto-create or attach a HaBridge as parent.
     * See Symcon docs: GetCompatibleParents
     */
    public function GetCompatibleParents()
    {
        return '{"type": "require", "moduleIDs": ["{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}"]}';
    }


    /**
     * Receive data from parent (HaBridge Splitter)
     * Expects packets with DataID {C78CF679-C945-4AEE-BE58-A5616D85A6B8}
     * and payload structure: { EntityID, Payload: { state?, attributes? } }
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['DataID'])) {
            return;
        }
        if ($data['DataID'] !== '{C78CF679-C945-4AEE-BE58-A5616D85A6B8}') {
            return; // Not for us
        }
        $entityId = (string)($data['EntityID'] ?? '');
        if ($entityId === '') {
            return;
        }
        $myEntity = $this->ReadPropertyString('entity_id');
        if ($myEntity === '' || $entityId !== $myEntity) {
            return; // Different entity
        }
        $payload = $data['Payload'] ?? [];
        $out = ['entity_id' => $entityId];
        if (is_array($payload)) {
            if (array_key_exists('state', $payload)) {
                $out['state'] = $payload['state'];
            }
            if (isset($payload['attributes']) && is_array($payload['attributes'])) {
                $out['attributes'] = $payload['attributes'];
            } else {
                $attr = $payload;
                unset($attr['state']);
                if (!empty($attr)) {
                    $out['attributes'] = $attr;
                }
            }
        } elseif (is_string($payload) && $payload !== '') {
            $out['state'] = $payload;
        }
        $this->ProcessMQTTStateUpdate(json_encode($out));
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Ensure instance is marked as created/active before any early returns
        $this->SetStatus(102);
        $createExtra = $this->ReadPropertyBoolean('create_additional_vars');
        // If already initialized AND the user does NOT want additional variables,
        // do a minimal refresh (Status only) and exit.
        if ($this->ReadAttributeBoolean('Initialized') && !$createExtra) {
            $entityId = $this->ReadPropertyString('entity_id');
            if ($entityId === '') {
                return;
            }

            // Try to fetch the current state from Home Assistant
            $device = null;
            $haConfig = $this->GetHAConfig();
            if (is_array($haConfig) && $haConfig['url'] !== '' && $haConfig['token'] !== '') {
                $devices = $this->FetchDevices($haConfig);
                if (is_array($devices)) {
                    foreach ($devices as $entity) {
                        if (isset($entity['entity_id']) && $entity['entity_id'] === $entityId) {
                            $device = $entity;
                            break;
                        }
                    }
                }
            }

            // Update Status variable value and ensure presentation (no other variables)
            if ($device !== null && isset($device['state']) && $this->GetIDForIdent('Status') !== false) {
                $statusId = $this->GetIDForIdent('Status');
                $varInfo = IPS_GetVariable($statusId);
                $actualVarType = $varInfo['VariableType'];
                $rawState = $device['state'];
                $rawStr = is_scalar($rawState) ? strtolower(trim((string)$rawState)) : '';
                $isUnknown = in_array($rawStr, ['unavailable','unknown','none','null','']);
                if ($isUnknown && in_array($actualVarType, [VARIABLETYPE_BOOLEAN, VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
                    $this->SendDebug('ApplyChanges Refresh', 'Ignored state', 0);
                } else {
                    switch ($actualVarType) {
                        case VARIABLETYPE_BOOLEAN:
                            $value = in_array($rawStr, ['on', 'true', '1', 'home']);
                            break;
                        case VARIABLETYPE_INTEGER:
                            $value = (int)$rawState;
                            break;
                        case VARIABLETYPE_FLOAT:
                            $value = (float)$rawState;
                            break;
                        default:
                            $value = (string)$rawState;
                            break;
                    }
                    $this->SetValue('Status', $value);
                }
                // Do NOT re-apply presentation here. Respect user changes after initial creation.
            }
            // If additional variables are disabled, ensure we remove any existing HAS_ variables
            if (!$createExtra) {
                $this->CleanupAdditionalVariables();
            }
            return;
        }
        
        $this->MigrateProperties();
        
        $entityId = $this->ReadPropertyString('entity_id');
        
        if ($entityId === '') {
            $this->MaintainVariable('Status', 'Status', VARIABLETYPE_STRING, '', 0, true);
            return;
        }
        
        // Get Home Assistant configuration
        $haConfig = $this->GetHAConfig();
        if (!is_array($haConfig) || $haConfig['url'] === '' || $haConfig['token'] === '') {
            $this->CreateFallbackStatusVariable($entityId);
            return;
        }
        
        // Fetch devices from Home Assistant
        $devices = $this->FetchDevices($haConfig);
        if (!is_array($devices) || empty($devices)) {
            $this->CreateFallbackStatusVariable($entityId);
            return;
        }
        
        // Find matching device
        $device = null;
        foreach ($devices as $entity) {
            if (isset($entity['entity_id']) && $entity['entity_id'] === $entityId) {
                $device = $entity;
                break;
            }
        }
        
        if ($device === null) {
            $this->CreateFallbackStatusVariable($entityId);
            return;
        }
        
        // Extract entity domain (e.g. 'light' from 'light.bedroom')
        $entityDomain = '';
        if (strpos($entityId, '.') !== false) {
            $entityDomain = substr($entityId, 0, strpos($entityId, '.'));
        }
        
        // Create status variable with correct type based on device state
        $attributes = $device['attributes'] ?? [];
        $varInfo = $this->DetermineVariableType('status', $device['state'] ?? '', $entityDomain, $attributes, true);
        $varType = $varInfo[0];
        $convertedValue = $varInfo[1];
        $profile = $varInfo[2];
        $editable = $varInfo[3];
        $presentation = $varInfo[4] ?? [];
        
        // Register status variable with presentation if available (always), else with profile
        if (!empty($presentation)) {
            // Use modern variable presentation instead of classic profiles
            $this->MaintainVariable('Status', 'Status', $varType, '', 0, true);
            $varId = $this->GetIDForIdent('Status');
            // Only set presentation if there is no custom presentation yet
            $varMeta = IPS_GetVariable($varId);
            $hasCustom = isset($varMeta['VariableCustomPresentation'])
                && is_array($varMeta['VariableCustomPresentation'])
                && !empty($varMeta['VariableCustomPresentation']);
            if (!$hasCustom) {
                IPS_SetVariableCustomPresentation($varId, $presentation);
            }
        } else {
            $this->MaintainVariable('Status', 'Status', $varType, $profile, 0, true);
        }
        $this->SetValue('Status', $convertedValue);
        
        // Enable action for editable domains
        if ($editable) {
            $this->EnableAction('Status');
        }
        
        // Set icon on Status variable
        // 1) Prefer explicit HA icon attribute when present
        if (isset($device['attributes']['icon'])) {
            $mappedIcon = $this->MapHAIconToSymcon($device['attributes']['icon']);
            if ($mappedIcon !== '') {
                $stateVarId = $this->GetIDForIdent('Status');
                $obj = IPS_GetObject($stateVarId);
                $currentIcon = $obj['ObjectIcon'] ?? '';
                if ($currentIcon === '') {
                    IPS_SetIcon($stateVarId, $mappedIcon);
                }
            }
        } elseif ($entityDomain === 'binary_sensor') {
            // 2) For binary_sensor: map device_class to an icon
            $presentation = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
            if (!empty($presentation) && isset($presentation['ICON'])) {
                $stateVarId = $this->GetIDForIdent('Status');
                $obj = IPS_GetObject($stateVarId);
                $currentIcon = $obj['ObjectIcon'] ?? '';
                if ($currentIcon === '') {
                    IPS_SetIcon($stateVarId, (string)$presentation['ICON']);
                }
            }
        }
        
        // Process attributes as variables (only when additional vars are enabled)
        if ($createExtra && isset($device['attributes']) && is_array($device['attributes'])) {
            foreach ($device['attributes'] as $key => $value) {
                // Do not skip: create variables for all attributes when enabled
                
                $ident = 'HAS_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key);
                
                // Determine variable type and configuration
                // For attribute variables, don't use entityDomain to avoid slider presentation
                $varInfo = $this->DetermineVariableType($key, $value, '', $device['attributes'] ?? [], false);
                $varType = $varInfo[0];
                $convertedValue = $varInfo[1];
                $profile = $varInfo[2];
                $editable = $varInfo[3];
                $presentation = $varInfo[4] ?? [];
                
                // Register variable with presentation if available
                if (!empty($presentation) && $varType === VARIABLETYPE_FLOAT) {
                    // Use modern variable presentation for numeric attributes
                    $this->MaintainVariable($ident, $key, VARIABLETYPE_FLOAT, '', 0, true);
                    $varId = $this->GetIDForIdent($ident);
                    $varMeta = IPS_GetVariable($varId);
                    $hasCustom = isset($varMeta['VariableCustomPresentation'])
                        && is_array($varMeta['VariableCustomPresentation'])
                        && !empty($varMeta['VariableCustomPresentation']);
                    if (!$hasCustom) {
                        IPS_SetVariableCustomPresentation($varId, $presentation);
                    }
                } else {
                    $this->MaintainVariable($ident, $key, $varType, $profile, 0, true);
                }
                $this->SetValue($ident, $convertedValue);
                
                // Hide attribute variables - only Status variable should be visible
                $varId = $this->GetIDForIdent($ident);
                IPS_SetHidden($varId, true);
                
                // Enable action for editable variables
                if ($editable) {
                    $this->EnableAction($ident);
                }
            }
        }
        // If user disabled additional variables, ensure cleanup of our prefixed variables
        if (!$createExtra) {
            $this->CleanupAdditionalVariables();
        }
        // Mark initialization as complete so future ApplyChanges() calls only refresh Status value
        $this->WriteAttributeBoolean('Initialized', true);
    }
    
    /**
     * Migration logic for new properties
     */
    protected function MigrateProperties()
    {
        // Migration completed in previous versions
    }
    
    /**
     * Creates a fallback status variable when Home Assistant is not available
     */
    protected function CreateFallbackStatusVariable(string $entityId)
    {
        $createExtra = $this->ReadPropertyBoolean('create_additional_vars');
        $entityDomain = '';
        if (strpos($entityId, '.') !== false) {
            $entityDomain = substr($entityId, 0, strpos($entityId, '.'));
        }
        
        // Determine type based on entity domain (no attributes available in fallback)
        $varInfo = $this->DetermineVariableType('status', null, $entityDomain, [], true);
        $varType = $varInfo[0];
        $defaultValue = $varInfo[1];
        $profile = $varInfo[2];
        $editable = $varInfo[3];
        $presentation = $varInfo[4] ?? [];
        
        // Create variable with determined type
        if (!empty($presentation)) {
            // Use modern variable presentation for fallback variables
            if ($varType === VARIABLETYPE_FLOAT) {
                $defaultPresentation = [
                    'PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', // Slider
                    'MIN' => 0,
                    'MAX' => 100,
                    'STEP_SIZE' => 1,
                    'SUFFIX' => '',
                    'DIGITS' => 0
                ];
            } elseif ($varType === VARIABLETYPE_BOOLEAN) {
                // Use presentation from DetermineVariableType or default
                $defaultPresentation = !empty($presentation) ? $presentation : [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => 'On',
                    'CAPTION_OFF' => 'Off'
                ];
            } else {
                $defaultPresentation = $presentation;
            }
            
            $this->MaintainVariable('Status', 'Status', $varType, '', 0, true);
            $varId = $this->GetIDForIdent('Status');
            IPS_SetVariableCustomPresentation($varId, $defaultPresentation);
        } else {
            $this->MaintainVariable('Status', 'Status', $varType, $profile, 0, true);
        }
        $this->SetValue('Status', $defaultValue);
        
        // Enable action for editable domains
        if ($editable && in_array($entityDomain, ['input_number', 'light', 'switch', 'input_boolean'])) {
            $this->EnableAction('Status');
        }
    }
    
    /**
     * Process attributes and create corresponding variables
     */
    protected function ProcessAttributes(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            // Skip metadata attributes and slider configuration attributes
            if (in_array($key, [
                'friendly_name', 'editable',                  // UI metadata (icon now processed)
                'initial', 'max', 'min', 'mode', 'step',      // Slider configuration
                'unit_of_measurement'                          // Unit metadata
            ])) {
                continue;
            }
            
            $ident = 'HAS_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key);
            
            $profile = '';
            $varType = VARIABLETYPE_STRING;
            
            // Determine variable type based on value
            if (is_bool($value)) {
                $varType = VARIABLETYPE_BOOLEAN;
                $profile = '~Switch';
            } elseif (is_int($value)) {
                $varType = VARIABLETYPE_INTEGER;
            } elseif (is_float($value)) {
                $varType = VARIABLETYPE_FLOAT;
                
                // Set appropriate profiles for numeric attributes
                if (stripos($key, 'temp') !== false) {
                    $profile = '~Temperature';
                } elseif (stripos($key, 'humid') !== false) {
                    $profile = '~Humidity';
                } elseif (stripos($key, 'bright') !== false) {
                    $profile = '~Intensity.255';
                }
            }
            
            // Create or maintain the variable
            $this->MaintainVariable($ident, $key, $varType, $profile, 0, true);
            
            // Hide all attribute variables (keep them for MQTT updates but not visible in UI)
            $varId = $this->GetIDForIdent($ident);
            IPS_SetHidden($varId, true);
            
            // Set the value based on type
            switch ($varType) {
                case VARIABLETYPE_BOOLEAN:
                    $boolValue = $value;
                    if (is_string($value)) {
                        $boolValue = in_array(strtolower($value), ['true', 'on', '1', 'yes']);
                    }
                    $this->SetValue($ident, $boolValue);
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->SetValue($ident, (int)$value);
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->SetValue($ident, (float)$value);
                    break;
                case VARIABLETYPE_STRING:
                default:
                    $this->SetValue($ident, is_scalar($value) ? (string)$value : json_encode($value));
                    break;
            }
        }
    }
    
    /**
     * Get Home Assistant configuration from parent
     */
    protected function GetHAConfig(): array
    {
        $parentID = $this->ReadPropertyInteger('parent_id');
        
        if ($parentID === 0) {
            return ['url' => '', 'token' => ''];
        }
        
        if (!IPS_InstanceExists($parentID)) {
            return ['url' => '', 'token' => ''];
        }
        
        try {
            $parentInfo = IPS_GetInstance($parentID);
            
            if ($parentInfo['ModuleInfo']['ModuleID'] === '{32D99DCD-A530-4907-3FB0-44D7D472771D}') {
                $url = IPS_GetProperty($parentID, 'ha_url');
                $token = IPS_GetProperty($parentID, 'ha_token');
                
                return [
                    'url' => is_string($url) ? $url : '',
                    'token' => is_string($token) ? $token : ''
                ];
            }
        } catch (Exception $e) {
            // Ignore errors
        }
        
        return ['url' => '', 'token' => ''];
    }
    
    /**
     * Fetch devices from Home Assistant
     */
    protected function FetchDevices(array $haConfig): ?array
    {
        if ($haConfig['url'] === '' || $haConfig['token'] === '') {
            return null;
        }
        
        $apiUrl = rtrim($haConfig['url'], '/') . '/api/states';
        
        $headers = [
            'Authorization: Bearer ' . $haConfig['token'],
            'Content-Type: application/json'
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($result === false || $httpCode !== 200) {
            return null;
        }
        
        $devices = json_decode($result, true);
        return is_array($devices) ? $devices : null;
    }
    
 /**
 * Process MQTT state update
 *
 * Akzeptiert jetzt …
 *  • volle Payloads   mit "state" und/oder "attributes"
 *  • reine Attribute-Payloads  ohne "state"
 *  • Topic-Spezialfälle wie …/last_updated (entity_id-Suffix wird toleriert)
 *
 * Pflicht bleibt: "entity_id".
 */
public function ProcessMQTTStateUpdate(string $data): bool
{
    /* ---------- 0) Dekodieren & Grund-Checks ---------- */
    $payload = json_decode($data, true);
    if (!is_array($payload) || !isset($payload['entity_id'])) {
        return false;                                       // entity_id bleibt Pflicht
    }

    // Akzeptiere nur exakte entity_id für diese Instanz
    $entityIdBase = $this->ReadPropertyString('entity_id');
    if ($payload['entity_id'] !== $entityIdBase) {
        return false;                                       // fremde Entität → Abbruch
    }

    // Wenn weder 'state' noch 'attributes' existieren,
    // aber weitere Schlüssel → alles als Attribute auffassen
    if (!array_key_exists('state', $payload) && !array_key_exists('attributes', $payload)) {
        if (count($payload) > 1) {                          // außer entity_id gibt es noch Daten
            $attributesRaw = $payload;
            unset($attributesRaw['entity_id']);
            $payload = [
                'entity_id'  => $payload['entity_id'],
                'attributes' => $attributesRaw
            ];
        } else {
            return false;                                   // wirklich leer: ignorieren
        }
    }

    /* ---------- 1) Status-Variable (nur wenn 'state' vorhanden) ---------- */
    if (array_key_exists('state', $payload) && $this->GetIDForIdent('Status') !== false) {
        $statusId = $this->GetIDForIdent('Status');
        $varInfo  = IPS_GetVariable($statusId);

        $raw    = $payload['state'];
        $rawStr = is_scalar($raw) ? strtolower(trim((string)$raw)) : '';
        $isUnknown = in_array($rawStr, ['unavailable','unknown','none','null','']);

        // Für numerische/boolesche Variablen: 'unavailable/unknown' ignorieren, um 0/false zu vermeiden
        if ($isUnknown && in_array($varInfo['VariableType'], [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT, VARIABLETYPE_BOOLEAN], true)) {
            $this->SendDebug('ProcessMQTTStateUpdate', 'Ignored state "' . (string)$raw . '" for numeric/bool variable to keep last value', 0);
        } else {
            $skipStateSet = false;
            switch ($varInfo['VariableType']) {
                case VARIABLETYPE_BOOLEAN:
                    $value = is_bool($raw)
                        ? $raw
                        : in_array($rawStr, ['on','true','1','yes','home']);
                    break;
                case VARIABLETYPE_INTEGER:
                    // Prevent accidental 0 by ignoring non-numeric states for numeric variables
                    if (!is_numeric($raw)) {
                        $this->SendDebug('ProcessMQTTStateUpdate', 'Ignored non-numeric state for INTEGER variable: ' . (string)$raw, 0);
                        $skipStateSet = true; // skip SetValue and presentation updates
                        break;
                    }
                    $value = (int)$raw;
                    break;
                case VARIABLETYPE_FLOAT:
                    // Prevent accidental 0 by ignoring non-numeric states for numeric variables
                    if (!is_numeric($raw)) {
                        $this->SendDebug('ProcessMQTTStateUpdate', 'Ignored non-numeric state for FLOAT variable: ' . (string)$raw, 0);
                        $skipStateSet = true; // skip SetValue and presentation updates
                        break;
                    }
                    $value = (float)$raw;
                    break;
                default:
                    $value = (string)$raw;
            }
            if (!$skipStateSet) {
                $this->SetValue('Status', $value);
                // Apply Value presentation with OPTIONS for binary_sensor (no switch)
                $entityDomain = '';
                $dot = strpos($entityIdBase, '.');
                if ($dot !== false) {
                    $entityDomain = substr($entityIdBase, 0, $dot);
                }
                if ($entityDomain === 'binary_sensor') {
                    // Apply presentation only if none exists yet (respect user's changes)
                    $varMeta = IPS_GetVariable($statusId);
                    $hasCustom = isset($varMeta['VariableCustomPresentation'])
                        && is_array($varMeta['VariableCustomPresentation'])
                        && !empty($varMeta['VariableCustomPresentation']);
                    if (!$hasCustom) {
                        $presentation = $this->CreateBinarySensorPresentationByDeviceClass($payload['attributes'] ?? []);
                        if (!empty($presentation)) {
                            IPS_SetVariableCustomPresentation($statusId, $presentation);
                            // Also set icon only if none is set yet
                            if (isset($presentation['ICON'])) {
                                $obj = IPS_GetObject($statusId);
                                $currentIcon = $obj['ObjectIcon'] ?? '';
                                if ($currentIcon === '') {
                                    IPS_SetIcon($statusId, (string)$presentation['ICON']);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /* ---------- 1b) Icon-Update bei Attribut 'icon' ---------- */
    $createExtra = $this->ReadPropertyBoolean('create_additional_vars');
    if ($createExtra && isset($payload['attributes']) && is_array($payload['attributes']) && isset($payload['attributes']['icon'])) {
        $iconName = $this->MapHAIconToSymcon((string)$payload['attributes']['icon']);
        $statusVarId = $this->GetIDForIdent('Status');
        if ($iconName !== '' && $statusVarId !== false) {
            $obj = IPS_GetObject($statusVarId);
            $currentIcon = $obj['ObjectIcon'] ?? '';
            if ($currentIcon === '') {
                IPS_SetIcon($statusVarId, $iconName);
            }
        }
    }

    /* ---------- 2) Attribut-Variablen aktualisieren (nur vorhandene) ---------- */
    if (isset($payload['attributes']) && is_array($payload['attributes'])) {
        foreach ($payload['attributes'] as $key => $val) {
            // Skip meta attributes only if additional variable creation is disabled
            $skipKeys = $createExtra ? [] : [
                'icon','initial','max','min','mode','step','unit_of_measurement',
                'friendly_name','editable'
            ];
            if (in_array($key, $skipKeys)) {
                continue;
            }

            $ident = 'HAS_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key);
            $varId = $this->GetIDForIdent($ident);
            if ($varId === false) {                         // keine neue Variable anlegen
                continue;
            }

            $varInfo = IPS_GetVariable($varId);
            switch ($varInfo['VariableType']) {
                case VARIABLETYPE_BOOLEAN:
                    $bool = is_bool($val)
                        ? $val
                        : in_array(strtolower((string)$val),
                                  ['true','on','1','yes','home']);
                    $this->SetValue($ident, $bool);
                    break;

                case VARIABLETYPE_INTEGER:
                    $this->SetValue($ident, (int)$val);
                    break;

                case VARIABLETYPE_FLOAT:
                    $this->SetValue($ident, (float)$val);
                    break;

                default:
                    $this->SetValue($ident,
                        is_scalar($val) ? (string)$val : json_encode($val));
            }
        }
    }

    /* ---------- 3) Letztes Update merken ---------- */
    $this->WriteAttributeString('LastMQTTUpdate', date('Y-m-d H:i:s'));
    return true;
}
    
    /**
     * Handle user actions
     */
    public function RequestAction($ident, $value)
    {
        if ($ident === 'ProcessMQTTStateUpdate') {
            // Ensure the parameter is a JSON string as required by the new signature
            $stringValue = is_array($value) ? json_encode($value) : (string)$value;
            return $this->ProcessMQTTStateUpdate($stringValue);
        }
        $entityId = $this->ReadPropertyString('entity_id');
        if ($entityId === '') {
            return;
        }
        // Determine domain from entity_id
        $entityDomain = '';
        if (strpos($entityId, '.') !== false) {
            $entityDomain = substr($entityId, 0, strpos($entityId, '.'));
        }
        // Map action to Home Assistant service and data
        $service = '';
        $data = ['entity_id' => $entityId];
        if ($ident === 'Status') {
            switch ($entityDomain) {
                case 'input_number':
                    $service = 'input_number/set_value';
                    $data['value'] = (float)$value;
                    break;
                case 'number':
                    $service = 'number/set_value';
                    $data['value'] = (float)$value;
                    break;
                case 'light':
                    $service = $value ? 'light/turn_on' : 'light/turn_off';
                    break;
                case 'switch':
                    $service = $value ? 'switch/turn_on' : 'switch/turn_off';
                    break;
                case 'input_boolean':
                    $service = $value ? 'input_boolean/turn_on' : 'input_boolean/turn_off';
                    break;
                default:
                    $service = '';
                    break;
            }
        }
        if ($service !== '') {
            // Send to parent (HaBridge Splitter)
            $this->SendDataToParent(json_encode([
                'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                'Action'   => 'CallService',
                'Service'  => $service,
                'Data'     => $data,
                'SenderID' => $this->InstanceID
            ]));
            // Immediate local feedback
            if ($ident === 'Status') {
                $this->SetValue($ident, $value);
            }
            return;
        }
        // Fallback: If we do not know how to map, at least set local value
        $this->SetValue($ident, $value);
    }
    
    /**
     * Call Home Assistant service
     */
    protected function CallHAService(string $entityId, string $domain, string $ident, $value): bool
    {
        $haConfig = $this->GetHAConfig();
        if (!is_array($haConfig) || $haConfig['url'] === '' || $haConfig['token'] === '') {
            return false;
        }
        
        // Determine service and data based on domain and action
        $service = '';
        $data = ['entity_id' => $entityId];
        
        if ($ident === 'Status') {
            switch ($domain) {
                case 'input_number':
                    $service = 'input_number/set_value';
                    $data['value'] = (float)$value;
                    break;
                case 'number':
                    $service       = 'number/set_value';
                    $data['value'] = (float)$value;
                    break;
                case 'light':
                    $service = $value ? 'light/turn_on' : 'light/turn_off';
                    break;
                case 'switch':
                    $service = $value ? 'switch/turn_on' : 'switch/turn_off';
                    break;
                case 'input_boolean':
                    $service = $value ? 'input_boolean/turn_on' : 'input_boolean/turn_off';
                    break;
                default:
                    return false;
            }
        }
        
        if ($service === '') {
            return false;
        }
        
        $apiUrl = rtrim($haConfig['url'], '/') . '/api/services/' . $service;
        
        $headers = [
            'Authorization: Bearer ' . $haConfig['token'],
            'Content-Type: application/json'
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        return $result !== false && $httpCode === 200;
    }
    
/**
 * Determine variable type, converted value, editability
 * und – falls nötig – eine Presentation-GUID.
 *
 * Klassische IP-Symcon-Profile (~Temperature, ~Switch …)
 * werden **nicht** mehr gesetzt: $profile bleibt überall ''.
 */
protected function DetermineVariableType(
    string $attributeName,
    $value,
    string $entityDomain = '',
    array $attributes = [],
    bool $isStatusVariable = false
): array {
    /* ---------- Default ---------- */
    $varType        = VARIABLETYPE_STRING;
    $convertedValue = is_scalar($value) ? (string)$value : json_encode($value);
    $profile        = '';          // kein altes Profil
    $editable       = false;
    $presentation   = [];

    /* --- device_class = timestamp → Date/Time (read-only) --- */
    if ($isStatusVariable
        && ($attributes['device_class'] ?? '') === 'timestamp') {

        $varType = VARIABLETYPE_INTEGER;
        $convertedValue = is_string($value) ? strtotime($value) ?: 0
                                            : (is_int($value) ? $value : 0);

        $presentation = [
            'PRESENTATION' => '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}', // Date/Time
        ];
        return [$varType, $convertedValue, $profile, false, $presentation];
    }

    /* --- Boolean-Domänen --- */
    if (in_array($entityDomain,
        ['switch','binary_sensor','input_boolean','automation','light','device_tracker'])) {

        $varType  = VARIABLETYPE_BOOLEAN;
        $editable = in_array($entityDomain, ['switch','input_boolean','light']);

        $convertedValue = is_bool($value) ? $value
            : in_array(strtolower((string)$value), ['on','true','1','home']);

        if ($entityDomain === 'binary_sensor' && $isStatusVariable) {
            // Binary-Sensoren: Wertanzeige mit Labels/Icon gemäß device_class
            $presentation = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
        } else {
            $presentation = $editable
                ? $this->CreateBooleanPresentation($entityDomain, true)
                : ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}']; // Value
        }
    }

    /* --- input_number / number --- */
    elseif (in_array($entityDomain, ['input_number','number'])) {
        $varType        = VARIABLETYPE_FLOAT;
        $convertedValue = (float)$value;
        $editable       = true;
        $presentation   = $this->CreateSliderPresentation($attributes, $convertedValue);
    }

    /* --- Counter (read-only) --- */
    elseif ($entityDomain === 'counter') {
        $varType        = VARIABLETYPE_INTEGER;
        $convertedValue = (int)$value;
        $editable       = false;
    }

    /* --- Sensor (immer read-only) --- */
    elseif ($entityDomain === 'sensor') {
        if (is_numeric($value)) {
            $varType        = (strpos((string)$value, '.') !== false) ? VARIABLETYPE_FLOAT
                                                                      : VARIABLETYPE_INTEGER;
            $convertedValue = $varType === VARIABLETYPE_FLOAT ? (float)$value : (int)$value;
        }
        $presentation = [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', // Value
            'SUFFIX'       => isset($attributes['unit_of_measurement'])
                                ? ' ' . $attributes['unit_of_measurement'] : '',
            'DIGITS'       => ($varType === VARIABLETYPE_FLOAT) ? 2 : 0
        ];
    }

    /* --- primitive Fallbacks --- */
    elseif (is_bool($value)) {
        $varType        = VARIABLETYPE_BOOLEAN;
        $convertedValue = $value;
        $presentation   = ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
    }
    elseif (is_int($value)) {
        $varType        = VARIABLETYPE_INTEGER;
        $convertedValue = $value;
    }
    elseif (is_float($value)) {
        $varType        = VARIABLETYPE_FLOAT;
        $convertedValue = $value;
    }

    /* --- Value-Presentation als Default für schreibgeschützte Status-Variablen --- */
    if ($isStatusVariable && !$editable && empty($presentation)) {
        $presentation = ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
    }

    return [$varType, $convertedValue, $profile, $editable, $presentation];
}
    
    /**
     * Create intelligent slider presentation for input_number entities
     * Based on Home Assistant min/max/step attributes using IP-Symcon 8.0+ API
     */
    protected function CreateSliderPresentation(array $attributes, float $currentValue): array
    {
        $min = (float)($attributes['min'] ?? 0);
        $max = (float)($attributes['max'] ?? 100);
        $step = (float)($attributes['step'] ?? 1);
        $unit = $attributes['unit_of_measurement'] ?? '';

        $this->SendDebug('HA Attributes RAW', json_encode($attributes), 0);
        $this->SendDebug('Slider Creation', "Min: $min, Max: $max, Step: $step, Unit: '$unit', Current: $currentValue", 0);

        $presentation = [
            'PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', // Slider GUID
            'MIN' => $min,
            'MAX' => $max,
            'STEP_SIZE' => $step, // Korrekte Parameter für moderne Darstellung
            'SUFFIX' => $unit ? ' ' . $unit : '', // Leerzeichen vor Suffix
        ];

        if ($step < 1) {
            $presentation['DIGITS'] = 2;
        }

        $this->SendDebug('Slider Presentation FINAL', json_encode($presentation), 0);
        return $presentation;
    }
    
    /**
     * Create modern boolean presentation based on entity domain
     */
    protected function CreateBooleanPresentation(string $entityDomain, bool $editable): array
    {
        $presentation = [];
        
        // Use different presentations based on entity domain
        switch ($entityDomain) {
            case 'light':
                $presentation = [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => 'On',
                    'CAPTION_OFF' => 'Off',
                    'ICON_ON' => 'Bulb',
                    'ICON_OFF' => 'Bulb'
                ];
                break;
                
            case 'switch':
                $presentation = [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => 'On',
                    'CAPTION_OFF' => 'Off',
                    'ICON_ON' => 'Power',
                    'ICON_OFF' => 'Power'
                ];
                break;
                
            case 'input_boolean':
                $presentation = [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => 'True',
                    'CAPTION_OFF' => 'False',
                    'ICON_ON' => 'Information',
                    'ICON_OFF' => 'Information'
                ];
                break;
                
            case 'binary_sensor':
            case 'device_tracker':
            case 'automation':
            default:
                // Use toggle display for read-only sensors
                $presentation = [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => $entityDomain === 'device_tracker' ? 'Home' : 'Active',
                    'CAPTION_OFF' => $entityDomain === 'device_tracker' ? 'Away' : 'Inactive',
                    'ICON_ON' => $entityDomain === 'device_tracker' ? 'House' : 'Information',
                    'ICON_OFF' => $entityDomain === 'device_tracker' ? 'Door' : 'Information'
                ];
                break;
        }
        
        $this->SendDebug('Boolean Presentation Created', "Domain: $entityDomain, Editable: " . ($editable ? 'Yes' : 'No') . ', Presentation: ' . json_encode($presentation), 0);
        return $presentation;
    }
    
    /**
     * Create Value presentation for binary_sensor based on device_class mapping.
     * Returns presentation array including ICON and boolean OPTIONS captions.
     */
    protected function CreateBinarySensorPresentationByDeviceClass(array $attributes): array
    {
        $deviceClass = isset($attributes['device_class']) ? (string)$attributes['device_class'] : '';
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
        ];
        return [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', // Value
            'OPTIONS'      => $options,
            'ICON'         => $icon
        ];
    }
    
    /**
     * Map Home Assistant icons to IP-Symcon icons
     */
    protected function MapHAIconToSymcon(string $haIcon): string
    {
        // 1) Try mapping from assets/ha_icons.json (cached)
        $map = $this->LoadIconMapping();
        if (isset($map[$haIcon]) && is_string($map[$haIcon])) {
            return $map[$haIcon];
        }

        // 2) Legacy fallback mapping for common icons
        $legacy = [
            'mdi:lightbulb' => 'Bulb',
            'mdi:lightbulb-outline' => 'Bulb',
            'mdi:power-socket-eu' => 'Power',
            'mdi:toggle-switch' => 'Power',
            'mdi:thermometer' => 'Temperature',
            'mdi:water-percent' => 'Drops',
            'mdi:brightness-6' => 'Sun',
            'mdi:gauge' => 'Gauge',
            'mdi:home' => 'House',
            'mdi:door' => 'Door',
            'mdi:window-open' => 'Window',
            'mdi:motion-sensor' => 'Motion',
            'mdi:shield-check' => 'Shield'
        ];
        return $legacy[$haIcon] ?? '';
    }

    /**
     * Load icon mapping from assets/ha_icons.json with simple in-memory cache
     */
    protected function LoadIconMapping(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $file = __DIR__ . '/assets/ha_icons.json';
        if (!file_exists($file)) {
            $this->SendDebug('IconMap', 'assets/ha_icons.json not found', 0);
            $cache = [];
            return $cache;
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            $this->SendDebug('IconMap', 'Failed to read ha_icons.json', 0);
            $cache = [];
            return $cache;
        }
        // Be tolerant to a trailing dot or BOMs
        $json = trim($json);
        if (substr($json, -1) === '.') {
            $json = substr($json, 0, -1);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->SendDebug('IconMap', 'Invalid JSON in ha_icons.json', 0);
            $cache = [];
            return $cache;
        }
        $cache = $data;
        return $cache;
    }
}
