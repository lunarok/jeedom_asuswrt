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
		$result = asuswrt::scan();
		foreach ($result as $asuswrt) {
			if ($asuswrt['mac'] == '' && $asuswrt['ip'] == '' && $asuswrt['hostname'] == '') {
				continue;
			}
			$eqlogic=asuswrt::byLogicalId($asuswrt['mac'], 'asuswrt');
			if (!is_object($eqlogic)) {
				$eqlogic = new asuswrt();
				$eqlogic->setEqType_name('asuswrt');
				$eqlogic->setLogicalId($asuswrt['mac']);
				$eqlogic->setIsEnable(1);
				$eqlogic->setIsVisible(0);
				$eqlogic->setName($asuswrt['hostname'] . ' - ' . $asuswrt['ip']);
				$eqlogic->setConfiguration('hostname', $asuswrt['hostname']);
				$eqlogic->setConfiguration('mac', $asuswrt['mac']);
				$eqlogic->setConfiguration('ip', $asuswrt['ip']);
				$eqlogic->save();
			}
			if (($eqlogic->getConfiguration('hostname') !=  $asuswrt['hostname']) || ($eqlogic->getConfiguration('ip') !=  $asuswrt['ip'])) {
				$eqlogic->setConfiguration('hostname', $asuswrt['hostname']);
				$eqlogic->setConfiguration('ip', $asuswrt['ip']);
				$eqlogic->save();
			}
			$eqlogic->loadCmdFromConf('client');
			foreach ($asuswrt as $logicalid => $value) {
				$eqlogic->checkAndUpdateCmd($logicalid, $value);
			}
			$presence = ($asuswrt['status'] == 'UNKNOWN') ? 0 : 1;
			if (($presence != asuswrtCmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'presence')->execCmd()) && ($eqlogic->getConfiguration('activation') != '')) {
				$manageEq = eqLogic::byLogicalId($eqlogic->getConfiguration('ip'),$eqlogic->getConfiguration('activation'));
				$manageEq->setIsEnable($presence);
				$manageEq->save();
			}
			$eqlogic->checkAndUpdateCmd('presence', $presence);
		}

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
		$eqlogic->checkAndUpdateCmd('txtotal', $result['txtotal']);
		$eqlogic->checkAndUpdateCmd('txspeed', $speed);
		$cmd = cmd::byEqLogicIdAndLogicalId($eqlogic->getId(),'rxtotal');
		$past = $cmd->execCmd();
		$speed = round(($result['rxtotal'] - $past)/60000000,2);
		$eqlogic->checkAndUpdateCmd('rxtotal', $result['txtotal']);
		$eqlogic->checkAndUpdateCmd('rxspeed', $speed);
		$eqlogic->checkAndUpdateCmd('wifi24', $result['wifi24']);
		$eqlogic->checkAndUpdateCmd('wifi5', $result['wifi5']);
		$eqlogic->checkAndUpdateCmd('guest24', $result['guest24']);
		$eqlogic->checkAndUpdateCmd('guest5', $result['guest5']);
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
		}

		if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
			log::add('asuswrt', 'error', 'connexion SSH KO');
			return 'error connecting';
		}
		if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
			log::add('asuswrt', 'error', 'Authentification SSH KO');
			return 'error connecting';
		}

		$stream = ssh2_exec($connection, 'cat /var/lib/misc/dnsmasq.leases');
		stream_set_blocking($stream, true);
		while($line = fgets($stream)) {
			//84529 01:e0:4c:68:15:8e 192.168.0.102 host2 01:00:e0:4c:68:15:8e
			//55822 28:5c:07:f6:97:80 192.168.0.32 host *
			$array=explode(" ", $line);
			$mac = trim(strtolower($array[1]));
			$result[$mac]['mac'] = $mac;
			$result[$mac]['ip'] = $array[2];
			$result[$mac]['hostname'] = $array[3];
			$result[$mac]['rssi'] = 0;
			$result[$mac]['status'] = 'UNKNOWN';
			$result[$mac]['internet'] = 1;
		}
		fclose($stream);

		$stream = ssh2_exec($connection, 'arp -n');
		stream_set_blocking($stream, true);
		while($line = fgets($stream)) {
			//? (192.168.0.23) at 64:db:8b:7c:b8:2b [ether]  on br0
			//? (192.168.0.67) at 04:cf:8c:9c:51:e4 [ether]  on br0
			$array=explode(" ", $line);
			$mac = trim(strtolower($array[3]));
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
				$result[$mac]['status'] = $array[5];
			}
		}
		fclose($stream);

		$stream = ssh2_exec($connection, 'cat /tmp/wiredclientlist.json');
		stream_set_blocking($stream, true);
		$ethernet = explode("[",stream_get_contents($stream));
		$ethernet = explode("]", $ethernet[1]);
		$ethernet = explode(",", $ethernet[0]);
		foreach ($ethernet as $value) {
			$mac = trim(strtolower($value),'"');
			$result[$mac]['connexion'] = 'ethernet';
			//log::add('asuswrt', 'debug', 'Ethernet ' . $mac);
			fclose($stream);
		}
		fclose($stream);

		$stream = ssh2_exec($connection, "wl -i eth1 assoclist | cut -d' ' -f2");
		stream_set_blocking($stream, true);
		while($line = fgets($stream)) {
			//assoclist 1C:F2:9A:34:4D:37
			//assoclist 44:07:0B:4A:A9:96
			$array=explode(" ", $line);
			$mac = trim(strtolower($array[0]));
			$result[$mac]['connexion'] = 'wifi2.4';
			$wifi[] = $mac;
			//log::add('asuswrt', 'debug', 'Wifi 2.4 ' . $array[0]);
		}
		fclose($stream);

		$stream = ssh2_exec($connection, "wl -i eth2 assoclist | cut -d' ' -f2");
		stream_set_blocking($stream, true);
		while($line = fgets($stream)) {
			$array=explode(" ", $line);
			$mac = trim(strtolower($array[0]));
			$result[$mac]['connexion'] = 'wifi5';
			$wifi[] = $mac;
			//log::add('asuswrt', 'debug', 'Wifi 5 ' . $array[0]);
		}
		fclose($stream);

		foreach ($wifi as $value) {
			$stream = ssh2_exec($connection, 'wl -i eth2 rssi ' . $value);
			stream_set_blocking($stream, true);
			$result[$value]['rssi'] = stream_get_contents($stream);
			fclose($stream);
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
			if (array_key_exists($array['ip'], $blocked)) {
				$result[$array['mac']]['internet'] = 0;
				log::add('asuswrt', 'debug', 'Blocked ' . $array['ip']);
			}
		}

		$closesession = ssh2_exec($connection, 'exit');
		stream_set_blocking($closesession, true);
		stream_get_contents($closesession);

		//REACHABLE, DELAY, STABLE, ARP
		log::add('asuswrt', 'debug', 'Scan Asus, result ' . json_encode($result));
		return $result;
	}

	public static function speed() {
		$result = array();

		if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
			log::add('asuswrt', 'error', 'connexion SSH KO');
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

		$stream = ssh2_exec($connection, 'nvram get wl1.1_radio');
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

		$closesession = ssh2_exec($connection, 'exit');
		stream_set_blocking($closesession, true);
		stream_get_contents($closesession);

		log::add('asuswrt', 'debug', 'Speed Asus, result ' . json_encode($result));
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
		if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
			log::add('asuswrt', 'error', 'connexion SSH KO');
			return 'error connecting';
		}
		if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
			log::add('sshcommander', 'error', 'Authentification SSH KO');
			return 'error connecting';
		}

		//iptables -I FORWARD -s 192.168.2.100 -j DROP
		//iptables -D FORWARD -s 192.168.2.101 -j DROP
		$active = ($_enable) ? 'D' : 'I';
		$ip = $this->getConfiguration('ip');
		log::add('asuswrt', 'debug', 'Commande : iptables -' . $active . ' FORWARD -s ' . $ip . ' -j DROP');
		$stream = ssh2_exec($connection, 'iptables -' . $active . ' FORWARD -s ' . $ip . ' -j DROP');

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
		}
	}

}

?>
