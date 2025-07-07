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
        
        // MQTT Integration
        $this->RegisterPropertyBoolean('mqtt_enabled', false);
        $this->RegisterAttributeString('LastMQTTUpdate', '');
        $this->RegisterAttributeBoolean('Initialized', false);
    }
    
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Ensure instance is marked as created/active before any early returns
        $this->SetStatus(102);
        // If the module was already initialized, do not recreate or modify variables/presentations.
        // Only refresh the value of the Status variable, then exit.
        if ($this->ReadAttributeBoolean('Initialized')) {
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

            // Update only the Status variable value (no presentation/profile changes)
            if ($device !== null && isset($device['state']) && $this->GetIDForIdent('Status') !== false) {
                $varInfo = IPS_GetVariable($this->GetIDForIdent('Status'));
                $actualVarType = $varInfo['VariableType'];
                $rawState = $device['state'];
                switch ($actualVarType) {
                    case VARIABLETYPE_BOOLEAN:
                        $value = in_array(strtolower((string)$rawState), ['on', 'true', '1', 'home']);
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
        
        // Register status variable with intelligent presentation
        if (!empty($presentation)) {
            // Use modern variable presentation instead of profiles
            $this->MaintainVariable('Status', 'Status', $varType, '', 0, true);
            $varId = $this->GetIDForIdent('Status');
            IPS_SetVariableCustomPresentation($varId, $presentation);
        } else {
            $this->MaintainVariable('Status', 'Status', $varType, $profile, 0, true);
        }
        $this->SetValue('Status', $convertedValue);
        
        // Enable action for editable domains
        if ($editable) {
            $this->EnableAction('Status');
        }
        
        // Set icon on Status variable if available (icon variable created via attributes)
        if (isset($device['attributes']['icon'])) {
            $mappedIcon = $this->MapHAIconToSymcon($device['attributes']['icon']);
            if ($mappedIcon !== '') {
                $stateVarId = $this->GetIDForIdent('Status');
                IPS_SetIcon($stateVarId, $mappedIcon);
            }
        }
        
        // Process attributes as variables
        if (isset($device['attributes']) && is_array($device['attributes'])) {
            foreach ($device['attributes'] as $key => $value) {
                // Skip metadata attributes (icon is now processed as variable)
                if (in_array($key, ['friendly_name', 'editable'])) {
                    continue;
                }
                
                $ident = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
                
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
                    IPS_SetVariableCustomPresentation($varId, $presentation);
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
            
            $ident = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
            
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
 * Erwartet einen JSON-String von HaBridge.
 * - "entity_id"   (string)  Pflicht
 * - "state"       (mixed)   optional
 * - "attributes"  (array)   optional
 *
 * Die Routine akzeptiert Nachrichten, die
 *  • sowohl "state" als auch "attributes" enthalten,
 *  • nur "state" oder nur "attributes" enthalten.
 *
 * Sie aktualisiert
 *  • die Status-Variable (falls "state" vorhanden) und
 *  • bereits existierende Attribut-Variablen (falls im Objektbaum vorhanden).
 *
 * Es werden **keine neuen Variablen** angelegt und keine Präsentationen geändert.
 *
 * @param string $data JSON-kodierte Payload
 * @return bool  true = verarbeitet, false = ignoriert/fehlerhaft
 */
public function ProcessMQTTStateUpdate(string $data): bool
{
    /* ---------- 0) Dekodieren & Grund-Checks ---------- */
    $payload = json_decode($data, true);
    if (!is_array($payload) || !isset($payload['entity_id'])) {
        return false;                                // entity_id bleibt Pflicht
    }
    // mind. "state" ODER "attributes" muss vorhanden sein
    if (!array_key_exists('state', $payload) && !array_key_exists('attributes', $payload)) {
        return false;
    }

    $entityId = $this->ReadPropertyString('entity_id');
    if ($entityId !== $payload['entity_id']) {
        return false;                                // Nachricht gehört zu einer anderen Instanz
    }

    /* ---------- 1) Status-Variable (nur wenn 'state' gesetzt) ---------- */
    if (array_key_exists('state', $payload) && $this->GetIDForIdent('Status') !== false) {

        $statusId = $this->GetIDForIdent('Status');
        $varInfo  = IPS_GetVariable($statusId);

        switch ($varInfo['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = is_bool($payload['state'])
                    ? $payload['state']
                    : in_array(strtolower((string)$payload['state']), ['on','true','1','yes','home']);
                break;
            case VARIABLETYPE_INTEGER:
                $value = (int)$payload['state'];
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float)$payload['state'];
                break;
            default:
                $value = (string)$payload['state'];
        }
        $this->SetValue('Status', $value);
    }

    /* ---------- 2) Attribut-Variablen ---------- */
    if (isset($payload['attributes']) && is_array($payload['attributes'])) {
        foreach ($payload['attributes'] as $key => $val) {

            // Metadaten ignorieren – sollen unsichtbar bleiben
            if (in_array($key, [
                'icon','initial','max','min','mode','step','unit_of_measurement',
                'friendly_name','editable'
            ])) {
                continue;
            }

            $ident = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
            $varId = $this->GetIDForIdent($ident);
            if ($varId === false) {
                // Variable existiert nicht → NICHT neu anlegen
                continue;
            }

            $varInfo = IPS_GetVariable($varId);
            switch ($varInfo['VariableType']) {
                case VARIABLETYPE_BOOLEAN:
                    $bool = is_bool($val)
                        ? $val
                        : in_array(strtolower((string)$val), ['true','on','1','yes','home']);
                    $this->SetValue($ident, $bool);
                    break;

                case VARIABLETYPE_INTEGER:
                    $this->SetValue($ident, (int)$val);
                    break;

                case VARIABLETYPE_FLOAT:
                    $this->SetValue($ident, (float)$val);
                    break;

                default:
                    $this->SetValue($ident, is_scalar($val) ? (string)$val : json_encode($val));
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
        // Get entity domain
        $entityDomain = '';
        if (strpos($entityId, '.') !== false) {
            $entityDomain = substr($entityId, 0, strpos($entityId, '.'));
        }
        // Call appropriate Home Assistant service
        $success = $this->CallHAService($entityId, $entityDomain, $ident, $value);
        if (!$success) {
            // Set local value anyway for immediate feedback
            $this->SetValue($ident, $value);
        }
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
     * Determine variable type, value, profile and editability
     * Enhanced with intelligent slider presentation for input_number entities
     */
    protected function DetermineVariableType(string $attributeName, $value, string $entityDomain = '', array $attributes = [], bool $isStatusVariable = false): array
    {
        $varType = VARIABLETYPE_STRING;
        $convertedValue = is_scalar($value) ? (string)$value : json_encode($value);
        $profile = '';
        $editable = false;
        $presentation = [];

        // Handle Home Assistant device_class 'timestamp' for status variables
        if ($isStatusVariable && isset($attributes['device_class']) && $attributes['device_class'] === 'timestamp') {
            // Use integer variable with modern Date/Time presentation
            $varType = VARIABLETYPE_INTEGER;

            // Convert ISO‑8601 string to Unix timestamp if needed
            if (is_string($value) && $value !== '') {
                $parsed = strtotime($value);
                $convertedValue = ($parsed !== false) ? $parsed : 0;
            } elseif (is_int($value)) {
                $convertedValue = $value;
            } else {
                $convertedValue = 0;
            }

            // VARIABLE_PRESENTATION_DATE_TIME
            $presentation = [
                'PRESENTATION' => '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}',
            ];

            // Timestamp sensors are typically read‑only
            $editable = false;

            return [$varType, $convertedValue, $profile, $editable, $presentation];
        }
        
        // Boolean domains
        if (in_array($entityDomain, ['switch', 'binary_sensor', 'input_boolean', 'automation', 'light', 'device_tracker'])) {
            $varType = VARIABLETYPE_BOOLEAN;
            $profile = '~Switch';
            $editable = in_array($entityDomain, ['switch', 'input_boolean', 'light']);
            
            if (is_bool($value)) {
                $convertedValue = $value;
            } elseif (is_string($value)) {
                $convertedValue = in_array(strtolower($value), ['on', 'true', '1', 'home']);
            } elseif (is_null($value)) {
                $convertedValue = false;
            } else {
                $convertedValue = (bool)$value;
            }
            
            // Choose presentation based on editability
            if ($isStatusVariable) {
                if ($editable) {
                    // Editable → use switch presentation
                    $presentation = $this->CreateBooleanPresentation($entityDomain, $editable);
                } else {
                    // Read‑only → simple value presentation
                    // VARIABLE_PRESENTATION_VALUE_PRESENTATION
                    $presentation = [
                        'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                    ];
                }
            }
        }
        // Numeric domains with intelligent slider presentation
        elseif (in_array($entityDomain, ['input_number', 'number'])) {
            $varType = VARIABLETYPE_FLOAT;
            $convertedValue = (float)$value;
            $editable = true;
            
            // Create intelligent slider presentation based on Home Assistant attributes
            $presentation = $this->CreateSliderPresentation($attributes, $convertedValue);
        }
        // Counter domains
        elseif ($entityDomain === 'counter') {
            $varType = VARIABLETYPE_INTEGER;
            $convertedValue = (int)$value;
            $editable = false;
        }
        // Sensor domains - check for numeric values
        elseif ($entityDomain === 'sensor') {
            if (is_numeric($value)) {
                if (is_float($value) || (is_string($value) && strpos($value, '.') !== false)) {
                    $varType = VARIABLETYPE_FLOAT;
                    $convertedValue = (float)$value;
                } else {
                    $varType = VARIABLETYPE_INTEGER;
                    $convertedValue = (int)$value;
                }
                
                // Create custom presentation with suffix if unit_of_measurement is available (only for status variable)
                $unit = $attributes['unit_of_measurement'] ?? '';
                if ($unit !== '' && $isStatusVariable) {
                    $presentation = [
                        'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                        'MIN' => 0,
                        'MAX' => $convertedValue * 2, // Dynamic max based on current value
                        'STEP_SIZE' => ($varType === VARIABLETYPE_FLOAT) ? 0.1 : 1,
                        'SUFFIX' => ' ' . $unit,
                        'DIGITS' => ($varType === VARIABLETYPE_FLOAT) ? 2 : 0
                    ];
                } else {
                    // Set appropriate profiles for sensors without unit
                    if (stripos($attributeName, 'temp') !== false) {
                        $profile = '~Temperature';
                    } elseif (stripos($attributeName, 'humid') !== false) {
                        $profile = '~Humidity';
                    } elseif (stripos($attributeName, 'bright') !== false) {
                        $profile = '~Intensity.255';
                    }
                }
            }
        }
        // Check value type for other cases
        elseif (is_bool($value)) {
            $varType = VARIABLETYPE_BOOLEAN;
            $convertedValue = $value;
            $profile = '~Switch';
        } elseif (is_int($value)) {
            $varType = VARIABLETYPE_INTEGER;
            $convertedValue = $value;
            
            // Create custom presentation with suffix if unit_of_measurement is available (only for status variable)
            $unit = $attributes['unit_of_measurement'] ?? '';
            if ($unit !== '' && $isStatusVariable) {
                $presentation = [
                    'PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', // Slider
                    'MIN' => 0,
                    'MAX' => $convertedValue * 2, // Dynamic max
                    'STEP_SIZE' => 1,
                    'SUFFIX' => ' ' . $unit,
                    'DIGITS' => 0
                ];
            }
        } elseif (is_float($value)) {
            $varType = VARIABLETYPE_FLOAT;
            $convertedValue = $value;
            
            // Create custom presentation with suffix if unit_of_measurement is available (only for status variable)
            $unit = $attributes['unit_of_measurement'] ?? '';
            if ($unit !== '' && $isStatusVariable) {
                $presentation = [
                    'PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}', // Slider
                    'MIN' => 0,
                    'MAX' => $convertedValue * 2, // Dynamic max
                    'STEP_SIZE' => 0.1,
                    'SUFFIX' => ' ' . $unit,
                    'DIGITS' => 2
                ];
            } else {
                // Set appropriate profiles for numeric values without unit
                if (stripos($attributeName, 'temp') !== false) {
                    $profile = '~Temperature';
                } elseif (stripos($attributeName, 'humid') !== false) {
                    $profile = '~Humidity';
                }
            }
        }
        
        // If this is a read‑only status variable (no action) and no presentation was chosen yet,
        // assign a simple value presentation so the value is displayed nicely in the UI
        if ($isStatusVariable && !$editable && empty($presentation)) {
            // VARIABLE_PRESENTATION_VALUE_PRESENTATION
            $presentation = [
                'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            ];
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
     * Map Home Assistant icons to IP-Symcon icons
     */
    protected function MapHAIconToSymcon(string $haIcon): string
    {
        $iconMap = [
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
        
        return $iconMap[$haIcon] ?? '';
    }
}
