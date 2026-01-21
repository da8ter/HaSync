<?php

declare(strict_types=1);

 require_once dirname(__DIR__) . '/libs/HaRestHelper.php';

/**
 * HaConfigurator - Home Assistant REST API Configurator für IP-Symcon
 * Automatische Geräteerkennung Aktualisierung
 * 
 * @version 2.0.0
 * @author Windsurf.io
 */
class HaConfigurator extends IPSModule
{
    public function Create()
    {
        parent::Create();
        
        // Properties for Home Assistant connection
        $this->RegisterPropertyString('ha_url', '');
        $this->RegisterPropertyString('ha_token', '');
        // Multi-Entity wizard inputs
        $this->RegisterPropertyString('multi_group_name', '');
        $this->RegisterPropertyString('multi_entity_ids', ''); // newline or comma separated list of entity_ids
        $this->RegisterPropertyInteger('target_category', 0);
        
        // Real-time options
        $this->RegisterPropertyBoolean('use_realtime', false);
        $this->RegisterPropertyInteger('websocket_id', 0);
        
       
        // Message handler
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ShowMultiNameMissingPopup()
    {
        $this->UpdateFormField('multi_name_missing_popup', 'visible', true);
    }

    protected function GetHAConfig(): array
    {
        $result = ['url' => '', 'token' => ''];

        // Prefer configuration from HaBridge
        $bridgeModuleID = '{B8A9C2D1-4E5F-6789-ABCD-123456789ABC}';
        $bridges = @IPS_GetInstanceListByModuleID($bridgeModuleID);
        if (is_array($bridges)) {
            foreach ($bridges as $bridgeId) {
                if (!IPS_InstanceExists($bridgeId)) {
                    continue;
                }
                $url = (string)@IPS_GetProperty($bridgeId, 'ha_url');
                $token = (string)@IPS_GetProperty($bridgeId, 'ha_token');
                if ($url !== '' && $token !== '') {
                    $result['url'] = $url;
                    $result['token'] = $token;
                    return $result;
                }
            }
        }

        // Fallback: legacy configuration from this Configurator instance
        $url = (string)$this->ReadPropertyString('ha_url');
        $token = (string)$this->ReadPropertyString('ha_token');
        if ($url !== '' && $token !== '') {
            $result['url'] = $url;
            $result['token'] = $token;
        }

        return $result;
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }
    
    /**
     * Handle system messages
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

    }
    
    /**
     * Setup polling based on configuration
     */
    protected function SetupPolling()
    {
        $usePolling = $this->ReadPropertyBoolean('use_polling');
        $interval = $this->ReadPropertyInteger('polling_interval');
        
        if ($usePolling && $interval > 0) {
            $this->SetTimerInterval('PollingTimer', $interval * 1000);
        } else {
            $this->SetTimerInterval('PollingTimer', 0);
        }
    }
    
    /**
     * Get entity state from Home Assistant
     */
    public function GetEntityState(string $entityId)
    {
        $ha = $this->GetHAConfig();
        $haUrl = (string)$ha['url'];
        $haToken = (string)$ha['token'];
        
        if (empty($haUrl) || empty($haToken)) {
            return false;
        }

        $r = HaRestHelper::GetJson($haUrl, $haToken, '/api/states/' . $entityId, 10, 5);
        if (!$r['ok'] || ($r['http'] ?? 0) !== 200 || !is_array($r['json'] ?? null)) {
            $err = (string)($r['error'] ?? '');
            if ($err !== '') {
                $this->SendDebug('GetEntityState', 'REST Error for ' . $entityId . ': ' . $err, 0);
            } else {
                $this->SendDebug('GetEntityState', 'HTTP Error ' . (int)($r['http'] ?? 0) . ' for ' . $entityId, 0);
            }
            return false;
        }
        return $r['json'];
    }

    /**
     * Fetch all devices/entities from Home Assistant
     */
    public function FetchDevices()
    {
        $ha = $this->GetHAConfig();
        $haUrl = (string)$ha['url'];
        $haToken = (string)$ha['token'];
        
        if (empty($haUrl) || empty($haToken)) {
            $this->SendDebug('FetchDevices', 'Home Assistant URL or token not configured', 0);
            return false;
        }

        $r = HaRestHelper::GetJson($haUrl, $haToken, '/api/states', 30, 10);
        if (!$r['ok'] || ($r['http'] ?? 0) !== 200 || !is_array($r['json'] ?? null)) {
            $err = (string)($r['error'] ?? '');
            if ($err !== '') {
                $this->SendDebug('FetchDevices', 'REST Error: ' . $err, 0);
            } else {
                $this->SendDebug('FetchDevices', 'HTTP Error: ' . (int)($r['http'] ?? 0), 0);
            }
            return false;
        }

        return $r['json'];
    }

    /**
     * Get HaDevice module ID
     */
    protected function GetHaDeviceModuleID(): string
    {
        return '{8DF4E3B9-1FF2-B0B3-649E-117AC0B355FD}';
    }

    /**
     * Update configurator
     */
    public function UpdateConfigurator()
    {
        IPS_ReloadForm($this->InstanceID);
    }

    /**
     * Get configuration form
     */
    public function GetConfigurationForm()
    {
        // Try to load existing form.json
        $formFile = __DIR__ . '/form.json';
        if (file_exists($formFile)) {
            $form = json_decode(file_get_contents($formFile), true);
        } else {
            // Create minimal form if form.json doesn't exist
            $form = [
                'elements' => [
                    [
                        'type' => 'ValidationTextBox',
                        'name' => 'ha_url',
                        'caption' => $this->Translate('Home Assistant URL')
                    ],
                    [
                        'type' => 'PasswordTextBox',
                        'name' => 'ha_token',
                        'caption' => $this->Translate('Long-lived Access Token')
                    ]
                ],
                'actions' => [
                    [
                        'type' => 'Configurator',
                        'name' => 'DeviceConfigurator',
                        'rowCount' => 20,
                        'add' => true,
                        'delete' => true,
                        'columns' => [
                            [
                                'label' => $this->Translate('Entity ID'),
                                'name' => 'entity_id',
                                'width' => '200px'
                            ],
                            [
                                'label' => $this->Translate('Name'),
                                'name' => 'friendly_name',
                                'width' => 'auto'
                            ],
                            [
                                'label' => $this->Translate('State'),
                                'name' => 'state',
                                'width' => '100px'
                            ]
                        ],
                        'values' => []
                    ]
                ],
                'status' => []
            ];
        }
        
        // Find configurator (supports top-level and inside ExpansionPanel)
        $configuratorRef = null; // reference to the configurator array
        // 1) Try top-level actions
        foreach ($form['actions'] as $idx => &$action) {
            if (isset($action['type'], $action['name'])
                && $action['type'] === 'Configurator'
                && $action['name'] === 'DeviceConfigurator') {
                $configuratorRef = &$action;
                break;
            }
        }
        unset($action);
        // 2) Try nested in ExpansionPanel -> items
        if ($configuratorRef === null) {
            foreach ($form['actions'] as $aIdx => &$action) {
                if (($action['type'] ?? '') === 'ExpansionPanel' && isset($action['items']) && is_array($action['items'])) {
                    foreach ($action['items'] as $iIdx => &$item) {
                        if (isset($item['type'], $item['name'])
                            && $item['type'] === 'Configurator'
                            && $item['name'] === 'DeviceConfigurator') {
                            $configuratorRef = &$item;
                            break 2;
                        }
                    }
                    unset($item);
                }
            }
            unset($action);
        }
        
        // If no configurator found and form.json missing, create a minimal one at actions[0]
        if ($configuratorRef === null && !file_exists($formFile)) {
            // Ensure actions exists
            if (!isset($form['actions']) || !is_array($form['actions'])) {
                $form['actions'] = [];
            }
            $form['actions'][] = [
                'type' => 'Configurator',
                'name' => 'DeviceConfigurator',
                'rowCount' => 20,
                'add' => true,
                'delete' => true,
                'columns' => [
                    [ 'label' => 'Entity ID', 'name' => 'entity_id', 'width' => '30%' ],
                    [ 'label' => 'Name', 'name' => 'friendly_name', 'width' => '30%' ],
                    [ 'label' => 'State', 'name' => 'state', 'width' => 'auto' ]
                ],
                'values' => []
            ];
            $configuratorRef = &$form['actions'][count($form['actions']) - 1];
        }
        
        // Check Home Assistant URL and token
        $ha = $this->GetHAConfig();
        $haUrl = (string)$ha['url'];
        $haToken = (string)$ha['token'];
        
        if (empty($haUrl) || empty($haToken)) {
            if ($configuratorRef !== null) {
                $configuratorRef['values'] = [[
                    'entity_id' => '',
                    'friendly_name' => $this->Translate('Home Assistant URL and Token must be configured'),
                    'state' => ''
                ]];
            }
            return json_encode($form);
        }
        
        // Fetch all devices from Home Assistant
        $devices = $this->FetchDevices();
        
        if ($devices === false || empty($devices)) {
            if ($configuratorRef !== null) {
                $configuratorRef['values'] = [[
                    'entity_id' => '',
                    'friendly_name' => $this->Translate('No devices found or connection error'),
                    'state' => ''
                ]];
            }
            return json_encode($form);
        }
        
        // Get HaDevice module ID for instance creation
        $HaDeviceModuleID = $this->GetHaDeviceModuleID();
        
        // Get existing instances
        $existingInstances = [];
        if (!empty($HaDeviceModuleID)) {
            $instanceIDs = IPS_GetInstanceListByModuleID($HaDeviceModuleID);
            foreach($instanceIDs as $id) {
                if(IPS_InstanceExists($id)) {
                    try {
                        $entityIdProperty = @IPS_GetProperty($id, 'entity_id');
                        if(!empty($entityIdProperty)) {
                            $existingInstances[$entityIdProperty] = $id;
                        }
                    } catch (Exception $e) {
                        // Property doesn't exist - skip
                    }
                }
            }
        }
        
        // Prepare device list
        $values = [];
        foreach ($devices as $device) {
            if (isset($device['entity_id'])) {
                $entityId = $device['entity_id'];
                $friendlyName = $device['attributes']['friendly_name'] ?? $entityId;
                $instanceID = $existingInstances[$entityId] ?? 0;
                
                $row = [
                    'entity_id' => $entityId,
                    'friendly_name' => $friendlyName,
                    'state' => $device['state'] ?? $this->Translate('unknown'),
                    'instanceID' => $instanceID,
                ];

                // Offer creation only if no instance exists yet
                // Use Variant 1 (Single Instance) - HaDevice auto-connects to HaBridge in its Create() method
                if ($instanceID === 0) {
                    $row['create'] = [
                        'moduleID' => $HaDeviceModuleID,
                        'configuration' => [
                            'entity_id' => $entityId,
                            'parent_id' => $this->InstanceID
                        ],
                        'name' => $friendlyName
                    ];
                }

                $values[] = $row;
            }
        }
        
        // Sort by name
        usort($values, function ($a, $b) {
            return strcmp($a['friendly_name'], $b['friendly_name']);
        });
        
        // Update values in configurator
        if ($configuratorRef !== null) {
            if (isset($configuratorRef['create'])) {
                unset($configuratorRef['create']);
            }
            $configuratorRef['values'] = $values;
        }

        // Build selectable rows for multi-entity creation
        $selectRows = [];
        foreach ($devices as $device) {
            if (!isset($device['entity_id'])) {
                continue;
            }
            $entityId = (string)$device['entity_id'];
            $friendlyName = (string)($device['attributes']['friendly_name'] ?? $entityId);
            $domain = strpos($entityId, '.') !== false ? substr($entityId, 0, strpos($entityId, '.')) : '';
            $selectRows[] = [
                'select' => false,
                'entity_id' => $entityId,
                'friendly_name' => $friendlyName,
                'domain' => $domain
            ];
        }

        // Append Multi-Entity Wizard action block if not present in form.json
        $form['actions'][] = [
            'type' => 'PopupAlert',
            'name' => 'multi_name_missing_popup',
            'visible' => false,
            'popup' => [
                'closeCaption' => $this->Translate('OK'),
                'items' => [
                    [ 'type' => 'Label', 'caption' => $this->Translate('Bitte einen Gerätenamen vergeben') ]
                ]
            ]
        ];

        $form['actions'][] = [
            'type' => 'ExpansionPanel',
            'expanded' => true,
            'caption' => $this->Translate('Multi-Entitäten-Gerät erstellen'),
            'items' => [
                [ 'type' => 'ValidationTextBox', 'name' => 'multi_group_name', 'caption' => $this->Translate('Geräte Name') ],
                [ 'type' => 'SelectObject', 'name' => 'target_category', 'caption' => $this->Translate('Zielkategorie') ],
                [
                    'type' => 'List',
                    'name' => 'multi_select_entities',
                    'caption' => $this->Translate('Entitäten auswählen'),
                    'rowCount' => 12,
                    'add' => false,
                    'delete' => false,
                    'columns' => [
                        [ 'name' => 'select', 'caption' => '', 'width' => '30px', 'edit' => [ 'type' => 'CheckBox' ] ],
                        [ 'name' => 'entity_id', 'caption' => $this->Translate('Entity ID'), 'width' => '30%' ],
                        [ 'name' => 'friendly_name', 'caption' => $this->Translate('Name'), 'width' => '30%' ],
                        [ 'name' => 'domain', 'caption' => $this->Translate('Domain'), 'width' => 'auto' ]
                    ],
                    'values' => $selectRows
                ],
                [ 'type' => 'Button', 'caption' => $this->Translate('Create from selection'), 'onClick' => ' if (trim((string)$multi_group_name) === "") { HACO_ShowMultiNameMissingPopup($id); return; } $sel=[]; foreach ($multi_select_entities as $row) { if (isset($row["select"]) && $row["select"]) { $sel[] = $row["entity_id"]; } } HACO_CreateMultiEntityDeviceFromSelection($id, json_encode($sel), $multi_group_name, $target_category);' ]
            ]
        ];

        return json_encode($form);
    }

    /**
     * Create a HaMultiEntityDevice instance from the provided group name and entity IDs
     */
    public function CreateMultiEntityDevice(int $categoryId = 0)
    {
        $group = trim($this->ReadPropertyString('multi_group_name'));
        if ($group === '') {
            $msg = $this->Translate('Bitte einen Gerätenamen vergeben');
            $this->LogMessage($msg, KL_ERROR);
            return false;
        }
        $idsRaw = trim($this->ReadPropertyString('multi_entity_ids'));
        if ($idsRaw === '') {
            $this->LogMessage('No entity IDs provided', KL_WARNING);
            return false;
        }
        // Parse IDs (comma or newline separated)
        $list = preg_split('/[\n,;]+/', $idsRaw);
        $entityIds = [];
        foreach ($list as $id) {
            $id = trim($id);
            if ($id !== '') {
                $entityIds[] = $id;
            }
        }
        $entityIds = array_values(array_unique($entityIds));
        if (empty($entityIds)) {
            $this->LogMessage('Parsed entity list is empty', KL_WARNING);
            return false;
        }

        // Resolve friendly names via /api/states
        $states = $this->FetchDevices();
        $entities = [];
        foreach ($entityIds as $eid) {
            $friendly = $eid;
            $found = false;
            if (is_array($states)) {
                foreach ($states as $st) {
                    if (($st['entity_id'] ?? '') === $eid) {
                        $friendly = (string)($st['attributes']['friendly_name'] ?? $eid);
                        $found = true;
                        break;
                    }
                }
            }
            $entities[] = [ 'entity_id' => $eid, 'alias' => $friendly, 'role' => 'other', 'section' => '' ];
            if (!$found) {
                $this->SendDebug('CreateMultiEntityDevice', 'Entity not found in states: ' . $eid, 0);
            }
        }

        // Create instance
        $moduleID = '{5E0B3C3A-FD10-4E32-95D3-1B4EAA9A7C77}'; // HaMultiEntityDevice
        $instID = @IPS_CreateInstance($moduleID);
        if ($instID === false) {
            $this->LogMessage('Failed to create HaMultiEntityDevice instance', KL_ERROR);
            return false;
        }
        if ($group !== '') {
            @IPS_SetName($instID, $group);
        }
        // Decide parent: target category if provided/selected; otherwise under HaBridge
        $targetCat = $categoryId > 0 ? $categoryId : (int)$this->ReadPropertyInteger('target_category');
        if ($targetCat > 0 && @IPS_ObjectExists($targetCat)) {
            @IPS_SetParent($instID, $targetCat);
        }
        @IPS_SetProperty($instID, 'group_name', $group);
        @IPS_SetProperty($instID, 'entities', json_encode($entities));
        @IPS_ApplyChanges($instID);

        // Clear input fields
        $this->UpdateFormField('multi_group_name', 'value', '');
        $this->UpdateFormField('multi_entity_ids', 'value', '');

        return true;
    }

    /**
     * Create a HaMultiEntityDevice instance from the selection list
     */
    public function CreateMultiEntityDeviceFromSelection(string $idsJSON, string $groupName = '', int $categoryId = 0)
    {
        $ids = json_decode($idsJSON, true);
        if (!is_array($ids) || empty($ids)) {
            $this->LogMessage('Selection is empty', KL_WARNING);
            return false;
        }
        $group = trim($groupName) !== '' ? trim($groupName) : trim($this->ReadPropertyString('multi_group_name'));
        if ($group === '') {
            $msg = $this->Translate('Bitte einen Gerätenamen vergeben');
            $this->LogMessage($msg, KL_ERROR);
            return false;
        }

        // Resolve friendly names via /api/states
        $states = $this->FetchDevices();
        $entities = [];
        foreach ($ids as $eid) {
            $eid = (string)$eid;
            if ($eid === '') {
                continue;
            }
            $friendly = $eid;
            if (is_array($states)) {
                foreach ($states as $st) {
                    if (($st['entity_id'] ?? '') === $eid) {
                        $friendly = (string)($st['attributes']['friendly_name'] ?? $eid);
                        break;
                    }
                }
            }
            $entities[] = [ 'entity_id' => $eid, 'alias' => $friendly, 'role' => 'other', 'section' => '' ];
        }

        // Create instance
        $moduleID = '{5E0B3C3A-FD10-4E32-95D3-1B4EAA9A7C77}'; // HaMultiEntityDevice
        $instID = @IPS_CreateInstance($moduleID);
        if ($instID === false) {
            $this->LogMessage('Failed to create HaMultiEntityDevice instance', KL_ERROR);
            return false;
        }
        if ($group !== '') {
            @IPS_SetName($instID, $group);
        }
        // Decide parent: target category if provided/selected; otherwise under HaBridge
        $targetCat = $categoryId > 0 ? $categoryId : (int)$this->ReadPropertyInteger('target_category');
        if ($targetCat > 0 && @IPS_ObjectExists($targetCat)) {
            @IPS_SetParent($instID, $targetCat);
        }
        @IPS_SetProperty($instID, 'group_name', $group);
        @IPS_SetProperty($instID, 'entities', json_encode($entities));
        @IPS_ApplyChanges($instID);

        // Clear input fields
        $this->UpdateFormField('multi_group_name', 'value', '');
        $this->UpdateFormField('multi_entity_ids', 'value', '');

        return true;
    }
}
