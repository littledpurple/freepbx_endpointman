#!/usr/bin/env python3
"""Параметры портов (P1 …) и линий (SIP1 …) телефон берёт только после строк «--Port Config--» / «--SIP Line List--».
Генератор шаблона конфига D-Link DPH-150S (прошивка Fanvil): ключи выровнены до 19 символов перед ':',
как в файле, выгруженном с телефона (у «SIP{$line} …» — по подставленному «SIP1 …»).
Запуск: python3 brands/dlink/make_template.py  → brands/dlink/dph150s/$mac и dph150s/template_data.json"""
import os

def keep(var, name, value=None):
    """Строка только если значение задано (иначе телефон оставляет своё): {if $var != ''}…{/if}"""
    return "{if condition=\"$%s != ''\"}" % var + k(name, value if value is not None else "{$%s}" % var) + "\n{/if}"

def k(name, value):
    width = len(name.replace("{$line}", "1"))
    assert width <= 19, name
    return name + " " * (19 - width) + ":" + value

L = ["<<VOIP CONFIG FILE>>Version:{$config_version}", ""]
L += ["<GLOBAL CONFIG MODULE>",
      k("SNTP Server", "{$dlink_sntp_server}"),
      k("Enable SNTP", "1"),
      k("Time Zone", "{$dlink_time_zone}"),
      k("Enable Daylight", "0"),
      k("MMI Set", "{$language|12}"),
      ""]
L += ["<TELE CONFIG MODULE>",
      k("Dial End With #", "{$dial_end_hash|1}"),
      k("Accept Any Call", "{$accept_any_call|0}"),
      k("Dial With Timeout", "1"),
      k("Dial Timeout value", "{$dial_timeout|5}"),
      k("--Port Config--", ""),
      k("P1 CallWaiting", "{$call_waiting|1}"),
      ""]
L += ["<DSP CONFIG MODULE>",
      k("Signal Standard", "{$signal_standard|19}"),
      k("VAD", "{$vad|0}"),
      keep("ring_type", "Ring Type") +
      k("--Port Config--", ""),
      keep("output_vol", "P1 Output Vol") +
      keep("input_vol", "P1 Input Vol") +
      keep("handfree_vol", "P1 HandFree Vol") +
      keep("ringtone_vol", "P1 RingTone Vol") + k("P1 First Codec", "{$codec1|0}"),
      k("P1 Second Codec", "{$codec2|1}"),
      k("P1 Third Codec", "{$codec3|17}"),
      k("P1 Forth Codec", "{$codec4|-1}"),
      ""]
L += ["<SIP CONFIG MODULE>",
      k("--SIP Line List--", ""),
      "{line_loop}" + k("SIP{$line} Phone Number", "{$username}"),
      k("SIP{$line} Display Name", "{$displayname}"),
      k("SIP{$line} Register Addr", "{$server_host}"),
      k("SIP{$line} Register Port", "{$server_port}"),
      k("SIP{$line} Register User", "{$authname}"),
      k("SIP{$line} Register Pwd", "{$secret}"),
      k("SIP{$line} Register TTL", "{$reg_ttl|60}"),
      k("SIP{$line} Enable Reg", "1"),
      k("SIP{$line} Proxy Addr", "{$server_host}"),
      k("SIP{$line} Proxy Port", "{$server_port}"),
      k("SIP{$line} Proxy User", "{$authname}"),
      k("SIP{$line} Proxy Pwd", "{$secret}"),
      k("SIP{$line} DTMF Mode", "{$dtmf_mode|1}"),
      k("SIP{$line} Transport", "{$transport|0}"),
      k("SIP{$line} Mwi No.", "{$myvoicemail}"),
      "{/line_loop}"]
L += ["<MMI CONFIG MODULE>",
      "{if condition=\"$globaladminpassword != ''\"}" + k("--MMI Account--", ""),
      k("Account1 Name", "admin"),
      k("Account1 Pass", "{$globaladminpassword}"),
      k("Account1 Level", "10"),
      "{/if}{if condition=\"$dlink_guest_password != ''\"}" + k("Account2 Name", "guest"),
      k("Account2 Pass", "{$dlink_guest_password}"),
      k("Account2 Level", "5"),
      "{/if}"]
L += ["<PHONE CONFIG MODULE>",
      keep("keypad_password", "Keypad Password") +
      keep("lcd_logo", "LCD Logo") +
      k("Phone Model", "{$model}"),
      "{if condition=\"$fkey1_type != ''\"}" + k("--Function Key--", ""),
      k("Fkey1 Type", "{$fkey1_type}"),
      k("Fkey1 Value", "{$fkey1_value}"),
      k("Fkey1 Title", "{$fkey1_title}"),
      "{/if}"]
L += ["<AUTOUPDATE CONFIG MODULE>",
      k("Download Username", "{$dlink_prov_user}"),
      k("Download password", "{$dlink_prov_pass}"),
      k("Download Server IP", "{$dlink_prov_server}"),
      k("Config File Name", ""),
      k("Download Protocol", "{$dlink_prov_protocol}"),
      k("Download Mode", "{$download_mode|2}"),
      k("Download Interval", "{$download_interval|1}"),
      k("DHCP Option 66", "{$dhcp_option66|1}"),
      "",
      "<<END OF FILE>>", ""]
open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "dph150s", "$mac"), "w").write("\n".join(L))


# ---- Поля редактора шаблона (template_data.json). "" = оставить как в телефоне (строки нет в файле) ----
import json

def lst(var, desc, default, opts):
    return {"variable": "$" + var, "default_value": default, "description": desc, "type": "list",
            "data": [{"text": t, "value": v} for v, t in opts]}

def inp(var, desc, default="", maxc=None):
    d = {"variable": "$" + var, "default_value": default, "description": desc, "type": "input"}
    if maxc:
        d["max_chars"] = str(maxc)
    return d

BR = {"type": "break"}
KEEP = ("", "Keep phone value")
codecs = [("0", "G.711 A-law (PCMA)"), ("1", "G.711 u-law (PCMU)"), ("17", "G.729"), ("15", "G.723"), ("-1", "None")]
tz = [("auto", "Time zone of Endpoint Manager"), ("34", "GMT+02:00 Kaliningrad / Helsinki, Kyiv"), ("40", "GMT+03:00 Moscow, St. Petersburg"),
      ("43", "GMT+04:00 Samara / Abu Dhabi"), ("46", "GMT+05:00 Yekaterinburg"), ("50", "GMT+06:00 Omsk / Novosibirsk"),
      ("54", "GMT+07:00 Krasnoyarsk"), ("60", "GMT+08:00 Irkutsk"), ("63", "GMT+09:00 Yakutsk"), ("67", "GMT+10:00 Vladivostok"),
      ("71", "GMT+11:00 Magadan"), ("72", "GMT+12:00 Kamchatka"), ("25", "GMT London")]
vol = [KEEP] + [(str(i), str(i)) for i in range(1, 10)]
rings = [KEEP] + [(str(i), "Type %d" % i) for i in range(1, 10)] + [(str(100 + i), "User %d" % i) for i in range(1, 6)]
fkey_types = [KEEP, ("4", "Function (Value: F_MWI = voicemail)"), ("1", "Speed dial (Value: number)"), ("2", "Line"), ("5", "DTMF"), ("0", "None")]

data = {"template_data": {"category": [
  {"name": "general", "subcategory": [
    {"name": "Time", "item": [
      lst("time_zone", "Time Zone", "auto", tz), BR,
      inp("sntp_server", "SNTP Server (empty: time server of Endpoint Manager)", "", 32),
    ]},
    {"name": "Phone", "item": [
      lst("language", "Language", "12", [("12", "Russian"), ("1", "English")]), BR,
      inp("lcd_logo", "Greeting on the display (empty: keep the one of the phone)", "", 16), BR,
      lst("signal_standard", "Tones (dial, busy, ring back)", "19", [("19", "Russia"), ("2", "Germany"), ("13", "United Kingdom"), ("11", "USA"), ("1", "China")]), BR,
      lst("call_waiting", "Call Waiting", "1", [("1", "Enabled"), ("0", "Disabled")]),
    ]},
    {"name": "Dialing", "item": [
      lst("dial_end_hash", "# ends the number", "1", [("1", "Yes"), ("0", "No")]), BR,
      inp("dial_timeout", "Dial timeout, seconds", "5", 2),
    ]},
    {"name": "Audio", "item": [
      lst("codec1", "Codec 1", "0", codecs), lst("codec2", "Codec 2", "1", codecs), BR,
      lst("codec3", "Codec 3", "17", codecs), lst("codec4", "Codec 4", "-1", codecs), BR,
      lst("vad", "VAD (silence suppression)", "0", [("0", "Disabled"), ("1", "Enabled")]),
    ]},
    {"name": "Volume and Ring", "item": [
      lst("ringtone_vol", "Ring volume", "", vol), lst("ring_type", "Ring melody", "", rings), BR,
      lst("output_vol", "Handset volume", "", vol), lst("input_vol", "Microphone volume", "", vol), BR,
      lst("handfree_vol", "Speakerphone volume", "", vol),
    ]},
    {"name": "SIP", "item": [
      lst("dtmf_mode", "DTMF", "1", [("1", "RFC2833"), ("2", "SIP INFO"), ("0", "In audio (inband)")]), BR,
      inp("reg_ttl", "Registration expires, seconds", "60", 5),
    ]},
    {"name": "Security", "item": [
      lst("accept_any_call", "Calls not from the PBX (direct IP)", "0", [("0", "Reject"), ("1", "Accept")]), BR,
      inp("guest_password", "Password of the web user \"guest\" (empty: keep; needs Phone Admin Password)", "", 16), BR,
      inp("keypad_password", "Password of the menu on the phone keypad (empty: keep)", "", 8),
    ]},
    {"name": "Function Key F1", "item": [
      lst("fkey1_type", "Type", "", fkey_types), BR,
      inp("fkey1_value", "Value", "", 30), inp("fkey1_title", "Title", "", 16),
    ]},
    {"name": "Maintenance", "item": [
      lst("dhcp_option66", "Take the provisioning server from DHCP option 66", "1", [("1", "Yes"), ("0", "No")]),
    ]},
    {"name": "Autoprovisioning", "item": [
      lst("download_mode", "Download the configuration", "2", [("2", "On boot and every N hours"), ("1", "Only on boot"), ("0", "Never (stop provisioning)")]), BR,
      inp("download_interval", "Every N hours", "1", 3),
    ]},
  ]}
]}}
json.dump(data, open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "dph150s", "template_data.json"), "w"), indent=2, ensure_ascii=False)
