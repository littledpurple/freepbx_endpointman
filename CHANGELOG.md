# Changelog

Fork of [vsc55/freepbx_endpointman](https://github.com/vsc55/freepbx_endpointman) for FreePBX 17 / Asterisk 22 /
PHP 8.2. The list below covers changes relative to upstream `release/17.0` (base `5c9ccbf`).

## 17.0.6

### Provisioning (`p.php`)

- Per-MAC configuration files are generated on request; the phone gets its own file plus the shared family file.
- Phone file names are matched case-insensitively (Digium first requests `$mac.cfg` in lower case).
- Missing files return `404` before authentication, so a booting phone produces fewer `401` + `404` pairs.
- Optional HTTP Basic authentication (`prov_user` / `prov_pass`) and a CIDR allowlist (`prov_networks`),
  both configured in Advanced Settings.
- Configuration version grows only when the generated content changes; an unchanged file is not applied again.
- Phone-initiated configuration upload is accepted.

### Device list

- Add, edit and delete phones with several lines and templates on one page.
- Rebuild configuration and reboot a phone (AMI `PJSIPNotify`) from the list.
- Discover phones from the ARP cache (optional `nmap` scan) and from SIP registrations, including User-Agent.
- Provisioning can be disabled for an individual device.
- The End Point Manager block on the extension page works again.

### Template manager

- Template settings are visible and saved: the old `liquid-slider` markup kept them hidden.
- The Delete button no longer acts as Save; it deletes the template. Device settings have no Delete button.
- "Other Settings" tabs expose every variable of the family configuration files that is not described by the
  package (call type, forwarding, MWI, QoS, ...), per file and per line. Empty means the package default.

### POCE (Provisioning Configuration Editor)

- Editors are created on demand, so a file can be selected before the tab is initialised ("Editor Code is null!").
- A file opened in a hidden tab is measured and rendered after the tab becomes visible.
- The page no longer stretches: the FreePBX `#page_body` is `display: table`, so the editor container uses
  `width: 0` + `min-width: 100%` + `contain`, and CodeMirror starts with native scrolling.
- File copies are stored from the raw `$_POST` instead of HTML-encoded text; previously corrupted copies are
  decoded during generation.
- File tree uses jstree.

### Packages and brands

- Manual Upload imports brand packages and Provisioner packages.
- Updating a brand now refreshes its families and models.
- Digium D40 package.
- D-Link DPH-150S/SE package (Fanvil firmware), with its own config version format and `{if}` handling.
- D-Link template: security, volume, F1 key, DHCP option 66.

### Security

- AJAX requests require authentication again; the debug bypass (`authenticate=false`, `allowremote=true`)
  is removed.

### Compatibility and internals

- PHP 8.2 port: module tables from `module.xml`, kvstore instead of `configmod`, `PDO::FETCH_ASSOC`,
  Bootstrap 4.6 markup, internal tar.gz download/unpack, JSON autoload, classes split into `lib/`.
- Obsolete code moved to `_old/` or removed.
- Unit tests, a jsdom test for the device list, and Playwright browser checks under `utests/browser/`.
