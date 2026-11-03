<?php if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); } ?>

<?= $endpoint_warn ?>
<div class="container-fluid" id="epm_devices">
	<h1><?= _("End Point Configuration Manager") ?></h1>
	<h2><?= _("Extension Mapping") ?></h2>
	<div class="display">
		<div class="row">
			<div class="col-sm-12">
				<div class="fpbx-container">
					<div class="display no-border">

						<?php if ($no_brands) : ?>
							<div class="card text-center alert-warning">
								<div class="card-header"><strong><?= _("Warning!") ?></strong></div>
								<div class="card-body">
									<h5 class="card-title"><?= _("Welcome to Endpoint Manager") ?></h5>
									<p class="card-text"><?= _("There are no brands installed or no models enabled. Install a brand and enable the models of your phones in the Package Manager.") ?></p>
									<a href="config.php?display=epm_config" class="btn btn-primary"><?= _("Go to Package Manager") ?></a>
								</div>
							</div>
							<br>
						<?php endif; ?>

						<?php if ($no_srvip) : ?>
							<div class="alert alert-warning" role="alert">
								<strong><?= _("Warning!") ?></strong> <?= _("The IP address of the server is not set, the phones will not know where to register.") ?>
								<a href="config.php?display=epm_advanced"><?= _("Go to Settings") ?></a>
							</div>
						<?php endif; ?>

						<div id="toolbar-devices">
							<button type="button" class="btn btn-primary" id="epm_devices_btn_add" <?= $no_brands ? 'disabled' : '' ?>><i class="fa fa-plus"></i> <?= _("Add Device") ?></button>
							<button type="button" class="btn btn-default" id="epm_devices_btn_discover"><i class="fa fa-search"></i> <?= _("Find Phones") ?></button>
							<div class="btn-group">
								<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
									<i class="fa fa-cogs"></i> <?= _("Selected Phones") ?> <span class="caret"></span>
								</button>
								<div class="dropdown-menu">
									<a class="dropdown-item epm_devices_bulk" href="#" data-action="rebuild"><i class="fa fa-refresh"></i> <?= _("Rebuild Configs") ?></a>
									<a class="dropdown-item epm_devices_bulk" href="#" data-action="rebuild_reboot"><i class="fa fa-power-off"></i> <?= _("Rebuild Configs & Reboot") ?></a>
									<a class="dropdown-item epm_devices_bulk" href="#" data-action="reboot"><i class="fa fa-power-off"></i> <?= _("Reboot") ?></a>
									<div class="dropdown-divider"></div>
									<a class="dropdown-item epm_devices_bulk" href="#" data-action="enable"><i class="fa fa-play"></i> <?= _("Enable Provisioning") ?></a>
									<a class="dropdown-item epm_devices_bulk" href="#" data-action="disable"><i class="fa fa-pause"></i> <?= _("Disable Provisioning") ?></a>
									<div class="dropdown-divider"></div>
									<a class="dropdown-item epm_devices_bulk text-danger" href="#" data-action="delete"><i class="fa fa-trash"></i> <?= _("Delete") ?></a>
								</div>
							</div>
							<button type="button" class="btn btn-default" id="epm_devices_btn_rebuild_all"><i class="fa fa-refresh"></i> <?= _("Rebuild All") ?></button>
						</div>

						<table id="epm_devices_grid"
							data-url="<?= $url_grid ?>"
							data-cache="false"
							data-cookie="true"
							data-cookie-id-table="epm_devices_table"
							data-toolbar="#toolbar-devices"
							data-maintain-selected="true"
							data-click-to-select="false"
							data-show-columns="true"
							data-show-refresh="true"
							data-toggle="table"
							data-pagination="true"
							data-page-size="50"
							data-search="true"
							data-sort-name="lines"
							data-unique-id="id"
							class="table table-striped">
							<thead>
								<tr>
									<th data-field="state_check" data-checkbox="true"></th>
									<th data-field="mac_format" data-sortable="true"><?= _("MAC Address") ?></th>
									<th data-field="brand" data-sortable="true"><?= _("Brand") ?></th>
									<th data-field="model" data-sortable="true" data-formatter="epm_devices_grid_FormatModel"><?= _("Model") ?></th>
									<th data-field="lines" data-sortable="true" data-sorter="epm_devices_grid_SortLines" data-formatter="epm_devices_grid_FormatLines"><?= _("Lines") ?></th>
									<th data-field="template" data-sortable="true" data-formatter="epm_devices_grid_FormatTemplate"><?= _("Template") ?></th>
									<th data-field="ip" data-sortable="true" data-formatter="epm_devices_grid_FormatIP"><?= _("Registration") ?></th>
									<th data-field="disabled" data-sortable="true" data-formatter="epm_devices_grid_FormatProvisioning" data-events="epm_devices_grid_ProvisioningEvents"><?= _("Provisioning") ?></th>
									<th data-field="id" data-formatter="epm_devices_grid_FormatAction" data-events="epm_devices_grid_ActionEvents"><?= _("Actions") ?></th>
								</tr>
							</thead>
						</table>

					</div>
				</div>
			</div>
		</div>
	</div>
</div>


<!-- Add / Edit device -->
<div class="modal fade" id="epm_devices_modal_edit" tabindex="-1" role="dialog" data-backdrop="static" aria-labelledby="epm_devices_modal_edit_title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="epm_devices_modal_edit_title" class="modal-title"><?= _("Device") ?></h4>
				<button type="button" class="close" data-dismiss="modal" aria-label="<?= _("Close") ?>"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<form id="epm_devices_form" autocomplete="off" onsubmit="return false;">
					<input type="hidden" name="id" id="epm_devices_form_id" value="0">

					<div class="form-group row">
						<label class="col-md-3 col-form-label" for="epm_devices_form_mac"><?= _("MAC Address") ?></label>
						<div class="col-md-9">
							<input type="text" class="form-control" id="epm_devices_form_mac" name="mac" maxlength="17" placeholder="00:04:F2:00:00:00">
						</div>
					</div>
					<div class="form-group row">
						<label class="col-md-3 col-form-label" for="epm_devices_form_brand"><?= _("Brand") ?></label>
						<div class="col-md-9">
							<select class="form-control" id="epm_devices_form_brand" name="brand">
								<option value=""></option>
								<?php foreach ($brands as $b) : ?>
									<option value="<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<div class="form-group row">
						<label class="col-md-3 col-form-label" for="epm_devices_form_model"><?= _("Model") ?></label>
						<div class="col-md-9">
							<select class="form-control" id="epm_devices_form_model" name="model"></select>
							<small class="form-text text-muted"><?= _("Only enabled models are listed (Package Manager).") ?></small>
						</div>
					</div>
					<div class="form-group row">
						<label class="col-md-3 col-form-label" for="epm_devices_form_template"><?= _("Template") ?></label>
						<div class="col-md-9">
							<select class="form-control" id="epm_devices_form_template" name="template"></select>
							<small class="form-text text-muted"><?= _("\"Custom\" uses the settings of this device only, edit them with the template button in the list.") ?></small>
						</div>
					</div>

					<h5><?= _("Lines") ?></h5>
					<table class="table table-sm" id="epm_devices_form_lines">
						<thead>
							<tr>
								<th style="width: 90px"><?= _("Line") ?></th>
								<th><?= _("Extension") ?></th>
								<th><?= _("Display Name") ?></th>
								<th style="width: 50px"></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
					<button type="button" class="btn btn-default btn-sm" id="epm_devices_form_line_add"><i class="fa fa-plus"></i> <?= _("Add Line") ?></button>

					<hr>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="epm_devices_form_rebuild" checked>
						<label class="form-check-label" for="epm_devices_form_rebuild"><?= _("Write configuration files") ?></label>
					</div>
					<div class="form-check">
						<input class="form-check-input" type="checkbox" id="epm_devices_form_reboot">
						<label class="form-check-label" for="epm_devices_form_reboot"><?= _("Reboot the phone (it reloads the configuration)") ?></label>
					</div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-danger" data-dismiss="modal"><i class="fa fa-times"></i> <?= _("Cancel") ?></button>
				<button type="button" class="btn btn-primary" id="epm_devices_form_save"><i class="fa fa-check"></i> <?= _("Save") ?></button>
			</div>
		</div>
	</div>
</div>


<!-- Find phones -->
<div class="modal fade" id="epm_devices_modal_discover" tabindex="-1" role="dialog" data-backdrop="static" aria-labelledby="epm_devices_modal_discover_title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-xl" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<h4 id="epm_devices_modal_discover_title" class="modal-title"><?= _("Find Phones") ?></h4>
				<button type="button" class="close" data-dismiss="modal" aria-label="<?= _("Close") ?>"><span aria-hidden="true">&times;</span></button>
			</div>
			<div class="modal-body">
				<p class="text-muted"><?= _("Phones are found in the ARP cache of the server and in the SIP registrations (when the phone sends its MAC address). Only MAC addresses of installed brands are listed.") ?></p>
				<div class="form-row align-items-end">
					<div class="col-md-5">
						<label for="epm_devices_discover_netmask"><?= _("Network for nmap (optional)") ?></label>
						<input type="text" class="form-control" id="epm_devices_discover_netmask" value="<?= htmlspecialchars($nmap_search) ?>" placeholder="192.168.1.0/24">
					</div>
					<div class="col-md-3">
						<div class="form-check">
							<input class="form-check-input" type="checkbox" id="epm_devices_discover_nmap" <?= empty($nmap_location) ? 'disabled' : '' ?>>
							<label class="form-check-label" for="epm_devices_discover_nmap"><?= _("Scan with nmap") ?></label>
						</div>
					</div>
					<div class="col-md-4 text-right">
						<button type="button" class="btn btn-primary" id="epm_devices_discover_go"><i class="fa fa-search"></i> <?= _("Search") ?></button>
					</div>
				</div>
				<br>
				<table class="table table-sm table-striped" id="epm_devices_discover_table">
					<thead>
						<tr>
							<th><input type="checkbox" id="epm_devices_discover_all"></th>
							<th><?= _("MAC Address") ?></th>
							<th><?= _("IP") ?></th>
							<th><?= _("Brand") ?></th>
							<th><?= _("User Agent") ?></th>
							<th><?= _("Model") ?></th>
							<th><?= _("Extension") ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" id="epm_devices_discover_reboot">
					<label class="form-check-label" for="epm_devices_discover_reboot"><?= _("Reboot the added phones") ?></label>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-danger" data-dismiss="modal"><i class="fa fa-times"></i> <?= _("Close") ?></button>
				<button type="button" class="btn btn-primary" id="epm_devices_discover_add"><i class="fa fa-plus"></i> <?= _("Add Selected Phones") ?></button>
			</div>
		</div>
	</div>
</div>
