<?php
/**
 * Endpoint Manager - Devices
 *
 * Managed phones (endpointman_mac_list) and their lines (endpointman_line_list).
 * Replaces add_device, add_line, update_device, update_line, delete_line, delete_device, display_registration_list and discover_new of 14.0.x.
 *
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */

namespace FreePBX\modules\Endpointman;

class Devices
{
	const TABLES = [
		'mac_list'      => 'endpointman_mac_list',
		'line_list'     => 'endpointman_line_list',
		'model_list'    => 'endpointman_model_list',
		'brand_list'    => 'endpointman_brand_list',
		'product_list'  => 'endpointman_product_list',
		'template_list' => 'endpointman_template_list',
		'oui_list'      => 'endpointman_oui_list',
	];

	// endpointman_line_list.description is varchar(20)
	const DESCRIPTION_MAX = 20;

	public $epm = null;
	protected $db = null;
	protected $system = null;

	public function __construct($epm = null)
	{
		if (empty($epm))
		{
			throw new \Exception(_("Endpoint Manager object is required"));
		}
		$this->epm    = $epm;
		$this->db     = $epm->db;
		$this->system = $epm->system;
	}


	/**
	 * All managed devices with brand, model, template and lines.
	 */
	public function listDevices(): array
	{
		$sql = sprintf("SELECT mac.id, mac.mac, mac.template_id, mac.model AS model_id,
					model.model, model.max_lines, model.enabled AS model_enabled, brand.id AS brand_id, brand.name AS brand, brand.directory,
					product.id AS product_id, product.short_name AS product, tpl.name AS template_name,
					(mac.global_custom_cfg_data IS NOT NULL AND mac.global_custom_cfg_data != '') AS has_custom,
					COALESCE(mac.disabled, 0) AS disabled
				FROM %s AS mac
				LEFT JOIN %s AS model ON model.id = mac.model
				LEFT JOIN %s AS brand ON brand.id = model.brand
				LEFT JOIN %s AS product ON product.id = model.product_id
				LEFT JOIN %s AS tpl ON tpl.id = mac.template_id
				ORDER BY mac.id",
			self::TABLES['mac_list'], self::TABLES['model_list'], self::TABLES['brand_list'], self::TABLES['product_list'], self::TABLES['template_list']);
		$devices = [];
		foreach ($this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			$row['lines'] = [];
			$devices[$row['id']] = $row;
		}

		$sql = sprintf("SELECT line.luid, line.mac_id, line.line, line.ext, line.description, line.ipei, devices.tech
				FROM %s AS line LEFT JOIN devices ON devices.id = line.ext ORDER BY line.mac_id, line.line", self::TABLES['line_list']);
		foreach ($this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $line)
		{
			if (isset($devices[$line['mac_id']]))
			{
				$devices[$line['mac_id']]['lines'][] = $line;
			}
		}
		return array_values($devices);
	}

	public function getDevice(int $id): ?array
	{
		foreach ($this->listDevicesWhere('mac.id = :v', $id) as $device)
		{
			return $device;
		}
		return null;
	}

	public function getDeviceByMac(string $mac): ?array
	{
		$mac = $this->system->mac_check_clean($mac);
		if (!$mac)
		{
			return null;
		}
		foreach ($this->listDevicesWhere('mac.mac = :v', $mac) as $device)
		{
			return $device;
		}
		return null;
	}

	/**
	 * Device and line of an extension (an extension can be on one device only, unless show_all_registrations).
	 *
	 * @return array|null ['device' => array, 'line' => array]
	 */
	public function getDeviceByExt(string $ext): ?array
	{
		$stmt = $this->db->prepare(sprintf("SELECT * FROM %s WHERE ext = :ext ORDER BY luid LIMIT 1", self::TABLES['line_list']));
		$stmt->execute([':ext' => $ext]);
		$line = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (!$line)
		{
			return null;
		}
		$device = $this->getDevice((int)$line['mac_id']);
		return $device ? ['device' => $device, 'line' => $line] : null;
	}

	private function listDevicesWhere(string $where, $value): array
	{
		$stmt = $this->db->prepare(sprintf("SELECT mac.id FROM %s AS mac WHERE %s", self::TABLES['mac_list'], $where));
		$stmt->execute([':v' => $value]);
		$ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
		return array_values(array_filter($this->listDevices(), fn($d) => in_array($d['id'], $ids)));
	}


	/**
	 * Add a device.
	 *
	 * @param string $mac MAC address (any format)
	 * @param int $model_id Model ID (endpointman_model_list)
	 * @param int $template_id Template ID, 0 = custom settings of this device
	 * @param array $lines [['line' => 1, 'ext' => '141', 'description' => optional], ...]
	 * @return int New device ID
	 * @throws \Exception Validation errors
	 */
	public function addDevice(string $mac, int $model_id, int $template_id = 0, array $lines = []): int
	{
		$clean = $this->system->mac_check_clean($mac);
		if (!$clean)
		{
			throw new \Exception(sprintf(_("Invalid MAC Address '%s'!"), $mac));
		}
		if ($this->getDeviceByMac($clean))
		{
			throw new \Exception(sprintf(_("The MAC Address '%s' is already managed!"), $clean));
		}
		$model = $this->checkModel($model_id);
		$this->checkTemplate($template_id, $model);
		$lines = $this->checkLines($lines, $model, null);

		$this->db->beginTransaction();
		try
		{
			$stmt = $this->db->prepare(sprintf("INSERT INTO %s (mac, model, template_id, global_custom_cfg_data, global_user_cfg_data, config_files_override) VALUES (:mac, :model, :template, '', '', '')", self::TABLES['mac_list']));
			$stmt->execute([':mac' => $clean, ':model' => $model_id, ':template' => $template_id]);
			$id = (int)$this->db->lastInsertId();
			foreach ($lines as $line)
			{
				$this->insertLine($id, $line);
			}
			$this->db->commit();
		}
		catch (\Throwable $e)
		{
			$this->db->rollBack();
			throw $e;
		}
		return $id;
	}

	/**
	 * Update a device: MAC, model, template and the complete list of lines (lines not in the list are removed).
	 */
	public function updateDevice(int $id, string $mac, int $model_id, int $template_id, array $lines): void
	{
		$device = $this->getDevice($id);
		if (!$device)
		{
			throw new \Exception(sprintf(_("Device ID '%s' not found!"), $id));
		}
		$clean = $this->system->mac_check_clean($mac);
		if (!$clean)
		{
			throw new \Exception(sprintf(_("Invalid MAC Address '%s'!"), $mac));
		}
		$other = $this->getDeviceByMac($clean);
		if ($other && $other['id'] != $id)
		{
			throw new \Exception(sprintf(_("The MAC Address '%s' is already managed!"), $clean));
		}
		$model = $this->checkModel($model_id);
		$this->checkTemplate($template_id, $model);
		$lines = $this->checkLines($lines, $model, $id);

		$this->db->beginTransaction();
		try
		{
			// Model changed: the custom values belong to the old model's template
			$reset = ($device['model_id'] != $model_id) ? ", global_custom_cfg_data = '', global_user_cfg_data = '', config_files_override = ''" : "";
			$stmt  = $this->db->prepare(sprintf("UPDATE %s SET mac = :mac, model = :model, template_id = :template %s WHERE id = :id", self::TABLES['mac_list'], $reset));
			$stmt->execute([':mac' => $clean, ':model' => $model_id, ':template' => $template_id, ':id' => $id]);

			// Keep the rows (luid, per-line custom data) of the line numbers that stay
			$current = array_column($device['lines'], 'luid', 'line');
			$update  = $this->db->prepare(sprintf("UPDATE %s SET ext = :ext, description = :description, ipei = :ipei WHERE luid = :luid", self::TABLES['line_list']));
			foreach ($lines as $line)
			{
				if (isset($current[$line['line']]))
				{
					$update->execute([':ext' => $line['ext'], ':description' => $line['description'], ':ipei' => $line['ipei'], ':luid' => $current[$line['line']]]);
					unset($current[$line['line']]);
				}
				else
				{
					$this->insertLine($id, $line);
				}
			}
			$delete = $this->db->prepare(sprintf("DELETE FROM %s WHERE luid = :luid", self::TABLES['line_list']));
			foreach ($current as $luid)
			{
				$delete->execute([':luid' => $luid]);
			}
			$this->db->commit();
		}
		catch (\Throwable $e)
		{
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Add one line (extension) to an existing device. Line number = first free one if not given.
	 */
	public function addLine(int $id, string $ext, ?int $line = null, ?string $description = null): int
	{
		$device = $this->getDevice($id);
		if (!$device)
		{
			throw new \Exception(sprintf(_("Device ID '%s' not found!"), $id));
		}
		$lines = array_map(fn($l) => ['line' => $l['line'], 'ext' => $l['ext'], 'description' => $l['description'], 'ipei' => $l['ipei']], $device['lines']);
		if (empty($line))
		{
			$used = array_column($lines, 'line');
			for ($line = 1; in_array($line, $used); $line++);
		}
		$lines[] = ['line' => $line, 'ext' => $ext, 'description' => $description];
		$this->updateDevice($id, $device['mac'], (int)$device['model_id'], (int)$device['template_id'], $lines);
		return $line;
	}

	/**
	 * Delete a line; the device is deleted with its last line only if $allow_device_remove.
	 *
	 * @return bool True if the device was removed too.
	 */
	public function deleteLine(int $luid, bool $allow_device_remove = false): bool
	{
		$stmt = $this->db->prepare(sprintf("SELECT mac_id FROM %s WHERE luid = :luid", self::TABLES['line_list']));
		$stmt->execute([':luid' => $luid]);
		$mac_id = $stmt->fetchColumn();
		if ($mac_id === false)
		{
			throw new \Exception(sprintf(_("Line '%s' not found!"), $luid));
		}
		$stmt = $this->db->prepare(sprintf("SELECT COUNT(*) FROM %s WHERE mac_id = :id", self::TABLES['line_list']));
		$stmt->execute([':id' => $mac_id]);
		if ($stmt->fetchColumn() <= 1)
		{
			if (!$allow_device_remove)
			{
				throw new \Exception(_("You can't remove the only line left!"));
			}
			$this->deleteDevice((int)$mac_id);
			return true;
		}
		$stmt = $this->db->prepare(sprintf("DELETE FROM %s WHERE luid = :luid", self::TABLES['line_list']));
		$stmt->execute([':luid' => $luid]);
		return false;
	}

	/**
	 * Switch the provisioning of devices off/on: a disabled device gets no files (p.php answers 404, nothing is written),
	 * it stays in the list with its lines and settings.
	 *
	 * @return int Number of devices changed
	 */
	public function setDisabled(array $ids, bool $disabled): int
	{
		$stmt  = $this->db->prepare(sprintf("UPDATE %s SET disabled = :disabled WHERE id = :id", self::TABLES['mac_list']));
		$count = 0;
		foreach (array_unique(array_map('intval', $ids)) as $id)
		{
			$stmt->execute([':disabled' => $disabled ? 1 : 0, ':id' => $id]);
			$count += $stmt->rowCount();
		}
		return $count;
	}

	public function deleteDevice(int $id): void
	{
		$stmt = $this->db->prepare(sprintf("DELETE FROM %s WHERE mac_id = :id", self::TABLES['line_list']));
		$stmt->execute([':id' => $id]);
		$stmt = $this->db->prepare(sprintf("DELETE FROM %s WHERE id = :id", self::TABLES['mac_list']));
		$stmt->execute([':id' => $id]);
	}

	/**
	 * Refresh line descriptions from the FreePBX device names (after renaming an extension).
	 */
	public function syncDescriptions(?int $id = null): void
	{
		$sql = sprintf("UPDATE %s AS line JOIN devices ON devices.id = line.ext SET line.description = LEFT(devices.description, %d)%s",
			self::TABLES['line_list'], self::DESCRIPTION_MAX, $id ? " WHERE line.mac_id = :id" : "");
		$stmt = $this->db->prepare($sql);
		$stmt->execute($id ? [':id' => $id] : []);
	}


	/**
	 * SIP/PJSIP extensions that can be assigned to a line.
	 *
	 * @param int|null $mac_id Also include the extensions already on this device.
	 */
	public function availableExtensions(?int $mac_id = null): array
	{
		$sql = sprintf("SELECT devices.id, devices.description, devices.tech, line.mac_id
				FROM devices LEFT JOIN %s AS line ON line.ext = devices.id
				WHERE devices.tech IN ('sip', 'pjsip') ORDER BY CAST(devices.id AS UNSIGNED), devices.id", self::TABLES['line_list']);
		$all  = (bool)$this->epm->getConfig('show_all_registrations');
		$out  = [];
		foreach ($this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row)
		{
			if (isset($out[$row['id']])) { continue; }
			if (!$all && $row['mac_id'] !== null && $row['mac_id'] != $mac_id) { continue; }
			$out[$row['id']] = ['id' => $row['id'], 'name' => sprintf("%s - %s", $row['id'], $row['description']), 'tech' => $row['tech']];
		}
		return array_values($out);
	}

	/**
	 * Brands that are installed and have enabled models.
	 */
	public function brands(): array
	{
		$sql = sprintf("SELECT DISTINCT brand.id, brand.name FROM %s AS brand JOIN %s AS model ON model.brand = brand.id
				WHERE brand.installed = 1 AND brand.hidden = 0 AND model.enabled = 1 AND model.hidden = 0 ORDER BY brand.name",
			self::TABLES['brand_list'], self::TABLES['model_list']);
		return $this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Enabled models, optionally of one brand.
	 */
	public function models(?int $brand_id = null): array
	{
		$sql = sprintf("SELECT model.id, model.model AS name, model.max_lines, model.brand AS brand_id, model.product_id, product.short_name AS product
				FROM %s AS model JOIN %s AS product ON product.id = model.product_id
				WHERE model.enabled = 1 AND model.hidden = 0 AND product.hidden = 0 %s ORDER BY model.model",
			self::TABLES['model_list'], self::TABLES['product_list'], $brand_id ? "AND model.brand = :brand" : "");
		$stmt = $this->db->prepare($sql);
		$stmt->execute($brand_id ? [':brand' => $brand_id] : []);
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Templates that can be used with a model (same product). ID 0 = custom settings of the device.
	 */
	public function templates(int $model_id): array
	{
		$sql = sprintf("SELECT tpl.id, tpl.name FROM %s AS tpl JOIN %s AS model ON model.product_id = tpl.product_id
				WHERE model.id = :model ORDER BY tpl.name", self::TABLES['template_list'], self::TABLES['model_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':model' => $model_id]);
		return array_merge([['id' => 0, 'name' => _("Custom (this device)")]], $stmt->fetchAll(\PDO::FETCH_ASSOC));
	}

	/**
	 * Brand of a MAC address by its OUI.
	 *
	 * @return array|null ['id' => brand ID, 'name' => brand name]
	 */
	public function brandFromMac(string $mac): ?array
	{
		$clean = $this->system->mac_check_clean($mac);
		if (!$clean)
		{
			return null;
		}
		$sql  = sprintf("SELECT brand.id, brand.name FROM %s AS oui JOIN %s AS brand ON brand.id = oui.brand
				WHERE oui.oui = :oui AND brand.installed = 1 LIMIT 1", self::TABLES['oui_list'], self::TABLES['brand_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':oui' => substr($clean, 0, 6)]);
		return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * Find phones on the network: ARP cache (optionally filled by an nmap ping scan) and SIP registrations.
	 *
	 * @param string $netmask e.g. 192.168.1.0/24, only for nmap
	 * @param bool $use_nmap Run "nmap -sn" before reading the ARP cache (slow, the PBX must be in that network)
	 * @return array [['ip', 'mac', 'brand_id', 'brand', 'managed', 'ext', 'useragent'], ...]
	 */
	public function discover(string $netmask = '', bool $use_nmap = false): array
	{
		if ($use_nmap)
		{
			if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\/(\d{1,2})$/', $netmask))
			{
				throw new \Exception(sprintf(_("Invalid Netmask '%s'!"), $netmask));
			}
			$nmap = $this->epm->getConfig('nmap_location');
			if (empty($nmap) || !is_executable($nmap))
			{
				throw new \Exception(_("Could Not Find NMAP!"));
			}
			$this->epm->setConfig('nmap_search', $netmask);
			shell_exec(sprintf("%s -sn -n %s 2>/dev/null", escapeshellcmd($nmap), escapeshellarg($netmask)));
		}

		$found = [];
		// ARP cache of the PBX (phones in the same L2 network)
		foreach (@file('/proc/net/arp', FILE_IGNORE_NEW_LINES) ?: [] as $row)
		{
			$col = preg_split('/\s+/', trim($row));
			if (count($col) >= 4 && filter_var($col[0], FILTER_VALIDATE_IP) && ($mac = $this->system->mac_check_clean($col[3])) && $mac !== '000000000000')
			{
				$found[$mac] = ['ip' => $col[0], 'mac' => $mac, 'ext' => '', 'useragent' => ''];
			}
		}
		// SIP registrations: phones behind a router are not in the ARP cache, but often send their MAC in the user agent
		foreach ($this->registrations(true) as $ext => $reg)
		{
			$mac = null;
			if (preg_match('/\b([0-9a-f]{12})\b/i', $reg['useragent'], $m))
			{
				$mac = $this->system->mac_check_clean($m[1]);
			}
			foreach ($found as $k => $f)
			{
				if ($f['ip'] === $reg['ip']) { $mac = $k; }
			}
			if (!$mac)
			{
				continue;
			}
			$found[$mac] = array_merge($found[$mac] ?? ['ip' => $reg['ip'], 'mac' => $mac], ['ext' => (string)$ext, 'useragent' => $reg['useragent']]);
		}

		$managed = array_column($this->listDevices(), 'id', 'mac');
		$out     = [];
		foreach ($found as $mac => $f)
		{
			$brand = $this->brandFromMac($mac);
			if (!$brand) { continue; }   // PC, router, printer...
			$out[] = array_merge($f, [
				'brand_id' => $brand['id'],
				'brand'    => $brand['name'],
				'managed'  => isset($managed[$mac]),
			]);
		}
		usort($out, fn($a, $b) => strnatcmp($a['ext'] ?: $a['ip'], $b['ext'] ?: $b['ip']));
		return $out;
	}

	/**
	 * Current SIP registrations: [ext => ['ip', 'port', 'useragent', 'status']].
	 *
	 * @param bool $useragent Also read the user agent (one AMI command per contact - only the phone search needs it)
	 */
	public function registrations(bool $useragent = false): array
	{
		$astman = $this->epm->astman;
		if (empty($astman) || !$astman->connected())
		{
			return [];
		}
		$out = [];
		// PJSIP: one AOR per extension, "pjsip show contacts" lists ext/sip:ext@ip:port
		$res = $astman->send_request('Command', ['Command' => 'pjsip show contacts']);
		foreach (explode("\n", $res['data'] ?? '') as $row)
		{
			if (preg_match('/Contact:\s+([^\/\s]+)\/sip:[^@\s]+@\[?([0-9a-f.:]+?)\]?:(\d+)\S*\s+\S+\s+(\S+)/i', $row, $m))
			{
				$out[$m[1]] = ['ip' => $m[2], 'port' => $m[3], 'status' => $m[4], 'useragent' => ''];
			}
		}
		foreach ($useragent ? array_keys($out) : [] as $ext)
		{
			$res = $astman->send_request('Command', ['Command' => sprintf('pjsip show contact %s', $ext . '/sip:' . $ext . '@' . $out[$ext]['ip'] . ':' . $out[$ext]['port'])]);
			if (preg_match('/user_agent\s*:\s*(.+)/i', $res['data'] ?? '', $m))
			{
				$out[$ext]['useragent'] = trim($m[1]);
			}
		}
		return $out;
	}


	protected function checkModel(int $model_id): array
	{
		$sql  = sprintf("SELECT model.*, brand.installed FROM %s AS model JOIN %s AS brand ON brand.id = model.brand WHERE model.id = :id",
			self::TABLES['model_list'], self::TABLES['brand_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $model_id]);
		$model = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (empty($model) || empty($model_id))
		{
			throw new \Exception(_("You Must Select A Model From the Drop Down!"));
		}
		if (!$model['installed'])
		{
			throw new \Exception(sprintf(_("The brand of the model '%s' is not installed!"), $model['model']));
		}
		return $model;
	}

	protected function checkTemplate(int $template_id, array $model): void
	{
		if ($template_id == 0)
		{
			return;
		}
		$stmt = $this->db->prepare(sprintf("SELECT product_id FROM %s WHERE id = :id", self::TABLES['template_list']));
		$stmt->execute([':id' => $template_id]);
		$product = $stmt->fetchColumn();
		if ($product === false)
		{
			throw new \Exception(sprintf(_("Template ID '%s' not found!"), $template_id));
		}
		if ($product != $model['product_id'])
		{
			throw new \Exception(_("The template is for another product line than the selected model!"));
		}
	}

	/**
	 * Validate lines: line numbers 1..max_lines without duplicates, extensions exist and are free.
	 */
	protected function checkLines(array $lines, array $model, ?int $mac_id): array
	{
		if (empty($lines))
		{
			throw new \Exception(_("You Must Select an Extension/Device From the Drop Down!"));
		}
		$max   = max(1, (int)$model['max_lines']);
		$all   = (bool)$this->epm->getConfig('show_all_registrations');
		$seen  = [];
		$exts  = [];
		$clean = [];
		foreach ($lines as $line)
		{
			$n   = (int)($line['line'] ?? 0);
			$ext = trim((string)($line['ext'] ?? ''));
			if ($n < 1 || $n > $max)
			{
				throw new \Exception(sprintf(_("Line %s is not valid, the model '%s' has %s line(s)!"), $n, $model['model'], $max));
			}
			if (isset($seen[$n]))
			{
				throw new \Exception(sprintf(_("Line %s is assigned twice!"), $n));
			}
			if ($ext === '')
			{
				throw new \Exception(sprintf(_("Line %s has no extension!"), $n));
			}
			if (isset($exts[$ext]) && !$all)
			{
				throw new \Exception(sprintf(_("Extension %s is assigned to two lines!"), $ext));
			}

			$stmt = $this->db->prepare("SELECT description, tech FROM devices WHERE id = :id");
			$stmt->execute([':id' => $ext]);
			$dev = $stmt->fetch(\PDO::FETCH_ASSOC);
			if (!$dev || !in_array($dev['tech'], ['sip', 'pjsip']))
			{
				throw new \Exception(sprintf(_("Extension %s does not exist or is not SIP/PJSIP!"), $ext));
			}
			if (!$all)
			{
				$stmt = $this->db->prepare(sprintf("SELECT mac.mac FROM %s AS line JOIN %s AS mac ON mac.id = line.mac_id WHERE line.ext = :ext AND line.mac_id != :id",
					self::TABLES['line_list'], self::TABLES['mac_list']));
				$stmt->execute([':ext' => $ext, ':id' => $mac_id ?? 0]);
				if ($other = $stmt->fetchColumn())
				{
					throw new \Exception(sprintf(_("You can't assign the same user to multiple devices! Extension %s is on device %s."), $ext, $other));
				}
			}
			$desc = trim((string)($line['description'] ?? '')) ?: $dev['description'];
			$clean[] = [
				'line'        => $n,
				'ext'         => $ext,
				'description' => mb_substr($desc, 0, self::DESCRIPTION_MAX),
				'ipei'        => (string)($line['ipei'] ?? ''),
			];
			$seen[$n]   = true;
			$exts[$ext] = true;
		}
		usort($clean, fn($a, $b) => $a['line'] <=> $b['line']);
		return $clean;
	}

	protected function insertLine(int $mac_id, array $line): void
	{
		$stmt = $this->db->prepare(sprintf("INSERT INTO %s (mac_id, line, ext, description, ipei, custom_cfg_data, user_cfg_data) VALUES (:mac_id, :line, :ext, :description, :ipei, '', '')", self::TABLES['line_list']));
		$stmt->execute([
			':mac_id'      => $mac_id,
			':line'        => $line['line'],
			':ext'         => $line['ext'],
			':description' => $line['description'],
			':ipei'        => $line['ipei'] ?? '',
		]);
	}
}
