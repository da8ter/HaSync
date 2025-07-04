<?php

declare(strict_types=1);

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
        
        // Real-time options
        $this->RegisterPropertyBoolean('use_realtime', false);
        $this->RegisterPropertyInteger('websocket_id', 0);
        
        // Polling settings (fallback option)
        $this->RegisterPropertyBoolean('use_polling', true);
        $this->RegisterPropertyInteger('polling_interval', 30);
        
        // Timer for regular polling
        $this->RegisterTimer('PollingTimer', 0, 'HACO_PollStates($_IPS["TARGET"]);');
        
        // Message handler
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy()
    {
        $this->SetTimerInterval('PollingTimer', 0);
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetupPolling();
    }
    
    /**
     * Handle system messages
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELSTARTED) {
            $this->SetupPolling();
        }
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
     * Poll states from Home Assistant and update HaDevice instances
     */
    public function PollStates()
    {
        if (!$this->ReadPropertyBoolean('use_polling')) {
            return;
        }
        
        $HaDeviceModuleID = $this->GetHaDeviceModuleID();
        if (empty($HaDeviceModuleID)) {
            $this->LogMessage('Could not determine HaDevice module ID', KL_ERROR);
            return;
        }

        $instanceIDs = IPS_GetInstanceListByModuleID($HaDeviceModuleID);
        if (empty($instanceIDs)) {
            return;
        }

        foreach ($instanceIDs as $instanceID) {
            if (!IPS_InstanceExists($instanceID)) {
                continue;
            }

            try {
                $entityId = IPS_GetProperty($instanceID, 'entity_id');
                if (empty($entityId)) {
                    continue;
                }

                $state = $this->GetEntityState($entityId);
                if ($state !== false) {
                    IPS_RequestAction($instanceID, 'UpdateFromPolling', $state);
                }
            } catch (Exception $e) {
                $this->SendDebug('PollStates', 'Error polling instance ' . $instanceID . ': ' . $e->getMessage(), 0);
            }
        }
    }

    /**
     * Get entity state from Home Assistant
     */
    public function GetEntityState(string $entityId)
    {
        $haUrl = $this->ReadPropertyString('ha_url');
        $haToken = $this->ReadPropertyString('ha_token');
        
        if (empty($haUrl) || empty($haToken)) {
            return false;
        }
        
        $url = rtrim($haUrl, '/') . '/api/states/' . $entityId;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $haToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            $this->SendDebug('GetEntityState', 'cURL Error for ' . $entityId . ': ' . curl_error($ch), 0);
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->SendDebug('GetEntityState', 'HTTP Error ' . $httpCode . ' for ' . $entityId, 0);
            return false;
        }
        
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return false;
        }
        
        return $data;
    }

    /**
     * Fetch all devices/entities from Home Assistant
     */
    public function FetchDevices()
    {
        $haUrl = $this->ReadPropertyString('ha_url');
        $haToken = $this->ReadPropertyString('ha_token');
        
        if (empty($haUrl) || empty($haToken)) {
            $this->SendDebug('FetchDevices', 'Home Assistant URL or token not configured', 0);
            return false;
        }
        
        $url = rtrim($haUrl, '/') . '/api/states';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $haToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            $this->SendDebug('FetchDevices', 'cURL Error: ' . curl_error($ch), 0);
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->SendDebug('FetchDevices', 'HTTP Error: ' . $httpCode, 0);
            return false;
        }
        
        $data = json_decode($response, true);
        if (!is_array($data)) {
            $this->SendDebug('FetchDevices', 'Invalid JSON response', 0);
            return false;
        }
        
        return $data;
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
                        'caption' => 'Home Assistant URL'
                    ],
                    [
                        'type' => 'PasswordTextBox',
                        'name' => 'ha_token',
                        'caption' => 'Long-lived Access Token'
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
                                'label' => 'Entity ID',
                                'name' => 'entity_id',
                                'width' => '200px'
                            ],
                            [
                                'label' => 'Name',
                                'name' => 'friendly_name',
                                'width' => 'auto'
                            ],
                            [
                                'label' => 'State',
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
        
        // Find configurator index
        $configuratorIndex = -1;
        foreach ($form['actions'] as $index => $action) {
            if (isset($action['type']) && $action['type'] === 'Configurator' && 
                isset($action['name']) && $action['name'] === 'DeviceConfigurator') {
                $configuratorIndex = $index;
                break;
            }
        }
        
        if ($configuratorIndex === -1 && !file_exists($formFile)) {
            $configuratorIndex = 0;
        }
        
        // Check Home Assistant URL and token
        $haUrl = $this->ReadPropertyString('ha_url');
        $haToken = $this->ReadPropertyString('ha_token');
        
        if (empty($haUrl) || empty($haToken)) {
            if ($configuratorIndex !== -1) {
                $form['actions'][$configuratorIndex]['values'] = [[
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
            if ($configuratorIndex !== -1) {
                $form['actions'][$configuratorIndex]['values'] = [[
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
                        $entityIdProperty = IPS_GetProperty($id, 'entity_id');
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
                
                $values[] = [
                    'entity_id' => $entityId,
                    'friendly_name' => $friendlyName,
                    'state' => $device['state'] ?? $this->Translate('unknown'),
                    'instanceID' => $instanceID,
                    'create' => [
                        'moduleID' => $HaDeviceModuleID,
                        'configuration' => [
                            'entity_id' => $entityId,
                            'parent_id' => $this->InstanceID
                        ],
                        'name' => $friendlyName
                    ]
                ];
            }
        }
        
        // Sort by name
        usort($values, function ($a, $b) {
            return strcmp($a['friendly_name'], $b['friendly_name']);
        });
        
        // Update values in configurator
        if ($configuratorIndex !== -1) {
            $form['actions'][$configuratorIndex]['values'] = $values;
        }
        
        return json_encode($form);
    }
}
