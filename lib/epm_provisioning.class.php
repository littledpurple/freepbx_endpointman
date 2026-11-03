<?php
/**
 * Endpoint Manager - Provisioning
 *
 * Builds configuration files for a managed phone with the Provisioner.net library
 * (_ep_phone_modules), writes them to the configuration directory (TFTP) or returns
 * them for HTTP provisioning (provisioning/p.php), and asks the phone to resync.
 *
 * Port of prepare_configs/write_configs/get_phone_info from 14.0.x (includes/functions.inc).
 *
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */

namespace FreePBX\modules\Endpointman;

class Provisioning
{
	const TABLES = [
		'mac_list'       => 'endpointman_mac_list',
		'line_list'      => 'endpointman_line_list',
		'model_list'     => 'endpointman_model_list',
		'brand_list'     => 'endpointman_brand_list',
		'product_list'   => 'endpointman_product_list',
		'template_list'  => 'endpointman_template_list',
		'custom_configs' => 'endpointman_custom_configs',
	];

	// SIP NOTIFY event that makes each brand re-read its configuration (and reboot if needed)
	const RESYNC_EVENTS = [
		'polycom'     => 'check-sync',
		'yealink'     => 'check-sync;reboot=true',
		'yealinkv70'  => 'check-sync;reboot=true',
		'yealinkv80'  => 'check-sync;reboot=true',
		'snom'        => 'check-sync;reboot=true',
		'cisco'       => 'resync',
		'grandstream' => 'check-sync',
		'aastra'      => 'check-sync',
		'mitel'       => 'check-sync',
		'digium'      => 'check-sync',
		'dlink'       => 'check-sync;reboot=true',
		'default'     => 'check-sync',
	];

	// Value of the template variable {$transport} by brand for the protocol of the extension
	const TRANSPORT_VALUES = [
		'polycom' => ['udp' => 'UDPOnly', 'tcp' => 'TCPOnly', 'tls' => 'TLS'],
		'yealink' => ['udp' => '0', 'tcp' => '1', 'tls' => '2'],
		'dlink'   => ['udp' => '0', 'tcp' => '1'],
	];

	// Placeholder of {$config_version} while the files are generated: the real number depends on the content
	const CONFIG_VERSION_MARK = '@@EPM_CONFIG_VERSION@@';

	// Number of old copies kept in config_bkup/ for every file (backup_check = 0)
	const BACKUPS_KEEP = 5;

	public $epm = null;
	protected $db = null;
	protected $system = null;

	private $cache = [];

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
	 * Everything we know about a managed phone (old get_phone_info()).
	 *
	 * @param int $mac_id ID in endpointman_mac_list
	 * @return array|null Null if the device does not exist or has no valid model.
	 */
	public function getPhoneInfo(int $mac_id): ?array
	{
		$sql = sprintf("SELECT mac.id, mac.mac, mac.template_id, mac.global_custom_cfg_data, mac.global_user_cfg_data, mac.config_files_override,
					mac.global_settings_override, mac.specific_settings, COALESCE(mac.disabled, 0) AS disabled,
					model.id AS model_id, model.model, model.template_data, model.enabled, model.max_lines,
					brand.id AS brand_id, brand.name, brand.directory,
					product.id AS product_id, product.long_name, product.cfg_dir, product.cfg_ver
				FROM %s AS mac
				JOIN %s AS model ON model.id = mac.model
				JOIN %s AS brand ON brand.id = model.brand
				JOIN %s AS product ON product.id = model.product_id
				WHERE mac.id = :id",
			self::TABLES['mac_list'], self::TABLES['model_list'], self::TABLES['brand_list'], self::TABLES['product_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $mac_id]);
		$info = $stmt->fetch(\PDO::FETCH_ASSOC);
		if (empty($info))
		{
			return null;
		}

		$info['template_data_info'] = null;
		if ($info['template_id'] > 0)
		{
			$stmt = $this->db->prepare(sprintf("SELECT name, global_custom_cfg_data, config_files_override, global_settings_override FROM %s WHERE id = :id", self::TABLES['template_list']));
			$stmt->execute([':id' => $info['template_id']]);
			$info['template_data_info'] = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
		}

		$sql = sprintf("SELECT line.*, devices.tech, devices.dial, devices.devicetype, devices.user, devices.description AS device_description
				FROM %s AS line JOIN devices ON devices.id = line.ext
				WHERE line.mac_id = :id ORDER BY line.line ASC", self::TABLES['line_list']);
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':id' => $mac_id]);

		$info['line'] = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $line)
		{
			$sip = $this->getSipData($line['ext']);
			[$port, $proto] = $this->getSipPort($sip['sipdriver'] ?? 'chan_pjsip', $sip['transport'] ?? '');

			$line['secret']           = $sip['secret'] ?? '';
			$line['extension']        = $line['ext'];
			$line['user_extension']   = $line['user'];
			$line['allowedcodec']     = $sip['allow'] ?? '';
			$line['forcerport']       = $sip['force_rport'] ?? '';
			$line['media_encryption'] = $sip['media_encryption'] ?? '';
			$line['sipdriver']        = $sip['sipdriver'] ?? '';
			$line['transport']        = $sip['transport'] ?? '';
			$line['trustrpid']        = $sip['trustrpid'] ?? '';
			$line['callerid']         = $sip['callerid'] ?? '';
			$line['encryption']       = $sip['encryption'] ?? '';
			$line['server_port']      = $port;
			$line['proto']            = $proto;
			$info['line'][$line['line']] = $line;
		}
		return $info;
	}

	/**
	 * Generate all configuration files of a phone.
	 *
	 * @param int $mac_id ID in endpointman_mac_list
	 * @param bool $dynamic True for HTTP provisioning (p.php): the library builds per-MAC files with HTTP paths.
	 * @return array ['files' => [name => data], 'lib' => provisioner object, 'info' => phone info, 'settings' => global settings]
	 * @throws \Exception If the phone can not be provisioned.
	 */
	public function generate(int $mac_id, bool $dynamic = false): array
	{
		$info = $this->getPhoneInfo($mac_id);
		if (empty($info))
		{
			throw new \Exception(sprintf(_("Device ID '%s' not found or it has no valid model!"), $mac_id));
		}
		if (empty($info['line']))
		{
			throw new \Exception(sprintf(_("Device '%s' has no lines assigned!"), $info['mac']));
		}

		$settings = $this->getGlobalSettings($info);
		$lib      = $this->newProvisioner($info, $settings);

		$lib->settings = $this->buildSettings($info, $settings, $dynamic, $lib);
		$lib->mac      = $info['mac'];
		$versioned     = !empty($lib->config_version_format);
		if ($versioned)
		{
			$lib->settings['config_version'] = self::CONFIG_VERSION_MARK;
		}

		$files = $this->legacy(function () use ($lib) {
			return $lib->generate_all_files();
		});
		foreach ($files as $name => &$data)
		{
			$data = $this->fixupFile($name, $data);
			if ($versioned && is_string($data) && strpos($data, self::CONFIG_VERSION_MARK) !== false)
			{
				$data = $this->applyConfigVersion($info['mac'], $name, $data, $lib->config_version_format, (int)($lib->config_version_start ?? 1));
			}
		}
		unset($data);
		return ['files' => $files, 'lib' => $lib, 'info' => $info, 'settings' => $settings];
	}

	/**
	 * Phones that apply a file only when its version is higher than the one they have (D-Link/Fanvil "Version:2.0003"):
	 * the version of every (MAC, file) grows by one whenever the content changes, and stays the same otherwise,
	 * so the phone does not apply (and reboot) again on every download. Kept in the module kvstore, section configVersion,
	 * also after the device is deleted - a phone added again must get a higher version than the one it has.
	 *
	 * @param string $mac MAC of the phone
	 * @param string $name Name of the generated file
	 * @param string $data Content with CONFIG_VERSION_MARK
	 * @param string $format sprintf() format of the number (e.g. "2.%04d")
	 * @param int $start First number used
	 * @return string Content with the version
	 */
	protected function applyConfigVersion(string $mac, string $name, string $data, string $format, int $start): string
	{
		$key   = strtolower($mac) . '|' . $name;
		$hash  = md5($data);
		$state = $this->epm->isConfigExist($key, 'configVersion') ? $this->epm->getConfig($key, null, 'configVersion') : null;
		$state = is_array($state) ? $state : [];
		if (($state['hash'] ?? '') !== $hash)
		{
			$state = ['n' => max($start, (int)($state['n'] ?? 0) + 1), 'hash' => $hash, 'time' => time()];
			$this->epm->setConfig($key, $state, 'configVersion');
		}
		return str_replace(self::CONFIG_VERSION_MARK, sprintf($format, $state['n']), $data);
	}

	/**
	 * Generate and write the configuration of a phone (old prepare_configs + write_configs).
	 *
	 * @param int $mac_id ID in endpointman_mac_list
	 * @param bool $reboot Ask the phone to resync after writing.
	 * @return array ['mac' => string, 'files' => [written names], 'skipped' => [protected names], 'reboot' => bool|null]
	 * @throws \Exception
	 */
	public function write(int $mac_id, bool $reboot = false): array
	{
		$info = $this->getPhoneInfo($mac_id);
		if (!empty($info['disabled']))
		{
			throw new \Exception(sprintf(_("Provisioning of device '%s' is disabled!"), $info['mac']));
		}
		$gen      = $this->generate($mac_id, false);
		$lib      = $gen['lib'];
		$info     = $gen['info'];
		$path     = rtrim($gen['settings']['config_location'], '/') . '/';
		$src_path = sprintf("%s/endpoint/%s/%s/", $this->epm->PHONE_MODULES_PATH, $info['directory'], $info['cfg_dir']);
		$backup   = ($this->epm->getConfig('backup_check') != '1');

		if (!is_dir($path) || !is_writable($path))
		{
			throw new \Exception(sprintf(_("Configuration directory '%s' does not exist or is not writable!"), $path));
		}

		// Directories the phone expects (logs, overrides, contacts...) - the phone uploads into them, keep them open for tftpd
		$upload_dirs = [];
		foreach ((array)($lib->directory_structure ?? []) as $dir)
		{
			$this->mkdir($path . $dir);
			$upload_dirs[] = trim($dir, '/');
			if (is_dir($src_path . $dir))
			{
				$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src_path . $dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
				foreach ($it as $item)
				{
					if ($item->isDir())
					{
						$this->mkdir($path . substr($item->getPathname(), strlen($src_path)));
					}
				}
			}
		}

		// Static files shipped with the family (ring tones, backgrounds, dictionaries...)
		foreach ((array)($lib->copy_files ?? []) as $file)
		{
			if (!is_file($src_path . $file))
			{
				continue;
			}
			$this->mkdir(dirname($path . $file));
			if (!file_exists($path . $file) || md5_file($path . $file) !== md5_file($src_path . $file))
			{
				if (!@copy($src_path . $file, $path . $file))
				{
					throw new \Exception(sprintf(_("Could not copy file '%s'!"), $file));
				}
				@chmod($path . $file, 0664);
			}
		}

		$written   = [];
		$skipped   = [];
		$protected = (array)($lib->protected_files ?? []);
		foreach ($gen['files'] as $file => $data)
		{
			$target = $path . $file;
			$this->mkdir(dirname($target));
			if (file_exists($target) && in_array($file, $protected))
			{
				$skipped[] = $file;
				continue;
			}
			if (file_exists($target) && !is_writable($target))
			{
				throw new \Exception(sprintf(_("File '%s' is not writable!"), $target));
			}
			if ($backup && is_file($target) && !is_link($target) && $this->normalize(file_get_contents($target)) !== $this->normalize($data))
			{
				$this->backupFile($path, $file);
			}
			if (is_link($target))
			{
				unlink($target);
			}
			if (file_put_contents($target, $data) === false)
			{
				throw new \Exception(sprintf(_("File '%s' not written to hard drive!"), $target));
			}
			// tftpd (without --create) accepts uploads only into existing world-writable files
			$top = explode('/', $file)[0];
			@chmod($target, in_array($top, $upload_dirs) ? 0666 : 0664);
			$written[] = $file;

			$this->linkAdditionalMacs($path, $file, $info);
		}

		$result = ['mac' => $info['mac'], 'files' => $written, 'skipped' => $skipped, 'reboot' => null];
		if ($reboot)
		{
			$result['reboot'] = $this->reboot($mac_id, $info);
		}
		return $result;
	}

	/**
	 * Ask a phone to re-read its configuration with SIP NOTIFY (via AMI, works for chan_pjsip and chan_sip).
	 *
	 * @param int $mac_id ID in endpointman_mac_list
	 * @param array|null $info Phone info if already loaded
	 * @return bool True if the NOTIFY was accepted by Asterisk.
	 */
	public function reboot(int $mac_id, ?array $info = null): bool
	{
		$info = $info ?? $this->getPhoneInfo($mac_id);
		if (empty($info) || empty($info['line']))
		{
			return false;
		}
		$line  = reset($info['line']);
		$event = self::RESYNC_EVENTS[$info['directory']] ?? self::RESYNC_EVENTS['default'];
		$astman = $this->epm->astman;
		if (empty($astman) || !$astman->connected())
		{
			throw new \Exception(_("Asterisk Manager is not connected, the phone can not be rebooted!"));
		}

		if (($line['tech'] ?? 'pjsip') == 'sip' && ($line['sipdriver'] ?? '') == 'chan_sip')
		{
			$res = $astman->send_request('SIPnotify', ['Channel' => 'SIP/' . $line['ext'], 'Variable' => 'Event=' . $event]);
		}
		else
		{
			$res = $astman->send_request('PJSIPNotify', ['Endpoint' => $line['ext'], 'Variable' => 'Event=' . $event]);
		}
		return (($res['Response'] ?? '') === 'Success');
	}

	/**
	 * Remove the files generated for a MAC address and their backups (they contain the SIP passwords). Shared files are kept.
	 */
	public function removeFiles(string $mac): int
	{
		$mac  = $this->system->mac_check_clean($mac);
		$path = rtrim((string)$this->epm->getConfig('config_location'), '/');
		if (!$mac || empty($path) || !is_dir($path))
		{
			return 0;
		}
		$count = 0;
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file)
		{
			if (stripos($file->getFilename(), $mac) !== false)
			{
				$count += @unlink($file->getPathname()) ? 1 : 0;
			}
		}
		return $count;
	}

	/**
	 * IDs of the devices that use a template, a model, a product or a brand.
	 */
	public function getDeviceIds(string $by = 'all', $value = null): array
	{
		$where = [
			'all'      => '1 = 1',
			'template' => 'mac.template_id = :v',
			'model'    => 'mac.model = :v',
			'product'  => 'model.product_id = :v',
			'brand'    => 'model.brand = :v',
		];
		if (!isset($where[$by]))
		{
			throw new \Exception(sprintf(_("Invalid filter '%s'!"), $by));
		}
		// devices with provisioning disabled are left out (Rebuild All, rebuild after saving a template)
		$sql  = sprintf("SELECT mac.id FROM %s AS mac JOIN %s AS model ON model.id = mac.model WHERE %s AND COALESCE(mac.disabled, 0) = 0 ORDER BY mac.id",
			self::TABLES['mac_list'], self::TABLES['model_list'], $where[$by]);
		$stmt = $this->db->prepare($sql);
		$stmt->execute($by == 'all' ? [] : [':v' => $value]);
		return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
	}

	/**
	 * Rebuild (and optionally reboot) several devices; errors of one device do not stop the others.
	 *
	 * @return array [mac_id => ['status' => bool, 'message' => string, ...write() result]]
	 */
	public function rebuild(array $ids, bool $reboot = false): array
	{
		$out = [];
		foreach ($ids as $id)
		{
			try
			{
				$out[$id] = array_merge(['status' => true, 'message' => _("OK")], $this->write((int)$id, $reboot));
			}
			catch (\Throwable $e)
			{
				$out[$id] = ['status' => false, 'message' => $e->getMessage()];
			}
		}
		return $out;
	}


	/**
	 * Global settings (srvip, ntp, config_location, tz, server_type) with the template/device override.
	 */
	public function getGlobalSettings(array $info): array
	{
		$settings = [
			'srvip'           => $this->epm->getConfig('srvip'),
			'ntp'             => $this->epm->getConfig('ntp'),
			'config_location' => $this->epm->getConfig('config_location'),
			'tz'              => $this->epm->getConfig('tz'),
			'server_type'     => $this->epm->getConfig('server_type'),
		];
		$override = ($info['template_id'] > 0) ? ($info['template_data_info']['global_settings_override'] ?? '') : ($info['global_settings_override'] ?? '');
		$override = $this->unserialize($override, true);
		foreach ($override as $key => $value)
		{
			if ($value !== '' && $value !== null)
			{
				$settings[$key] = $value;
			}
		}
		return $settings;
	}

	protected function newProvisioner(array $info, array $settings)
	{
		$base  = $this->epm->PHONE_MODULES_PATH;
		$class = sprintf("endpoint_%s_%s_phone", $info['directory'], $info['cfg_dir']);
		if (!defined('PROVISIONER_BASE'))
		{
			define('PROVISIONER_BASE', $base . '/');
		}
		$files = ["endpoint/base.php", sprintf("endpoint/%s/base.php", $info['directory']), sprintf("endpoint/%s/%s/phone.php", $info['directory'], $info['cfg_dir'])];
		$this->legacy(function () use ($base, $files) {
			foreach ($files as $file)
			{
				if (!is_file($base . '/' . $file))
				{
					throw new \Exception(sprintf(_("Provisioner file '%s' not found, is the brand package installed?"), $file));
				}
				require_once($base . '/' . $file);
			}
		});
		if (!class_exists($class))
		{
			throw new \Exception(sprintf(_("Can't Load \"%s\" Class!"), $class));
		}

		$lib = $this->legacy(function () use ($class) { return new $class(); });
		$lib->root_dir        = $base . '/';
		$lib->engine          = 'asterisk';
		$lib->engine_location = $this->epm->getConfig('asterisk_location') ?: 'asterisk';
		$lib->system          = 'unix';
		$lib->brand_name      = $info['directory'];
		$lib->family_line     = $info['cfg_dir'];
		$lib->model           = $info['model'];  // must match family_data.json word for word
		try
		{
			$lib->DateTimeZone = new \DateTimeZone($settings['tz'] ?: date_default_timezone_get());
		}
		catch (\Exception $e)
		{
			throw new \Exception(sprintf(_("Invalid time zone '%s': %s"), $settings['tz'], $e->getMessage()));
		}

		// Alternate configuration files (Template Manager → "config files") instead of the ones from the package
		$override = ($info['template_id'] > 0) ? ($info['template_data_info']['config_files_override'] ?? '') : ($info['config_files_override'] ?? '');
		foreach ($this->unserialize($override) as $custom_id)
		{
			$stmt = $this->db->prepare(sprintf("SELECT original_name, data FROM %s WHERE id = :id", self::TABLES['custom_configs']));
			$stmt->execute([':id' => $custom_id]);
			if ($row = $stmt->fetch(\PDO::FETCH_ASSOC))
			{
				// copies saved by POCE before 17.0.2 are html-encoded (&lt;?xml ...)
				if (preg_match('/^\s*&lt;/', $row['data']))
				{
					$row['data'] = html_entity_decode($row['data'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
				}
				$lib->config_files_override[$row['original_name']] = $row['data'];
			}
		}
		return $lib;
	}

	/**
	 * Settings array for the provisioner library: template values, line data and placeholders.
	 */
	protected function buildSettings(array $info, array $settings, bool $dynamic, $lib): array
	{
		$ari_enabled = ($this->epm->getConfig('enable_ari') == 1);
		$user_data   = $this->unserialize($info['global_user_cfg_data']);
		$custom      = $this->unserialize(($info['template_id'] > 0) ? ($info['template_data_info']['global_custom_cfg_data'] ?? '') : $info['global_custom_cfg_data']);
		$custom_ari  = $custom['ari'] ?? [];
		$custom      = $custom['data'] ?? [];

		// Template keys: "name", "loop|name_item_count", "line|N|name"
		$data     = [];
		$line_ops = [];
		foreach ($custom as $full_key => $value)
		{
			if ($ari_enabled && isset($custom_ari[$full_key]) && isset($user_data[$full_key]))
			{
				$value = $user_data[$full_key];
			}
			$key = explode('|', $full_key);
			switch (count($key))
			{
				case 1:
					$data[$full_key] = $value;
					break;
				case 2:
					$breaks = explode('_', $key[1]);
					$data['loops'][$breaks[0]][$breaks[2] ?? ''][$breaks[1] ?? ''] = $value;
					break;
				case 3:
					$line_ops[$key[1]][$key[2]] = $value;
					break;
			}
		}

		if ($dynamic)
		{
			$data['provision'] = [
				'type'       => 'dynamic',
				'protocol'   => 'http',
				'path'       => rtrim($settings['srvip'] . dirname($_SERVER['REQUEST_URI'] ?? '/provisioning/p.php/x') . '/', '/'),
				'encryption' => false,
			];
		}
		else
		{
			$data['provision'] = ['type' => 'file', 'protocol' => 'tftp', 'path' => $settings['srvip'], 'encryption' => false];
		}
		$data['ntp'] = $settings['ntp'];

		$sysadmin = $this->getSysadminSettings();
		$globals  = [
			'server_host'         => $settings['srvip'],
			'primtimeserver'      => $settings['ntp'],
			'globaladminpassword' => $this->epm->getConfig('adminpass'),
			'globaluserpassword'  => $this->epm->getConfig('userpass'),
			'prov_user'           => (string)$this->epm->getConfig('prov_user'),
			'prov_pass'           => (string)$this->epm->getConfig('prov_pass'),
			'provisuser'          => $sysadmin['provisuser'] ?? '',
			'provispass'          => $sysadmin['provispass'] ?? '',
			'sslhpro'             => $sysadmin['sslhpro'] ?? '',
			'hpro'                => $sysadmin['hpro'] ?? '',
			'myvoicemail'         => $this->getFeatureCode('myvoicemail'),
			'dnd_on'              => $this->getFeatureCode('dnd_on'),
			'dnd_off'             => $this->getFeatureCode('dnd_off'),
			'dnd_toggle'          => $this->getFeatureCode('dnd_toggle'),
			'timestamp'           => time(),
		];
		$data = array_merge($data, $globals);

		$data['line'] = [];
		foreach ($info['line'] as $line)
		{
			$n = $line['line'];
			$proto_num = ['udp' => '0', 'tcp' => '1', 'tls' => '2'][$line['proto']] ?? '0';
			$srtp      = ($line['encryption'] == 'yes' || $line['media_encryption'] == 'sdes');
			$statics = [
				'line'             => $n,
				'username'         => $line['ext'],
				'authname'         => $line['ext'],
				'secret'           => $line['secret'],
				'displayname'      => $line['description'],
				'server_host'      => $settings['srvip'],
				'server_port'      => $line['server_port'],
				'proto'            => $line['proto'],
				'user_extension'   => $line['user_extension'],
				'extension'        => $line['ext'],
				'allowedcodec'     => $line['allowedcodec'],
				'forcerport'       => $line['forcerport'],
				'media_encryption' => $line['media_encryption'],
				'sipdriver'        => $line['sipdriver'],
				'transport'        => $line['transport'],
				'trustrpid'        => $line['trustrpid'],
				'callerid'         => $line['callerid'],
				'encryption'       => $line['encryption'],
				'tech'             => $line['tech'],
				'ipei'             => $line['ipei'],
				'gsproto'          => $proto_num,
				'gsSRTP'           => $srtp ? '1' : '0',
				'yealinktransport' => $proto_num,
				'yealinksrtp'      => $srtp ? '2' : '0',
			] + $globals;

			// "accN<name>" placeholders - line values usable outside the line loop
			foreach (['secret', 'displayname', 'user_extension', 'username', 'authname', 'extension', 'allowedcodec', 'forcerport',
					  'media_encryption', 'sipdriver', 'transport', 'trustrpid', 'callerid', 'encryption', 'server_port', 'ipei',
					  'gsproto', 'gsSRTP', 'yealinktransport', 'yealinksrtp'] as $name)
			{
				$data['acc' . $n . $name] = $statics[$name];
			}
			// "transport" is also a variable of the brand templates (Polycom: UDPOnly/TCPOnly/TLS, Yealink: 0/1/2):
			// give them the extension's protocol in their own format, otherwise leave the template default
			unset($statics['transport']);
			$brand_transport = self::TRANSPORT_VALUES[preg_replace('/v\d+$/', '', $info['directory'])][$line['proto']] ?? null;
			if ($brand_transport !== null)
			{
				$statics['transport'] = $brand_transport;
			}
			$data['line'][] = array_merge($line_ops[$n] ?? [], $statics);
		}

		// Device specific settings (network etc.)
		$specific = $this->unserialize($info['specific_settings']);
		foreach (($specific['data'] ?? []) as $key => $value)
		{
			$exp = explode('|', $key);
			if (isset($exp[2]))
			{
				$found = false;
				foreach ($data['line'] as &$l)
				{
					if ($l['line'] == $exp[1]) { $l[$exp[2]] = $value; $found = true; }
				}
				unset($l);
				if (!$found && isset($specific['data']['line|' . $exp[1] . '|line_enabled']))
				{
					$data['line'][] = ['line' => $exp[1], $exp[2] => $value];
				}
				continue;
			}
			$network = ['connection_type' => 'connection_type', 'ip4_address' => 'ipv4', 'ip6_address' => 'ipv6',
						'subnet_mask' => 'subnet', 'gateway_address' => 'gateway', 'primary_dns' => 'primary_dns'];
			if (isset($network[$key]))
			{
				$data['network'][$network[$key]] = $value;
			}
			else
			{
				$data[$key] = $value;
			}
		}

		$data['mac'] = $info['mac'];
		// processor_info is not declared in endpoint_base (dynamic property)
		$version = $this->epm->getConfig('version');
		$this->legacy(function () use ($lib, $version) { $lib->processor_info = "EndPoint Manager Version " . $version; });
		return $data;
	}


	/**
	 * Values of an extension in the FreePBX 'sip' table (secret, transport, allow...).
	 */
	protected function getSipData(string $ext): array
	{
		$stmt = $this->db->prepare("SELECT keyword, data FROM sip WHERE id = :id");
		$stmt->execute([':id' => $ext]);
		return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
	}

	/**
	 * SIP port and protocol of an extension from its driver and transport.
	 *
	 * @return array [port, proto]
	 */
	protected function getSipPort(string $driver, string $transport): array
	{
		if ($driver == 'chan_sip')
		{
			if (!isset($this->cache['sipsettings']))
			{
				$this->cache['sipsettings'] = $this->db->query("SELECT keyword, data FROM sipsettings")->fetchAll(\PDO::FETCH_KEY_PAIR);
			}
			$proto = strtolower(explode(',', $transport ?: 'udp')[0]);
			$port  = ($proto == 'tls') ? ($this->cache['sipsettings']['tlsbindport'] ?? '5061') : ($this->cache['sipsettings']['bindport'] ?? '5060');
			return [$port ?: ($proto == 'tls' ? '5061' : '5060'), $proto];
		}

		if (!isset($this->cache['pjsip']))
		{
			$this->cache['pjsip'] = [];
			try
			{
				$this->cache['pjsip'] = $this->db->query("SELECT `key`, val FROM kvstore_Sipsettings WHERE `key` LIKE '%port-%'")->fetchAll(\PDO::FETCH_KEY_PAIR);
			}
			catch (\Exception $e) { }
		}
		// transport "0.0.0.0-udp", empty = first udp bind
		$ip    = '0.0.0.0';
		$proto = 'udp';
		if (preg_match('/^(.+)-(udp|tcp|tls|ws|wss)$/i', $transport, $m))
		{
			$ip    = $m[1];
			$proto = strtolower($m[2]);
		}
		$port = $this->cache['pjsip'][$proto . 'port-' . $ip] ?? '';
		return [$port ?: ($proto == 'tls' ? '5061' : '5060'), $proto];
	}

	protected function getFeatureCode(string $name): string
	{
		if (!isset($this->cache['featurecodes']))
		{
			$this->cache['featurecodes'] = [];
			foreach ($this->db->query("SELECT featurename, defaultcode, customcode FROM featurecodes")->fetchAll(\PDO::FETCH_ASSOC) as $row)
			{
				$this->cache['featurecodes'][$row['featurename']] = $row['customcode'] ?: $row['defaultcode'];
			}
		}
		return (string)($this->cache['featurecodes'][$name] ?? '');
	}

	/**
	 * Provisioning settings of the commercial Sysadmin module (if it is installed).
	 */
	protected function getSysadminSettings(): array
	{
		if (!isset($this->cache['sysadmin']))
		{
			$this->cache['sysadmin'] = [];
			if ($this->epm->isActiveModule('sysadmin'))
			{
				try
				{
					$this->cache['sysadmin'] = $this->db->query("SELECT `key`, `value` FROM sysadmin_options")->fetchAll(\PDO::FETCH_KEY_PAIR);
				}
				catch (\Exception $e) { }
			}
		}
		return $this->cache['sysadmin'];
	}


	/**
	 * Run code of the Provisioner.net library. It was written for PHP 5: under PHP 8 it raises deprecations,
	 * warnings and notices that FreePBX (Whoops) would turn into exceptions.
	 */
	public function legacy(callable $fn)
	{
		$level = error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING & ~E_NOTICE & ~E_USER_NOTICE);
		try
		{
			return $fn();
		}
		catch (\Throwable $e)
		{
			throw new \Exception(sprintf(_("Error Returned From Provisioner Library: %s"), $e->getMessage()), 0, $e);
		}
		finally
		{
			error_reporting($level);
		}
	}

	/**
	 * Repair known template defects of the packages (e.g. "</ pres>" in Polycom spipm) so the phone does not reject the file.
	 */
	protected function fixupFile(string $name, $data)
	{
		if (!is_string($data) || !preg_match('/\.(cfg|xml)$/i', $name) || stripos(ltrim($data), '<') !== 0)
		{
			return $data;
		}
		return preg_replace('#</\s+([A-Za-z_][\w.:-]*)\s*>#', '</$1>', $data);
	}

	// Content without the generation timestamp of the provisioner header (no backup if only that changed)
	protected function normalize($data): string
	{
		return preg_replace('/^.*Generated on:.*$/m', '', (string)$data);
	}

	protected function unserialize($data, bool $slashes = false): array
	{
		if (empty($data) || !is_string($data))
		{
			return is_array($data) ? $data : [];
		}
		$value = @unserialize($slashes ? stripslashes($data) : $data, ['allowed_classes' => false]);
		if ($value === false && !$slashes)
		{
			$value = @unserialize(stripslashes($data), ['allowed_classes' => false]);
		}
		return is_array($value) ? $value : [];
	}

	protected function mkdir(string $dir): void
	{
		if (!is_dir($dir) && !@mkdir($dir, 0775, true))
		{
			throw new \Exception(sprintf(_("Could Not Create Directory: %s"), $dir));
		}
	}

	protected function backupFile(string $path, string $file): void
	{
		$dir = $path . 'config_bkup/';
		$this->mkdir(dirname($dir . $file));
		@copy($path . $file, sprintf("%s%s.%d", $dir, $file, time()));
		$old = glob($dir . $file . '.*') ?: [];
		sort($old);
		foreach (array_slice($old, 0, max(0, count($old) - self::BACKUPS_KEEP)) as $f)
		{
			@unlink($f);
		}
	}

	/**
	 * Symlinks for "additionalmacs" (template input, comma separated): multicell systems like Snom M700 provision
	 * every base station with the files of the main one. Only files with "_additional" in the name are linked.
	 */
	protected function linkAdditionalMacs(string $path, string $file, array $info): void
	{
		if (strpos($file, '_additional') === false)
		{
			return;
		}
		$macs = [];
		foreach ([$info['global_custom_cfg_data'], $info['template_data_info']['global_custom_cfg_data'] ?? ''] as $raw)
		{
			$cfg  = $this->unserialize($raw);
			$macs = array_merge($macs, explode(',', $cfg['data']['additionalmacs'] ?? ''));
		}
		foreach (array_unique(array_filter(array_map([$this->system, 'mac_check_clean'], array_map('trim', $macs)))) as $mac)
		{
			$link = $path . str_replace([strtolower($info['mac']), strtoupper($info['mac']), '_additional'], [strtolower($mac), strtolower($mac), ''], $file);
			if (is_link($link))
			{
				unlink($link);
			}
			if (!file_exists($link))
			{
				@symlink($path . $file, $link);
			}
		}
	}
}
