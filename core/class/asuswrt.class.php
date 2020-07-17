<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class asuswrt extends eqLogic {
  public function loadCmdFromConf($type) {
    if (!is_file(dirname(__FILE__) . '/../config/devices/' . $type . '.json')) {
      return;
    }
    $content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $type . '.json');
    if (!is_json($content)) {
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      return true;
    }
    foreach ($device['commands'] as $command) {
      $cmd = null;
      foreach ($this->getCmd() as $liste_cmd) {
        if ((isset($command['logicalId']) && $liste_cmd->getLogicalId() == $command['logicalId'])
        || (isset($command['name']) && $liste_cmd->getName() == $command['name'])) {
          $cmd = $liste_cmd;
          break;
        }
      }
      if ($cmd == null || !is_object($cmd)) {
        $cmd = new asuswrtCmd();
        $cmd->setEqLogic_id($this->getId());
        utils::a2o($cmd, $command);
        $cmd->save();
      }
    }
  }

  public static function cron() {
    asuswrt::scanDevices();
    asuswrt::scanRouteur();
  }

  public static function scanDevices() {
    $result = asuswrt::scan();
    foreach ($result as $asuswrt) {
      if ((!isset($asuswrt['mac'])) || ($asuswrt['mac'] == '') || ($asuswrt['mac'] == '<incomplete>')) {
        continue;
      }
      $eqlogic=asuswrt::byLogicalId($asuswrt['mac'], 'asuswrt');
      if (!is_object($eqlogic)) {
        if (!isset($asuswrt['ip']) || !isset($asuswrt['hostname'])) {
          continue;
        }
        log::add('asuswrt', 'debug', 'New host ' . $asuswrt['hostname'] . ' or IP ' . $asuswrt['ip'] . ' MAC ' . $asuswrt['mac']);
        $eqlogic = new asuswrt();
        $eqlogic->setEqType_name('asuswrt');
        $eqlogic->setLogicalId($asuswrt['mac']);
        $eqlogic->setIsEnable(1);
        $eqlogic->setIsVisible(0);
        $eqlogic->setName($asuswrt['hostname'] . ' - ' . $asuswrt['ip']);
        if (config::byKey('object_id', 'asuswrt') != '') {
          $eqlogic->setObject_id(config::byKey('object_id', 'asuswrt'));
        }
        $eqlogic->setConfiguration('hostname', $asuswrt['hostname']);
        $eqlogic->setConfiguration('mac', $asuswrt['mac']);
        $eqlogic->setConfiguration('ip', $asuswrt['ip']);
        $eqlogic->save();
      }
      if (array_key_exists('hostname', $asuswrt) && (($eqlogic->getConfiguration('hostname') !=  $asuswrt['hostname']) || (isset($asuswrt['ip']) && $eqlogic->getConfiguration('ip') !=  $asuswrt['ip']))) {             $eqlogic->setConfiguration('hostname', $asuswrt['hostname']);
        $eqlogic->save();
      }
      if ((isset($asuswrt['ip'])) && ($eqlogic->getConfiguration('ip') !=  $asuswrt['ip']) && ($asuswrt['ip'] != '')) {
        log::add('asuswrt', 'debug', 'New IP ' . $asuswrt['ip'] . ' from ' . $eqlogic->getConfiguration('ip'));
        $eqlogic->setConfiguration('ip', $asuswrt['ip']);
        $eqlogic->save();
      }
      $eqlogic->loadCmdFromConf('client');
      foreach ($asuswrt as $logicalid => $value) {
        $eqlogic->checkAndUpdateCmd($logicalid, $value);
      }
      $presence = ($asuswrt['status'] == 'OFFLINE') ? 0 : 1;
      if ((isset($asuswrt['connexion'])) && ($asuswrt['connexion'] == 'ethernet') && ($presence == 0)) {
        exec(system::getCmdSudo() . "ping -c1 " . $asuswrt['ip'], $output, $return_var);
        if ($return_var == 0) {
            $presence = 1;
        }
      }
      $eqlogic->checkAndUpdateCmd('presence', $presence);
      /*$cmd = asuswrtCmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'presence');
      if (is_object($cmd)) {
      if (($presence != asuswrtCmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'presence')->execCmd()) && ($eqlogic->getConfiguration('activation') != '')) {
      $manageEq = eqLogic::byLogicalId($eqlogic->getConfiguration('ip'),$eqlogic->getConfiguration('activation'));
      $manageEq->setIsEnable($presence);
      $manageEq->save();
    }
  }*/
}
}

public static function scanRouteur() {
  $result = asuswrt::speed();
  $eqlogic=asuswrt::byLogicalId('router', 'asuswrt');
  if (!is_object($eqlogic)) {
    $eqlogic = new asuswrt();
    $eqlogic->setEqType_name('asuswrt');
    $eqlogic->setLogicalId('router');
    $eqlogic->setIsEnable(1);
    $eqlogic->setIsVisible(1);
    $eqlogic->setName('Router');
    $eqlogic->save();
  }
  $eqlogic->loadCmdFromConf('router');
  $cmd = cmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'txtotal');
  $past = $cmd->execCmd();
  $speed = round(($result['txtotal'] - $past)/60000000,2);
  $eqlogic->checkAndUpdateCmd('txspeed', $speed);
  $cmd = cmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'rxtotal');
  $past = $cmd->execCmd();
  $speed = round(($result['rxtotal'] - $past)/60000000,2);
  $eqlogic->checkAndUpdateCmd('rxspeed', $speed);
  foreach ($result as $logicalid => $value) {
    if ($logicalid == "ethernet") {
      foreach ($value as $id => $values) {
        $cmdlogic = asuswrtCmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'ethernet' . $id . 'link');
        if (!is_object($cmdlogic)) {
          $cmdlogic = new asuswrtCmd();
          $cmdlogic->setName('Ethernet ' . $id . ' Lien');
          $cmdlogic->setEqLogic_id($eqlogic->getId());
          $cmdlogic->setLogicalId('ethernet' . $id . 'link');
          $cmdlogic->setType('info');
          $cmdlogic->setSubType('string');
          $cmdlogic->save();
        }
        $eqlogic->checkAndUpdateCmd('ethernet' . $id . 'link', $result['ethernet'][$id]['link']);
        $cmdlogic = asuswrtCmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'ethernet' . $id . 'mac');
        if (!is_object($cmdlogic)) {
          $cmdlogic = new asuswrtCmd();
          $cmdlogic->setName('Ethernet ' . $id . ' MAC');
          $cmdlogic->setEqLogic_id($eqlogic->getId());
          $cmdlogic->setLogicalId('ethernet' . $id . 'mac');
          $cmdlogic->setType('info');
          $cmdlogic->setSubType('string');
          $cmdlogic->save();
        }
        $eqlogic->checkAndUpdateCmd('ethernet' . $id . 'mac', $result['ethernet'][$id]['mac']);
      }
    } else {
      $eqlogic->checkAndUpdateCmd($logicalid, $value);
    }
  }
}

public static function scan() {
  $result = array();
  $wifi = array();
  $blocked = array();
  foreach (eqLogic::byType('asuswrt') as $asuswrt) {
    if ($asuswrt->getLogicalId('id') == 'router') {
      continue;
    }
    $result[$asuswrt->getConfiguration('mac')]['status'] = "OFFLINE";
    $result[$asuswrt->getConfiguration('mac')]['ap'] = 'none';
    $result[$asuswrt->getConfiguration('mac')]['mac'] = $asuswrt->getConfiguration('mac');
  }

  if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
    log::add('asuswrt', 'debug', 'connexion SSH KO');
    return 'error connecting';
  }
  if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
    log::add('asuswrt', 'error', 'Authentification SSH KO');
    return 'error connecting';
  }

  $stream = ssh2_exec($connection, "nvram get cfg_device_list");
  stream_set_blocking($stream, true);
  $line = stream_get_contents($stream);
  fclose($stream);
  $array=explode("<", $line);
  $i = 0;
  $aimesh = array();
  foreach ($array as $elt) {
    $elts = explode(">", $elt);
    if ($i == 0) {
      //first component is empty
    } else if ($i == 1) {
      $asus_mac = $elts[2];
    } else {
      $aimesh[$elts[2]]['mac'] = $elts[2];
      $aimesh[$elts[2]]['ip'] = $elts[1];
      //log::add('asuswrt', 'debug', 'AIMesh ' . $elts[2]);
    }
    $i++;
  }

  log::add('asuswrt', 'debug', 'AIMesh ' . print_r($aimesh, true));
  //log::add('asuswrt', 'debug', 'Routeur ' . $asus_mac);

  $stream = ssh2_exec($connection, "cat /var/lib/misc/dnsmasq.leases | awk '{print $2\" \"$3\" \"$4}'");
  stream_set_blocking($stream, true);
  while($line = fgets($stream)) {
    //84529 01:e0:4c:68:15:8e 192.168.0.102 host2 01:00:e0:4c:68:15:8e
    //55822 28:5c:07:f6:97:80 192.168.0.32 host *
    $array=explode(" ", $line);
    $mac = trim(strtolower($array[0]));
    if ($mac == '') { continue; }
    $result[$mac]['mac'] = $mac;
    $result[$mac]['ip'] = $array[1];
    $result[$mac]['hostname'] = $array[2];
    $result[$mac]['rssi'] = 0;
    $result[$mac]['status'] = 'OFFLINE';
    $result[$mac]['internet'] = 1;
    $result[$mac]['connexion'] = 'ethernet';
    $result[$mac]['ap'] = $asus_mac;
  }
  fclose($stream);

  $stream = ssh2_exec($connection, "cat /tmp/clientlist.json");
  stream_set_blocking($stream, true);
  $line = stream_get_contents($stream);
  fclose($stream);
  $array = json_decode($line,true);
  //log::add('asuswrt', 'debug', 'cientlist ' . print_r($array, true));

  foreach ($array[$asus_mac]['wired_mac'] as $id => $elt) {
    $result[$id]['mac'] = $id;
    $result[$id]['ip'] = $elt['ip'];
    $result[$id]['rssi'] = 0;
    $result[$id]['status'] = 'ONLINE';
    $result[$id]['internet'] = 1;
    $result[$id]['connexion'] = 'ethernet';
    $result[$id]['ap'] = $asus_mac;
  }

  foreach ($array[$asus_mac]['2G'] as $id => $elt) {
    $result[$id]['mac'] = $id;
    $result[$id]['ip'] = $elt['ip'];
    $result[$id]['rssi'] = $elt['rssi'];
    $result[$id]['status'] = 'WIFI';
    $result[$id]['internet'] = 1;
    $result[$id]['connexion'] = 'wifi2.4';
    $result[$id]['ap'] = $asus_mac;
  }

  foreach ($array[$asus_mac]['5G'] as $id => $elt) {
    $result[$id]['mac'] = $id;
    $result[$id]['ip'] = $elt['ip'];
    $result[$id]['rssi'] = $elt['rssi'];
    $result[$id]['status'] = 'WIFI';
    $result[$id]['internet'] = 1;
    $result[$id]['connexion'] = 'wifi5';
    $result[$id]['ap'] = $asus_mac;
  }

  unset($array[$asus_mac]);

  foreach ($array as $aimesh_mac => $elts) {
    foreach ($elts['2G'] as $id => $elt) {
      log::add('asuswrt', 'debug', '2G ' . print_r($elt, true));
      $result[$id]['mac'] = $id;
      $result[$id]['ip'] = $elt['ip'];
      $result[$id]['rssi'] = $elt['rssi'];
      $result[$id]['status'] = 'WIFI';
      $result[$id]['internet'] = 1;
      $result[$id]['connexion'] = 'wifi2.4';
      $result[$id]['ap'] = $aimesh_mac;
    }

    foreach ($elts['5G'] as $id => $elt) {
      log::add('asuswrt', 'debug', '5G ' . print_r($elt, true));
      $result[$id]['mac'] = $id;
      $result[$id]['ip'] = $elt['ip'];
      $result[$id]['rssi'] = $elt['rssi'];
      $result[$id]['status'] = 'WIFI';
      $result[$id]['internet'] = 1;
      $result[$id]['connexion'] = 'wifi5';
      $result[$id]['ap'] = $aimesh_mac;
    }
  }


  //log::add('asuswrt', 'debug', 'Array ' . print_r($array, true));

  $stream = ssh2_exec($connection, 'arp -v');
  stream_set_blocking($stream, true);
  while($line = fgets($stream)) {
    //? (192.168.0.23) at 64:db:8b:7c:b8:2b [ether]  on br0
    //? (192.168.0.67) at 04:cf:8c:9c:51:e4 [ether]  on br0
    $array=explode(" (", $line);
    $hostname = trim(strtolower($array[0]));
    $array2=explode(") at ", $array[1]);
    $ip = trim(strtolower($array2[0]));
    $array3=explode(" ", $array2[1]);
    $mac = trim(strtolower($array3[0]));
    if ($mac == '') { continue; }
    if (!array_key_exists($mac,$result)) {
      $result[$mac]['mac'] = $mac;
      $result[$mac]['hostname'] = $hostname;
      $result[$mac]['rssi'] = 0;
      $result[$mac]['internet'] = 1;
      $result[$mac]['connexion'] = 'ethernet';
      $result[$mac]['ap'] = $asus_mac;
    }
    $result[$mac]['hostname'] = $hostname;
    $result[$mac]['ip'] = $ip;
    $result[$mac]['status'] = 'ARP';
  }
  fclose($stream);

  $stream = ssh2_exec($connection, 'ip neigh');
  stream_set_blocking($stream, true);
  while($line = fgets($stream)) {
    //192.168.0.23 dev br0 lladdr 64:db:8b:7c:b8:2b REACHABLE
    //192.168.0.67 dev br0 lladdr 04:cf:8c:9c:51:e4 STALE
    $array=explode(" ", $line);
    if ($array[3] == 'lladdr') {
      $mac = trim(strtolower($array[4]));
      if ($mac == '') { continue; }
      if (!array_key_exists($mac,$result)) {
        $result[$mac]['mac'] = $mac;
        $result[$mac]['hostname'] = "?";
        $result[$mac]['rssi'] = 0;
        $result[$mac]['internet'] = 1;
        $result[$mac]['connexion'] = 'ethernet';
        $result[$mac]['ap'] = $asus_mac;
      }
      $result[$mac]['ip'] = $array[0];
      $result[$mac]['status'] = $array[5];
    }
  }
  fclose($stream);

$stream = ssh2_exec($connection, "nvram get wl_ifnames");
stream_set_blocking($stream, true);
$line = stream_get_contents($stream);
fclose($stream);
$array=explode(" ", $line);
$wl0 = trim(strtolower($array[0]));
$wl1 = trim(strtolower($array[1]));
log::add('asuswrt', 'debug', 'Wifi ' . $wl0 . ' ' . $wl1);

$stream = ssh2_exec($connection, "wl -i " . $wl0 . " assoclist | awk '{print $2}'");
//log::add('asuswrt', 'debug', "wl -i " . $wl0 . " assoclist | awk '{print $2}'");
stream_set_blocking($stream, true);
while($line = fgets($stream)) {
  //assoclist 1C:F2:9A:34:4D:37
  //assoclist 44:07:0B:4A:A9:96
  $mac = trim(strtolower($line));
  if ($mac == '') { continue; }
  $result[$mac]['connexion'] = 'wifi2.4';
  $result[$mac]['status'] = 'WIFI';
  $result[$mac]['ap'] = $asus_mac;
  $wifi[] = $mac;
}
fclose($stream);

foreach ($wifi as $value) {
  $stream = ssh2_exec($connection, 'wl -i ' . $wl0 . ' rssi ' . $value);
  stream_set_blocking($stream, true);
  $rssi = stream_get_contents($stream);
  $result[$value]['rssi'] = $rssi;
  fclose($stream);
  //log::add('asuswrt', 'debug', 'Wifi 2.4 ' . $value . ' ' . $rssi);
}
$wifi = array();

$stream = ssh2_exec($connection, "wl -i " . $wl1 . " assoclist | awk '{print $2}'");
//log::add('asuswrt', 'debug', "wl -i " . $wl1 . " assoclist | awk '{print $2}'");
stream_set_blocking($stream, true);
while($line = fgets($stream)) {
  $mac = trim(strtolower($line));
  if ($mac == '') { continue; }
  //log::add('asuswrt', 'debug', 'MAC ' . $mac);
  $result[$mac]['connexion'] = 'wifi5';
  $result[$mac]['status'] = 'WIFI';
  $result[$mac]['ap'] = $asus_mac;
  $wifi[] = $mac;
}
fclose($stream);

foreach ($wifi as $value) {
  $stream = ssh2_exec($connection, 'wl -i ' . $wl1 . ' rssi ' . $value);
  //log::add('asuswrt', 'debug', 'wl -i ' . $wl1 . ' rssi ' . $value);
  stream_set_blocking($stream, true);
  $rssi = stream_get_contents($stream);
  $result[$value]['rssi'] = $rssi;
  fclose($stream);
  //log::add('asuswrt', 'debug', 'Wifi 5 ' . $value . ' ' . $rssi);
}


//	iptables -S | grep "FORWARD -s" | grep DROP
$stream = ssh2_exec($connection, 'iptables -S | grep "FORWARD -s" | grep DROP');
stream_set_blocking($stream, true);
while($line = fgets($stream)) {
  $array=explode(" ", $line);
  $array2 =explode("/", $array[3]);
  $blocked[$array2[0]] = $array2[0];
  //log::add('asuswrt', 'debug', 'Blocked ' . $array2[0]);
}
fclose($stream);

foreach ($result as $array ) {
  if (array_key_exists('ip',$array)) {
    //log::add('asuswrt', 'debug', 'Check blocked and hostname ' . print_r($array,true));
    if (array_key_exists($array['ip'], $blocked)) {
      $result[$array['mac']]['internet'] = 0;
      log::add('asuswrt', 'debug', 'IP Blocked ' . $array['ip']);
    }
    if (array_key_exists('hostname', $array) && ((strpos($array['hostname'],'?') !== false) || (strpos($array['hostname'],'*') !== false))) {
      log::add('asuswrt', 'debug', 'Check hostname ' . $array['hostname'] . ' present ' . $array['ip']);
      $stream = ssh2_exec($connection, "cat /jffs/configs/dnsmasq.conf.add | grep " . $array['ip'] . "$ | awk -F'/' '{print $2}'");
      stream_set_blocking($stream, true);
      $hostname = stream_get_contents($stream);
      fclose($stream);
      if ($hostname != '') {
        $result[$array['mac']]['hostname'] = $hostname;
        log::add('asuswrt', 'debug', 'Resolve ' . $hostname);
      }
    }
  }
}

$closesession = ssh2_exec($connection, 'exit');
stream_set_blocking($closesession, true);
stream_get_contents($closesession);

//log::add('asuswrt', 'debug', 'Scan Routeur, result ' . json_encode($result));
/*
if (config::byKey('aimesh', 'asuswrt') != '') {
  $aimeshs = explode(';',config::byKey('aimesh', 'asuswrt'));
  foreach ($aimeshs as $aimesh) {
    log::add('asuswrt', 'debug', 'AP AIMesh ' . $aimesh);
    if (!$connection = ssh2_connect($aimesh,'22')) {
      log::add('asuswrt', 'error', 'connexion SSH KO');
      return 'error connecting';
    }
    if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
      log::add('asuswrt', 'error', 'Authentification SSH KO');
      return 'error connecting';
    }

    $stream = ssh2_exec($connection, "nvram get wl_ifnames");
    stream_set_blocking($stream, true);
    $line = stream_get_contents($stream);
    fclose($stream);
    $array=explode(" ", $line);
    $wl0 = trim(strtolower($array[0]));
    $wl1 = trim(strtolower($array[1]));

    if (strpos($wl0,'ath') === false) {
      log::add('asuswrt', 'debug', 'AP AIMesh non Atheros ' . $wl0 . ' ' . $wl1);
      $stream = ssh2_exec($connection, "wl -i " . $wl0 . " assoclist | awk '{print $2}'");
      //log::add('asuswrt', 'debug', "wl -i " . $wl0 . " assoclist | awk '{print $2}'");
      stream_set_blocking($stream, true);
      while($line = fgets($stream)) {
        $mac = trim(strtolower($line));
        if ($mac == '') { continue; }
        $result[$mac]['connexion'] = 'wifi2.4';
        $result[$mac]['ap'] = 'ap ' . $aimesh;
        $result[$mac]['status'] = 'WIFI';
        $wifi[] = $mac;
      }
      fclose($stream);

      foreach ($wifi as $value) {
        $stream = ssh2_exec($connection, 'wl -i ' . $wl0 . ' rssi ' . $value);
        stream_set_blocking($stream, true);
        $rssi = stream_get_contents($stream);
        $result[$value]['rssi'] = $rssi;
        fclose($stream);
        //log::add('asuswrt', 'debug', 'Wifi 2.4 ' . $mac . ' ' . $rssi);
      }
      $wifi = array();

      $stream = ssh2_exec($connection, "wl -i " . $wl1 . " assoclist | awk '{print $2}'");
      //log::add('asuswrt', 'debug', "wl -i " . $wl1 . " assoclist | awk '{print $2}'");
      stream_set_blocking($stream, true);
      while($line = fgets($stream)) {
        $mac = trim(strtolower($line));
        if ($mac == '') { continue; }
        $result[$mac]['connexion'] = 'wifi5';
        $result[$mac]['ap'] = 'ap ' . $aimesh;
        $result[$mac]['status'] = 'WIFI';
        $wifi[] = $mac;
      }
      fclose($stream);

      foreach ($wifi as $value) {
        $stream = ssh2_exec($connection, 'wl -i ' . $wl1 . ' rssi ' . $value);
        stream_set_blocking($stream, true);
        $rssi = stream_get_contents($stream);
        $result[$value]['rssi'] = $rssi;
        fclose($stream);
        //log::add('asuswrt', 'debug', 'Wifi 5 ' . $mac . ' ' . $rssi);
      }
    } else {
      log::add('asuswrt', 'debug', 'AP AIMesh Atheros ' . $wl0 . ' ' . $wl1);
      $stream = ssh2_exec($connection, "wlanconfig " . $wl0 . " list sta | sed '1 d' | awk '{print $1\" \"$6}'");
      stream_set_blocking($stream, true);
      while($line = fgets($stream)) {
        $array=explode(" ", $line);
        $mac = trim(strtolower($array[0]));
        if ($mac == '') { continue; }
        $result[$mac]['connexion'] = 'wifi2.4';
        $result[$mac]['ap'] = 'ap ' . $aimesh;
        $result[$mac]['rssi'] = '-' . $array[1];
        $result[$mac]['status'] = 'WIFI';
        log::add('asuswrt', 'debug', '2.4 : ' . $mac . ' rssi ' . $array[1]);
      }
      fclose($stream);

      $stream = ssh2_exec($connection, "wlanconfig " . $wl1 . " list sta | sed '1 d' | awk '{print $1\" \"$6}'");
      stream_set_blocking($stream, true);
      while($line = fgets($stream)) {
        $array=explode(" ", $line);
        $mac = trim(strtolower($array[0]));
        if ($mac == '') { continue; }
        $result[$mac]['connexion'] = 'wifi5';
        $result[$mac]['ap'] = 'ap ' . $aimesh;
        $result[$mac]['rssi'] = '-' . $array[1];
        $result[$mac]['status'] = 'WIFI';
        log::add('asuswrt', 'debug', '5 : ' . $mac . ' rssi ' . $array[1]);
      }
      fclose($stream);
    }


    $closesession = ssh2_exec($connection, 'exit');
    stream_set_blocking($closesession, true);
    stream_get_contents($closesession);
  }
}
*/

//REACHABLE, DELAY, STABLE, ARP
log::add('asuswrt', 'debug', 'Scan Asus, result ' . json_encode($result));
return $result;
}

public static function speed() {
  $result = array();

  if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
    log::add('asuswrt', 'debug', 'connexion SSH KO');
    return 'error connecting';
  }
  if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
    log::add('sshcommander', 'error', 'Authentification SSH KO');
    return 'error connecting';
  }

  $stream = ssh2_exec($connection, 'cat /sys/class/net/eth0/statistics/tx_bytes');
  stream_set_blocking($stream, true);
  $result['txtotal'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'cat /sys/class/net/eth0/statistics/rx_bytes');
  stream_set_blocking($stream, true);
  $result['rxtotal'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wl0_radio');
  stream_set_blocking($stream, true);
  $result['wifi24'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wl1_radio');
  stream_set_blocking($stream, true);
  $result['wifi5'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wl0.1_radio');
  stream_set_blocking($stream, true);
  $result['guest24'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wl1.1_radio');
  stream_set_blocking($stream, true);
  $result['guest5'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wan0_state_t');
  stream_set_blocking($stream, true);
  $result['wan0_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wan0_ipaddr');
  stream_set_blocking($stream, true);
  $result['wan0_ipaddr'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wan0_ifname');
  stream_set_blocking($stream, true);
  $result['wan0_ifname'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wan1_state_t');
  stream_set_blocking($stream, true);
  $result['wan1_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wan1_ipaddr');
  stream_set_blocking($stream, true);
  $result['wan1_ipaddr'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get wan1_ifname');
  stream_set_blocking($stream, true);
  $result['wan1_ifname'] = stream_get_contents($stream);
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get vpn_client1_state');
  stream_set_blocking($stream, true);
  $result['vpn_client1_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get vpn_client2_state');
  stream_set_blocking($stream, true);
  $result['vpn_client2_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get vpn_client3_state');
  stream_set_blocking($stream, true);
  $result['vpn_client3_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get vpn_client4_state');
  stream_set_blocking($stream, true);
  $result['vpn_client4_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);

  $stream = ssh2_exec($connection, 'nvram get vpn_client5_state');
  stream_set_blocking($stream, true);
  $result['vpn_client5_state'] = asuswrt::vpnStatus(stream_get_contents($stream));
  fclose($stream);
  
  $stream = ssh2_exec($connection, "ping -c1 -W1 www.google.com | tail -1");
  stream_set_blocking($stream, true);
  $ping0 = stream_get_contents($stream);
  log::add('asuswrt', 'debug', 'Ping Google ' . $ping0);
  $ping = explode(' = ', $ping0);
  $ping2 = explode('/', $ping[1]);
  $result['ping_google'] = floatval($ping2[0]);
  log::add('asuswrt', 'debug', 'Ping Google ' . $result['ping_google']);
  fclose($stream);
  
  $stream = ssh2_exec($connection, "ping -c1 -W1 8.8.8.8 | tail -1");
  stream_set_blocking($stream, true);
  $ping = explode(' = ', stream_get_contents($stream));
  $ping2 = explode('/', $ping[1]);
  $result['ping_dns'] = floatval($ping2[0]);
  fclose($stream);
  
  $stream = ssh2_exec($connection, "wl -i eth1 phy_tempsense | awk '{ print $1 * .5 + 20 }'");
  stream_set_blocking($stream, true);
  $result['temp_wl24'] = stream_get_contents($stream);
  fclose($stream);
  
  $stream = ssh2_exec($connection, "wl -i eth2 phy_tempsense | awk '{ print $1 * .5 + 20 }'");
  stream_set_blocking($stream, true);
  $result['temp_wl5'] = stream_get_contents($stream);
  fclose($stream);
  
  $stream = ssh2_exec($connection, "cat /proc/dmu/temperature | head -1");
  stream_set_blocking($stream, true);
  $memory = stream_get_contents($stream);
  log::add('asuswrt', 'debug', 'Temp, result ' . $memory);
  $result['temp_cpu'] = preg_replace("/[^0-9]/", "", $memory );
  fclose($stream);
  
  $stream = ssh2_exec($connection, "top -bn1 | head -3 | awk '/Mem/ {print $2,$4}' | sed 's/K//g'");
  stream_set_blocking($stream, true);
  $memory = explode(' ',stream_get_contents($stream));
  $result['mem_used'] = $memory[0];
  $result['mem_free'] = $memory[1];
  fclose($stream);
  
  $stream = ssh2_exec($connection, "top -bn1 | head -3 | awk '/CPU/ {print $2,$4,$6,$8,$10,$12,$14}' | sed 's/%//g'");
  stream_set_blocking($stream, true);
  $cpu = explode(' ',stream_get_contents($stream));
  $result['cpu_user'] = $cpu[0];
  $result['cpu_sys'] = $cpu[1];
  $result['cpu_nic'] = $cpu[2];
  $result['cpu_idle'] = $cpu[3];
  $result['cpu_io'] = $cpu[4];
  $result['cpu_irq'] = $cpu[5];
  $result['cpu_sirq'] = $cpu[6];
  fclose($stream);

  $stream = ssh2_exec($connection, 'robocfg showports | tail -n +2');
  stream_set_blocking($stream, true);
  while($line = fgets($stream)) {
    /*# robocfg showports | tail -n +2
    Port 0:   DOWN enabled stp: none vlan: 1 jumbo: off mac: 00:00:00:00:00:00
    Port 1:   DOWN enabled stp: none vlan: 1 jumbo: off mac: 00:00:00:00:00:00
    Port 2:   DOWN enabled stp: none vlan: 1 jumbo: off mac: 00:00:00:00:00:00
    Port 3:   DOWN enabled stp: none vlan: 1 jumbo: off mac: 00:00:00:00:00:00
    Port 4: 1000FD enabled stp: none vlan: 2 jumbo: off mac: 34:27:92:42:d7:03*/
    $array=explode(": ", $line);
    $mac = trim(strtolower($array[5]));
    $indice = str_replace('Port ','',$array[0]);
    $array2=explode(" ", trim($array[1]));
    $result['ethernet'][$indice]['mac'] = $mac;
    $result['ethernet'][$indice]['link'] = $array2[0];
  }
  fclose($stream);

  $closesession = ssh2_exec($connection, 'exit');
  stream_set_blocking($closesession, true);
  stream_get_contents($closesession);

  log::add('asuswrt', 'debug', 'Speed Asus, result ' . json_encode($result));
  return $result;
}

public function vpnStatus($_value) {
  switch (intval($_value)) {
    case 0:
    $result = "Stopped";
    break;
    case 1:
    $result = "Connecting";
    break;
    case 2:
    $result = "Connected";
    break;
    default:
    $result = "Unknow";
    break;
  }

  return $result;
}

public function manageWifi($_enable = true, $_wifi = '0') {
  if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
    log::add('asuswrt', 'error', 'connexion SSH KO');
    return 'error connecting';
  }
  if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
    log::add('sshcommander', 'error', 'Authentification SSH KO');
    return 'error connecting';
  }
  //active wifi5, wifi24 c'est wl0, couper c'est 0, statut : nvram get wl1_radio
  //nvram set wl1_radio=1
  //nvram commit
  //service restart_wireless

  $active = ($_enable) ? '1' : '0';
  $stream = ssh2_exec($connection, 'nvram set wl' . $_wifi . '_radio=' . $active);
  $stream = ssh2_exec($connection, 'nvram commit');
  $stream = ssh2_exec($connection, 'service restart_wireless');

  $closesession = ssh2_exec($connection, 'exit');
  stream_set_blocking($closesession, true);
  stream_get_contents($closesession);
}

public function manageInternet($_enable = true) {
  //iptables -I FORWARD -s 192.168.2.100 -j DROP
  //iptables -D FORWARD -s 192.168.2.101 -j DROP
  $active = ($_enable) ? 'D' : 'I';
  $ip = $this->getConfiguration('ip');
  $this->sendAsus('iptables -' . $active . ' FORWARD -s ' . $ip . ' -j DROP');
}

public function wakeOnLan() {
  $this->sendAsus('/usr/sbin/ether-wake ' . $this->getConfiguration('mac'));
}

public function restartAsus() {
  $this->sendAsus('sudo reboot');
}

public function sendAsus($_cmd = '') {
  if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
    log::add('asuswrt', 'error', 'connexion SSH KO');
    return 'error connecting';
  }
  if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
    log::add('sshcommander', 'error', 'Authentification SSH KO');
    return 'error connecting';
  }
  log::add('asuswrt', 'error', 'Send : ' . $_cmd);
  $stream = ssh2_exec($connection, $_cmd);

  $closesession = ssh2_exec($connection, 'exit');
  stream_set_blocking($closesession, true);
  stream_get_contents($closesession);
}

}

class asuswrtCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getType() == "info") {
    } else {
      $eqLogic = $this->getEqLogic();
      if ($this->getConfiguration('type') == 'wifi') {
        $eqLogic->manageWifi($this->getConfiguration('enable'),$this->getConfiguration('wifi'));
      }
      if ($this->getConfiguration('type') == 'internet') {
        $eqLogic->manageInternet($this->getConfiguration('enable'));
      }
      if ($this->getConfiguration('type') == 'restart') {
        $eqLogic->restartAsus();
      }
      if ($this->getConfiguration('type') == 'wol') {
        $eqLogic->wakeOnLan();
      }
    }
  }

}

?>
