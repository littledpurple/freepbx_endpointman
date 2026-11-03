<?PHP

/**
 * D-Link Base File (DPH-150S/SE, DPH-400 h/w Fx: firmware of Fanvil, "<<VOIP CONFIG FILE>>" text configuration)
 *
 * @license MPL / GPLv2 / LGPL
 * @package Provisioner
 */
abstract class endpoint_dlink_base extends endpoint_base {

    public $brand_name = 'dlink';
    protected $use_system_dst = FALSE;

    // The phone applies the file only if its version is higher than the current one ("Version:2.0002" by default);
    // Endpoint Manager raises the number when the content changes (Provisioning::applyConfigVersion)
    public $config_version_format = '2.%04d';
    public $config_version_start = 100;

    // "Time Zone" of the phone (index of its list) => offset in minutes. Several cities share an offset: the first
    // index of an offset is used, Russian cities first.
    protected $time_zones = array(
        34 => 120, 40 => 180, 43 => 240, 46 => 300, 50 => 360, 54 => 420, 60 => 480, 63 => 540, 67 => 600, 71 => 660, 72 => 720,
        0 => -720, 1 => -660, 2 => -600, 3 => -540, 4 => -480, 5 => -420, 9 => -360, 13 => -300, 15 => -240, 18 => -210,
        19 => -180, 22 => -120, 24 => -60, 25 => 0, 27 => 60, 42 => 210, 45 => 270, 48 => 330, 49 => 345, 53 => 390,
        64 => 570, 74 => 780,
    );

    // "Download Protocol" of the autoprovisioning
    protected $download_protocols = array('ftp' => 1, 'tftp' => 2, 'http' => 4);

    function reboot() {
        if (($this->engine == "asterisk") AND ($this->system == "unix")) {
            exec($this->engine_location . " -rx 'pjsip send notify dlink-reboot endpoint " . $this->settings['line'][0]['username'] . "'");
        }
    }

    function prepare_for_generateconfig() {
        // The phone asks for its MAC in lower case without extension: "84c9b2c4b0b7"
        $this->mac = strtolower($this->mac);
        parent::prepare_for_generateconfig();

        // Time zone: the one of the template, otherwise from the time zone of Endpoint Manager
        if (isset($this->settings['time_zone']) && $this->settings['time_zone'] !== '' && $this->settings['time_zone'] !== 'auto') {
            $this->settings['dlink_time_zone'] = $this->settings['time_zone'];
        } else {
            $offset = 180;
            if ($this->DateTimeZone instanceof DateTimeZone) {
                $offset = intdiv($this->DateTimeZone->getOffset(new DateTime('now', $this->DateTimeZone)), 60);
            }
            $index = array_search($offset, $this->time_zones);
            $this->settings['dlink_time_zone'] = ($index === FALSE) ? 40 : $index;
        }

        // SNTP: the server of the template, otherwise the time server of Endpoint Manager, otherwise the PBX
        $sntp = isset($this->settings['sntp_server']) ? trim($this->settings['sntp_server']) : '';
        if ($sntp === '') {
            $sntp = !empty($this->settings['ntp']) ? $this->settings['ntp'] : (isset($this->settings['server_host']) ? $this->settings['server_host'] : '');
        }
        $this->settings['dlink_sntp_server'] = $sntp;

        // Autoprovisioning of the phone keeps pointing to this server: HTTP to p.php or TFTP to the configuration directory
        $provision = isset($this->settings['provision']) ? $this->settings['provision'] : array();
        $protocol = isset($provision['protocol']) ? strtolower($provision['protocol']) : 'tftp';
        $this->settings['dlink_prov_protocol'] = isset($this->download_protocols[$protocol]) ? $this->download_protocols[$protocol] : 2;
        $this->settings['dlink_prov_server'] = isset($provision['path']) ? preg_replace('#^[a-z]+://#i', '', rtrim($provision['path'], '/')) : '';
        $this->settings['dlink_prov_user'] = isset($this->settings['prov_user']) ? $this->settings['prov_user'] : '';
        $this->settings['dlink_prov_pass'] = isset($this->settings['prov_pass']) ? $this->settings['prov_pass'] : '';

        // Accounts of the web go as one list after "--MMI Account--": the guest only together with the admin
        unset($this->settings['dlink_guest_password']);
        if (!empty($this->settings['globaladminpassword']) && isset($this->settings['guest_password']) && $this->settings['guest_password'] !== '') {
            $this->settings['dlink_guest_password'] = $this->settings['guest_password'];
        }
    }

    // Values of the template used inside {line_loop}: the loop sees only the data of the line
    protected $line_template_values = array('dtmf_mode' => 1, 'reg_ttl' => 60);

    function parse_lines_hook($line_data, $line_total) {
        foreach ($this->line_template_values as $name => $default) {
            if (!isset($line_data[$name]) || $line_data[$name] === '') {
                $line_data[$name] = (isset($this->settings[$name]) && $this->settings[$name] !== '') ? $this->settings[$name] : $default;
            }
        }
        if (empty($line_data['authname'])) {
            $line_data['authname'] = $line_data['username'];
        }
        return $line_data;
    }

    // The phone writes its file with CRLF
    public function generate_file($filename, $extradata, $ignoredynamicmapping=FALSE, $prepare=FALSE) {
        if ($prepare) {
            $this->prepare_for_generateconfig();
        }
        // {if condition=...} are resolved here: endpoint_base::parse_conditionals() drops the block that follows
        // every true condition (unconditional preg_replace after the replacement)
        $saved = $this->config_files_override;
        $source = isset($this->config_files_override[$extradata]) ? $this->config_files_override[$extradata]
            : file_get_contents($this->root_dir . $this->modules_path . $this->brand_name . "/" . $this->family_line . "/" . $extradata);
        $this->config_files_override[$extradata] = $this->resolve_conditionals($source);
        try {
            $data = parent::generate_file($filename, $extradata, $ignoredynamicmapping, FALSE);
        } finally {
            $this->config_files_override = $saved;
        }
        if (is_string($data)) {
            $data = preg_replace("/\r?\n/", "\r\n", $data);
        }
        return $data;
    }

    // Same syntax as endpoint_base: isset($var), $var == 'x', $var != 'x' (settings, not line values)
    protected function resolve_conditionals($contents) {
        $settings = $this->settings;
        return preg_replace_callback('/{if condition="(.*?)"}(.*?){\/if}/si', function ($m) use ($settings) {
            if (preg_match('/^isset\(\$(\w*)\)$/i', $m[1], $c)) {
                $ok = isset($settings[$c[1]]);
            } elseif (preg_match('/^\$(\w+) (==|!=) \'(.*)\'$/i', $m[1], $c)) {
                $ok = isset($settings[$c[1]]) && (($c[2] == '==') ? ((string)$settings[$c[1]] === $c[3]) : ((string)$settings[$c[1]] !== $c[3]));
            } else {
                $ok = FALSE;
            }
            return $ok ? $m[2] : '';
        }, $contents);
    }

}
