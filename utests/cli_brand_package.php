<?php
/**
 * CLI-проверка Manual Upload (импорт пакета бренда) на живой БД: собирает из установленного бренда
 * тестовый пакет с другим id и каталогом, ставит, ставит повторно, проверяет отказы и удаляет.
 *   php cli_brand_package.php [SRC_BRAND_DIR] [TEST_ID] [TEST_DIR]   (по умолчанию yealink 990 epmtest)
 * Запуск: runuser -u asterisk -- php utests/cli_brand_package.php
 */
$bootstrap_settings['freepbx_auth'] = false;
include '/etc/freepbx.conf';
$epm = FreePBX::Endpointman();
[$src_dir, $test_id, $test_dir] = [$argv[1] ?? 'yealink', $argv[2] ?? '990', $argv[3] ?? 'epmtest'];

$endpoint = $epm->PHONE_MODULES_PATH . '/endpoint';
$work     = sys_get_temp_dir() . '/epm_brand_test_' . getmypid();
$fails    = 0;

function check($ok, $msg)
{
	global $fails;
	echo ($ok ? "OK   " : "FAIL "), $msg, "\n";
	if (! $ok) { $fails++; }
}

// Пакет: <dir>/brand_data.json (+ семейство), brand_data правится функцией $edit
function make_package($dir, $edit, $name, $with_json = true)
{
	global $endpoint, $src_dir, $work;
	$root = "$work/src_$name";
	exec(sprintf('rm -rf %1$s && mkdir -p %1$s && cp -a %2$s %1$s/%3$s', escapeshellarg($root), escapeshellarg("$endpoint/$src_dir"), escapeshellarg($dir)));
	$json = json_decode(file_get_contents("$root/$dir/brand_data.json"), true);
	$json = $edit($json);
	if ($with_json) { file_put_contents("$root/$dir/brand_data.json", json_encode($json, JSON_PRETTY_PRINT)); }
	else            { unlink("$root/$dir/brand_data.json"); }
	$tgz = "$work/$dir-$name-" . time() . ".tgz";
	exec(sprintf('tar czf %s -C %s %s', escapeshellarg($tgz), escapeshellarg($root), escapeshellarg($dir)));
	return $tgz;
}

$as_test = function($json) use ($test_id, $test_dir) {
	$json['data']['brands']['brand_id']  = $test_id;
	$json['data']['brands']['directory'] = $test_dir;
	$json['data']['brands']['name']      = 'EPM Test';
	$json['data']['brands']['oui_list']  = [];
	return $json;
};

function brand_rows() { return sql("SELECT id, directory, local, installed FROM endpointman_brand_list ORDER BY id", 'getAll', \PDO::FETCH_ASSOC); }
function count_rows($table, $brand) { return (int) sql(sprintf("SELECT COUNT(*) FROM %s WHERE brand = %d", $table, $brand), 'getOne'); }

@mkdir($work, 0700, true);
$before = brand_rows();
$other  = [];
foreach ($before as $row) { $other[$row['id']] = [count_rows('endpointman_product_list', $row['id']), count_rows('endpointman_model_list', $row['id'])]; }
check(! $epm->get_hw_brand($test_id, 'id', '*', true), "бренда $test_id до теста нет");

try
{
	// 1. Установка
	$err = [];
	ob_start();
	$ok  = $epm->epm_config->install_brand_package(make_package($test_dir, $as_test, 'ok'), true, $err);
	$log = ob_get_clean();
	check($ok, "установка пакета: " . ($ok ? '' : strip_tags($log)));
	$row = $epm->get_hw_brand($test_id, 'id', '*', true);
	check(($row['directory'] ?? '') == $test_dir && $row['installed'] == 1 && $row['local'] == 1, "строка бренда: installed=1, local=1");
	check(count_rows('endpointman_product_list', $test_id) > 0, "семейства: " . count_rows('endpointman_product_list', $test_id));
	check(count_rows('endpointman_model_list', $test_id) > 0, "модели: " . count_rows('endpointman_model_list', $test_id));
	check(file_exists("$endpoint/$test_dir/brand_data.json"), "brand_data.json скопирован (раньше пропускался)");
	$files_src = (int) trim(shell_exec(sprintf('find %s -type f | wc -l', escapeshellarg("$endpoint/$src_dir"))));
	$files_dst = (int) trim(shell_exec(sprintf('find %s -type f | wc -l', escapeshellarg("$endpoint/$test_dir"))));
	check($files_src == $files_dst, "файлы пакета на месте ($files_dst из $files_src)");
	$local = json_decode(@file_get_contents("$endpoint/master_local.json"), true);
	check(in_array($test_dir, array_column($local['data']['brands'] ?? [], 'directory')), "бренд записан в master_local.json");
	check(! empty($epm->packages->getBrandByDirectory($test_dir)), "бренд виден модулю (packages->getBrandByDirectory)");
	check($epm->packages->master_json->isLocalBrand($test_dir), "isLocalBrand");
	$product = sql(sprintf("SELECT id FROM endpointman_product_list WHERE brand = %d LIMIT 1", $test_id), 'getOne');
	check(! empty($epm->packages->getProductByProductID((int) $product)), "семейство $product видно модулю");
	$upd = $epm->epm_config->brand_update_check($test_dir);
	check($upd['update'] === 0, "проверка обновлений бренда: update=" . var_export($upd['update'], true) . " (было -3, «нет в master.json»)");
	check(! file_exists("$epm->PROVISIONER_PATH/$test_dir") && ! glob("$epm->PROVISIONER_PATH/upload_*") && ! glob("$epm->TEMP_PATH/upload_*"), "временные файлы убраны");

	// 2. Повторная установка (обновление того же бренда)
	ob_start();
	$ok = $epm->epm_config->install_brand_package(make_package($test_dir, $as_test, 'again'), true, $err);
	$log = ob_get_clean();
	check($ok && strpos($log, 'Updating Brand') !== false, "повторная установка обновляет бренд");
	check(strpos($log, 'Updating Family') !== false && strpos($log, 'Updating Model') !== false && strpos($log, 'is not exist') === false,
		"повторная установка обновляет семейства и модели (getProduct() возвращал false для существующих)");

	// 3. Отказы
	$cases = [
		'чужой brand_id (8 = yealink)' => [make_package($test_dir, function($j) use ($as_test) { $j = $as_test($j); $j['data']['brands']['brand_id'] = 8; return $j; }, 'id8'), 'already belongs'],
		'каталог в JSON ≠ папка'       => [make_package($test_dir, function($j) use ($as_test) { $j = $as_test($j); $j['data']['brands']['directory'] = 'other'; return $j; }, 'dir'), 'is not the folder'],
		'нет brand_data.json'          => [make_package($test_dir, $as_test, 'nojson', false), 'brand_data.json'],
		'не архив'                     => [(function() use ($work) { file_put_contents("$work/bad.tgz", "not a tar"); return "$work/bad.tgz"; })(), '❌'],
	];
	foreach ($cases as $title => [$file, $expect])
	{
		$err = [];
		ob_start();
		$ok  = $epm->epm_config->install_brand_package($file, true, $err);
		$log = ob_get_clean();
		check(! $ok && strpos(implode(' ', $err), $expect) !== false, "отказ: $title — " . trim(strip_tags(implode(' ', $err))) . ($ok ? ' (установился!)' : ''));
	}
	$row = $epm->get_hw_brand(8, 'id', '*', true);
	check(($row['directory'] ?? '') == 'yealink', "yealink не тронут отказом по id");
}
finally
{
	// 4. Удаление
	if ($epm->get_hw_brand($test_id, 'id', '*', true))
	{
		$err = [];
		ob_start();
		$ok = $epm->epm_config->remove_brand($test_id, false, true, $err);
		$log = ob_get_clean();
		check($ok, "удаление бренда: " . ($ok ? '' : strip_tags($log)));
	}
	check(! $epm->get_hw_brand($test_id, 'id', '*', true) && count_rows('endpointman_product_list', $test_id) == 0 && count_rows('endpointman_model_list', $test_id) == 0, "строк бренда, семейств и моделей не осталось");
	check(! file_exists("$endpoint/$test_dir"), "каталог бренда удалён");
	$local = json_decode(@file_get_contents("$endpoint/master_local.json"), true);
	check(! in_array($test_dir, array_column($local['data']['brands'] ?? [], 'directory')), "бренд убран из master_local.json");
	exec(sprintf('rm -rf %s', escapeshellarg($work)));
}

$after = brand_rows();
check($before == $after, "таблица брендов как до теста");
foreach ($other as $id => $counts)
{
	if ($counts != [count_rows('endpointman_product_list', $id), count_rows('endpointman_model_list', $id)]) { check(false, "семейства/модели бренда $id изменились"); }
}
echo $fails ? "\n$fails FAIL\n" : "\nALL OK\n";
exit($fails ? 1 : 0);
