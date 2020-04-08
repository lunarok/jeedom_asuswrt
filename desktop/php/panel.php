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

if (!isConnect('admin')) {
  throw new Exception('401 - Accès non autorisé');
}
$plugin = plugin::byId('asuswrt');
$eqLogics = asuswrt::byType('asuswrt');
?>

<table class="table table-condensed tablesorter" align="center">
  <thead>
    <tr>
      <th>{{Equipement}}</th>
      <th>{{ID}}</th>
      <th>{{Hostname}}</th>
      <th>{{IP}}</th>
      <th>{{MAC}}</th>
      <th>{{Connexion}}</th>
      <th>{{AP}}</th>
      <th>{{RSSI}}</th>
      <th>{{Presence}}</th>
    </tr>
  </thead>
  <tbody>
    <?php
    foreach ($eqLogics as $eqLogic) {
      if ($eqLogic->getLogicalId('id') == 'router') {
        continue;
      }
      $cmd = $eqLogic->getCmd(null, 'hostname');
      $hotsname = (is_object($cmd)) ? $cmd->execCmd():'';
      $cmd = $eqLogic->getCmd(null, 'ip');
      $ip = (is_object($cmd)) ? $cmd->execCmd():'';
      $cmd = $eqLogic->getCmd(null, 'mac');
      $mac = (is_object($cmd)) ? $cmd->execCmd():'';
      $cmd = $eqLogic->getCmd(null, 'connexion');
      $connexion = (is_object($cmd)) ? $cmd->execCmd():'';
      $cmd = $eqLogic->getCmd(null, 'ap');
      $ap = (is_object($cmd)) ? $cmd->execCmd():'';
      $cmd = $eqLogic->getCmd(null, 'rssi');
      $rssi = (is_object($cmd)) ? $cmd->execCmd():'';
      $cmd = $eqLogic->getCmd(null, 'presence');
      $presence = (is_object($cmd)) ? $cmd->execCmd():'';
      echo '<tr>';
      echo '<td><a href="' . $eqLogic->getLinkToConfiguration() . '" style="text-decoration: none;">' . $eqLogic->getHumanName(true) . '</a></td>';
      echo '<td><span class="label label-info" style="font-size : 1em; cursor : default;">' . $eqLogic->getId() . '</span></td>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $hotsname . '</span></br></br>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $ip . '</span></br></br>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $mac . '</span></br></br>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $connexion . '</span></br></br>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $ap . '</span></br></br>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $rssi . '</span></br></br>';
      echo '<td><center><span class="label label-info" style="font-size : 0.8em;cursor:default">' . $presence . '</span></br></br>';
      echo '</tr>';
    }
    ?>
  </tbody>
</table>


<?php include_file('desktop', 'panel', 'js', 'asuswrt');?>
