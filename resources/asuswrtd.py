"""
Rip off of AsusWRT routers handling from Home Assistant.
It serves the results as JSON on the context root

Support for ASUSWRT routers.

For more details about this platform, please refer to the documentation at
https://home-assistant.io/components/device_tracker.asuswrt/
"""
import argparse
from http.server import BaseHTTPRequestHandler,HTTPServer
from os import environ
import logging
import re
import socket
import threading
import json
from collections import namedtuple
from datetime import timedelta

REQUIREMENTS = ['pexpect==4.0.1']

_LOGGER = logging.getLogger(__name__)
CONF_HOST= 'host'
CONF_PASSWORD= 'password'
CONF_USERNAME= 'username'
CONF_PORT='port'
CONF_MODE = 'mode'
CONF_PROTOCOL = 'protocol'
CONF_PUB_KEY = 'pub_key'
CONF_SSH_KEY = 'ssh_key'

DEFAULT_SSH_PORT = 22

parser = argparse.ArgumentParser()
parser.add_argument("asus", help="ip asus")
parser.add_argument("user", help="user asus")
parser.add_argument("password", help="pass asus")
args = parser.parse_args()

asus = str(args.asus)
user = str(args.user)
password = str(args.password)

MIN_TIME_BETWEEN_SCANS = timedelta(seconds=5)

SECRET_GROUP = 'Password or SSH Key'

_LEASES_CMD = 'cat /var/lib/misc/dnsmasq.leases'
_LEASES_REGEX = re.compile(
    r'\w+\s' +
    r'(?P<mac>(([0-9a-f]{2}[:-]){5}([0-9a-f]{2})))\s' +
    r'(?P<ip>([0-9]{1,3}[\.]){3}[0-9]{1,3})\s' +
    r'(?P<host>([^\s]+))')

# Command to get both 5GHz and 2.4GHz clients
_WL_CMD = '{ wl -i eth2 assoclist & wl -i eth1 assoclist ; }'
_WL_REGEX = re.compile(
    r'\w+\s' +
    r'(?P<mac>(([0-9A-F]{2}[:-]){5}([0-9A-F]{2})))')

_ARP_CMD = 'arp -n'
_ARP_REGEX = re.compile(
    r'.+\s' +
    r'\((?P<ip>([0-9]{1,3}[\.]){3}[0-9]{1,3})\)\s' +
    r'.+\s' +
    r'(?P<mac>(([0-9a-f]{2}[:-]){5}([0-9a-f]{2})))' +
    r'\s' +
    r'.*')

_IP_NEIGH_CMD = 'ip neigh'
_IP_NEIGH_REGEX = re.compile(
    r'(?P<ip>([0-9]{1,3}[\.]){3}[0-9]{1,3}|'
    r'([0-9a-fA-F]{1,4}:){1,7}[0-9a-fA-F]{0,4}(:[0-9a-fA-F]{1,4}){1,7})\s'
    r'\w+\s'
    r'\w+\s'
    r'(\w+\s(?P<mac>(([0-9a-f]{2}[:-]){5}([0-9a-f]{2}))))?\s'
    r'\s?(router)?'
    r'(?P<status>(\w+))')

_NVRAM_CMD = 'nvram get client_info_tmp'
_NVRAM_REGEX = re.compile(
    r'.*>.*>' +
    r'(?P<ip>([0-9]{1,3}[\.]){3}[0-9]{1,3})' +
    r'>' +
    r'(?P<mac>(([0-9a-fA-F]{2}[:-]){5}([0-9a-fA-F]{2})))' +
    r'>' +
    r'.*')

# pylint: disable=unused-argument
def get_scanner(hass, config):
    """Validate the configuration and return an ASUS-WRT scanner."""
    scanner = AsusWrtDeviceScanner(config)

    return scanner if scanner.success_init else None


AsusWrtResult = namedtuple('AsusWrtResult', 'neighbors leases arp nvram')


class AsusWrtDeviceScanner:
    """This class queries a router running ASUSWRT firmware."""

    # Eighth attribute needed for mode (AP mode vs router mode)
    def __init__(self, config):
        """Initialize the scanner."""
        self.host = config[CONF_HOST]
        self.username = config[CONF_USERNAME]
        self.password = config.get(CONF_PASSWORD, '')
        self.ssh_key = config.get('ssh_key', config.get('pub_key', ''))
        self.protocol = config[CONF_PROTOCOL]
        self.mode = config[CONF_MODE]
        self.port = config[CONF_PORT]
        self.ssh_args = {}

        if self.protocol == 'ssh':

            self.ssh_args['port'] = self.port
            if self.ssh_key:
                self.ssh_args['ssh_key'] = self.ssh_key
            elif self.password:
                self.ssh_args['password'] = self.password
            else:
                _LOGGER.error("No password or private key specified")
                self.success_init = False
                return
        else:
            if not self.password:
                _LOGGER.error("No password specified")
                self.success_init = False
                return

        self.lock = threading.Lock()

        self.last_results = {}

        # Test the router is accessible.
        data = self.get_asuswrt_data()
        self.success_init = data is not None

    def scan_devices(self):
        """Scan for new devices and return a list with found device IDs."""
        self._update_info()
        return [client['mac'] for client in self.last_results]

    def get_device_name(self, device):
        """Return the name of the given device or None if we don't know."""
        if not self.last_results:
            return None
        for client in self.last_results:
            if client['mac'] == device:
                return client['host']
        return None

    def _update_info(self):
        """Ensure the information from the ASUSWRT router is up to date.

        Return boolean if scanning successful.
        """
        if not self.success_init:
            return False

        with self.lock:
            _LOGGER.info('Checking ARP')
            data = self.get_asuswrt_data()
            if not data:
                return False

            active_clients = [client for client in data.values() if
                              client['status'] == 'REACHABLE' or
                              client['status'] == 'DELAY' or
                              client['status'] == 'STALE' or
                              client['status'] == 'IN_NVRAM']
            self.last_results = active_clients
            return True

    def ssh_connection(self):
        """Retrieve data from ASUSWRT via the ssh protocol."""
        from pexpect import pxssh, exceptions

        ssh = pxssh.pxssh()
        try:
            ssh.login(self.host, self.username, **self.ssh_args)
        except exceptions.EOF as err:
            _LOGGER.error("Connection refused. SSH enabled?")
            return None
        except pxssh.ExceptionPxssh as err:
            _LOGGER.error("Unable to connect via SSH: %s", str(err))
            return None

        try:
            ssh.sendline(_IP_NEIGH_CMD)
            ssh.prompt()
            neighbors = ssh.before.split(b'\n')[1:-1]
            if self.mode == 'ap':
                ssh.sendline(_ARP_CMD)
                ssh.prompt()
                arp_result = ssh.before.split(b'\n')[1:-1]
                ssh.sendline(_WL_CMD)
                ssh.prompt()
                leases_result = ssh.before.split(b'\n')[1:-1]
                ssh.sendline(_NVRAM_CMD)
                ssh.prompt()
                nvram_result = ssh.before.split(b'\n')[1].split(b'<')[1:]
            else:
                arp_result = ['']
                nvram_result = ['']
                ssh.sendline(_LEASES_CMD)
                ssh.prompt()
                leases_result = ssh.before.split(b'\n')[1:-1]
            ssh.logout()
            return AsusWrtResult(neighbors, leases_result, arp_result,
                                 nvram_result)
        except pxssh.ExceptionPxssh as exc:
            _LOGGER.error("Unexpected response from router: %s", exc)
            return None

    def get_asuswrt_data(self):
        """Retrieve data from ASUSWRT and return parsed result."""
        result = self.ssh_connection()

        if not result:
            return {}

        devices = {}
        if self.mode == 'ap':
            for lease in result.leases:
                match = _WL_REGEX.search(lease.decode('utf-8'))

                if not match:
                    _LOGGER.warning("Could not parse wl row: %s", lease)
                    continue

                host = ''

                # match mac addresses to IP addresses in ARP table
                for arp in result.arp:
                    if match.group('mac').lower() in \
                            arp.decode('utf-8').lower():
                        arp_match = _ARP_REGEX.search(
                            arp.decode('utf-8').lower())
                        if not arp_match:
                            _LOGGER.warning("Could not parse arp row: %s", arp)
                            continue

                        devices[arp_match.group('ip')] = {
                            'host': host,
                            'status': '',
                            'ip': arp_match.group('ip'),
                            'mac': match.group('mac').upper(),
                            }

                # match mac addresses to IP addresses in NVRAM table
                for nvr in result.nvram:
                    if match.group('mac').upper() in nvr.decode('utf-8'):
                        nvram_match = _NVRAM_REGEX.search(nvr.decode('utf-8'))
                        if not nvram_match:
                            _LOGGER.warning("Could not parse nvr row: %s", nvr)
                            continue

                        # skip current check if already in ARP table
                        if nvram_match.group('ip') in devices.keys():
                            continue

                        devices[nvram_match.group('ip')] = {
                            'host': host,
                            'status': 'IN_NVRAM',
                            'ip': nvram_match.group('ip'),
                            'mac': match.group('mac').upper(),
                            }

        else:
            for lease in result.leases:
                if lease.startswith(b'duid '):
                    continue
                match = _LEASES_REGEX.search(lease.decode('utf-8'))

                if not match:
                    _LOGGER.warning("Could not parse lease row: %s", lease)
                    continue

                # For leases where the client doesn't set a hostname, ensure it
                # is blank and not '*', which breaks entity_id down the line.
                host = match.group('host')
                if host == '*':
                    host = ''

                devices[match.group('ip')] = {
                    'host': host,
                    'status': '',
                    'ip': match.group('ip'),
                    'mac': match.group('mac').upper(),
                    }

        for neighbor in result.neighbors:
            match = _IP_NEIGH_REGEX.search(neighbor.decode('utf-8'))
            if not match:
                _LOGGER.warning("Could not parse neighbor row: %s", neighbor)
                continue
            if match.group('ip') in devices:
                devices[match.group('ip')]['status'] = match.group('status')
        return devices

PORT_NUMBER = 9090
config={CONF_HOST: asus, CONF_USERNAME: user, CONF_PASSWORD: password,CONF_PROTOCOL: 'ssh', CONF_MODE: 'router', CONF_PORT: '22' }
scanner=get_scanner({}, config)
#This class will handles any incoming request from
#the browser
class myHandler(BaseHTTPRequestHandler):

	#Handler for the GET requests
	def do_GET(self):
		if self.path=="/":
				self.send_response(200)
				self.send_header("Content-type", "application/json")
				self.end_headers()
				self.wfile.write(json.dumps(scanner.get_asuswrt_data()))
		return
try:
	#Create a web server and define the handler to manage the
	#incoming request
	server = HTTPServer(('', PORT_NUMBER), myHandler)
	print('Started httpserver on port ' , PORT_NUMBER)

	#Wait forever for incoming htto requests
	server.serve_forever()

except KeyboardInterrupt:
	print('^C received, shutting down the web server')
	server.socket.close()
