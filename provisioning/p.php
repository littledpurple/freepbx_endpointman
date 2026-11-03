<?PHP
/**
 * Endpoint Manager - HTTP provisioning
 *
 * Phone provisioning server URL: http://<server>/provisioning/p.php (or /provisioning/ with mod_rewrite).
 * Only when "Provisioning Server Type" is HTTP or HTTPS (Advanced Settings).
 *
 * GET  <file with the MAC of a managed phone>  generated on the fly (dynamic configuration)
 * GET  <other file>                            static file from the configuration directory (firmware, dictionaries...)
 * PUT/POST logs|overrides|contacts/<MAC file>  uploads of the phone (logs, local changes, directory)
 */

const EPM_UPLOAD_DIRS = ['logs', 'overrides', 'contacts'];
const EPM_UPLOAD_MAX  = 5 * 1024 * 1024;

function epm_http_error(int $code, string $text)
{
	http_response_code($code);
	header('Content-Type: text/plain');
	echo $text, "\n";
	exit;
}

$bootstrap_settings['freepbx_auth'] = false;
if (!@include_once(getenv('FREEPBX_CONF') ? getenv('FREEPBX_CONF') : '/etc/freepbx.conf'))
{
	include_once('/etc/asterisk/freepbx.conf');
}

$epm         = FreePBX::Endpointman();
$server_type = $epm->getConfig("server_type");
if (!in_array($server_type, ['http', 'https']))
{
	epm_http_error(403, _("Access denied!"));
}

// Only the networks of the phones (Advanced Settings: HTTP Provisioning Networks)
function epm_ip_in_networks(string $ip, string $networks): bool
{
	foreach (array_filter(array_map('trim', explode(',', $networks))) as $net)
	{
		[$addr, $bits] = array_pad(explode('/', $net, 2), 2, '32');
		$mask = $bits == 0 ? 0 : (~0 << (32 - (int)$bits)) & 0xFFFFFFFF;
		if ((ip2long($ip) & $mask) === (ip2long($addr) & $mask))
		{
			return true;
		}
	}
	return false;
}
$remote   = $_SERVER['REMOTE_ADDR'] ?? '';
$networks = (string)$epm->getConfig("prov_networks");
if ($networks !== '' && !in_array($remote, ['127.0.0.1', '::1']) && !epm_ip_in_networks($remote, $networks))
{
	epm_http_error(403, _("Access denied!"));
}

// Generated file names carry the MAC as it is stored in EPM (upper case), while some phones (Digium D40) ask for
// their $mac.cfg in lower case first and only then repeat it upper case: without this lookup every boot logged a 404.
function epm_file_key(array $files, string ...$names): ?string
{
	$wanted = array_map('strtolower', $names);
	foreach (array_keys($files) as $key)
	{
		if (in_array(strtolower((string)$key), $wanted, true))
		{
			return (string)$key;
		}
	}
	return null;
}

// Files that do not exist get 404 before the authentication: the phones ask without credentials first and look for
// optional files (firmware, licenses...) on every boot - 401+404 pairs trip the "multiple 4xx" alerts (Wazuh 31151)
function epm_request_exists($epm, string $path): bool
{
	$filename = basename($path);
	if (preg_match('/([0-9a-f]{12})/i', $filename, $m) && !preg_match('/^0{10}[0-9]{2}$/', $m[1]))
	{
		$device = $epm->devices->getDeviceByMac(strtoupper($m[1]));
		if (!$device || !empty($device['disabled']))
		{
			return false;
		}
		try
		{
			$files = $epm->provisioning->generate((int)$device['id'], true)['files'];
			if (epm_file_key($files, $path, $filename) !== null)
			{
				return true;
			}
		}
		catch (\Throwable $e)
		{
			return true;	// let the normal path report the error
		}
	}
	if (preg_match('/^(spa.*\.cfg|aastra\.cfg|y0{11}[1-7]\.cfg|dialplan\.xml)$/i', $filename))
	{
		return true;
	}
	$base = rtrim((string)$epm->getConfig("config_location"), '/');
	$real = realpath($base . '/' . $path);
	return $real !== false && is_file($real) && strpos($real, realpath($base) . '/') === 0;
}

// HTTP Basic authentication (Advanced Settings: HTTP Provisioning User/Password)
// Requested path relative to the provisioning root: PATH_INFO of p.php/<path> or the rewritten URI
$path = $_SERVER['PATH_INFO'] ?? '';
if ($path === '')
{
	$path = preg_replace('#^.*/provisioning/(p\.php/?)?#', '', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
}
$path = ltrim(rawurldecode($path), '/');
if ($path === '' || strpos($path, "\0") !== false || preg_match('#(^|/)\.\.?(/|$)#', $path) || strpos($path, 'config_bkup') !== false)
{
	epm_http_error(404, _("File not Found!"));
}

$prov_user = (string)$epm->getConfig("prov_user");
if ($prov_user !== '' && in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD']) && !epm_request_exists($epm, $path))
{
	epm_http_error(404, _("File not Found!"));
}
if ($prov_user !== '')
{
	$user = $_SERVER['PHP_AUTH_USER'] ?? '';
	$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
	if (!hash_equals($prov_user, $user) || !hash_equals((string)$epm->getConfig("prov_pass"), $pass))
	{
		header('WWW-Authenticate: Basic realm="Provisioning"');
		epm_http_error(401, _("Authentication required!"));
	}
}

$config_location = rtrim((string)$epm->getConfig("config_location"), '/');
$filename        = basename($path);
$mac             = null;
if (preg_match('/([0-9a-f]{12})/i', $filename, $m) && !preg_match('/^0{10}[0-9]{2}$/', $m[1]))
{
	$mac = strtoupper($m[1]);
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');


// Uploads of the phone
if (in_array($method, ['PUT', 'POST']))
{
	$dir = explode('/', $path)[0];
	if (!$mac || !in_array($dir, EPM_UPLOAD_DIRS) || substr_count($path, '/') != 1 || !$epm->devices->getDeviceByMac($mac))
	{
		epm_http_error(403, _("Access denied!"));
	}
	$data = file_get_contents('php://input', false, null, 0, EPM_UPLOAD_MAX + 1);
	if ($data === false || strlen($data) > EPM_UPLOAD_MAX)
	{
		epm_http_error(413, _("File too large!"));
	}
	if (!is_dir($config_location . '/' . $dir))
	{
		@mkdir($config_location . '/' . $dir, 0775, true);
	}
	// logs are appended by the phone with every upload of the same name: keep the last version only
	if (file_put_contents($config_location . '/' . $path, $data) === false)
	{
		epm_http_error(500, _("System Failure!"));
	}
	http_response_code(200);
	exit;
}
if (!in_array($method, ['GET', 'HEAD']))
{
	epm_http_error(405, _("Method not allowed!"));
}


// Firmware Linksys/SPA504G-7.4.3a is broken and MUST be upgraded.
if (preg_match('/7\.4\.3a/', $_SERVER['HTTP_USER_AGENT'] ?? ''))
{
	$provis_ip = $epm->getConfig("srvip");
	echo '<flat-profile><Upgrade_Enable group="Provisioning/Firmware_Upgrade">Yes</Upgrade_Enable>';
	echo '<Upgrade_Rule group="Provisioning/Firmware_Upgrade">http://' . $provis_ip . '/current.bin</Upgrade_Rule></flat-profile>';
	exit;
}

// Provisioning disabled for this phone (Device List): no generated nor stored files for its MAC
if ($mac && ($device = $epm->devices->getDeviceByMac($mac)) && !empty($device['disabled']))
{
	epm_http_error(404, _("File not Found!"));
}

// Configuration of a managed phone, generated now
if ($mac && ($device = $epm->devices->getDeviceByMac($mac)))
{
	try
	{
		$gen = $epm->provisioning->generate((int)$device['id'], true);
	}
	catch (\Throwable $e)
	{
		freepbx_log(FPBX_LOG_ERROR, sprintf("Endpoint Manager p.php (%s): %s", $mac, $e->getMessage()));
		epm_http_error(500, _("System Failure!"));
	}
	if (($key = epm_file_key($gen['files'], $path, $filename)) !== null)
	{
		header('Content-Type: ' . (preg_match('/\.(xml|cfg)$/i', $key) && stripos(ltrim((string)$gen['files'][$key]), '<') === 0 ? 'text/xml' : 'text/plain'));
		header('Cache-Control: no-cache');
		echo $gen['files'][$key];
		exit;
	}
	// not generated (logs, licenses...): try the static files
}

// Global files of some brands (spa.cfg, aastra.cfg...)
$base = $epm->PHONE_MODULES_PATH . '/endpoint/base.php';
if (is_file($base))
{
	$epm->provisioning->legacy(function () use ($base) { require_once($base); });
	$web_path = sprintf('http://%s%s/', $_SERVER['SERVER_NAME'] ?? $epm->getConfig("srvip"), dirname($_SERVER['PHP_SELF'] ?? '/provisioning/p.php'));
	if (in_array(strtolower($filename), ['spa.cfg', 'aastra.cfg']) || preg_match('/^(spa.*\.cfg|y0{11}[1-7]\.cfg|dialplan\.xml)$/i', $filename))
	{
		$data = $epm->provisioning->legacy(function () use ($filename, $config_location, $web_path) {
			return (new Provisioner_Globals())->dynamic_global_files($filename, $config_location . '/', $web_path);
		});
		if ($data !== false && $data !== 'empty')
		{
			echo $data;
			exit;
		}
		if ($data === 'empty')
		{
			exit;	// already sent (file)
		}
	}
}

// Static file from the configuration directory
$real = realpath($config_location . '/' . $path);
if ($real !== false && is_file($real) && strpos($real, realpath($config_location) . '/') === 0)
{
	header('Content-Type: application/octet-stream');
	header('Content-Length: ' . filesize($real));
	header('Cache-Control: no-cache');
	if ($method === 'GET')
	{
		readfile($real);
	}
	exit;
}

epm_http_error(404, _("File not Found!"));
