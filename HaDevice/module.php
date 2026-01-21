<?php

declare(strict_types=1);

 require_once dirname(__DIR__) . '/libs/HaRestHelper.php';

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
        
        // Auto-connect to HaBridge parent if not already connected
        $bridgeModuleID = '{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}';
        if (@IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            $bridges = @IPS_GetInstanceListByModuleID($bridgeModuleID);
            if (is_array($bridges) && count($bridges) > 0) {
                @IPS_ConnectInstance($this->InstanceID, (int)$bridges[0]);
            }
        }
    }

    /**
     * Convert HA xy_color value (array or string) to Symcon JSON object string {"x":x,"y":y}
     */
    protected function ConvertXyFromHa($value): string
    {
        $x = 0.0; $y = 0.0;
        if (is_array($value)) {
            if (isset($value['x']) || isset($value['y'])) {
                $x = (float)($value['x'] ?? 0);
                $y = (float)($value['y'] ?? 0);
            } else {
                $vals = array_values($value);
                if (count($vals) >= 2) {
                    $x = (float)$vals[0];
                    $y = (float)$vals[1];
                }
            }
        } elseif (is_string($value)) {
            $str = trim($value);
            $decoded = json_decode($str, true);
            if (is_array($decoded)) {
                return $this->ConvertXyFromHa($decoded);
            }
            $str = trim($str, "[] \t\n\r");
            $parts = preg_split('/\s*,\s*/', $str);
            if (is_array($parts) && count($parts) >= 2) {
                $x = (float)$parts[0];
                $y = (float)$parts[1];
            }
        }
        return json_encode(['x' => $x, 'y' => $y]);
    }

    /**
     * Convert Symcon JSON object string {"x":x,"y":y} (or variants) to HA numeric array [x,y]
     */
    protected function ConvertXyToHa($value): array
    {
        $x = 0.0; $y = 0.0;
        if (is_string($value)) {
            $str = trim($value);
            $decoded = json_decode($str, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $str = trim($str, "[] \t\n\r");
                $parts = preg_split('/\s*,\s*/', $str);
                if (is_array($parts) && count($parts) >= 2) {
                    $x = (float)$parts[0];
                    $y = (float)$parts[1];
                    return [$x, $y];
                }
            }
        }
        if (is_array($value)) {
            if (isset($value['x']) || isset($value['y'])) {
                $x = (float)($value['x'] ?? 0);
                $y = (float)($value['y'] ?? 0);
            } else {
                $vals = array_values($value);
                if (count($vals) >= 2) {
                    $x = (float)$vals[0];
                    $y = (float)$vals[1];
                }
            }
        }
        return [$x, $y];
    }

    /**
     * Remove all additional variables created by this module (prefixed with HAS_)
     * Keeps the main Status variable and Color variables intact.
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
                $name = $obj['ObjectName'] ?? '';
                if ($ident === 'Status') {
                    continue;
                }
                // Keep rgb_color only (always auto-created)
                $lowerName = strtolower($name);
                if ($lowerName === 'rgb_color') {
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

    public function GetCompatibleParents()
    {
        return '{"type": "connect", "modules": [{"moduleID": "{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}", "configuration": {}}]}';
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

        $entityId = $this->ReadPropertyString('entity_id');
        $connId = 0;
        try {
            $connId = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        } catch (Exception $e) {
            $connId = 0;
        }
        if ($connId === 0) {
            $bridgeModuleID = '{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}';
            $bridges = @IPS_GetInstanceListByModuleID($bridgeModuleID);
            if (is_array($bridges) && count($bridges) > 0) {
                @IPS_ConnectInstance($this->InstanceID, (int)$bridges[0]);
                try {
                    $connId = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
                } catch (Exception $e) {
                    $connId = 0;
                }
            }
        }
        if ($connId > 0 && $entityId !== '') {
            @ $this->SendDataToParent(json_encode([
                'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                'Action'   => 'UpdateSubscriptions',
                'SenderID' => $this->InstanceID
            ]));
        }

        $createExtra = $this->ReadPropertyBoolean('create_additional_vars');
        // If already initialized AND the user does NOT want additional variables,
        // do a minimal refresh (Status only) and exit.
        if ($this->ReadAttributeBoolean('Initialized') && !$createExtra) {
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
            $statusId = @$this->GetIDForIdent('Status');
            if ($device !== null && isset($device['state']) && $statusId !== false && IPS_VariableExists($statusId)) {
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
                    $this->SetValueIfChangedByIdent('Status', $value);
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
        $this->SetValueIfChangedByIdent('Status', $convertedValue);
        
        // Enable action for editable domains
        if ($editable) {
            $this->EnableAction('Status');
        }
        
        // Set icon on Status variable
        $stateVarId = @$this->GetIDForIdent('Status');
        if ($stateVarId !== false && IPS_VariableExists($stateVarId)) {
            // 1) Prefer explicit HA icon attribute when present
            if (isset($device['attributes']['icon'])) {
                $mappedIcon = $this->MapHAIconToSymcon($device['attributes']['icon']);
                if ($mappedIcon !== '') {
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
                    $obj = IPS_GetObject($stateVarId);
                    $currentIcon = $obj['ObjectIcon'] ?? '';
                    if ($currentIcon === '') {
                        IPS_SetIcon($stateVarId, (string)$presentation['ICON']);
                    }
                }
            }
        }
        
        // Process attributes as variables
        if (isset($device['attributes']) && is_array($device['attributes'])) {
            // Detect preferred color mode from existing variables or attributes
            $preferredMode = $this->DetectPreferredColorModeFromInstanceOrAttributes($device['attributes']); // '', 'xy', 'hs', 'rgb'
            $selectedColorKey = '';
            if ($preferredMode === 'xy') {
                $selectedColorKey = 'xy_color';
            } elseif ($preferredMode === 'hs') {
                $selectedColorKey = 'hs_color';
            } elseif ($preferredMode === 'rgb') {
                $selectedColorKey = 'rgb_color';
            }

            foreach ($device['attributes'] as $key => $value) {
                // Only rgb_color is always created; hs_color and xy_color are regular attributes
                $lowerKey = strtolower($key);
                $isColorVar = ($lowerKey === 'rgb_color');
                // If a preferred mode exists, only that exact color variable is treated as "always create"
                $shouldCreateColor = ($selectedColorKey !== '') ? ($lowerKey === $selectedColorKey) : $isColorVar;
                
                // Create color variables ALWAYS, other attributes only if create_additional_vars is enabled
                if (!$createExtra && !$shouldCreateColor) {
                    continue;
                }
                
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
                if (!empty($presentation) && ($varType === VARIABLETYPE_FLOAT || $varType === VARIABLETYPE_STRING)) {
                    // Use modern variable presentation for numeric/color attributes
                    $this->MaintainVariable($ident, $key, $varType, '', 0, true);
                    $varId = $this->GetIDForIdent($ident);
                    // Always clear any previous custom profile before setting presentation
                    @IPS_SetVariableCustomProfile($varId, '');
                    // Determine whether this is rgb_color (always gets presentation)
                    $isExactColorVarPres = (strtolower($key) === 'rgb_color');
                    $varMeta = IPS_GetVariable($varId);
                    $hasCustom = isset($varMeta['VariableCustomPresentation'])
                        && is_array($varMeta['VariableCustomPresentation'])
                        && !empty($varMeta['VariableCustomPresentation']);
                    // For exact color variables: always set presentation to ensure color picker
                    // For other attributes: only set if no custom presentation exists yet
                    if ($isExactColorVarPres || !$hasCustom) {
                        IPS_SetVariableCustomPresentation($varId, $presentation);
                    }
                } else {
                    $this->MaintainVariable($ident, $key, $varType, $profile, 0, true);
                }
                $this->SetValueIfChangedByIdent($ident, $convertedValue);
                
                // Hide attribute variables - except selected color variable (visible and editable)
                $varId = $this->GetIDForIdent($ident);
                $isVisibleSelectedColor = ($selectedColorKey !== '') ? ($lowerKey === $selectedColorKey) : $isColorVar;
                if (!$isVisibleSelectedColor) {
                    IPS_SetHidden($varId, true);
                }
                
                // Enable action for editable variables (includes color variables)
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

        $varIdBefore = @$this->GetIDForIdent('Status');
        $hadVarBefore = ($varIdBefore !== false && IPS_VariableExists($varIdBefore));
        
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
                    'CAPTION_ON' => $this->Translate('On'),
                    'CAPTION_OFF' => $this->Translate('Off')
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

        if (!$hadVarBefore) {
            $this->SetValueIfChangedByIdent('Status', $defaultValue);
        }
        
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
                    $this->SetValueIfChangedByIdent($ident, $boolValue);
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->SetValueIfChangedByIdent($ident, (int)$value);
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->SetValueIfChangedByIdent($ident, (float)$value);
                    break;
                case VARIABLETYPE_STRING:
                default:
                    $this->SetValueIfChangedByIdent($ident, is_scalar($value) ? (string)$value : json_encode($value));
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
            if (($parentInfo['InstanceStatus'] ?? 0) !== 102) {
                return ['url' => '', 'token' => ''];
            }
            
            if ($parentInfo['ModuleInfo']['ModuleID'] === '{32D99DCD-A530-4907-3FB0-44D7D472771D}') {
                $url = @IPS_GetProperty($parentID, 'ha_url');
                $token = @IPS_GetProperty($parentID, 'ha_token');
                
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

        $r = HaRestHelper::GetJson($haConfig['url'], $haConfig['token'], '/api/states', 10, 5);
        if (!$r['ok'] || ($r['http'] ?? 0) !== 200 || !is_array($r['json'] ?? null)) {
            return null;
        }
        return $r['json'];
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
    $statusId = @$this->GetIDForIdent('Status');
    if (array_key_exists('state', $payload) && $statusId !== false && IPS_VariableExists($statusId)) {
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
                $this->SetValueIfChangedByIdent('Status', $value);
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
        $statusVarId = @$this->GetIDForIdent('Status');
        if ($iconName !== '' && $statusVarId !== false && IPS_VariableExists($statusVarId)) {
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
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false || !is_int($varId) || $varId <= 0 || !IPS_VariableExists($varId)) {
                continue;                                    // Variable existiert nicht, kein Update
            }

            $varInfo = IPS_GetVariable($varId);
            switch ($varInfo['VariableType']) {
                case VARIABLETYPE_BOOLEAN:
                    $bool = is_bool($val)
                        ? $val
                        : in_array(strtolower((string)$val),
                                  ['true','on','1','yes','home']);
                    $this->SetValueIfChangedByIdent($ident, $bool);
                    break;

                case VARIABLETYPE_INTEGER:
                    $this->SetValueIfChangedByIdent($ident, (int)$val);
                    break;

                case VARIABLETYPE_FLOAT:
                    $this->SetValueIfChangedByIdent($ident, (float)$val);
                    break;

                default:
                    // Normalize color values to Symcon JSON object form where required
                    $lowerKey = strtolower($key);
                    if ($lowerKey === 'rgb_color') {
                        $this->SetValueIfChangedByIdent($ident, $this->ConvertRgbFromHa($val));
                    } elseif ($lowerKey === 'xy_color') {
                        $this->SetValueIfChangedByIdent($ident, $this->ConvertXyFromHa($val));
                    } else {
                        $this->SetValueIfChangedByIdent($ident,
                            is_scalar($val) ? (string)$val : json_encode($val));
                    }
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
        // Handle attribute actions: rgb_color
        if ($ident === 'HAS_rgb_color') {
            // Convert Symcon JSON {"r":x,"g":y,"b":z} (or variants) -> HA array [r,g,b]
            $rgbArr = $this->ConvertRgbToHa($value);
            if ($entityDomain === 'light') {
                $service = 'light/turn_on';
                $data = ['entity_id' => $entityId, 'rgb_color' => $rgbArr];
                // Send to parent (HaBridge Splitter)
                $this->SendDataToParent(json_encode([
                    'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                    'Action'   => 'CallService',
                    'Service'  => $service,
                    'Data'     => $data,
                    'SenderID' => $this->InstanceID
                ]));
                // Immediate local feedback in Symcon JSON format
                $this->SetValueIfChangedByIdent($ident, $this->ConvertRgbFromHa($value));
                return;
            }
        }
        // Handle attribute actions: xy_color
        if ($ident === 'HAS_xy_color') {
            // Convert Symcon JSON {"x":x,"y":y} (or variants) -> HA array [x,y]
            $xyArr = $this->ConvertXyToHa($value);
            if ($entityDomain === 'light') {
                $service = 'light/turn_on';
                $data = ['entity_id' => $entityId, 'xy_color' => $xyArr];
                // Send to parent (HaBridge Splitter)
                $this->SendDataToParent(json_encode([
                    'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                    'Action'   => 'CallService',
                    'Service'  => $service,
                    'Data'     => $data,
                    'SenderID' => $this->InstanceID
                ]));
                // Immediate local feedback in Symcon JSON format
                $this->SetValueIfChangedByIdent($ident, $this->ConvertXyFromHa($value));
                return;
            }
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
                $this->SetValueIfChangedByIdent($ident, $value);
            }
            return;
        }
        // Fallback: If we do not know how to map, at least set local value
        $this->SetValueIfChangedByIdent($ident, $value);
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
        
        $r = HaRestHelper::PostJson($haConfig['url'], $haConfig['token'], '/api/services/' . $service, $data, 10, 5);
        return ($r['ok'] ?? false) && ((int)($r['http'] ?? 0) === 200);
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

    /* --- rgb_color: Always with Color Presentation --- */
    $lowerAttrName = strtolower($attributeName);
    if ($lowerAttrName === 'rgb_color') {
        $varType = VARIABLETYPE_STRING;
        $convertedValue = $this->ConvertRgbFromHa($value);
        $editable = true;
        $presentation = $this->CreateColorPresentation($attributeName);
        
        if (!empty($presentation)) {
            return [$varType, $convertedValue, $profile, $editable, $presentation];
        }
    }
    
    /* --- xy_color and hs_color: Regular attributes with conversion but no special presentation --- */
    if ($lowerAttrName === 'xy_color') {
        $varType = VARIABLETYPE_STRING;
        $convertedValue = $this->ConvertXyFromHa($value);
        return [$varType, $convertedValue, $profile, false, []];
    }
    
    if ($lowerAttrName === 'hs_color') {
        $varType = VARIABLETYPE_STRING;
        $convertedValue = is_scalar($value) ? (string)$value : json_encode($value);
        return [$varType, $convertedValue, $profile, false, []];
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
                    'CAPTION_ON' => $this->Translate('On'),
                    'CAPTION_OFF' => $this->Translate('Off'),
                    'ICON_ON' => 'Bulb',
                    'ICON_OFF' => 'Bulb'
                ];
                break;
                
            case 'switch':
                $presentation = [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => $this->Translate('On'),
                    'CAPTION_OFF' => $this->Translate('Off'),
                    'ICON_ON' => 'Power',
                    'ICON_OFF' => 'Power'
                ];
                break;
                
            case 'input_boolean':
                $presentation = [
                    // VARIABLE_PRESENTATION_SWITCH
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => $this->Translate('True'),
                    'CAPTION_OFF' => $this->Translate('False'),
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
                    'CAPTION_ON' => $entityDomain === 'device_tracker' ? $this->Translate('Home') : $this->Translate('Active'),
                    'CAPTION_OFF' => $entityDomain === 'device_tracker' ? $this->Translate('Away') : $this->Translate('Inactive'),
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
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}', // Value
            'OPTIONS'      => $options,
            'ICON'         => $icon
        ];
    }
    
    /**
     * Create Color presentation based on attribute name
     * Color variables are STRING type in IP-Symcon
     * @param string $attributeName Name of the color attribute
     * @return array Presentation array or empty if not a color attribute
     */
    protected function CreateColorPresentation(string $attributeName): array
    {
        $lowerName = strtolower($attributeName);
        
        // Standard preset colors (as JSON string for IPS)
        $presets = json_encode([
            ["Color" => 16007990],  // Rot
            ["Color" => 16761095],  // Orange
            ["Color" => 10233776],  // Gelb
            ["Color" => 48340],     // Grün
            ["Color" => 2201331],   // Blau
            ["Color" => 15277667]   // Weiß
        ]);
        
        // sRGB color space (as JSON string for IPS)
        $colorSpace = json_encode([
            ["x" => 0.64, "y" => 0.33],   // Red
            ["x" => 0.3, "y" => 0.6],     // Green
            ["x" => 0.15, "y" => 0.06],   // Blue
            ["x" => 0.3127, "y" => 0.329] // White point D65
        ]);
        
        // hs_color (Hue/Saturation)
        if (strpos($lowerName, 'hs_color') !== false) {
            return [
                'PRESENTATION' => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}',
                'SELECTION' => 0,
                'PRESET_VALUES' => $presets,
                'ENCODING' => 2,           // HS
                'COLOR_SPACE' => 1,        // sRGB
                'COLOR_CURVE' => 0,        // Linear
                'CUSTOM_COLOR_CURVE' => '[]',
                'CUSTOM_COLOR_SPACE' => $colorSpace
            ];
        }
        
        // rgb_color (RGB)
        if (strpos($lowerName, 'rgb_color') !== false) {
            return [
                'PRESENTATION' => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}',
                'SELECTION' => 0,
                'PRESET_VALUES' => $presets,
                'ENCODING' => 0,           // RGB
                'COLOR_SPACE' => 1,        // sRGB
                'COLOR_CURVE' => 0         // Linear
            ];
        }
        
        // xy_color (CIE XY)
        if (strpos($lowerName, 'xy_color') !== false) {
            return [
                'PRESENTATION' => '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}',
                'SELECTION' => 1,
                'PRESET_VALUES' => $presets,
                'ENCODING' => 4,           // XY
                'COLOR_SPACE' => 1,        // sRGB
                'COLOR_CURVE' => 0,        // Linear
                'CUSTOM_COLOR_CURVE' => '[]',
                'CUSTOM_COLOR_SPACE' => $colorSpace
            ];
        }
        
        return [];
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
     * Set variable value by ident only if it has changed.
     * Returns true if value was updated, false if unchanged or variable not found.
     */
    protected function SetValueIfChangedByIdent(string $ident, $newValue): bool
    {
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false || !is_int($varId) || $varId <= 0 || !IPS_VariableExists($varId)) {
            return false;
        }
        return $this->SetIpsValueIfChanged($varId, $newValue);
    }

    /**
     * Detect preferred color mode from existing IPS variables or HA attributes.
     * Priority:
     * 1) IPS variable with ident 'HAS_color_mode'
     * 2) IPS variable with name 'color_mode'
     * 3) HA attributes['color_mode']
     * Returns: 'xy' | 'hs' | 'rgb' | ''
     */
    protected function DetectPreferredColorModeFromInstanceOrAttributes(array $attributes): string
    {
        // 1) Try by ident 'HAS_color_mode'
        try {
            $varId = @$this->GetIDForIdent('HAS_color_mode');
            if (is_int($varId) && $varId > 0 && IPS_VariableExists($varId)) {
                $raw = @GetValue($varId);
                $mode = strtolower(trim((string)$raw));
                if (in_array($mode, ['xy','hs','rgb'], true)) {
                    return $mode;
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        // 2) Try by variable name 'color_mode' under this instance
        try {
            $children = @IPS_GetChildrenIDs($this->InstanceID);
            if (is_array($children)) {
                foreach ($children as $id) {
                    $obj = @IPS_GetObject($id);
                    if (!is_array($obj) || ($obj['ObjectType'] ?? 0) !== 2 /* otVariable */) {
                        continue;
                    }
                    $name = (string)($obj['ObjectName'] ?? '');
                    if (strtolower($name) === 'color_mode') {
                        $raw = @GetValue($id);
                        $mode = strtolower(trim((string)$raw));
                        if (in_array($mode, ['xy','hs','rgb'], true)) {
                            return $mode;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }

        // 3) Fallback to HA attributes
        $attrMode = strtolower(trim((string)($attributes['color_mode'] ?? '')));
        if (in_array($attrMode, ['xy','hs','rgb'], true)) {
            return $attrMode;
        }
        return '';
    }

    /**
     * Convert HA rgb_color value (array or string) to Symcon JSON object string {"r":x,"g":y,"b":z}
     */
    protected function ConvertRgbFromHa($value): string
    {
        $r = 0; $g = 0; $b = 0;
        if (is_array($value)) {
            if (isset($value['r']) || isset($value['g']) || isset($value['b'])) {
                $r = (int)($value['r'] ?? 0);
                $g = (int)($value['g'] ?? 0);
                $b = (int)($value['b'] ?? 0);
            } else {
                // Numeric array [r,g,b]
                $vals = array_values($value);
                if (count($vals) >= 3) {
                    $r = (int)$vals[0];
                    $g = (int)$vals[1];
                    $b = (int)$vals[2];
                }
            }
        } elseif (is_string($value)) {
            $str = trim($value);
            // Try JSON decode first (object or array)
            $decoded = json_decode($str, true);
            if (is_array($decoded)) {
                return $this->ConvertRgbFromHa($decoded);
            }
            // Fallback: allow formats like "[255,156,243]" or "255,156,243"
            $str = trim($str, "[] \t\n\r");
            $parts = preg_split('/\s*,\s*/', $str);
            if (is_array($parts) && count($parts) >= 3) {
                $r = (int)$parts[0];
                $g = (int)$parts[1];
                $b = (int)$parts[2];
            }
        }
        // Clamp to 0..255
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        return json_encode(['r' => $r, 'g' => $g, 'b' => $b]);
    }

    /**
     * Convert Symcon JSON object string {"r":x,"g":y,"b":z} (or variants) to HA numeric array [r,g,b]
     */
    protected function ConvertRgbToHa($value): array
    {
        $r = 0; $g = 0; $b = 0;
        if (is_string($value)) {
            $str = trim($value);
            $decoded = json_decode($str, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                // Fallback: allow "255,156,243" or "[255,156,243]"
                $str = trim($str, "[] \t\n\r");
                $parts = preg_split('/\s*,\s*/', $str);
                if (is_array($parts) && count($parts) >= 3) {
                    $r = (int)$parts[0];
                    $g = (int)$parts[1];
                    $b = (int)$parts[2];
                    return [max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b))];
                }
            }
        }
        if (is_array($value)) {
            if (isset($value['r']) || isset($value['g']) || isset($value['b'])) {
                $r = (int)($value['r'] ?? 0);
                $g = (int)($value['g'] ?? 0);
                $b = (int)($value['b'] ?? 0);
            } else {
                $vals = array_values($value);
                if (count($vals) >= 3) {
                    $r = (int)$vals[0];
                    $g = (int)$vals[1];
                    $b = (int)$vals[2];
                }
            }
        }
        return [max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b))];
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
        // Normalize incoming value to variable type
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
