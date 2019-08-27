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

	public static function dependancy_info() {
		$return = array();
		$return['progress_file'] = jeedom::getTmpFolder('asuswrt') . '/dependancy';
		$cmd = "pip3 list | grep pexpect";
		exec($cmd, $output, $return_var);
		$cmd = "pip3 list | grep http.server";
		exec($cmd, $output2, $return_var);
		$return['state'] = 'nok';
		if (array_key_exists(0,$output) && array_key_exists(0,$output2)) {
		    if ($output[0] != "" && $output2[0] != "") {
			$return['state'] = 'ok';
		    }
		}
		return $return;
	}

	public static function dependancy_install() {
		$dep_info = self::dependancy_info();
		log::remove(__CLASS__ . '_dep');
		if ($dep_info['state'] != 'ok') {
			$resource_path = realpath(dirname(__FILE__) . '/../../resources');
			passthru('/bin/bash ' . $resource_path . '/install_apt.sh ' . jeedom::getTmpFolder('asuswrt') . '/dependancy > ' . log::getPathToLog(__CLASS__ . '_dep') . ' 2>&1 &');
		}
	}

	public static function deamon_info() {
		$return = array();
		$return['log'] = 'asuswrt';
		$return['state'] = 'nok';
		$pid = trim( shell_exec ('ps ax | grep "asuswrt/resources/asuswrtd.py" | grep -v "grep" | wc -l') );
		if ($pid != '' && $pid != '0') {
			$return['state'] = 'ok';
		}
		$return['launchable'] = 'ok';
		if (config::byKey('addr', 'asuswrt', '') == '' || config::byKey('user', 'asuswrt', '') == '' || config::byKey('password', 'asuswrt', '') == '') {
			$return['launchable'] = 'nok';
		}
		return $return;
	}

	public static function deamon_start() {
		log::remove(__CLASS__ . '_update');
		log::remove(__CLASS__ . '_node');
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') {
			throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
		}
		$asuswrt_path = realpath(dirname(__FILE__) . '/../../resources/');
		$cmd = '/usr/bin/python3 ' . $asuswrt_path . '/asuswrtd.py';
		$cmd .= ' ' . config::byKey('addr', 'asuswrt');
		$cmd .= ' ' . config::byKey('user', 'asuswrt');
		$cmd .= ' ' . config::byKey('password', 'asuswrt');
		log::add('asuswrt', 'info', 'Lancement démon asuswrt : ' . $cmd);
		$result = exec($cmd . ' >> ' . log::getPathToLog('asuswrt') . ' 2>&1 &');
		$i = 0;
		while ($i < 30) {
			$deamon_info = self::deamon_info();
			if ($deamon_info['state'] == 'ok') {
				break;
			}
			sleep(1);
			$i++;
		}
		if ($i >= 30) {
			log::add('asuswrt', 'error', 'Impossible de lancer le démon asuswrtd. Vérifiez le log.', 'unableStartDeamon');
			return false;
		}
		message::removeAll('asuswrt', 'unableStartDeamon');
		return true;
	}

	public static function deamon_stop() {
		exec('kill $(ps aux | grep "/asuswrtd.py" | awk \'{print $2}\')');
		log::add('asuswrt', 'info', 'Arrêt du service asuswrt');
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			sleep(1);
			exec('kill -9 $(ps aux | grep "/asuswrtd.py" | awk \'{print $2}\')');
		}
		$deamon_info = self::deamon_info();
		if ($deamon_info['state'] == 'ok') {
			sleep(1);
			exec('sudo kill -9 $(ps aux | grep "/asuswrtd.py" | awk \'{print $2}\')');
		}
	}

	public function scanDevices() {
		asuswrt::deamon_start();
		if (config::byKey('addr', 'klf200', '') == '' || config::byKey('password', 'klf200', '') == '') {
			return;
		}
		$http = new com_http('http://localhost:9090/');
		$return = json_decode($http->exec(15,2),true);
		log::add('klf200', 'debug', 'Scan Devices, result ' . print_r($return, true));
	}

}

class asuswrtCmd extends cmd {

}
?>
