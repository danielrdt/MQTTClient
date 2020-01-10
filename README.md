[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-5.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Schnittcher/MQTTClient/workflows/Check%20Style/badge.svg)](https://github.com/Schnittcher/MQTTClient/actions)

# MQTTClient
Ein MQTT Client für IP-Symcon, der auf zwei Arten genutzt werden kann. Der Client kann entweder per Datenfluss mit einem eigenen Modul verbunden werden oder er kann ein vorgegebenes Script aufrufen.

## Inhaltverzeichnis
1. [Voraussetzungen](#1-voraussetzungen)
2. [Enthaltene Module](#2-enthaltene-module)
3. [Installation](#3-installation)
4. [Konfiguration in IP-Symcon](#4-konfiguration-in-ip-symcon)
5. [Funktionen](#5-funktionen)
6. [Spenden](#6-spenden)
7. [Lizenz](#7-lizenz)

## 1. Voraussetzungen

* mindestens IPS Version 5.2

## 2. Enthaltene Module

* MQTTClient

## 3. Installation
Über den IP-Symcon Module Store.

## 4. Konfiguration in IP-Symcon

### 4.1 Modul Typ: Script
Wenn der Modul Typ auf "Script" steht, dann muss dem Modul ein Handle Script zugeordnet werden, welches die eingehenden Nachrichten verarbeitet.

Beispiel:

```php
    if($_IPS['SENDER']=='MQTT_CONNECT') {					
            MQTT_Subscribe(12345 /*[MQTTClient]*/, '#', 0);	  //InstanzID muss angepasst werden.
    }
    if($_IPS['SENDER']=='MQTT_GET_PAYLOAD') {
        IPS_LogMessage('MQTT Topic', print_r($_IPS['Topic'],true));
        IPS_LogMessage('MQTT Payload', print_r($_IPS['Payload'],true));
    }
```

Mit diesem Handle Script würde beim Connect alles abonniert werden, was der MQTT Broker versendet.
Es werden alle Nachrichten in das Log von IP-Symcon geschrieben.

### 4.2 Modul Typ: Forward

#### GUIDs

| Bezeichnung  | GUID |
| ------------- | ------------- |
| GUID vom MQTTClient (Splitter)  | {EE0D345A-CF31-428A-A613-33CE98E752DD}  |
| TX | {97475B04-67C3-A74D-C970-E9409B0EFA1D}  |
| RX | {DBDA9DF7-5D04-F49D-370A-2B9153D00D9B}  |

## 5 Funktionen

**MQTTC_Publish(string $Topic, string $payload, $qos, $retain)**
```php
$topic = 'Licht1';
$payload = 'ON';
MQTTC_Publish(12345 /*[MQTTClient]*/, $topic, $payload, 0, 0);	  //InstanzID muss angepasst werden.
```
Mit diesem Beispiel wird a ndas Topic Licht1 das Paylaod ON gesendet.

**MQTTC_Subscribe(string $Topic, $qos)**
```php
$topic = '#';
$qos = 0;
MQTT_Subscribe(12345 /*[MQTTClient]*/, '#', 0);	  //InstanzID muss angepasst werden.
```
Mit diesem Beispiel würde alles abonniert werden, was der MQTT Broker versendet.

## 6. Spenden

Dieses Modul ist für die nicht kommzerielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:    

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>

## 7. Lizenz

Dieses Modul ist ursprünglich von thomasf68 (https://github.com/thomasf68/IPS_MQTT) entwickelt worden.
Ich habe dieses Modul verändert, damit es mit der aktuellen IP-Symcon Version läuft.
Überflüssignen Code, der nicht verwendet wurde, habe ich entfernt.

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)