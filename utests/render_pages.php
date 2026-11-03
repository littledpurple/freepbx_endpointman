<?php
/**
 * Рендер страниц модуля без браузера: ловит фатальные ошибки и исключения PHP 8.
 *
 * Страницы читают запрос через freepbxGetSanitizedRequest() (filter_input), поэтому каждая страница
 * запрашивается у встроенного сервера PHP, где этот же файл работает роутером.
 *
 *   runuser -u asterisk -- php utests/render_pages.php ["display[:subpage][?k=v&...]" ...]
 *   EPM_DUMP=/tmp/page.html — сохранить HTML последней страницы
 */
const EPM_RENDER_PORT = 18089;

if (PHP_SAPI === 'cli')
{
	$pages = array_slice($argv, 1) ?: [
		'epm_oss', 'epm_devices', 'epm_placeholders', 'epm_config',
		'epm_advanced', 'epm_advanced:settings', 'epm_advanced:oui_manager', 'epm_advanced:poce', 'epm_advanced:iedl', 'epm_advanced:manual_upload',
		'epm_templates', 'epm_templates:manager',
	];
	$server = proc_open(sprintf('exec php -S 127.0.0.1:%d %s', EPM_RENDER_PORT, escapeshellarg(__FILE__)), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
	usleep(400000);
	$fail = 0;
	foreach ($pages as $p)
	{
		[$path, $query] = array_pad(explode('?', $p, 2), 2, '');
		[$display, $subpage] = array_pad(explode(':', $path, 2), 2, '');
		$url  = sprintf('http://127.0.0.1:%d/?display=%s&subpage=%s&%s&__label=%s', EPM_RENDER_PORT, urlencode($display), urlencode($subpage), $query, urlencode($p));
		$line = trim((string)@file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120, 'ignore_errors' => true]])));
		$line = $line === '' ? sprintf("%-40s FAIL no output (fatal before buffering?)", $p) : $line;
		echo $line, "\n";
		if (!preg_match('/^\S+\s+OK\s/', $line)) { $fail++; }
	}
	proc_terminate($server);
	exit($fail ? 1 : 0);
}

// --- router of the built-in server: render one page ---
$bootstrap_settings['freepbx_auth'] = false;
include '/etc/freepbx.conf';
defined('FREEPBX_IS_AUTH') || define('FREEPBX_IS_AUTH', true);
$_SERVER += ['SERVER_ADDR' => '127.0.0.1'];	// Apache sets it, the built-in server does not
$label   = $_GET['__label'] ?? '';
$display = $_GET['display'] ?? '';

// сессия первого администратора FreePBX — страницам нужен $_SESSION['AMP_user']
$admin = FreePBX::Database()->query("SELECT username FROM ampusers WHERE sections LIKE '%*%' LIMIT 1")->fetchColumn();
$_SESSION = ['AMP_user' => new ampuser($admin)];

$epm = FreePBX::Endpointman();
register_shutdown_function(function () use ($label) {
	$err = error_get_last();
	if (defined('EPM_RENDER_DONE')) { return; }
	while (ob_get_level()) { $buf = ob_get_clean(); }
	if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]))
	{
		printf("%-40s FAIL fatal: %s @ %s:%d\n", $label, $err['message'], basename($err['file']), $err['line']);
	}
	else
	{
		printf("%-40s FAIL page stopped (die/exit): %s\n", $label, substr(trim(strip_tags($buf ?? '')), -150));
	}
});

ob_start();
try
{
	global $amp_conf;
	$epm->doConfigPageInit($display);
	include sprintf('%s/page.%s.php', $epm->MODULE_PATH, $display);
	echo $epm->getRightNav($_GET);
	$epm->getActionBar($_GET);
	$out = ob_get_clean();
	define('EPM_RENDER_DONE', 1);
	$bad = preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Page Not Found|Uncaught|not found)/i', $out, $m) ? $m[1] : '';
	if (getenv('EPM_DUMP')) { file_put_contents(getenv('EPM_DUMP'), $out); }
	printf("%-40s %s %6d bytes %s\n", $label, $bad ? 'WARN' : 'OK  ', strlen($out), $bad);
}
catch (\Throwable $e)
{
	while (ob_get_level()) { ob_end_clean(); }
	define('EPM_RENDER_DONE', 1);
	printf("%-40s FAIL %s: %s @ %s:%d\n", $label, (new ReflectionClass($e))->getShortName(), $e->getMessage(), str_replace($epm->MODULE_PATH . '/', '', $e->getFile()), $e->getLine());
}
