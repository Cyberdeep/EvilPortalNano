<?php namespace pineapple;

class EvilPortal extends Module
{

	// CONSTANTS
	private $CLIENTS_FILE = '/tmp/EVILPORTAL_CLIENTS.txt';
	private $ALLOWED_FILE = '/pineapple/modules/EvilPortal/data/allowed.txt';
	// CONSTANTS

	public function route()
	{
		switch($this->request->action) {
			case 'getControlValues':
				$this->getControlValues();
				break;

			case 'startStop':
				$this->handleRunning();
				break;

			case 'enableDisable':
				$this->handleEnable();
				break;

			case 'portalList':
				$this->handleGetPortalList();
				break;

			case 'submitPortalCode':
				$this->submitPortalCode();
				break;

			case 'deletePortal':
				$this->handleDeletePortal();
				break;

			case 'activatePortal':
				$this->activatePortal();
				break;

			case 'getPortalCode':
				$this->getPortalCode();
				break;
		}
	}

	public function getPortalCode() {
		$portalName = $this->request->name;
		$storage = $this->request->storage;

		if ($storage != "active")
			$dir = ($storage == "sd" ? "/sd/portals/" : "/root/portals/");
		else
			$dir = "/etc/nodogsplash/htdocs/";

		$message = "";
		$code = "";

		if (file_exists($dir . $portalName)) {
			$code = file_get_contents($dir . $portalName);
			$message = $portalName . " is ready for editting.";
		} else {
			$message = "Error finding " . $portalName . ".";
		}

		$this->response = array("message" => $message, "code" => $code);

	}

	public function activatePortal() {
		$portalName = $this->request->name;
		$storage = $this->request->storage;

		if ($storage != "active")
			$dir = ($storage == "sd" ? "/sd/portals/" : "/root/portals/");
		else
			$dir = "/etc/nodogsplash/htdocs/";

		$message = "";
		if (file_exists($dir . $portalName)) {
			unlink("/etc/nodogsplash/htdocs/splash.html");
			$portalName = escapeshellarg($portalName);
			exec("ln -s " . $dir . $portalName . " /etc/nodogsplash/htdocs/splash.html");
			$message = $portalName . " is now active.";
		} else {
			$message = "Couldn't find " . $portalName . ".";
		}

		$this->response = array("message" => $message);

	}

	public function handleDeletePortal() {
		$portalName = $this->request->name;
		$storage = $this->request->storage;

		$dir = ($storage == "sd" ? "/sd/portals/" : "/root/portals/");

		unlink($dir . $portalName);

		$message = "";

		if (!file_exists($dir . $portalName)) {
			$message = "Deleted " . $portalName;
		} else {
			$message = "Error deleting " . $portalName;
		}

		$this->response = array("message" => $message);

	}

	public function submitPortalCode() {
		$code = $this->request->portalCode;
		$storage = $this->request->storage;
		$portalName = $this->request->name;

		if ($storage != "active")
			$dir = ($storage == "sd" ? "/sd/portals/" : "/root/portals/");
		else
			$dir = "/etc/nodogsplash/htdocs/";

		$message = "";

		if (!file_exists($dir . $portalName)) {
			file_put_contents($dir . $portalName, $code);
			$message = "Created " . $portalName;
		} else {
			file_put_contents($dir . $portalName, $code);
			$message = "Updated " . $portalName;
		}
		

		$this->response = array(
				"message" => $message
			);

	}

	public function handleGetPortalList() {
		if (!file_exists("/root/portals"))
			mkdir("/root/portals");

		$all_portals = array();
		$root_portals = preg_grep('/^([^.])/', scandir("/root/portals"));

		foreach ($root_portals as $portal) {
			$obj = array("title" => $portal, "location" => "internal");
			array_push($all_portals, $obj);
		}

		$active = array("title" => "splash.html", "location" => "active");
		array_push($all_portals, $active);

		$this->response = $all_portals;
	}

	public function handleEnable() {
		$response_array = array();
		if (!$this->checkAutoStart()) {
			exec("/etc/init.d/firewall disable");
			//exec("/etc/init.d/nodogsplash enable");
			$enabled = $this->checkAutoStart();
			$message = "NoDogSplash is now enabled on startup.";
			if (!$enabled)
				$message = "Error enabling NoDogSplash on startup.";

			$response_array = array(
					"control_success" => $enabled,
					"control_message" => $message
				);

		} else {
			exec("/etc/init.d/nodogsplash disable");
			//exec("/etc/init.d/firewall enable");
			$enabled = !$this->checkAutoStart();
			$message = "NoDogSplash now disabled on startup.";
			if (!$enabled)
				$message = "Error disabling NoDogSplash on startup.";

			$response_array = array(
					"control_success" => $enabled,
					"control_message" => $message
				);
		}
		$this->response = $response_array;
	}

	public function checkCaptivePortalRunning() {
		return exec("iptables -t nat -L PREROUTING | grep 172.16.42.1") == '' ? false : true;
	}

	public function startCaptivePortal() {

		// Delete client tracking file if it exists
		if (file_exists($this->CLIENTS_FILE)) {
			unlink($this->CLIENTS_FILE);
		}

		// Enable forwarding. It should already be enabled on the pineapple but do it anyways just to be safe
		exec("echo 1 > /proc/sys/net/ipv4/ip_forward");

		// Insert allowed clients into tracking file
		$allowedClients = file_get_contents($this->ALLOWED_FILE);
		file_put_contents($this->CLIENTS_FILE, $allowedClients);

		// Configure other rules
		exec("iptables -t nat -A PREROUTING -s 172.16.42.0/24 -p tcp --dport 80 -j DNAT --to-destination 172.16.42.1:1337");
		exec("iptables -A INPUT -p tcp --dport 53 -j ACCEPT");

		// Add rule for each allowed client
		$lines = file($this->CLIENTS_FILE);
		foreach ($lines as $client) {
			exec("iptables -t nat -I PREROUTING -s {$client} -j ACCEPT");
		}

		// Drop everything else
		exec("iptables -A INPUT -j DROP");

		return $this->checkCaptivePortalRunning();

	}

	public function stopCaptivePortal() {
		if (file_exists($this->CLIENTS_FILE)) {
			$lines = file($this->CLIENTS_FILE);
			foreach ($lines as $client) {
				exec("iptables -t nat -D PREROUTING -s {$client} -j ACCEPT");
			}
			unlink($this->CLIENTS_FILE);
		}

		exec("iptables -t nat -D PREROUTING -s 172.16.42.0/24 -p tcp --dport 80 -j DNAT --to-destination 172.16.42.1:1337");
		exec("iptables -D INPUT -p tcp --dport 53 -j ACCEPT");
		exec("iptables -D INPUT -j DROP");

		return $this->checkCaptivePortalRunning();

	}

	public function handleRunning() {
		$response_array = array();
		if (!$this->checkCaptivePortalRunning()) {
			//exec("/etc/init.d/nodogsplash start");
			//$running = $this->checkRunning("nodogsplash");
			$running = $this->startCaptivePortal();
			$message = "Started NoDogSplash.";
			if (!$running)
				$message = "Error starting NoDogSplash.";

			$response_array = array(
				"control_success" => $running,
				"control_message" => $message
			);
		} else {
			//exec("/etc/init.d/nodogsplash stop");
			//sleep(1);
			//$running = !$this->checkRunning("nodogsplash");
			$running = !$this->stopCaptivePortal();
			$message = "Stopped NoDogSplash.";
			if (!$running)
				$message = "Error stopping NoDogSplash.";

			$response_array = array(
					"control_success" => $running,
					"control_message" => $message
				);
		}

		$this->response = $response_array;
	}

	/*public function handleDepends() {
		$response_array = array();
		if (!$this->checkDependency("nodogsplash")) {
			$installed = $this->installDependency("nodogsplash");
			$message = "Successfully installed dependencies.";
			if (!$installed) {
				$message = "Error installing dependencies.";
			} else {
				exec("/etc/init.d/nodogsplash disable");
				$this->uciSet("nodogsplash.@instance[0].enabled", true);
				$this->uciAddList("nodogsplash.@instance[0].users_to_router", "allow tcp port 1471");
			}
			$response_array = array(
					"control_success" => $installed,
					"control_message" => $message
				);
			
		} else {
			exec("opkg remove nodogsplash");
			$removed = !$this->checkDependency("nodogsplash");
			$message = "Successfully removed dependencies.";
			if ($installed) {
				$message = "Error removing dependencies.";
			}
			$response_array = array(
					"control_success" => $removed,
					"control_message" => $message
				);
		}

		$this->response = $response_array;

	}*/

	public function getControlValues() {
		$this->response = array(
				//"dependencies" => true,
				"running" => $this->checkCaptivePortalRunning(),
				"autostart" => $this->checkAutoStart()
			);
	}

	public function checkAutoStart() {
		if (exec("ls /etc/rc.d/ | grep nodogsplash") == '') {
			return false;
		} else {
			return true;
		}
	}

	/*public function checkDepends() {
		$splash = true;
		if (exec("opkg list-installed | grep nodogsplash") == '') {
    		$splash = false;
		}
    	return true;
	}*/

	public function checkRunning() {
		if (exec("ps | grep -v grep | grep -o nodogsplash") == '') {
			return false;
		} else {
			return true;
		}
	}

}