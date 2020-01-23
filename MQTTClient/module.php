<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs//TFphpMQTT.php';

class MQTTClient extends IPSModule
{
    private $mqtt;

    public function __construct($InstanceID)
    {
        // Diese Zeile nicht löschen
        parent::__construct($InstanceID);

        $sClass = @$this->GetBuffer('MQTT');
        if ($sClass != '') {
            $this->mqtt = unserialize($sClass);
        } else {
            $this->mqtt = null;
        }
    }

    public function Destroy()
    {
        $this->SendDebug(__FUNCTION__, 'Destroy MQTT Disconnect', 0);
        $this->MQTTDisconnect();
        //Never delete this line!
        parent::Destroy();
    }

    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RequireParent('{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}');    //Client Socket Modul
        $this->RegisterPropertyString('ClientID', 'symcon');
        $this->RegisterPropertyString('User', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('ModuleType', 2);
        $this->RegisterPropertyInteger('script', 0);

        $this->RegisterPropertyInteger('PingInterval', 30);
        $this->RegisterTimer('MQTTC_Ping', 0, 'MQTTC_Ping($_IPS[\'TARGET\']);');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        $this->RegisterMessage(0, IPS_BASE);
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage(0, OM_CHANGEPARENT);
        $cID = $this->GetConnectionID();
        if ($cID != 0) {
            $this->RegisterMessage($cID, IM_CHANGESTATUS);
            if (IPS_GetProperty($cID, 'Host') != null && IPS_GetProperty($cID, 'Port') != 0) {
                IPS_SetProperty($cID, 'Open', true);
                IPS_ApplyChanges($cID);
            }
        }

        // Diese Zeile nicht loeschen
        parent::ApplyChanges();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, 'SenderID: ' . $SenderID . ' MessageID:' . $Message . 'Data: ' . print_r($Data, true), 0);
        switch ($Message) {
            case VM_UPDATE:
                $this->Publish($SenderID, $Data);
                break;
            case VM_DELETE:
                $this->UnSubscribe($SenderID);
                break;
            case IM_CHANGESTATUS:
                switch ($Data[0]) {
                    case 102:
                        $this->SendDebug(__FUNCTION__, 'I/O Modul > Aktiviert', 0);
                        IPS_Sleep(500);
                        if (is_null($this->mqtt)) {
                            $this->MQTTConnect();
                        }
                        break;
                    case 104:
                        $this->SendDebug(__FUNCTION__, 'I/O Modul > Deaktiviert', 0);
                        if ($this->GetStatus() == 102) {
                            $this->MQTTDisconnect();
                        }
                        break;
                    case 200:
                        $this->SendDebug(__FUNCTION__, 'I/O Modul > Fehler', 0);
                        $this->MQTTDisconnect();
                        IPS_Sleep(500);
                        if ($this->HasActiveParent()) {
                            $this->MQTTConnect();
                        }
                        break;
                    default:
                        $this->SendDebug(__FUNCTION__, 'I/O Modul unbekantes Ereignis ' . $Data[0], 0);
                        break;
                }
                break;
            case IPS_KERNELSTARTED:
                $this->mqtt = null;
                break;
            case OM_CHANGEPARENT:
                $this->SendDebug(__FUNCTION__, 'Parent changed', 0);
                break;
            case IPS_KERNELMESSAGE:
                switch ($Data[0]) {
                    case KR_READY:
                        $this->SendDebug(__FUNCTION__, 'KR_Ready ->reconect', 0);
                        break;
                    default:
                        $this->SendDebug(__FUNCTION__, 'Kernelmessage unhahndled, ID' . $Data[0], 0);
                        break;
                }
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'Unknown Message' . $Message, 0);
                break;
        }
    }
    public function ReceiveData($JSONString)
    {
        if (!is_null($this->mqtt)) {
            $data = json_decode($JSONString);
            $buffer = utf8_decode($data->Buffer);

            $this->mqtt->receive($buffer);
            $sClass = serialize($this->mqtt);
            $this->SetBuffer('MQTT', $sClass);
        } else {
            $this->SendDebug(__FUNCTION__, 'MQTT = NULL', 0);
            if ($this->HasActiveParent()) {
                $this->MQTTConnect();
            }
        }
    }

    public function ForwardData($JSONString)
    {
        $this->SendDebug(__FUNCTION__ . 'JSONString:', $JSONString, 0);
        $data = json_decode($JSONString);
        $Buffer = utf8_decode($data->Buffer);
        $Buffer = json_decode($Buffer);
        $this->publish($Buffer->Topic, $Buffer->Payload, 0, $Buffer->Retain);
    }

    public function onSendText(string $Data)
    {
        $res = false;
        $json = json_encode(
            ['DataID'    => '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', //IO-TX
                'Buffer' => utf8_encode($Data)]);
        if ($this->HasActiveParent()) {
            $res = parent::SendDataToParent($json);
        } else {
            $this->SendDebug(__FUNCTION__, 'No active Parent', 0);
        }
        $this->SetTimerInterval('MQTTC_Ping', $this->ReadPropertyInteger('PingInterval') * 1000);
        return $res;
    }

    public function onDebug(string $topic, string $data, $Format = 0)
    {
        $this->SendDebug($topic, $data, $Format);
    }

    public function onReceive($para)
    {
        //if Script oder Forward
        if ($this->ReadPropertyInteger('ModuleType') == 1) {
            $scriptid = $this->ReadPropertyInteger('script');
            IPS_RunScriptEx($scriptid, $para);
        }

        if ($this->ReadPropertyInteger('ModuleType') == 2) {
            if ($para['SENDER'] == 'MQTT_CONNECT') {
                $this->Subscribe('#', 0);
            }

            $JSON['DataID'] = '{DBDA9DF7-5D04-F49D-370A-2B9153D00D9B}';
            $JSON['Buffer'] = json_encode($para, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $Data = json_encode($JSON, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->SendDebug('SendDataToChildren', print_r($Data, true), 0);
            $this->SendDataToChildren($Data);
        }
    }

    public function Ping()
    {
        if (!is_null($this->mqtt)) {
            $this->mqtt->ping();
        }
    }

    public function Publish(string $topic, string $content, $qos = 0, $retain = 0)
    {
        if (!is_null($this->mqtt)) {
            $this->mqtt->publish($topic, $content, $qos, $retain);
        } else {
            $this->SendDebug(__FUNCTION__, 'Error, Publish nicht möglich', 0);
        }
    }

    public function Subscribe(string $topic, $qos = 0)
    {
        if (!is_null($this->mqtt)) {
            $this->mqtt->subscribe($topic, $qos);
        } else {
            $this->SendDebug(__FUNCTION__, 'Error, Subscribe nicht möglich', 0);
        }
    }

    private function MQTTDisconnect()
    {
        if (!is_null($this->mqtt)) {
            $this->mqtt->close();
            $this->mqtt = null;
            $this->OSave($this->mqtt, 'MQTT');
            $clientid = $this->GetClientID();
            $this->LogMessage('Connection closed', KL_NOTIFY);
        }
        $cID = $this->GetConnectionID();
        if ($cID != 0) {
            if (IPS_GetProperty($cID, 'Open')) {
                IPS_SetProperty($cID, 'Open', false);
                IPS_ApplyChanges($cID);
            }
        }
        $this->SetTimerInterval('MQTTC_Ping', 0);
    }

    private function GetClientID()
    {
        return $this->ReadPropertyString('ClientID'); //. '_' . rand(1, 100);
    }

    private function GetConnectionID()
    {
        return IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function MQTTConnect()
    {
        $this->LogMessage('Starting connection to Client', KL_NOTIFY);
        if ($this->GetConnectionID() != 0) {
            if (is_null($this->mqtt)) {
                $clientid = $this->GetClientID();
                $username = $this->ReadPropertyString('User');
                $password = $this->ReadPropertyString('Password');
                $this->mqtt = new phpMQTT($this, $clientid);
                // callback Funktionen
                $this->mqtt->onSend = 'onSendText';
                $this->mqtt->onDebug = 'onDebug';
                $this->mqtt->onReceive = 'onReceive';
                $this->mqtt->debug = true;
                if ($this->mqtt->connect(true, null, $username, $password)) {
                    $this->LogMessage('Connected to ClientID ' . $clientid, KL_NOTIFY);
                    $this->OSave($this->mqtt, 'MQTT');
                    IPS_Sleep(500);
                    $this->SetTimerInterval('MQTTC_Ping', $this->ReadPropertyInteger('PingInterval') * 1000);
                }
            }
        }
    }

    private function OSave($object, $name)
    {
        if ($object === null) {
            $sClass = '';
        } else {
            $sClass = serialize($object);
        }
        $this->SetBuffer($name, $sClass);
    }
}