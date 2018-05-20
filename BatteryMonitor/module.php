<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');

/**
 * Class BatteryMonitor
 * IP-Symcon Battery Monitor Module
 *
 * @version     1.1
 * @category    Symcon
 * @package     de.codeking.symcon.batterymonitor
 * @author      Frank Herrmann <frank@codeking.de>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKing/de.codeking.symcon.batterymonitor
 *
 */
class BatteryMonitor extends Module
{
    use InstanceHelper;

    private $data = [];
    private $notifications;

    protected $profile_mappings = [];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyInteger('notification_level', 5);

        // register private properties
        $this->RegisterPropertyString('notifications', '[]');

        // register timer every 2 hours
        $register_timer = 60 * 60 * 2 * 100;
        $this->RegisterTimer('UpdateData', $register_timer, $this->_getPrefix() . '_Update($_IPS[\'TARGET\']);');
    }

    /**
     * execute, when kernel is ready
     */
    protected function onKernelReady()
    {
        $this->Update();
    }

    /**
     * Update battery data
     */
    public function Update()
    {
        $this->notifications = json_decode($this->ReadPropertyString('notifications'), true);

        // get all objects
        $object_ids = IPS_GetObjectList();

        foreach ($object_ids AS $object_id) {
            // continue on own object id
            if ($object_id == $this->InstanceID) {
                continue;
            }

            // get object
            $object = IPS_GetObject($object_id);

            // when object has children, proceed
            if ($object['HasChildren']) {
                // get object variables
                $battery_variables = [];
                foreach ($object['ChildrenIDs'] AS $variable_id) {
                    if ($variable = @IPS_GetVariable($variable_id)) {
                        // get battery values
                        if ($variable['VariableProfile'] == '~Battery' || $variable['VariableCustomProfile'] == '~Battery') {
                            $battery_variables['status'] = [
                                'id' => $variable['VariableID'],
                                'value' => $variable['VariableValue']
                            ];
                        } else if ($variable['VariableProfile'] == '~Battery.100' || $variable['VariableCustomProfile'] == '~Battery.100') {
                            $battery_variables['battery'] = [
                                'id' => $variable['VariableID'],
                                'value' => $variable['VariableValue']
                            ];
                        } else if ($variable['VariableProfile'] == '~Intensity.100' || $variable['VariableCustomProfile'] == '~Intensity.100') {
                            $battery_variables['intensity'] = [
                                'id' => $variable['VariableID'],
                                'value' => $variable['VariableValue']
                            ];
                        }

                        // detect battery profile
                        if (strstr($variable['VariableProfile'], '~Battery') || strstr($variable['VariableCustomProfile'], '~Battery')) {
                            $battery_variables['has_battery'] = true;
                        }
                    }
                }
                // when battery variables equals 2, add to data array
                if (isset($battery_variables['has_battery'])) {
                    $this->data[] = [
                        'id' => $object['ObjectID'],
                        'name' => isset($battery_variables['name']) ? $battery_variables['name'] : $object['ObjectName'],
                        'target_id' => isset($battery_variables['battery']) ? $battery_variables['battery']['id'] : (isset($battery_variables['intensity']) ? $battery_variables['intensity']['id'] : $battery_variables['status']['id']),
                        'status' => isset($battery_variables['battery']) ? $battery_variables['battery']['value'] : (isset($battery_variables['intensity']) ? $battery_variables['intensity']['value'] : $battery_variables['status']['value'])
                    ];
                }
            }
        }

        // log data
        $this->_log('BatteryMonitor', json_encode($this->data));

        // save data
        $this->SaveData();
    }

    /**
     * save battery data to variables
     */
    private function SaveData()
    {
        // sort data
        usort($this->data, function (array $a, array $b) {
            if (is_int($a['status'])) {
                if ($a['status'] == $b['status']) {
                    return 0;
                }

                return ($a['status'] < $b['status']) ? -1 : 1;
            } else {
                return -1;
            }
        });

        // loop battery data and save variables
        $position = 0;
        foreach ($this->data AS $data) {
            // create link
            $this->CreateLink($this->InstanceID, $data['target_id'], $data['name'], $position, 'Battery');
            $position++;

            // @ToDo: send notification on low battery.
            // @ToDo: reset when battery was changed!
        }
    }

    /**
     * create custom variable profile
     * @param string $profile_id
     * @param string $name
     */
    protected function CreateCustomVariableProfile(string $profile_id, string $name)
    {
        switch ($name):
            case 'Status':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, 0, '?', '', -1);
                IPS_SetVariableProfileAssociation($profile_id, 1, 'OK', '', -1);
                break;
        endswitch;
    }
}