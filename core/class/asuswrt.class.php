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
			$eqlogic=asuswrt::byLogicalId($asuswrt['mac'], 'asuswrt');
		    if (!is_object($eqlogic)) {
		      $eqlogic = new asuswrt();
		      $eqlogic->setEqType_name('asuswrt');
		      $eqlogic->setLogicalId($asuswrt['mac']);
		      $eqlogic->setIsEnable(1);
		      $eqlogic->setIsVisible(0);
		      $eqlogic->setName($asuswrt['hostname']);
		      $eqlogic->setConfiguration('hostname', $asuswrt['hostname']);
		      $eqlogic->setConfiguration('mac', $asuswrt['mac']);
		      //$eqlogic->setConfiguration('connexion', $asuswrt['connexion']);
		      $eqlogic->save();
		    }
			$eqlogic->loadCmdFromConf('client');
			foreach ($asuswrt as $logicalid => $value) {
				$eqlogic->checkAndUpdateCmd($logicalid, $value);
			}
			$presence = ($asuswrt['status'] == 'UNKNOW') ? 0 : 1;
			$eqlogic->checkAndUpdateCmd('presence', $presence);
		}
	}

	public static function scan() {
		$result = array();
		foreach (eqLogic::byType('asuswrt') as $asuswrt) {
			$result[$asuswrt->getConfiguration('mac')]['status'] = "OFFLINE";
		}

		if (!$connection = ssh2_connect(config::byKey('addr', 'asuswrt'),'22')) {
      log::add('asuswrt', 'error', 'connexion SSH KO');
      return 'error connecting';
    }
		if (!ssh2_auth_password($connection,config::byKey('user', 'asuswrt'),config::byKey('password', 'asuswrt'))){
			log::add('sshcommander', 'error', 'Authentification SSH KO');
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
			$result[$mac]['connexion'] = 'ethernet';
			$result[$mac]['status'] = 'UNKNOWN';
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
			    $result[$mac]['connexion'] = 'ethernet';
			}
		}
		fclose($stream);

		$stream = ssh2_exec($connection, 'wl -i eth1 assoclist');
    stream_set_blocking($stream, true);
    while($line = fgets($stream)) {
			//assoclist 1C:F2:9A:34:4D:37
			//assoclist 44:07:0B:4A:A9:96
			$array=explode(" ", $line);
	    	$mac = trim(strtolower($array[1]));
			$result[$mac]['connexion'] = 'wifi2.4';
		}
		fclose($stream);

		$stream = ssh2_exec($connection, 'wl -i eth2 assoclist');
    stream_set_blocking($stream, true);
    while($line = fgets($stream)) {
			$array=explode(" ", $line);
	    		$mac = trim(strtolower($array[1]));
			$result[$mac]['connexion'] = 'wifi5';
		}
		fclose($stream);

		$closesession = ssh2_exec($connection, 'exit');
		stream_set_blocking($closesession, true);
		stream_get_contents($closesession);

		//REACHABLE, DELAY, STABLE, ARP
		log::add('asuswrt', 'debug', 'Scan Asus, result ' . print_r($result, true));
		return $result;
	}

}

class asuswrtCmd extends cmd {

}
?>
