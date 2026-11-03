"use strict";

var epm_devices_cache = {
	extensions: [],		// [{id, name}] available for the device being edited
	models: {}			// brand_id => [{id, name, max_lines}]
};

function epm_devices_document_ready ()
{
	$('#epm_devices_btn_add').on('click', function() { epm_devices_edit_open(0); });
	$('#epm_devices_btn_discover').on('click', function() { $('#epm_devices_modal_discover').modal('show'); });
	$('#epm_devices_btn_rebuild_all').on('click', epm_devices_rebuild_all);
	$('.epm_devices_bulk').on('click', function(e) {
		e.preventDefault();
		epm_devices_bulk($(this).data('action'));
	});

	$('#epm_devices_form_brand').on('change', function() {
		epm_devices_load_models($(this).val(), null, function() { $('#epm_devices_form_model').trigger('change'); });
	});
	$('#epm_devices_form_model').on('change', function() {
		epm_devices_load_templates($(this).val(), null);
		epm_devices_lines_limit();
	});
	$('#epm_devices_form_line_add').on('click', function() { epm_devices_line_add(null); });
	$('#epm_devices_form_lines').on('click', '.epm_devices_line_remove', function() {
		$(this).closest('tr').remove();
		epm_devices_lines_limit();
	});
	$('#epm_devices_form_lines').on('change', '.epm_devices_line_ext', function() {
		var ext = epm_devices_cache.extensions.find(x => String(x.id) === String($(this).val()));
		$(this).closest('tr').find('.epm_devices_line_desc').attr('placeholder', ext ? ext.name.replace(/^[^-]+-\s*/, '') : '');
	});
	$('#epm_devices_form_save').on('click', epm_devices_save);

	$('#epm_devices_discover_go').on('click', epm_devices_discover);
	$('#epm_devices_discover_all').on('change', function() {
		$('#epm_devices_discover_table tbody .epm_devices_discover_sel:enabled').prop('checked', $(this).is(':checked'));
	});
	$('#epm_devices_discover_add').on('click', epm_devices_discover_add);
}

function epm_devices_windows_load (nTab = "") { }

function epm_devices_change_tab (nTab = "") { }


function epm_devices_ajax(command, data, callback)
{
	var request = $.extend({
		module    : "endpointman",
		module_sec: "epm_devices",
		module_tab: "manager",
		command   : command
	}, data || {});
	return epm_gloabl_manager_ajax(request, callback);
}

function epm_devices_refresh()
{
	$('#epm_devices_grid').bootstrapTable('refresh', { silent: true });
}

function epm_devices_escape(str)
{
	return $('<div>').text(str === null || str === undefined ? '' : String(str)).html();
}


/*** GRID ***/
function epm_devices_grid_FormatModel(value, row, index)
{
	if (!row.model_ok)
	{
		return '<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> ' + _("Unknown") + '</span>';
	}
	return epm_devices_escape(value);
}

function epm_devices_grid_FormatLines(value, row, index)
{
	var out = [];
	$.each(value || [], function(i, l) {
		var color = l.registered ? 'text-success' : 'text-muted';
		var title = l.registered ? _("Registered") + ' ' + l.status : _("Not registered");
		out.push(sprintf('<span title="%s"><i class="fa fa-circle %s"></i> %s: <a href="config.php?display=extensions&extdisplay=%s">%s</a> %s</span>',
			epm_devices_escape(title), color, l.line, encodeURIComponent(l.ext), epm_devices_escape(l.ext), epm_devices_escape(l.description)));
	});
	return out.join('<br>');
}

function epm_devices_grid_SortLines(a, b)
{
	var x = (a && a.length) ? parseInt(a[0].ext, 10) || 0 : 0;
	var y = (b && b.length) ? parseInt(b[0].ext, 10) || 0 : 0;
	return x - y;
}

function epm_devices_grid_FormatTemplate(value, row, index)
{
	var url = (row.template_id > 0)
		? 'config.php?display=epm_templates&subpage=edit&custom=0&idsel=' + row.template_id
		: 'config.php?display=epm_templates&subpage=edit&custom=1&idsel=' + row.id;
	var icon = (row.template_id > 0) ? 'fa-file-text-o' : 'fa-pencil';
	return sprintf('<a href="%s" title="%s"><i class="fa %s"></i> %s</a>', url, epm_devices_escape(_("Edit template settings")), icon, epm_devices_escape(value));
}

function epm_devices_grid_FormatIP(value, row, index)
{
	if (!value)
	{
		return '<span class="text-muted">' + _("Not registered") + '</span>';
	}
	return sprintf('<a href="http://%s/" target="_blank" title="%s">%s</a>', epm_devices_escape(value), epm_devices_escape(_("Go to phone web interface")), epm_devices_escape(value));
}

function epm_devices_grid_FormatProvisioning(value, row, index)
{
	return value
		? sprintf('<a href="#" class="epm_devices_prov_toggle text-danger" title="%s"><i class="fa fa-pause"></i> %s</a>', epm_devices_escape(_("The phone gets no configuration. Click to enable.")), _("Disabled"))
		: sprintf('<a href="#" class="epm_devices_prov_toggle text-success" title="%s"><i class="fa fa-play"></i> %s</a>', epm_devices_escape(_("Click to disable")), _("Enabled"));
}

window.epm_devices_grid_ProvisioningEvents = {
	'click .epm_devices_prov_toggle': function (e, value, row) {
		e.preventDefault();
		var disable = !value;
		if (disable && !confirm(sprintf(_("Disable the provisioning of %s? The phone keeps its current configuration."), row.mac_format))) { return; }
		epm_devices_ajax('set_disabled', { ids: [row.id], disabled: disable ? 1 : 0 }, function(status, data) {
			if (data && data.message) { fpbxToast(data.message, '', status ? 'success' : 'error'); }
			epm_devices_refresh();
		});
	}
};

function epm_devices_grid_FormatAction(value, row, index)
{
	var btn = '<button type="button" class="btn btn-default btn-sm %s" title="%s"><i class="fa %s"></i></button>';
	return '<div class="btn-group" role="group">'
		+ sprintf(btn, 'epm_devices_act_edit', _("Edit"), 'fa-edit')
		+ sprintf(btn, 'epm_devices_act_rebuild', _("Rebuild Configs"), 'fa-refresh')
		+ sprintf(btn, 'epm_devices_act_reboot', _("Reboot"), 'fa-power-off')
		+ sprintf(btn, 'epm_devices_act_delete', _("Delete"), 'fa-trash')
		+ '</div>';
}

window.epm_devices_grid_ActionEvents = {
	'click .epm_devices_act_edit': function (e, value, row) {
		epm_devices_edit_open(row.id);
	},
	'click .epm_devices_act_rebuild': function (e, value, row) {
		epm_devices_ajax('rebuild', { ids: [row.id], reboot: 0 }, function(status, data) {
			if (status) { fpbxToast(data.message, '', 'success'); }
		});
	},
	'click .epm_devices_act_reboot': function (e, value, row) {
		if (!confirm(sprintf(_("Reboot the phone %s?"), row.mac_format))) { return; }
		epm_devices_ajax('reboot', { ids: [row.id] }, function(status, data) {
			if (status) { fpbxToast(data.message, '', 'success'); }
		});
	},
	'click .epm_devices_act_delete': function (e, value, row) {
		if (!confirm(sprintf(_("Delete the device %s? Its configuration files will be removed too."), row.mac_format))) { return; }
		epm_devices_ajax('delete_devices', { ids: [row.id] }, function(status, data) {
			if (status) { fpbxToast(data.message, '', 'success'); epm_devices_refresh(); }
		});
	}
};

function epm_devices_selected_ids()
{
	return $.map($('#epm_devices_grid').bootstrapTable('getSelections'), function(row) { return row.id; });
}

function epm_devices_bulk(action)
{
	var ids = epm_devices_selected_ids();
	if (ids.length === 0)
	{
		fpbxToast(_("No Phones Selected!"), '', 'warning');
		return;
	}
	var done = function(status, data) {
		if (data && data.message) { fpbxToast(data.message, '', status ? 'success' : 'error'); }
		epm_devices_refresh();
	};
	switch (action)
	{
		case 'rebuild':
			epm_devices_ajax('rebuild', { ids: ids, reboot: 0 }, done);
			break;
		case 'rebuild_reboot':
			if (!confirm(sprintf(_("Rebuild and reboot %d phone(s)?"), ids.length))) { return; }
			epm_devices_ajax('rebuild', { ids: ids, reboot: 1 }, done);
			break;
		case 'reboot':
			if (!confirm(sprintf(_("Reboot %d phone(s)?"), ids.length))) { return; }
			epm_devices_ajax('reboot', { ids: ids }, done);
			break;
		case 'enable':
		case 'disable':
			epm_devices_ajax('set_disabled', { ids: ids, disabled: action === 'disable' ? 1 : 0 }, done);
			break;
		case 'delete':
			if (!confirm(sprintf(_("Delete %d device(s)? Their configuration files will be removed too."), ids.length))) { return; }
			epm_devices_ajax('delete_devices', { ids: ids }, done);
			break;
	}
}

function epm_devices_rebuild_all()
{
	var reboot = confirm(_("Rebuild the configuration of all phones.\n\nOK = also reboot the phones, Cancel = only rebuild."));
	epm_devices_ajax('rebuild_all', { reboot: reboot ? 1 : 0 }, function(status, data) {
		if (data && data.message) { fpbxToast(data.message, '', status ? 'success' : 'error'); }
		epm_devices_refresh();
	});
}


/*** ADD / EDIT ***/
function epm_devices_edit_open(id, preset)
{
	var $m = $('#epm_devices_modal_edit');
	$('#epm_devices_form_id').val(id || 0);
	$('#epm_devices_form_mac').val('');
	$('#epm_devices_form_brand').val('');
	$('#epm_devices_form_model').empty();
	$('#epm_devices_form_template').empty();
	$('#epm_devices_form_lines tbody').empty();
	$('#epm_devices_form_reboot').prop('checked', false);
	$('#epm_devices_form_rebuild').prop('checked', true);
	$('#epm_devices_modal_edit_title').text(id ? _("Edit Device") : _("Add Device"));

	epm_devices_ajax('list_extensions', { id: id || 0 }, function(status, data) {
		epm_devices_cache.extensions = status ? data.options : [];
		if (!id)
		{
			preset = preset || {};
			$('#epm_devices_form_mac').val(preset.mac || '');
			if (preset.brand)
			{
				$('#epm_devices_form_brand').val(preset.brand);
				epm_devices_load_models(preset.brand, preset.model || null, function() {
					epm_devices_load_templates($('#epm_devices_form_model').val(), 0);
					epm_devices_line_add({ line: 1, ext: preset.ext || '' });
				});
			}
			else
			{
				epm_devices_line_add({ line: 1, ext: preset.ext || '' });
			}
			$m.modal('show');
			return;
		}
		epm_devices_ajax('get_device', { id: id }, function(status, data) {
			if (!status) { return; }
			var d = data.device;
			$('#epm_devices_form_mac').val(d.mac.match(/.{2}/g).join(':'));
			$('#epm_devices_form_brand').val(d.brand_id);
			epm_devices_load_models(d.brand_id, d.model_id, function() {
				epm_devices_load_templates(d.model_id, d.template_id);
				$.each(d.lines, function(i, l) { epm_devices_line_add(l); });
			});
			$m.modal('show');
		});
	});
}

function epm_devices_load_models(brand, selected, callback)
{
	var $sel = $('#epm_devices_form_model').empty();
	if (!brand)
	{
		if (callback) { callback(); }
		return;
	}
	epm_devices_ajax('list_models', { brand: brand, selected: selected || '' }, function(status, data) {
		if (status)
		{
			epm_devices_cache.models[brand] = data.options;
			$.each(data.options, function(i, o) {
				$sel.append($('<option>', { value: o.id, text: o.name + (o.product ? ' (' + o.product.trim() + ')' : ''), selected: o.is_select }).data('max_lines', o.max_lines));
			});
		}
		if (callback) { callback(); }
	});
}

function epm_devices_load_templates(model, selected)
{
	var $sel = $('#epm_devices_form_template').empty();
	if (!model) { return; }
	epm_devices_ajax('list_templates', { model: model, selected: (selected === null || selected === undefined) ? 0 : selected }, function(status, data) {
		if (!status) { return; }
		$.each(data.options, function(i, o) {
			$sel.append($('<option>', { value: o.id, text: o.name, selected: o.is_select }));
		});
	});
}

function epm_devices_max_lines()
{
	return parseInt($('#epm_devices_form_model option:selected').data('max_lines'), 10) || 1;
}

function epm_devices_line_add(line)
{
	var $tbody = $('#epm_devices_form_lines tbody');
	var max    = epm_devices_max_lines();
	var used   = $tbody.find('.epm_devices_line_num').map(function() { return parseInt($(this).val(), 10); }).get();
	var num    = (line && line.line) ? parseInt(line.line, 10) : 1;
	if (!line || !line.line)
	{
		while (used.indexOf(num) !== -1) { num++; }
	}
	if ($tbody.find('tr').length >= max && !(line && line.luid))
	{
		fpbxToast(sprintf(_("This model has %d line(s)."), max), '', 'warning');
		return;
	}

	var $num = $('<select>', { class: 'form-control form-control-sm epm_devices_line_num' });
	for (var i = 1; i <= Math.max(max, num); i++)
	{
		$num.append($('<option>', { value: i, text: i, selected: i === num }));
	}
	var $ext = $('<select>', { class: 'form-control form-control-sm epm_devices_line_ext' }).append($('<option>', { value: '', text: '' }));
	$.each(epm_devices_cache.extensions, function(i, e) {
		$ext.append($('<option>', { value: e.id, text: e.name, selected: line && String(line.ext) === String(e.id) }));
	});
	var $desc = $('<input>', { type: 'text', class: 'form-control form-control-sm epm_devices_line_desc', maxlength: 20, value: (line && line.description) ? line.description : '' });
	var $rm   = $('<button>', { type: 'button', class: 'btn btn-default btn-sm epm_devices_line_remove', title: _("Remove") }).append($('<i>', { class: 'fa fa-trash' }));

	$tbody.append($('<tr>').append($('<td>').append($num), $('<td>').append($ext), $('<td>').append($desc), $('<td>').append($rm)));
	$ext.trigger('change');
}

// Keep the line number options within the lines of the selected model
function epm_devices_lines_limit()
{
	var max = epm_devices_max_lines();
	$('#epm_devices_form_lines .epm_devices_line_num').each(function() {
		var cur = parseInt($(this).val(), 10);
		$(this).empty();
		for (var i = 1; i <= max; i++)
		{
			$(this).append($('<option>', { value: i, text: i, selected: i === cur }));
		}
	});
	$('#epm_devices_form_line_add').prop('disabled', $('#epm_devices_form_lines tbody tr').length >= max);
}

function epm_devices_save()
{
	var lines = [];
	$('#epm_devices_form_lines tbody tr').each(function() {
		lines.push({
			line       : $(this).find('.epm_devices_line_num').val(),
			ext        : $(this).find('.epm_devices_line_ext').val(),
			description: $(this).find('.epm_devices_line_desc').val()
		});
	});
	var $btn = $('#epm_devices_form_save').prop('disabled', true);
	epm_devices_ajax('save_device', {
		id      : $('#epm_devices_form_id').val(),
		mac     : $('#epm_devices_form_mac').val(),
		model   : $('#epm_devices_form_model').val() || 0,
		template: $('#epm_devices_form_template').val() || 0,
		lines   : JSON.stringify(lines),
		rebuild : $('#epm_devices_form_rebuild').is(':checked') ? 1 : 0,
		reboot  : $('#epm_devices_form_reboot').is(':checked') ? 1 : 0
	}, function(status, data) {
		$btn.prop('disabled', false);
		if (data && data.id)
		{
			// saved (maybe with a config error that was already shown)
			$('#epm_devices_modal_edit').modal('hide');
			epm_devices_refresh();
		}
		if (status) { fpbxToast(data.message, '', 'success'); }
	});
}


/*** FIND PHONES ***/
function epm_devices_discover()
{
	var $tbody = $('#epm_devices_discover_table tbody').html('<tr><td colspan="7"><i class="fa fa-spinner fa-spin"></i> ' + _("Searching...") + '</td></tr>');
	var $btn   = $('#epm_devices_discover_go').prop('disabled', true);
	epm_devices_ajax('list_extensions', { id: 0 }, function(st, ext) {
		var extensions = st ? ext.options : [];
		epm_devices_ajax('discover', {
			netmask: $('#epm_devices_discover_netmask').val(),
			nmap   : $('#epm_devices_discover_nmap').is(':checked') ? 1 : 0
		}, function(status, data) {
			$btn.prop('disabled', false);
			$tbody.empty();
			if (!status) { return; }
			if (data.rows.length === 0)
			{
				$tbody.append('<tr><td colspan="7">' + _("No Devices Found") + '</td></tr>');
				return;
			}
			fpbxToast(data.message, '', 'info');
			$.each(data.rows, function(i, r) {
				var $sel = $('<input>', { type: 'checkbox', class: 'epm_devices_discover_sel', disabled: r.managed });
				var $model = $('<select>', { class: 'form-control form-control-sm epm_devices_discover_model', disabled: r.managed });
				var $ext = $('<select>', { class: 'form-control form-control-sm epm_devices_discover_ext', disabled: r.managed }).append($('<option>', { value: '', text: '' }));
				$.each(extensions, function(j, e) {
					$ext.append($('<option>', { value: e.id, text: e.name, selected: String(e.id) === String(r.ext) }));
				});
				epm_devices_ajax('list_models', { brand: r.brand_id }, function(s, m) {
					if (!s) { return; }
					$.each(m.options, function(j, o) {
						// preselect the model named in the user agent (e.g. "SPIP_331" -> "SoundPoint IP 331")
						var num = (o.name.match(/\d{2,}/) || [''])[0];
						$model.append($('<option>', { value: o.id, text: o.name, selected: num !== '' && (r.useragent || '').indexOf(num) !== -1 }));
					});
				});
				$tbody.append($('<tr>').data('row', r).append(
					$('<td>').append($sel),
					$('<td>').text(r.mac.match(/.{2}/g).join(':')),
					$('<td>').text(r.ip),
					$('<td>').text(r.brand),
					$('<td>').append($('<small>').text(r.useragent || '')),
					$('<td>').append(r.managed ? $('<span>', { class: 'text-muted', text: _("Already managed") }) : $model),
					$('<td>').append(r.managed ? '' : $ext)
				));
			});
		});
	});
}

function epm_devices_discover_add()
{
	var queue = [];
	$('#epm_devices_discover_table tbody tr').each(function() {
		if (!$(this).find('.epm_devices_discover_sel').is(':checked')) { return; }
		var r = $(this).data('row');
		queue.push({
			mac  : r.mac,
			model: $(this).find('.epm_devices_discover_model').val(),
			ext  : $(this).find('.epm_devices_discover_ext').val()
		});
	});
	if (queue.length === 0)
	{
		fpbxToast(_("No Phones Selected!"), '', 'warning');
		return;
	}
	var reboot = $('#epm_devices_discover_reboot').is(':checked') ? 1 : 0;
	var added  = 0;
	var next = function() {
		var item = queue.shift();
		if (!item)
		{
			fpbxToast(sprintf(_("%d phone(s) added."), added), '', 'success');
			epm_devices_refresh();
			epm_devices_discover();
			return;
		}
		epm_devices_ajax('save_device', {
			id: 0, mac: item.mac, model: item.model || 0, template: 0,
			lines: JSON.stringify([{ line: 1, ext: item.ext }]), rebuild: 1, reboot: reboot
		}, function(status, data) {
			if (data && data.id) { added++; }
			next();
		});
	};
	next();
}
