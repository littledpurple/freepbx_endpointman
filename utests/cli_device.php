<?php
/**
 * CLI-проверка Devices/Provisioning на живой БД.
 *   php cli_device.php gen MAC MODEL_ID EXT [OUTDIR]   — добавить временно, сгенерировать, удалить
 *   php cli_device.php <method> [json-args]           — вызвать метод Devices (list, availableExtensions...)
 * Запуск: runuser -u asterisk -- php utests/cli_device.php ...
 */
$bootstrap_settings['freepbx_auth'] = false;
include '/etc/freepbx.conf';
$epm = FreePBX::Endpointman();
$cmd = $argv[1] ?? 'list';

try
{
	switch ($cmd)
	{
		case 'gen':
			[$mac, $model, $ext, $out] = array_pad(array_slice($argv, 2), 4, null);
			$id = $epm->devices->addDevice($mac, (int)$model, 0, [['line' => 1, 'ext' => $ext]]);
			try
			{
				$gen = $epm->provisioning->generate($id);
				foreach ($gen['files'] as $name => $data)
				{
					printf("%-45s %6d\n", $name, strlen($data));
					if ($out)
					{
						@mkdir(dirname("$out/$name"), 0775, true);
						file_put_contents("$out/$name", $data);
					}
				}
				echo "protected: ", implode(', ', $gen['lib']->protected_files ?? []), "\n";
			}
			finally
			{
				$epm->devices->deleteDevice($id);
				echo "device $id removed\n";
			}
			break;

		default:
			$args = isset($argv[2]) ? json_decode($argv[2], true) : [];
			$res  = call_user_func_array([$epm->devices, $cmd], $args);
			echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
	}
}
catch (\Throwable $e)
{
	printf("ERROR %s: %s @ %s:%d\n", get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine());
	exit(1);
}
