<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/HaRestHelper.php';

/**
 * HaMultiEntityDevice - Groups multiple Home Assistant entities in one IP-Symcon instance
 *
 * Responsibilities:
 *  - Owns multiple entity_ids
 *  - Creates one Status variable per entity (unique Ident derived from entity_id or alias)
 *  - Receives state updates via HaBridge broadcast and routes to the right variable
 *  - Applies presentations only on first creation; respects user changes afterwards
 *  - Enforces Value presentation for binary_sensors (no toggle)
 *  - Ignores 'unavailable'/'unknown'/'none'/'null'/'' for numeric/bool to avoid 0/false resets
 */
class HaMultiEntityDevice extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('group_name', '');
        $this->RegisterPropertyString('entities', '[]'); // array of { entity_id, alias, role, section }
        $this->RegisterPropertyBoolean('create_additional_vars', false);

        // Attributes
        $this->RegisterAttributeBoolean('Initialized', false);
        $this->RegisterAttributeString('EntityIndex', '{}'); // entity_id => ident

        // Connect or create HaBridge parent
        $bridgeModuleID = '{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}';
        if (@IPS_GetInstance($this->InstanceID)['ConnectionID'] === 0) {
            $bridges = @IPS_GetInstanceListByModuleID($bridgeModuleID);
            if (is_array($bridges) && count($bridges) > 0) {
                @IPS_ConnectInstance($this->InstanceID, (int)$bridges[0]);
            }
        }
    }

    public function GetCompatibleParents()
    {
        return '{"type": "connect", "modules": [{"moduleID": "{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}", "configuration": {}}]}';
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
                $vals = array_values($value);
                if (count($vals) >= 3) {
                    $r = (int)$vals[0];
                    $g = (int)$vals[1];
                    $b = (int)$vals[2];
                }
            }
        } elseif (is_string($value)) {
            $str = trim($value);
            $decoded = json_decode($str, true);
            if (is_array($decoded)) {
                return $this->ConvertRgbFromHa($decoded);
            }
            $str = trim($str, "[] \t\n\r");
            $parts = preg_split('/\s*,\s*/', $str);
            if (is_array($parts) && count($parts) >= 3) {
                $r = (int)$parts[0];
                $g = (int)$parts[1];
                $b = (int)$parts[2];
            }
        }
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

    public function Rebuild(): void
    {
        $this->ApplyChanges();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);

        $entities = $this->ReadEntities();

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
        if ($connId > 0 && !empty($entities)) {
            @ $this->SendDataToParent(json_encode([
                'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                'Action'   => 'UpdateSubscriptions',
                'SenderID' => $this->InstanceID
            ]));
        }
        $index = [];

        // Try to fetch current states once (best effort)
        $states = $this->FetchStates(); // entity_id => [state, attributes]
        $createExtra = $this->ReadPropertyBoolean('create_additional_vars');

        foreach ($entities as $e) {
            $entityId = (string)($e['entity_id'] ?? '');
            if ($entityId === '') {
                continue;
            }
            $alias = isset($e['alias']) && is_string($e['alias']) ? trim($e['alias']) : '';
            $ident = $this->BuildIdentForEntity($entityId);
            $index[$entityId] = $ident;

            $varIdBefore = @$this->GetIDForIdent($ident);
            $hadVarBefore = ($varIdBefore !== false && IPS_VariableExists($varIdBefore));

            $entityDomain = '';
            if (strpos($entityId, '.') !== false) {
                $entityDomain = substr($entityId, 0, strpos($entityId, '.'));
            }

            $state = $states[$entityId]['state'] ?? null;
            $attributes = $states[$entityId]['attributes'] ?? [];

            [$varType, $convertedValue, $profile, $editable, $presentation] =
                $this->DetermineVariableType('status', $state, $entityDomain, $attributes, true);

            $name = $alias !== '' ? $alias : ('Status ' . $entityId);

            if (!empty($presentation)) {
                $this->MaintainVariable($ident, $name, $varType, '', 0, true);
                $varId = @$this->GetIDForIdent($ident);
                if ($varId === false || !IPS_VariableExists($varId)) {
                    continue;
                }
                // Only set presentation if there is no custom presentation yet
                $meta = @IPS_GetVariable($varId);
                $custom = (is_array($meta) && isset($meta['VariableCustomPresentation']) && is_array($meta['VariableCustomPresentation']))
                    ? $meta['VariableCustomPresentation']
                    : [];
                $hasCustom = !empty($custom);
                $isIncomplete = false;
                if ($hasCustom && isset($custom['PRESENTATION']) && $custom['PRESENTATION'] === '{3319437D-7CDE-699D-750A-3C6A3841FA75}') {
                    // Consider it incomplete if OPTIONS are missing for a binary_sensor Value presentation
                    if ($entityDomain === 'binary_sensor' && (!isset($custom['OPTIONS']) || !is_array($custom['OPTIONS']) || empty($custom['OPTIONS']))) {
                        $isIncomplete = true;
                    }
                }
                // Migrate away from SWITCH presentation for binary_sensor
                if ($entityDomain === 'binary_sensor' && $hasCustom && isset($custom['PRESENTATION']) && $custom['PRESENTATION'] === '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}') {
                    $isIncomplete = true;
                }
                if (!$hasCustom || $isIncomplete) {
                    if ($entityDomain === 'binary_sensor' && (empty($presentation) || !isset($presentation['OPTIONS']))) {
                        $this->SendDebug('ApplyChanges', 'Binary sensor without device_class -> using generic An/Aus options', 0);
                        $presentation = [
                            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                            'OPTIONS' => [
                                [
                                    'Value' => false,
                                    'Caption' => $this->Translate('Off'),
                                    'IconActive' => false,
                                    'IconValue' => '',
                                    'ColorActive' => false,
                                    'ColorValue' => -1
                                ],
                                [
                                    'Value' => true,
                                    'Caption' => $this->Translate('On'),
                                    'IconActive' => false,
                                    'IconValue' => '',
                                    'ColorActive' => false,
                                    'ColorValue' => -1
                                ]
                            ]
                        ];
                    }
                    $this->SendDebug('ApplyChanges', 'Setting presentation for ' . $entityId . ' (' . $entityDomain . '): ' . json_encode($presentation), 0);
                    // Remove ICON and other unsupported keys before setting presentation
                    $presForIPS = $presentation;
                    if (isset($presForIPS['ICON'])) {
                        unset($presForIPS['ICON']);
                    }
                    // IPS expects OPTIONS as array, not JSON string
                    // Some environments do not accept Presentation on BOOLEAN variables -> guard
                    if ($varType !== VARIABLETYPE_BOOLEAN && is_array($presForIPS)) {
                        @IPS_SetVariableCustomPresentation($varId, $presForIPS);
                    }
                    // Set icon right away if presentation suggests one and none is set yet
                    $objSet = @IPS_GetObject($varId);
                    $currentIconSet = is_array($objSet) ? ($objSet['ObjectIcon'] ?? '') : '';
                    if ($currentIconSet === '' && isset($presentation['ICON']) && is_string($presentation['ICON']) && $presentation['ICON'] !== '') {
                        @IPS_SetIcon($varId, (string)$presentation['ICON']);
                    }
                    if ($entityDomain === 'binary_sensor') {
                        @$this->DisableAction($ident);
                    }
                } else {
                    $this->MaintainVariable($ident, $name, $varType, $profile, 0, true);
                }
            } else {
                $this->MaintainVariable($ident, $name, $varType, $profile, 0, true);
            }

            // Initial value (only if changed)
            $rawStr = is_scalar($state) ? strtolower(trim((string)$state)) : '';
            $isUnknown = ($state === null) || in_array($rawStr, ['unavailable', 'unknown', 'none', 'null', ''], true);
            if (!($hadVarBefore && $isUnknown && in_array($varType, [VARIABLETYPE_BOOLEAN, VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true))) {
                $this->SetValueIfChangedByIdent($ident, $convertedValue);
            }

            // Icon only if none set
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false || !IPS_VariableExists($varId)) {
                continue;
            }
            $obj = @IPS_GetObject($varId);
            $currentIcon = is_array($obj) ? ($obj['ObjectIcon'] ?? '') : '';
            if ($currentIcon === '') {
                $icon = '';
                if (isset($attributes['icon'])) {
                    $icon = $this->MapHAIconToSymcon((string)$attributes['icon']);
                }
                if ($icon === '' && $entityDomain === 'binary_sensor') {
                    $p = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
                    if (!empty($p) && isset($p['ICON'])) {
                        $icon = (string)$p['ICON'];
                    }
                }
                if ($icon !== '') {
                    @IPS_SetIcon($varId, $icon);
                }
            }

            if ($editable && in_array($entityDomain, ['input_number', 'number', 'light', 'switch', 'input_boolean', 'lock'])) {
                $this->EnableAction($ident);
            }

            // Optionally create additional attribute variables for this entity
            if ($createExtra && is_array($attributes) && !empty($attributes)) {
                $this->ProcessAttributesForEntity($entityId, $attributes);
            }
        }

        $this->WriteAttributeString('EntityIndex', json_encode($index));
        $this->WriteAttributeBoolean('Initialized', true);

        // Ask parent (HaBridge) to refresh subscriptions
        $parentId = 0;
        try {
            $parentId = (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        } catch (Exception $e) {
            $parentId = 0;
        }
        if ($parentId > 0) {
            @ $this->SendDataToParent(json_encode([
                'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                'Action'   => 'UpdateSubscriptions',
                'SenderID' => $this->InstanceID
            ]));
        }

        // If user has disabled additional variables, ensure we remove any previously created ones
        if (!$createExtra) {
            $this->CleanupAdditionalVariables();
        }
    }

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
        $entities = $this->ReadEntities();
        $known = false;
        foreach ($entities as $e) {
            if (($e['entity_id'] ?? '') === $entityId) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            return;
        }
        $payload = isset($data['Payload']) && is_array($data['Payload']) ? $data['Payload'] : ['state' => $data['Payload']];
        $this->ProcessEntityStateUpdate($entityId, $payload);
    }

    public function RequestAction($ident, $value)
    {
        // Map ident to entity_id
        $entityId = $this->ResolveEntityIdByIdent($ident);
        if ($entityId === '') {
            // Support attribute idents like HAS_<STAT_...>_<attr>
            if (strpos($ident, 'HAS_') === 0) {
                $pos = strrpos($ident, '_');
                $statusIdent = $pos !== false ? substr($ident, 4, $pos - 4) : substr($ident, 4);
                $entityId = $this->ResolveEntityIdByIdent($statusIdent);
            }
            if ($entityId === '') {
                return;
            }
        }
        $domain = '';
        if (strpos($entityId, '.') !== false) {
            $domain = substr($entityId, 0, strpos($entityId, '.'));
        }

        // Handle rgb_color/xy_color attribute actions
        if (strpos($ident, 'HAS_') === 0) {
            $attrKey = '';
            $pos = strrpos($ident, '_');
            if ($pos !== false) {
                $attrKey = strtolower(substr($ident, $pos + 1));
            }
            if ($attrKey === 'rgb_color' && $domain === 'light') {
                $rgbArr = $this->ConvertRgbToHa($value);
                $service = 'light/turn_on';
                $data = ['entity_id' => $entityId, 'rgb_color' => $rgbArr];
                $this->SendDataToParent(json_encode([
                    'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                    'Action'   => 'CallService',
                    'Service'  => $service,
                    'Data'     => $data,
                    'SenderID' => $this->InstanceID
                ]));
                // Local feedback in Symcon JSON format
                $this->SetValueIfChangedByIdent($ident, $this->ConvertRgbFromHa($value));
                return;
            } elseif ($attrKey === 'xy_color' && $domain === 'light') {
                $xyArr = $this->ConvertXyToHa($value);
                $service = 'light/turn_on';
                $data = ['entity_id' => $entityId, 'xy_color' => $xyArr];
                $this->SendDataToParent(json_encode([
                    'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
                    'Action'   => 'CallService',
                    'Service'  => $service,
                    'Data'     => $data,
                    'SenderID' => $this->InstanceID
                ]));
                $this->SetValueIfChangedByIdent($ident, $this->ConvertXyFromHa($value));
                return;
            }
        }

        // Translate to HA service
        $service = '';
        $data = ['entity_id' => $entityId];
        switch ($domain) {
            case 'input_number':
            case 'number':
                $service = 'input_number/set_value';
                $data['value'] = (float)$value;
                break;
            case 'light':
                $service = $value ? 'light/turn_on' : 'light/turn_off';
                break;
            case 'switch':
            case 'input_boolean':
                $service = $value ? $domain . '/turn_on' : $domain . '/turn_off';
                break;
            case 'lock':
                $service = $value ? 'lock/lock' : 'lock/unlock';
                break;
            default:
                // Not controllable – just set local value if changed
                $this->SetValueIfChangedByIdent($ident, $value);
                return;
        }
        // Send to parent (HaBridge Splitter)
        $this->SendDataToParent(json_encode([
            'DataID'   => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
            'Action'   => 'CallService',
            'Service'  => $service,
            'Data'     => $data,
            'SenderID' => $this->InstanceID
        ]));
        // Immediate local feedback (only if changed)
        $this->SetValueIfChangedByIdent($ident, $value);
    }

    /* -------------------- Internals -------------------- */

    protected function ProcessEntityStateUpdate(string $entityId, array $payload): void
    {
        $ident = $this->ResolveIdentByEntityId($entityId);
        if ($ident === '') {
            return;
        }
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false || !is_int($varId) || $varId <= 0 || !IPS_VariableExists($varId)) {
            return;
        }
        $varInfo = IPS_GetVariable($varId);
        if (!array_key_exists('state', $payload)) {
            // Attributes-only update -> keep last state
            if (isset($payload['attributes']) && is_array($payload['attributes']) && !empty($payload['attributes'])) {
                $this->ProcessAttributesForEntity($entityId, $payload['attributes']);
            }
            return;
        }

        $raw = $payload['state'];
        $rawStr = is_scalar($raw) ? strtolower(trim((string)$raw)) : '';
        $isUnknown = in_array($rawStr, ['unavailable','unknown','none','null',''], true);

        // Ignore unknown/unavailable for numeric/bool
        if ($isUnknown && in_array($varInfo['VariableType'], [VARIABLETYPE_BOOLEAN, VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
            $this->SendDebug('StateUpdate', 'Ignored unknown state for numeric/bool', 0);
            return;
        }

        $value = null;
        switch ($varInfo['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = is_bool($raw) ? $raw : in_array($rawStr, ['on','true','1','home'], true);
                break;
            case VARIABLETYPE_INTEGER:
                if (!is_numeric($raw)) {
                    $this->SendDebug('StateUpdate', 'Ignored non-numeric for INTEGER: ' . (string)$raw, 0);
                    return;
                }
                $value = (int)$raw;
                break;
            case VARIABLETYPE_FLOAT:
                if (!is_numeric($raw)) {
                    $this->SendDebug('StateUpdate', 'Ignored non-numeric for FLOAT: ' . (string)$raw, 0);
                    return;
                }
                $value = (float)$raw;
                break;
            default:
                $value = is_scalar($raw) ? (string)$raw : json_encode($raw);
        }
        $this->SetValueIfChangedByIdent($ident, $value);

        // Binary sensor: if no presentation set yet, set it once (respect user changes)
        $domain = '';
        if (strpos($entityId, '.') !== false) {
            $domain = substr($entityId, 0, strpos($entityId, '.'));
        }
        if ($domain === 'binary_sensor') {
            $meta = IPS_GetVariable($varId);
            $custom = isset($meta['VariableCustomPresentation']) && is_array($meta['VariableCustomPresentation'])
                ? $meta['VariableCustomPresentation']
                : [];
            $hasCustom = !empty($custom);
            $isIncomplete = false;
            if ($hasCustom && isset($custom['PRESENTATION']) && $custom['PRESENTATION'] === '{3319437D-7CDE-699D-750A-3C6A3841FA75}') {
                if (!isset($custom['OPTIONS']) || !is_array($custom['OPTIONS']) || empty($custom['OPTIONS'])) {
                    $isIncomplete = true;
                }
            }
            if (!$hasCustom || $isIncomplete) {
                $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                $p = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
                if (empty($p)) {
                    $p = [
                        'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                        'OPTIONS' => [
                            [ 'Value' => false, 'Caption' => $this->Translate('Off'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1 ],
                            [ 'Value' => true,  'Caption' => $this->Translate('On'),  'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1 ]
                        ]
                    ];
                }
                // Remove ICON and other unsupported keys before setting presentation
                $presForIPS = $p;
                if (isset($presForIPS['ICON'])) {
                    unset($presForIPS['ICON']);
                }
                // IPS expects OPTIONS as array, not JSON string
                // Some environments do not accept Presentation on BOOLEAN variables -> guard
                if ($varInfo['VariableType'] !== VARIABLETYPE_BOOLEAN && is_array($presForIPS)) {
                    @IPS_SetVariableCustomPresentation($varId, $presForIPS);
                }
                // Icon only if none set
                $obj = IPS_GetObject($varId);
                $currentIcon = $obj['ObjectIcon'] ?? '';
                if ($currentIcon === '' && isset($p['ICON'])) {
                    IPS_SetIcon($varId, (string)$p['ICON']);
                }
                // Ensure action is disabled
                @$this->DisableAction($ident);
            }
        }

        // Update or create additional attribute variables for this entity
        // Color variables are ALWAYS created, other attributes only if create_additional_vars is enabled
        if (isset($payload['attributes']) && is_array($payload['attributes']) && !empty($payload['attributes'])) {
            $this->ProcessAttributesForEntity($entityId, $payload['attributes']);
        }
    }

    /**
     * Create or update additional attribute variables for a given entity.
     * Color variables are ALWAYS created, other variables only if create_additional_vars is enabled.
     * Variables are hidden (except color) and use idents in the form HAS_<entityIdent>_<attrKey>
     */
    protected function ProcessAttributesForEntity(string $entityId, array $attributes): void
    {
        $createExtra = $this->ReadPropertyBoolean('create_additional_vars');
        $skip = [];
        $entityIdent = $this->BuildIdentForEntity($entityId); // e.g. STAT_sensor_xxx
        // Determine base position from the entity's Status variable
        $statusId = @$this->GetIDForIdent($entityIdent);
        $basePos = 0;
        if ($statusId !== false && $statusId > 0) {
            $obj = @IPS_GetObject($statusId);
            if (is_array($obj) && isset($obj['ObjectPosition'])) {
                $basePos = (int)$obj['ObjectPosition'];
            }
        }
        $offset = 1;
        foreach ($attributes as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            
            // Only rgb_color is always created; hs_color and xy_color are regular attributes
            $isColorVar = (strtolower($key) === 'rgb_color');
            
            // Create rgb_color ALWAYS, other attributes only if create_additional_vars is enabled
            if (!$createExtra && !$isColorVar) {
                continue;
            }
            $sanKey = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$key);
            $ident = 'HAS_' . $entityIdent . '_' . $sanKey;

            // Use DetermineVariableType for proper type detection and presentation (especially for colors)
            $varInfo = $this->DetermineVariableType($key, $value, '', [], false);
            $varType = $varInfo[0];
            $convertedValue = $varInfo[1];
            $profile = $varInfo[2];
            $editable = $varInfo[3];
            $presentation = $varInfo[4] ?? [];

            // Find existing attribute variable under the Status variable
            $varId = ($statusId > 0) ? @IPS_GetObjectIDByIdent($ident, $statusId) : false;
            if ($varId === false || $varId <= 0 || !IPS_VariableExists($varId)) {
                // Create variable manually to place it under the Status variable
                $varId = @IPS_CreateVariable($varType);
                if ($varId === false) {
                    continue;
                }
                @IPS_SetParent($varId, ($statusId > 0 ? $statusId : $this->InstanceID));
                @IPS_SetIdent($varId, $ident);
                @IPS_SetName($varId, (string)$key);
                
                // Set presentation for color variables
                if (!empty($presentation)) {
                    // Always clear any previous custom profile before setting presentation
                    @IPS_SetVariableCustomProfile($varId, '');
                    if (is_array($presentation)) {
                        @IPS_SetVariableCustomPresentation($varId, $presentation);
                    }
                } else {
                    // Ensure no custom profile is set for non-color attribute variables
                    @IPS_SetVariableCustomProfile($varId, '');
                }
            } else {
                // Ensure type/profile match; recreate if type changed
                $v = @IPS_GetVariable($varId);
                if (is_array($v) && isset($v['VariableType']) && $v['VariableType'] !== $varType) {
                    @IPS_DeleteVariable($varId);
                    $varId = @IPS_CreateVariable($varType);
                    @IPS_SetParent($varId, ($statusId > 0 ? $statusId : $this->InstanceID));
                    @IPS_SetIdent($varId, $ident);
                    @IPS_SetName($varId, (string)$key);
                    
                    // Set presentation for color variables
                    if (!empty($presentation) && is_array($presentation)) {
                        @IPS_SetVariableCustomPresentation($varId, $presentation);
                    }
                } else {
                    // Update presentation: for exact color variables always set; otherwise only if none exists
                    if (!empty($presentation)) {
                        $varMeta = @IPS_GetVariable($varId);
                        $hasCustom = is_array($varMeta) && isset($varMeta['VariableCustomPresentation']) 
                            && is_array($varMeta['VariableCustomPresentation']) 
                            && !empty($varMeta['VariableCustomPresentation']);
                        $isExactColorVarPres = (strtolower($key) === 'rgb_color');
                        if ($isExactColorVarPres || !$hasCustom) {
                            // Clear any profile before setting presentation to avoid conflicts
                            @IPS_SetVariableCustomProfile($varId, '');
                            if (is_array($presentation)) {
                                @IPS_SetVariableCustomPresentation($varId, $presentation);
                            }
                        }
                    } else {
                        // Always clear any previous custom profile for non-color attribute variables
                        @IPS_SetVariableCustomProfile($varId, '');
                    }
                }
            }
            
            // Enable action for editable variables (includes color variables)
            if ($editable) {
                @$this->EnableAction($ident);
            }
            // Hide attribute variables - except rgb_color (visible and editable)
            $isExactColorVar = (strtolower($key) === 'rgb_color');
            if (!$isExactColorVar) {
                @IPS_SetHidden($varId, true);
            }
            // Place directly below the corresponding Status variable (ordering among siblings)
            @IPS_SetPosition($varId, $offset);
            $offset++;

            // Set value using converted value from DetermineVariableType
            $this->SetIpsValueIfChanged($varId, $convertedValue);
        }
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
                @SetValue($varId, $normalized);
                return true;
            case VARIABLETYPE_INTEGER:
                $normalized = (int)$newValue;
                $current = (int)GetValue($varId);
                if ($current === $normalized) {
                    return false;
                }
                @SetValue($varId, $normalized);
                return true;
            case VARIABLETYPE_FLOAT:
                $normalized = (float)$newValue;
                $current = (float)GetValue($varId);
                if (abs($current - $normalized) < 1e-6) {
                    return false;
                }
                @SetValue($varId, $normalized);
                return true;
            default:
                $normalized = is_string($newValue) ? $newValue : (string)$newValue;
                $current = (string)GetValue($varId);
                if ($current === $normalized) {
                    return false;
                }
                @SetValue($varId, $normalized);
                return true;
        }
    }

    /**
     * Remove all HAS_* variables created for attributes (across all entities)
     * Keeps Color variables intact.
     */
    protected function CleanupAdditionalVariables(): void
{
    try {
        // 1) Legacy cleanup: delete HAS_* directly under the instance (except color variables)
        $children = @IPS_GetChildrenIDs($this->InstanceID);
        if (is_array($children)) {
            foreach ($children as $id) {
                $obj = @IPS_GetObject($id);
                if (!is_array($obj)) {
                    continue;
                }
                $ident = $obj['ObjectIdent'] ?? '';
                $name = $obj['ObjectName'] ?? '';
                // Keep rgb_color only (always auto-created)
                $lowerName = strtolower($name);
                if ($lowerName === 'rgb_color') {
                    continue;
                }
                if (is_string($ident) && strpos($ident, 'HAS_') === 0) {
                    @IPS_DeleteVariable($id);
                }
            }
        }

        // 2) Cleanup under each Status variable (STAT_*) - except color variables
        if (is_array($children)) {
            foreach ($children as $id) {
                $obj = @IPS_GetObject($id);
                if (!is_array($obj) || ($obj['ObjectType'] ?? 0) !== 2 /* otVariable */) {
                    continue;
                }
                $ident = $obj['ObjectIdent'] ?? '';
                if (is_string($ident) && strpos($ident, 'STAT_') === 0) {
                    $sub = @IPS_GetChildrenIDs($id);
                    if (!is_array($sub)) {
                        continue;
                    }
                    foreach ($sub as $sid) {
                        $sobj = @IPS_GetObject($sid);
                        $sident = is_array($sobj) ? ($sobj['ObjectIdent'] ?? '') : '';
                        $sname = is_array($sobj) ? ($sobj['ObjectName'] ?? '') : '';
                        // Keep rgb_color only (always auto-created)
                        $lowerSname = strtolower($sname);
                        if ($lowerSname === 'rgb_color') {
                            continue;
                        }
                        if (is_string($sident) && strpos($sident, 'HAS_') === 0) {
                            @IPS_DeleteVariable($sid);
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // ignore
    }
}
    protected function DetermineVariableType(
        string $attributeName,
        $value,
        string $entityDomain = '',
        array $attributes = [],
        bool $isStatusVariable = false
    ): array {
        $varType = VARIABLETYPE_STRING;
        $convertedValue = is_scalar($value) ? (string)$value : json_encode($value);
        $profile = '';
        $editable = false;
        $presentation = [];

        if ($isStatusVariable && ($attributes['device_class'] ?? '') === 'timestamp') {
            $varType = VARIABLETYPE_INTEGER;
            $convertedValue = is_string($value) ? (strtotime($value) ?: 0) : (is_int($value) ? $value : 0);
            $presentation = ['PRESENTATION' => '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}'];
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

        if (in_array($entityDomain, ['switch','binary_sensor','input_boolean','automation','light','device_tracker','lock'], true)) {
            $varType = VARIABLETYPE_BOOLEAN;
            $editable = in_array($entityDomain, ['switch','input_boolean','light','lock'], true);
            $convertedValue = is_bool($value) ? $value : in_array(strtolower((string)$value), ['on','true','1','home'], true);
            if ($entityDomain === 'binary_sensor' && $isStatusVariable) {
                $presentation = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
                if (empty($presentation)) {
                    // Generic labels even without device_class
                    $presentation = [
                        'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                        'OPTIONS' => [
                            [ 'Value' => false, 'Caption' => $this->Translate('Off'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1 ],
                            [ 'Value' => true,  'Caption' => $this->Translate('On'),  'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1 ]
                        ]
                    ];
                }
                $editable = false;
            } else {
                $presentation = $editable
                    ? $this->CreateBooleanPresentation($entityDomain, true)
                    : ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
            }
        } elseif (in_array($entityDomain, ['input_number','number'], true)) {
            $varType = VARIABLETYPE_FLOAT;
            $convertedValue = is_numeric($value) ? (float)$value : 0.0;
            $editable = true;
            $presentation = $this->CreateSliderPresentation($attributes, (float)$convertedValue);
        } elseif ($entityDomain === 'counter') {
            $varType = VARIABLETYPE_INTEGER;
            $convertedValue = (int)$value;
            $editable = false;
        } elseif ($entityDomain === 'sensor') {
            if (is_numeric($value)) {
                $varType = (strpos((string)$value, '.') !== false) ? VARIABLETYPE_FLOAT : VARIABLETYPE_INTEGER;
                $convertedValue = $varType === VARIABLETYPE_FLOAT ? (float)$value : (int)$value;
            }
            $presentation = [
                'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                'SUFFIX'       => isset($attributes['unit_of_measurement']) ? (' ' . $attributes['unit_of_measurement']) : '',
                'DIGITS'       => ($varType === VARIABLETYPE_FLOAT) ? 2 : 0
            ];
        } elseif (is_bool($value)) {
            $varType = VARIABLETYPE_BOOLEAN;
            $convertedValue = (bool)$value;
        } elseif (is_int($value)) {
            $varType = VARIABLETYPE_INTEGER;
            $convertedValue = (int)$value;
        } elseif (is_float($value)) {
            $varType = VARIABLETYPE_FLOAT;
            $convertedValue = (float)$value;
        }

        if ($isStatusVariable && !$editable && empty($presentation)) {
            $presentation = ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
        }

        return [$varType, $convertedValue, $profile, $editable, $presentation];
    }

    protected function CreateBooleanPresentation(string $entityDomain, bool $editable): array
    {
        if (!$editable) {
            return ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
        }
        switch ($entityDomain) {
            case 'light':
                return [
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => $this->Translate('On'),
                    'CAPTION_OFF' => $this->Translate('Off'),
                    'ICON_ON' => 'Bulb',
                    'ICON_OFF' => 'Bulb'
                ];
            case 'switch':
            case 'input_boolean':
                return [
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => $this->Translate('On'),
                    'CAPTION_OFF' => $this->Translate('Off'),
                    'ICON_ON' => 'Power',
                    'ICON_OFF' => 'Power'
                ];
        }
        return ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
    }

    protected function CreateBinarySensorPresentationByDeviceClass(array $attributes): array
    {
        $deviceClass = isset($attributes['device_class']) ? (string)$attributes['device_class'] : '';
        $this->SendDebug('BinarySensor', 'device_class=' . ($deviceClass !== '' ? $deviceClass : '(none)'), 0);
        if ($deviceClass === '') {
            return [];
        }
        // device_class => [TRUE Caption, FALSE Caption, Icon]
        $map = [
            'battery'           => [$this->Translate('Batterie niedrig'), $this->Translate('Batterie ok'), 'battery-alert'],
            'battery_charging'  => [$this->Translate('lädt'), $this->Translate('lädt nicht'), 'battery-bolt'],
            'carbon_monoxide'   => [$this->Translate('CO erkannt'), $this->Translate('kein CO'), 'cloud-bolt'],
            'cold'              => [$this->Translate('kalt'), $this->Translate('normal'), 'snowflake'],
            'connectivity'      => [$this->Translate('verbunden'), $this->Translate('getrennt'), 'wifi'],
            'door'              => [$this->Translate('offen'), $this->Translate('geschlossen'), 'door-open'],
            'garage_door'       => [$this->Translate('offen'), $this->Translate('geschlossen'), 'garage-open'],
            'gas'               => [$this->Translate('Gas erkannt'), $this->Translate('kein Gas'), 'cloud-bolt'],
            'heat'              => [$this->Translate('heiß'), $this->Translate('normal'), 'fire'],
            'light'             => [$this->Translate('Licht erkannt'), $this->Translate('kein Licht'), 'lightbulb-on'],
            'lock'              => [$this->Translate('entsperrt'), $this->Translate('gesperrt'), 'lock-open'],
            'moisture'          => [$this->Translate('nass'), $this->Translate('trocken'), 'droplet'],
            'motion'            => [$this->Translate('Bewegung erkannt'), $this->Translate('keine Bewegung'), 'person-running'],
            'moving'            => [$this->Translate('in Bewegung'), $this->Translate('stillstehend'), 'person-running'],
            'occupancy'         => [$this->Translate('belegt'), $this->Translate('frei'), 'house-person-return'],
            'opening'           => [$this->Translate('offen'), $this->Translate('geschlossen'), 'up-right-from-square'],
            'plug'              => [$this->Translate('eingesteckt'), $this->Translate('ausgesteckt'), 'plug'],
            'power'             => [$this->Translate('Strom erkannt'), $this->Translate('kein Strom'), 'bolt'],
            'presence'          => [$this->Translate('anwesend'), $this->Translate('abwesend'), 'user'],
            'problem'           => [$this->Translate('Problem erkannt'), $this->Translate('kein Problem'), 'triangle-exclamation'],
            'running'           => [$this->Translate('läuft'), $this->Translate('gestoppt'), 'play'],
            'safety'            => [$this->Translate('unsicher/gefährlich'), $this->Translate('sicher'), 'shield-exclamation'],
            'smoke'             => [$this->Translate('Rauch erkannt'), $this->Translate('kein Rauch'), 'fire-smoke'],
            'sound'             => [$this->Translate('Geräusch erkannt'), $this->Translate('kein Geräusch'), 'volume-high'],
            'tamper'            => [$this->Translate('Manipulation erkannt'), $this->Translate('keine Manipulation'), 'hand'],
            'update'            => [$this->Translate('Update verfügbar'), $this->Translate('aktuell'), 'arrows-rotate'],
            'vibration'         => [$this->Translate('Vibration erkannt'), $this->Translate('keine Vibration'), 'chart-fft'],
            'window'            => [$this->Translate('offen'), $this->Translate('geschlossen'), 'window-open'],
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
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'OPTIONS'      => $options,
            'ICON'         => $icon
        ];
    }

    protected function CreateSliderPresentation(array $attributes, float $currentValue): array
    {
        $min  = (float)($attributes['min'] ?? 0);
        $max  = (float)($attributes['max'] ?? 100);
        $step = (float)($attributes['step'] ?? 1);
        $unit = $attributes['unit_of_measurement'] ?? '';
        $p = [
            'PRESENTATION' => '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}',
            'MIN' => $min,
            'MAX' => $max,
            'STEP_SIZE' => $step,
            'SUFFIX' => $unit ? ' ' . $unit : ''
        ];
        if ($step < 1) {
            $p['DIGITS'] = 2;
        }
        return $p;
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

    /* --------- Helpers --------- */

    protected function ReadEntities(): array
    {
        $j = $this->ReadPropertyString('entities');
        $arr = json_decode($j, true);
        return is_array($arr) ? $arr : [];
    }

    protected function BuildIdentForEntity(string $entityId): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_]/', '_', $entityId);
        return 'STAT_' . $slug;
    }

    protected function ResolveEntityIdByIdent(string $ident): string
    {
        $index = json_decode($this->ReadAttributeString('EntityIndex'), true);
        if (!is_array($index)) {
            return '';
        }
        foreach ($index as $eId => $id) {
            if ($id === $ident) {
                return $eId;
            }
        }
        return '';
    }

    protected function ResolveIdentByEntityId(string $entityId): string
    {
        $index = json_decode($this->ReadAttributeString('EntityIndex'), true);
        if (isset($index[$entityId])) {
            return (string)$index[$entityId];
        }
        // Fallback: build on the fly
        return $this->BuildIdentForEntity($entityId);
    }

    protected function FetchStates(): array
    {
        $cfg = $this->GetHAConfig();
        if ($cfg['url'] === '' || $cfg['token'] === '') {
            return [];
        }

        $r = HaRestHelper::GetJson($cfg['url'], $cfg['token'], '/api/states', 10, 5);
        if (!$r['ok'] || ($r['http'] ?? 0) !== 200 || !is_array($r['json'] ?? null)) {
            return [];
        }
        $arr = $r['json'];
        $out = [];
        foreach ($arr as $item) {
            if (!isset($item['entity_id'])) {
                continue;
            }
            $out[$item['entity_id']] = [
                'state' => $item['state'] ?? null,
                'attributes' => $item['attributes'] ?? []
            ];
        }
        return $out;
    }

    protected function GetHAConfig(): array
    {
        // Try to resolve from HaConfigurator instances
        try {
            $moduleID = '{32D99DCD-A530-4907-3FB0-44D7D472771D}';
            $ids = @IPS_GetInstanceListByModuleID($moduleID);
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    if (!IPS_InstanceExists($id)) {
                        continue;
                    }
                    $url = @IPS_GetProperty($id, 'ha_url');
                    $token = @IPS_GetProperty($id, 'ha_token');
                    if (is_string($url) && $url !== '' && is_string($token) && $token !== '') {
                        return ['url' => $url, 'token' => $token];
                    }
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return ['url' => '', 'token' => ''];
    }
}
