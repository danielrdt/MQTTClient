<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs//TFphpMQTT.php';

define('__ROOT__', dirname(dirname(__FILE__)));
require_once __ROOT__ . '/libs/helpers/autoload.php';
require_once __ROOT__ . '/libs/TLS/autoloader.php';

use PTLS\Exceptions\TLSAlertException;
use PTLS\TLSContext;

class MQTTClient extends IPSModule
{
    use BufferHelper;

    const guid_socket = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    private $mqtt;
    private $tls_loop = 0;

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
        $this->RegisterPropertyBoolean('TLS', false);
        $this->RegisterPropertyBoolean('AutoSubscribe', true);
        $this->RegisterPropertyInteger('MQTTVersion', phpMQTT::MQTT_VERSION_311);

        $this->RegisterPropertyInteger('PingInterval', 30);
        $this->RegisterTimer('MQTTC_Ping', 0, 'MQTTC_Ping($_IPS[\'TARGET\']);');
        $this->RegisterTimer('MQTTC_Reconnect', 0, 'MQTTC_Reconnect($_IPS[\'TARGET\'], true);');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->State = TLSState::Init;
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
                set_error_handler([$this, 'onConnectError']);
                if (IPS_SetProperty($cID, 'Open', true)) {
                    IPS_ApplyChanges($cID);
                }
                restore_error_handler();
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
                if ($SenderID === $this->GetConnectionID()) {
                    switch ($Data[0]) {
                        case 102:
                            $this->SetTimerInterval('MQTTC_Reconnect', 0);
                            $this->SendDebug(__FUNCTION__, 'I/O Modul > Aktiviert', 0);
                            $this->State = TLSState::Init;
                            $this->Multi_TLS = null;
                            IPS_Sleep(500);
                            if ($this->ReadPropertyBoolean('TLS')) {
                                if (!$this->CreateTLSConnection()) {
                                    $this->SendDebug(__FUNCTION__, 'TLS > Fehler', 0);
                                    return;
                                }
                            }
                            if (is_null($this->mqtt)) {
                                $this->MQTTConnect();
                            }
                            break;
                        case 104:
                            $this->SendDebug(__FUNCTION__, 'I/O Modul > Deaktiviert', 0);
                            if ($this->GetStatus() == 102) {
                                $this->MQTTDisconnect();
                            }
                            $this->State = TLSState::Init;
                            $this->Multi_TLS = null;
                            break;
                        case 200:
                            $this->SendDebug(__FUNCTION__, 'I/O Modul > Fehler', 0);
                            if ($this->GetStatus() == 102) {
                                $this->MQTTDisconnect();
                            }
                            $this->State = TLSState::Init;
                            $this->Multi_TLS = null;
                            IPS_Sleep(500);
                            $this->SetTimerInterval('MQTTC_Reconnect', 5000);
                            $this->SendDebug(__FUNCTION__, 'Enabled reconnect in 5s', 0);
                            break;
                        default:
                            $this->SendDebug(__FUNCTION__, 'I/O Modul unbekantes Ereignis ' . $Data[0], 0);
                            break;
                    }
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

    /**
     * Reconnect parent socket
     * @param bool $force
     */
    public function Reconnect(bool $force = false)
    {
        $this->SendDebug(__FUNCTION__, "Force: $force", 0);
        $cID = $this->GetConnectionID();
        if (($this->HasActiveParent() || $force) && $cID > 0) {
            set_error_handler([$this, 'onConnectError']);
            if (IPS_SetProperty($cID, 'Open', true)) {
                IPS_ApplyChanges($cID);
            }
            restore_error_handler();
        }
    }

    public function ReceiveData($JSONString)
    {
        // convert json data to array
        $data = json_decode($JSONString);
        $buffer = utf8_decode($data->Buffer);

        if ($this->ReadPropertyBoolean('TLS')) {
            // check for TLS handshake
            if ($this->State == TLSState::TLSisSend || $this->State == TLSState::TLSisReceived) {
                $this->WaitForResponse(TLSState::TLSisSend);
                $this->SendDebug('Receive TLS Handshake', $buffer, 0);
                $this->Handshake = $buffer;

                $this->State = TLSState::TLSisReceived;
                return;
            }

            if (!$this->Multi_TLS || $this->State != TLSState::Connected) {
                return;
            }

            // decrypt TLS data
            if ((ord($buffer[0]) >= 0x14) && (ord($buffer[0]) <= 0x18) && (substr($buffer, 1, 2) == "\x03\x03")) {
                $TLSData = $buffer;
                $buffer = '';
                $TLS = $this->Multi_TLS;
                while (strlen($TLSData) > 0) {
                    $len = unpack('n', substr($TLSData, 3, 2))[1] + 5;
                    if (strlen($TLSData) >= $len) {
                        try {
                            $Part = substr($TLSData, 0, $len);
                            $TLSData = substr($TLSData, $len);
                            $TLS->encode($Part);
                            $buffer .= $TLS->input();
                        } catch (Exception $e) {
                            $this->SendDebug('TLS Error', $e->getMessage(), 0);
                            $this->SetTimerInterval('MQTTC_Reconnect', 1000);
                            return;
                        }
                    } else {
                        break;
                    }
                }

                $this->Multi_TLS = $TLS;
                if (strlen($TLSData) > 0) {
                    $this->SendDebug('Receive TLS Part', $TLSData, 0);
                }
            } else { // buffer does not match
                return;
            }
        }

        $this->SendDebug('ReceiveData', $buffer, 0);

        if (!is_null($this->mqtt)) {
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
        if (!isset($Buffer->Function)) {
            $this->publish($Buffer->Topic, $Buffer->Payload, 0, $Buffer->Retain);
            return;
        }

        switch ($Buffer->Function) {
            case 'Subscribe':
                $this->Subscribe($Buffer->Topic, 0);
            break;

            case 'Publish':
                $this->publish($Buffer->Topic, $Buffer->Payload, 0, $Buffer->Retain);
            break;
        }
    }

    public function onConnectError(int $errno, string $errstr, string $errfile, int $errline, array $errcontext)
    {
        switch ($errno) {
            case E_NOTICE:
                return true;

            case E_WARNING:
                $this->LogMessage("Connect failed ($errstr)", KL_WARNING);
                return true;

            default:
                return false;
        }
    }

    public function onSendText(string $Data)
    {
        $res = false;

        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'No active Parent', 0);
            $this->SetTimerInterval('MQTTC_Ping', 0);
            return $res;
        }

        if ($this->ReadPropertyBoolean('TLS')) {
            // encrypt data
            $TLS = $this->Multi_TLS;
            $this->SendDebug('Send TLS', $Data, 0);
            $Data = $TLS->output($Data)->decode();
            $this->Multi_TLS = $TLS;
        }

        $json = json_encode(
            ['DataID'    => self::guid_socket, //IO-TX
                'Buffer' => utf8_encode($Data)]
        );
        if ($this->HasActiveParent()) {
            $res = parent::SendDataToParent($json);
        } else {
            $this->SendDebug(__FUNCTION__, 'No active Parent', 0);
        }
        $this->SetTimerInterval('MQTTC_Ping', $this->ReadPropertyInteger('PingInterval') * 1000);
        return $res;
    }

    public function onDebug(string $topic, string $data, int $Format = 0)
    {
        $this->SendDebug($topic, $data, $Format);
    }

    public function onReceive(array $para)
    {
        //if Script oder Forward
        if ($this->ReadPropertyInteger('ModuleType') == 1) {
            $scriptid = $this->ReadPropertyInteger('script');
            IPS_RunScriptEx($scriptid, $para);
        }

        if ($this->ReadPropertyInteger('ModuleType') == 2) {
            if ($para['SENDER'] == 'MQTT_CONNECT' && $this->ReadPropertyBoolean('AutoSubscribe')) {
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

    public function Publish(string $topic, string $content, int $qos = 0, int $retain = 0)
    {
        if (!is_null($this->mqtt)) {
            $this->mqtt->publish($topic, $content, $qos, $retain);
        } else {
            $this->SendDebug(__FUNCTION__, 'Error, Publish nicht möglich', 0);
        }
    }

    public function Subscribe(string $topic, int $qos = 0)
    {
        if (!is_null($this->mqtt)) {
            $this->mqtt->subscribe($topic, $qos);
        } else {
            $this->SendDebug(__FUNCTION__, 'Error, Subscribe nicht möglich', 0);
        }
    }

    /**
     * Init TLS connection to client socket
     * @return bool
     */
    private function CreateTLSConnection()
    {
        // return true, if event channel is still connected
        if ($this->State == TLSState::Connected && $this->Multi_TLS) {
            return true;
        }

        // reset state
        $this->State = TLSState::TLSisSend;

        // init tls config
        $TLSconfig = TLSContext::getClientConfig([]);
        $TLS = TLSContext::createTLS($TLSconfig);

        $this->SendDebug('TLS start', '', 0);
        $loop = 1;
        $SendData = $TLS->decode();
        $this->SendDebug('Send TLS Handshake', '', 0);

        // send handshake data
        $JSON['DataID'] = self::guid_socket;
        $JSON['Buffer'] = utf8_encode($SendData);
        $JSON['Method'] = 'socket';

        $JsonString = json_encode($JSON);
        parent::SendDataToParent($JsonString);

        // check TLS handshake
        while (!$TLS->isHandshaked() && ($loop < 10)) {
            $loop++;
            $Result = $this->WaitForResponse(TLSState::TLSisReceived);
            if ($Result === false) {
                $this->SendDebug('TLS no answer', '', 0);

                if ($this->tls_loop < 2) {
                    $this->tls_loop++;
                    $this->SetTimerInterval('MQTTC_Reconnect', 1000);
                } else {
                    $this->State = TLSState::Init;
                }
                break;
            }

            $this->tls_loop = 0;
            $this->State = TLSState::TLSisSend;
            $this->SendDebug('Get TLS Handshake', $Result, 0);
            try {
                $TLS->encode($Result);
                if ($TLS->isHandshaked()) {
                    break;
                }
            } catch (TLSAlertException $e) {
                $this->SendDebug('TLS Error', $e->getMessage(), 0);

                // retry
                try {
                    if (strlen($out = $e->decode())) {
                        $JSON['DataID'] = self::guid_socket;
                        $JSON['Buffer'] = utf8_encode($SendData);
                        $JsonString = json_encode($JSON);
                        parent::SendDataToParent($JsonString);
                    }
                } catch (Exception $e) {
                }

                return false;
            }

            // loop handshake
            $SendData = $TLS->decode();
            if (strlen($SendData) > 0) {
                $this->SendDebug('TLS loop ' . $loop, $SendData, 0);
                $JSON['DataID'] = self::guid_socket;
                $JSON['Buffer'] = utf8_encode($SendData);
                $JsonString = json_encode($JSON);
                parent::SendDataToParent($JsonString);
            } else {
                $this->SendDebug('TLS waiting loop ' . $loop, $SendData, 0);
            }
        }

        // check if handshake was successfull
        if (!$TLS->isHandshaked()) {
            return false;
        }

        $this->Multi_TLS = $TLS;

        // debug
        $this->SendDebug('TLS ProtocolVersion', $TLS->getDebug()->getProtocolVersion(), 0);
        $UsingCipherSuite = explode("\n", $TLS->getDebug()->getUsingCipherSuite());
        unset($UsingCipherSuite[0]);
        foreach ($UsingCipherSuite as $Line) {
            $this->SendDebug(trim(substr($Line, 0, 14)), trim(substr($Line, 15)), 0);
        }

        // change state
        $this->State = TLSState::Connected;

        // handshake was successful! :)
        return true;
    }

    /**
     * Waits for client socket response
     * @param int $State
     * @return bool|string
     */
    private function WaitForResponse(int $State)
    {
        for ($i = 0; $i < 500; $i++) {
            if ($this->State == $State) {
                $Handshake = $this->Handshake;
                $this->Handshake = '';
                return $Handshake;
            }
            IPS_Sleep(5);
        }
        return false;
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
                if ($this->mqtt->connect(true, null, $username, $password, $this->ReadPropertyInteger('MQTTVersion'))) {
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
