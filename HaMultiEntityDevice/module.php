<?php

declare(strict_types=1);

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

    public function Rebuild(): void
    {
        $this->ApplyChanges();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);

        $entities = $this->ReadEntities();
        $index = [];

        // Try to fetch current states once (best effort)
        $states = $this->FetchStates(); // entity_id => [state, attributes]

        foreach ($entities as $e) {
            $entityId = (string)($e['entity_id'] ?? '');
            if ($entityId === '') {
                continue;
            }
            $alias = isset($e['alias']) && is_string($e['alias']) ? trim($e['alias']) : '';
            $ident = $this->BuildIdentForEntity($entityId);
            $index[$entityId] = $ident;

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
                $varId = $this->GetIDForIdent($ident);
                // Only set presentation if there is no custom presentation yet
                $meta = @IPS_GetVariable($varId);
                $hasCustom = is_array($meta) && isset($meta['VariableCustomPresentation']) &&
                             is_array($meta['VariableCustomPresentation']) && !empty($meta['VariableCustomPresentation']);
                if (!$hasCustom) {
                    IPS_SetVariableCustomPresentation($varId, $presentation);
                }
            } else {
                $this->MaintainVariable($ident, $name, $varType, $profile, 0, true);
                $varId = $this->GetIDForIdent($ident);
            }

            // Initial value
            $this->SetValue($ident, $convertedValue);

            // Icon only if none set
            $varId = $this->GetIDForIdent($ident);
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

            if ($editable && in_array($entityDomain, ['input_number', 'light', 'switch', 'input_boolean'])) {
                $this->EnableAction($ident);
            }
        }

        $this->WriteAttributeString('EntityIndex', json_encode($index));
        $this->WriteAttributeBoolean('Initialized', true);

        // Ask parent (HaBridge) to refresh subscriptions
        $this->SendDataToParent(json_encode([
            'DataID' => '{B5C8F9A1-2D3E-4F50-8A6B-1C2D3E4F5A6B}',
            'Action' => 'UpdateSubscriptions'
        ]));
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
            return;
        }
        $domain = '';
        if (strpos($entityId, '.') !== false) {
            $domain = substr($entityId, 0, strpos($entityId, '.'));
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
            default:
                // Not controllable – just set local value
                $this->SetValue($ident, $value);
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
        // Immediate local feedback
        $this->SetValue($ident, $value);
    }

    /* -------------------- Internals -------------------- */

    protected function ProcessEntityStateUpdate(string $entityId, array $payload): void
    {
        $ident = $this->ResolveIdentByEntityId($entityId);
        if ($ident === '') {
            return;
        }
        $varId = $this->GetIDForIdent($ident);
        if ($varId === false) {
            return;
        }
        $varInfo = IPS_GetVariable($varId);
        $raw = $payload['state'] ?? '';
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
        $this->SetValue($ident, $value);

        // Binary sensor: if no presentation set yet, set it once (respect user changes)
        $domain = '';
        if (strpos($entityId, '.') !== false) {
            $domain = substr($entityId, 0, strpos($entityId, '.'));
        }
        if ($domain === 'binary_sensor') {
            $meta = IPS_GetVariable($varId);
            $hasCustom = isset($meta['VariableCustomPresentation']) && is_array($meta['VariableCustomPresentation']) &&
                         !empty($meta['VariableCustomPresentation']);
            if (!$hasCustom) {
                $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];
                $p = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
                if (!empty($p)) {
                    IPS_SetVariableCustomPresentation($varId, $p);
                }
                // Icon only if none set
                $obj = IPS_GetObject($varId);
                $currentIcon = $obj['ObjectIcon'] ?? '';
                if ($currentIcon === '' && isset($p['ICON'])) {
                    IPS_SetIcon($varId, (string)$p['ICON']);
                }
            }
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

        if (in_array($entityDomain, ['switch','binary_sensor','input_boolean','automation','light','device_tracker'], true)) {
            $varType = VARIABLETYPE_BOOLEAN;
            $editable = in_array($entityDomain, ['switch','input_boolean','light'], true);
            $convertedValue = is_bool($value) ? $value : in_array(strtolower((string)$value), ['on','true','1','home'], true);
            if ($entityDomain === 'binary_sensor' && $isStatusVariable) {
                $presentation = $this->CreateBinarySensorPresentationByDeviceClass($attributes);
                if (empty($presentation)) {
                    $presentation = ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
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
                    'CAPTION_ON' => 'On',
                    'CAPTION_OFF' => 'Off',
                    'ICON_ON' => 'Bulb',
                    'ICON_OFF' => 'Bulb'
                ];
            case 'switch':
            case 'input_boolean':
                return [
                    'PRESENTATION' => '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}',
                    'CAPTION_ON' => 'On',
                    'CAPTION_OFF' => 'Off',
                    'ICON_ON' => 'Power',
                    'ICON_OFF' => 'Power'
                ];
        }
        return ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
    }

    protected function CreateBinarySensorPresentationByDeviceClass(array $attributes): array
    {
        $deviceClass = isset($attributes['device_class']) ? (string)$attributes['device_class'] : '';
        if ($deviceClass === '') {
            return ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
        }
        $map = [
            'door'      => ['offen', 'geschlossen', 'door-open'],
            'window'    => ['offen', 'geschlossen', 'window-open'],
            'plug'      => ['eingesteckt', 'ausgesteckt', 'plug'],
            'battery'   => ['Batterie niedrig', 'Batterie ok', 'battery-alert'],
            'problem'   => ['Problem erkannt', 'kein Problem', 'triangle-exclamation'],
            'motion'    => ['Bewegung erkannt', 'keine Bewegung', 'person-running'],
            'presence'  => ['anwesend', 'abwesend', 'user'],
            'lock'      => ['entsperrt', 'gesperrt', 'lock-open'],
        ];
        if (!isset($map[$deviceClass])) {
            return ['PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}'];
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

    protected function MapHAIconToSymcon(string $haIcon): string
    {
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
        $apiUrl = rtrim($cfg['url'], '/') . '/api/states';
        $headers = [
            'Authorization: Bearer ' . $cfg['token'],
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
        $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($result === false || $http !== 200) {
            return [];
        }
        $arr = json_decode($result, true);
        if (!is_array($arr)) {
            return [];
        }
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
