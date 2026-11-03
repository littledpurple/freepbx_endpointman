<?php
/**
 * Endpoint Manager Object Module - Sec Devices
 *
 * @author Javier Pastor
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */

namespace FreePBX\modules;

#[\AllowDynamicProperties]
class Endpointman_Devices
{
	public $epm;
	public $freepbx;
	public $db;
	public $config;

	public function __construct($epm)
	{
		$this->epm 	   = $epm;
		$this->freepbx = $epm->freepbx;
		$this->db 	   = $epm->freepbx->Database;
		$this->config  = $epm->freepbx->Config;
	}

	public function myShowPage(&$pagedata) { }

	public function ajaxRequest($req, &$setting)
	{
		$allowRequest = array(
			"list_devices",
			"get_device",
			"save_device",
			"delete_devices",
			"rebuild",
			"rebuild_all",
			"reboot",
			"discover",
			"set_disabled",
			"list_brands",
			"list_models",
			"list_templates",
			"list_extensions",
		);
		if (in_array(strtolower($req), $allowRequest))
		{
			$setting['authenticate'] = true;
			$setting['allowremote']  = false;
			return true;
		}
		return false;
	}

    public function ajaxHandler($module_tab = "", $command = "")
	{
		$request = freepbxGetSanitizedRequest();
		if ($module_tab != "manager")
		{
			return array("status" => false, "message" => sprintf(_("Tab '%s' not valid!"), $module_tab));
		}

		try
		{
			switch ($command)
			{
				case "list_devices":
					return $this->epm_devices_list();

				case "get_device":
					$device = $this->epm->devices->getDevice((int)($request['id'] ?? 0));
					if (!$device)
					{
						return array("status" => false, "message" => _("Device not found!"));
					}
					return array("status" => true, "message" => "OK", "device" => $device);

				case "save_device":
					return $this->epm_devices_save($request);

				case "delete_devices":
					return $this->epm_devices_delete($this->request_ids($request), ($request['remove_files'] ?? '1') == '1');

				case "rebuild":
					return $this->epm_devices_rebuild($this->request_ids($request), ($request['reboot'] ?? '0') == '1');

				case "rebuild_all":
					$this->epm->devices->syncDescriptions();
					return $this->epm_devices_rebuild($this->epm->provisioning->getDeviceIds('all'), ($request['reboot'] ?? '0') == '1');

				case "reboot":
					$out = array();
					foreach ($this->request_ids($request) as $id)
					{
						$out[$id] = $this->epm->provisioning->reboot($id);
					}
					$failed = array_keys(array_filter($out, fn($ok) => !$ok));
					return array(
						"status"  => empty($failed),
						"message" => empty($failed) ? _("Reboot request sent!") : sprintf(_("The phone did not accept the request (not registered?): %s"), implode(', ', $this->macs($failed))),
						"result"  => $out,
					);

				case "set_disabled":
					$disabled = ($request['disabled'] ?? '1') == '1';
					$count    = $this->epm->devices->setDisabled($this->request_ids($request), $disabled);
					return array("status" => true, "message" => sprintf($disabled ? _("Provisioning disabled for %d phone(s).") : _("Provisioning enabled for %d phone(s)."), $count));

				case "discover":
					$found = $this->epm->devices->discover(trim($request['netmask'] ?? ''), ($request['nmap'] ?? '0') == '1');
					return array("status" => true, "message" => sprintf(_("%d phone(s) found"), count($found)), "rows" => $found);

				case "list_brands":
					return $this->options($this->epm->devices->brands(), $request['selected'] ?? null);

				case "list_models":
					$brand = (int)($request['brand'] ?? 0);
					return $this->options($this->epm->devices->models($brand ?: null), $request['selected'] ?? null, ['max_lines', 'product']);

				case "list_templates":
					return $this->options($this->epm->devices->templates((int)($request['model'] ?? 0)), $request['selected'] ?? 0);

				case "list_extensions":
					$id = (int)($request['id'] ?? 0);
					return $this->options($this->epm->devices->availableExtensions($id ?: null), null);
			}
		}
		catch (\Throwable $e)
		{
			return array("status" => false, "message" => $e->getMessage());
		}
		return array("status" => false, "message" => sprintf(_("Command '%s' not found!"), $command));
	}

	public function doConfigPageInit($module_tab = "", $command = "") { }

	public function getRightNav($request, $params = array())
	{
		return "";
	}

	public function getActionBar($request)
	{
		return "";
	}

	public function showPage(array &$data)
	{
		$data['brands']        = $this->epm->devices->brands();
		$data['no_brands']     = empty($data['brands']);
		$data['no_srvip']      = ($this->epm->getConfig("srvip") == "");
		$data['server_type']   = $this->epm->getConfig("server_type");
		$data['nmap_search']   = $this->epm->getConfig("nmap_search");
		$data['nmap_location'] = $this->epm->getConfig("nmap_location");
		$data['url_grid']      = "ajax.php?module=endpointman&module_sec=epm_devices&module_tab=manager&command=list_devices";
	}


	/**
	 * Rows for the device grid, with the registration of every line.
	 */
	private function epm_devices_list()
	{
		$regs = $this->epm->devices->registrations();
		$rows = array();
		foreach ($this->epm->devices->listDevices() as $d)
		{
			$lines = array();
			$ip    = '';
			$state = 'none';
			foreach ($d['lines'] as $l)
			{
				$reg     = $regs[$l['ext']] ?? null;
				$lines[] = array('line' => $l['line'], 'ext' => $l['ext'], 'description' => $l['description'], 'registered' => !empty($reg), 'status' => $reg['status'] ?? '');
				if ($reg && $ip === '')
				{
					$ip    = $reg['ip'];
					$state = $reg['status'] ?: 'Avail';
				}
			}
			$rows[] = array(
				'id'          => (int)$d['id'],
				'mac'         => $d['mac'],
				'mac_format'  => implode(':', str_split($d['mac'], 2)),
				'brand'       => $d['brand'] ?? _('Unknown'),
				'brand_id'    => $d['brand_id'],
				'model'       => $d['model'] ?? _('Unknown'),
				'model_id'    => $d['model_id'],
				'model_ok'    => !empty($d['model']),
				'template_id' => (int)$d['template_id'],
				'template'    => ($d['template_id'] > 0) ? ($d['template_name'] ?? _('Deleted template!')) : _('Custom'),
				'has_custom'  => (bool)$d['has_custom'],
				'disabled'    => (bool)$d['disabled'],
				'lines'       => $lines,
				'ip'          => $ip,
				'state'       => $state,
			);
		}
		return $rows;
	}

	private function epm_devices_save(array $request)
	{
		$id       = (int)($request['id'] ?? 0);
		$mac      = trim($request['mac'] ?? '');
		$model    = (int)($request['model'] ?? 0);
		$template = (int)($request['template'] ?? 0);
		// raw value: freepbxGetSanitizedRequest() html-encodes the quotes of the JSON
		$lines    = json_decode($_POST['lines'] ?? $_REQUEST['lines'] ?? '[]', true);
		$rebuild  = ($request['rebuild'] ?? '1') == '1';
		$reboot   = ($request['reboot'] ?? '0') == '1';
		if (!is_array($lines))
		{
			return array("status" => false, "message" => _("Invalid lines!"));
		}

		if ($id > 0)
		{
			$this->epm->devices->updateDevice($id, $mac, $model, $template, $lines);
			$msg = _("Device saved!");
		}
		else
		{
			$id  = $this->epm->devices->addDevice($mac, $model, $template, $lines);
			$msg = _("Device added!");
		}

		$return = array("status" => true, "message" => $msg, "id" => $id);
		if ($rebuild)
		{
			$res = $this->epm->provisioning->rebuild(array($id), $reboot)[$id];
			if (!$res['status'])
			{
				// The device is saved, only the configuration could not be written
				return array("status" => false, "message" => sprintf(_("%s But the configuration was not written: %s"), $msg, $res['message']), "id" => $id);
			}
			$return['message'] .= ' ' . _("Configuration files written.");
			if ($reboot && !$res['reboot'])
			{
				$return['message'] .= ' ' . _("The phone did not accept the reboot request (not registered?).");
			}
		}
		return $return;
	}

	private function epm_devices_delete(array $ids, bool $remove_files)
	{
		$removed = 0;
		foreach ($ids as $id)
		{
			$device = $this->epm->devices->getDevice($id);
			if (!$device)
			{
				continue;
			}
			if ($remove_files)
			{
				$this->epm->provisioning->removeFiles($device['mac']);
			}
			$this->epm->devices->deleteDevice($id);
			$removed++;
		}
		return array("status" => true, "message" => sprintf(_("%d device(s) deleted!"), $removed));
	}

	private function epm_devices_rebuild(array $ids, bool $reboot)
	{
		if (empty($ids))
		{
			return array("status" => false, "message" => _("No Phones Selected!"));
		}
		$res    = $this->epm->provisioning->rebuild($ids, $reboot);
		$errors = array();
		foreach ($res as $id => $r)
		{
			if (!$r['status'])
			{
				$errors[] = sprintf("%s: %s", $this->macs([$id])[0] ?? $id, $r['message']);
			}
		}
		$ok = count($res) - count($errors);
		$message = $reboot ? sprintf(_("Rebuilt configs & rebooted %d phone(s)."), $ok) : sprintf(_("Rebuilt configs for %d phone(s)."), $ok);
		if (!empty($errors))
		{
			$message .= ' ' . _("Errors:") . ' ' . implode('; ', $errors);
		}
		return array("status" => empty($errors), "message" => $message, "result" => $res);
	}

	private function request_ids(array $request)
	{
		$ids = $request['ids'] ?? array();
		if (!is_array($ids))
		{
			$ids = explode(',', $ids);
		}
		return array_values(array_filter(array_map('intval', $ids)));
	}

	private function macs(array $ids)
	{
		$out = array();
		foreach ($ids as $id)
		{
			$d     = $this->epm->devices->getDevice((int)$id);
			$out[] = $d ? $d['mac'] : (string)$id;
		}
		return $out;
	}

	/**
	 * Format used by epm_global_options_list_ajax(): options [{id, name, is_select, ...extra}]
	 */
	private function options(array $rows, $selected, array $extra = array())
	{
		$options = array();
		foreach ($rows as $row)
		{
			$opt = array('id' => $row['id'], 'name' => $row['name'], 'is_select' => ($selected !== null && (string)$selected === (string)$row['id']));
			foreach ($extra as $k)
			{
				$opt[$k] = $row[$k] ?? null;
			}
			$options[] = $opt;
		}
		return array("status" => true, "message" => "OK", "options" => $options);
	}
}
