/**
 * Проверка JS страницы Device List в jsdom: настоящий jQuery и настоящие AJAX-запросы к FreePBX
 * (с 127.0.0.1 ajax.php не требует входа), модальные окна и bootstrap-table — заглушки.
 *
 *   cd /root/src/jstest && npm install jsdom@24
 *   EPM_DUMP=/tmp/dev.html runuser -u asterisk -- php utests/render_pages.php epm_devices
 *   NODE_PATH=/root/src/jstest/node_modules node utests/js/test_devices.js /tmp/dev.html <device id> <ext>
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const [html, devId, devExt] = process.argv.slice(2);
const MODULE = path.resolve(__dirname, '../..');
const dom = new JSDOM(fs.readFileSync(html, 'utf8'), {
	url: 'http://127.0.0.1/admin/config.php?display=epm_devices',
	runScripts: 'outside-only',
});
const w = dom.window;
// strict eval keeps the function declarations local; a <script> tag would make them global
const $script = f => w.eval(fs.readFileSync(f, 'utf8').replace(/^\s*["']use strict["'];?/, ''));

$script('/var/www/html/admin/assets/js/jquery-3.6.0.min.js');
const $ = w.jQuery;
const toasts = [];
const jsErrors = [];
w.addEventListener('error', e => jsErrors.push(e.message));
w._ = s => s;
w.sprintf = (fmt, ...a) => fmt.replace(/%[sd]/g, () => String(a.shift()));
w.fpbxToast = (msg, title, type) => toasts.push([type, msg]);
w.FreePBX = { ajaxurl: 'http://127.0.0.1/admin/ajax.php' };
w.confirm = () => true;
$.getUrlVar = name => (new w.URL(w.location.href)).searchParams.get(name) || '';
$.fn.modal = function (a) { this.attr('data-modal', a); return this; };
$.fn.selectpicker = function () { return this; };
w.__selections = [];
$.fn.bootstrapTable = function (m) { return m === 'getSelections' ? w.__selections : this; };

$script(path.join(MODULE, 'assets/js/epm_global.js'));
$script(path.join(MODULE, 'assets/js/epm_devices.js'));

const idle = () => new Promise(r => { const t = () => ($.active === 0 ? setTimeout(r, 50) : setTimeout(t, 50)); setTimeout(t, 50); });
let fails = 0;
const ok = (cond, msg) => { console.log((cond ? 'OK   ' : 'FAIL ') + msg); if (!cond) fails++; };

(async () => {
	await idle();	// epm_global.js calls epm_devices_document_ready() on document ready

	// grid formatters with the real list
	const rows = await new Promise(r => $.post(w.FreePBX.ajaxurl, { module: 'endpointman', module_sec: 'epm_devices', module_tab: 'manager', command: 'list_devices' }, r, 'json'));
	const row = rows.find(x => String(x.id) === devId);
	ok(!!row, 'list_devices contains device ' + devId);
	ok(w.epm_devices_grid_FormatLines(row.lines, row).includes('>' + devExt + '<'), 'lines formatter shows extension');
	ok(w.epm_devices_grid_FormatTemplate(row.template, row).includes('display=epm_templates&subpage=edit'), 'template formatter links to the editor');
	ok(w.epm_devices_grid_FormatIP(row.ip, row).includes(row.ip || 'Not registered'), 'registration formatter');
	ok(w.epm_devices_grid_FormatAction(row.id, row).includes('epm_devices_act_reboot'), 'action buttons');

	// provisioning switch: disable → p.php 404 for the MAC → enable again (a few seconds only)
	const list = () => new Promise(r => $.post(w.FreePBX.ajaxurl, { module: 'endpointman', module_sec: 'epm_devices', module_tab: 'manager', command: 'list_devices' }, r, 'json'));
	const prov = () => new Promise(r => $.ajax({ url: 'http://127.0.0.1/provisioning/p.php/' + row.mac.toLowerCase() + '.cfg', complete: x => r(x.status) }));
	ok(!row.disabled && w.epm_devices_grid_FormatProvisioning(row.disabled, row).includes('Enabled'), 'provisioning column: Enabled');
	const before = await prov();
	const toggle = w.epm_devices_grid_ProvisioningEvents['click .epm_devices_prov_toggle'];
	toasts.length = 0;
	toggle({ preventDefault() {} }, false, row);
	await idle();
	let r2 = (await list()).find(x => x.id === row.id);
	ok(r2.disabled === true && toasts.some(t => t[0] === 'success'), 'disabled by click: ' + JSON.stringify(toasts));
	const off = await prov();
	toggle({ preventDefault() {} }, true, r2);
	await idle();
	r2 = (await list()).find(x => x.id === row.id);
	ok(r2.disabled === false, 'enabled again');
	ok(off === 404 && before !== 404 && (await prov()) === before, `p.php: ${before} → disabled ${off} → enabled ${before}`);

	// edit dialog
	w.epm_devices_edit_open(parseInt(devId, 10));
	await idle();
	ok($('#epm_devices_modal_edit').attr('data-modal') === 'show', 'edit dialog opened');
	ok($('#epm_devices_form_mac').val() === row.mac_format, 'MAC filled: ' + $('#epm_devices_form_mac').val());
	ok($('#epm_devices_form_brand').val() === String(row.brand_id), 'brand selected');
	ok($('#epm_devices_form_model').val() === String(row.model_id), 'model selected: ' + $('#epm_devices_form_model option:selected').text());
	ok($('#epm_devices_form_template').val() === String(row.template_id), 'template selected: ' + $('#epm_devices_form_template option:selected').text());
	ok($('#epm_devices_form_lines tbody tr').length === row.lines.length, 'line rows: ' + $('#epm_devices_form_lines tbody tr').length);
	ok($('.epm_devices_line_ext').first().val() === devExt, 'line extension selected');
	ok($('.epm_devices_line_desc').first().attr('placeholder') !== '', 'description placeholder from extension name');

	const max = w.epm_devices_max_lines();
	for (let i = 0; i < max + 1; i++) { $('#epm_devices_form_line_add').trigger('click'); }
	ok($('#epm_devices_form_lines tbody tr').length <= max, 'no more lines than the model has (' + max + ')');
	ok(toasts.some(t => t[0] === 'warning'), 'warning when adding too many lines');
	$('#epm_devices_form_lines .epm_devices_line_remove').slice(row.lines.length).trigger('click');
	ok($('#epm_devices_form_lines tbody tr').length === row.lines.length, 'extra lines removed');

	// save without writing the files (same data)
	$('#epm_devices_form_rebuild').prop('checked', false);
	toasts.length = 0;
	$('#epm_devices_form_save').trigger('click');
	await idle();
	ok(toasts.some(t => t[0] === 'success'), 'save: ' + JSON.stringify(toasts));
	ok($('#epm_devices_modal_edit').attr('data-modal') === 'hide', 'dialog closed after save');

	// invalid MAC is refused by the server
	w.epm_devices_edit_open(0, { mac: 'zz:zz', brand: row.brand_id, model: row.model_id, ext: devExt });
	await idle();
	ok($('#epm_devices_form_model option').length > 0, 'add dialog: models of the brand loaded');
	ok($('#epm_devices_form_template option').length > 0, 'add dialog: templates loaded');
	toasts.length = 0;
	$('#epm_devices_form_save').trigger('click');
	await idle();
	ok(toasts.some(t => t[0] === 'error' && /MAC/i.test(t[1])), 'invalid MAC refused: ' + JSON.stringify(toasts));

	// find phones
	toasts.length = 0;
	$('#epm_devices_discover_go').trigger('click');
	await idle();
	ok($('#epm_devices_discover_table tbody tr').length > 0, 'discover rendered ' + $('#epm_devices_discover_table tbody tr').length + ' row(s)');

	// bulk action without selection
	toasts.length = 0;
	w.epm_devices_bulk('reboot');
	ok(toasts.some(t => t[0] === 'warning'), 'bulk without selection warns');

	ok(jsErrors.length === 0, 'no JS errors ' + JSON.stringify(jsErrors));
	console.log(fails ? `${fails} FAILED` : 'ALL OK');
	process.exit(fails ? 1 : 0);
})().catch(e => { console.log('EXCEPTION', e); process.exit(1); });
