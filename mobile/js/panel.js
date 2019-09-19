/* This file is part of Plugin openzwave for jeedom.
 *
 * Plugin openzwave for jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Plugin openzwave for jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Plugin openzwave for jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

function initKlf200Klf200() {
	 getKlf200State()
}

function getKlf200State(){
	$.ajax({
        type: "POST",
        url: "plugins/asuswrt/core/ajax/asuswrt.ajax.php",
        data: {
            action: "getAsuswrt",
            type: "mobile",
        },
        dataType: 'json',
		global : false,
        error: function (request, status, error) {
            handleAjaxError(request, status, error);
		},
        success: function (data) { // si l'appel a bien fonctionné
        if (data.state != 'ok') {
            $('#div_inclusionAlert').showAlert({message: data.result, level: 'danger'});
            return;
		}
		var table = '';
		for (asuswrt in data.result.devices) {
			var device = data.result.devices[asuswrt];
			table += '<tr><td>' +  device['name'] +' <br/></td>';
			table += '<td>' + device['hostname'] + '</td>';
			table += '<td>' + device['mac'] + '</td>';
			table += '<td>' + device['ip'] + '</td>';
			table += '<td>' + device['connexion'] + '</td>';
			table += '<td>' + device['presence'] + '</td>';
			table += '</tr>';
		}
		$("#table_asuswrt tbody").empty().append(table);
		$("#table_asuswrt tbody").trigger('create');
        }
});
}

 $('#table_asuswrt tbody').on('click','.bt_asuswrtAction',function(){
       jeedom.cmd.execute({id: $(this).data('cmd')});
       getKlf200State();
   })

  $('#table_asuswrt tbody').on('click','.bt_positiondeviceAction',function(){
       jeedom.cmd.execute({id: $(this).data('cmd'), value: {slider: $(this).data('value')}});
       getKlf200State();
   })

setInterval(function() {

getKlf200State();

}, 5000);
