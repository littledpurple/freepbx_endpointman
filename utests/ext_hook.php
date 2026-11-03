<?php
/**
 * Блок End Point Manager на странице внутреннего номера — без браузера, через встроенный сервер PHP.
 *   GET  ...?display=extensions&extdisplay=141            — HTML блока (doGuiHook)
 *   POST ...?display=extensions  action=edit&extdisplay=141&epm_mac=...  — сохранение (doConfigPageInit)
 * Пример:
 *   runuser -u asterisk -- php -S 127.0.0.1:18090 utests/ext_hook.php &
 *   curl 'http://127.0.0.1:18090/?display=extensions&extdisplay=141'
 */
$bootstrap_settings['freepbx_auth'] = false;
include '/etc/freepbx.conf';
defined('FREEPBX_IS_AUTH') || define('FREEPBX_IS_AUTH', true);
$_SERVER += ['SERVER_ADDR' => '127.0.0.1'];
session_start();
$admin = FreePBX::Database()->query("SELECT username FROM ampusers WHERE sections LIKE '%*%' LIMIT 1")->fetchColumn();
$_SESSION['AMP_user'] = new ampuser($admin);

$epm     = FreePBX::Endpointman();
$display = $_GET['display'] ?? 'extensions';
try
{
	if ($_SERVER['REQUEST_METHOD'] === 'POST')
	{
		$epm->doConfigPageInit($display);
		echo "processed\n";
		echo "session error: ", json_encode($_SESSION['endpointman_ext_error'] ?? []), "\n";
		return;
	}
	global $CC;
	$cc = $CC = new component($display);
	$epm->doGuiHook($cc);
	$r = new ReflectionObject($cc);
	foreach (['guielems_middle', 'jsfuncs'] as $prop)
	{
		$p = $r->getProperty($prop);
		$p->setAccessible(true);
		$v = $p->getValue($cc);
		if ($prop === 'jsfuncs')
		{
			echo "JS: ", implode(', ', array_keys($v ?? [])), "\n";
			continue;
		}
		foreach (($v ?? []) as $cat => $sections)
		{
			foreach ($sections as $section => $orders)
			{
				echo "== [$cat] $section\n";
				foreach ($orders as $order => $elems)
				{
					if (!is_array($elems)) { continue; }
					foreach ($elems as $e)
					{
						echo preg_replace('/\s+/', ' ', strip_tags($e->generatehtml(), '<input><select><option><a><div>')), "\n";
					}
				}
			}
		}
	}
}
catch (\Throwable $e)
{
	printf("EXCEPTION %s: %s @ %s:%d\n", get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
}
