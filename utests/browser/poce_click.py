import sys
import os
from playwright.sync_api import sync_playwright

U = os.environ.get("EPM_URL", "https://pbx.example.local/admin/config.php")
FLOWS = {
    "tab click":  "?display=epm_advanced",
    "direct url": "?display=epm_advanced&subpage=poce",
}
with sync_playwright() as p:
    b = p.chromium.launch()
    for name, url in FLOWS.items():
        for delay in (0, 1500):
            pg = b.new_page(ignore_https_errors=True, viewport={"width": 1366, "height": 768})
            log = []
            pg.on("pageerror", lambda e: log.append("ERR " + str(e).split("\n")[0][:150]))
            pg.on("console", lambda m: log.append("console " + m.text[:150]) if m.type in ("error", "warning") else None)
            pg.goto(U)
            pg.request.post(U, form={"username": "root", "password": sys.argv[1]}, headers={"Referer": U})
            pg.goto(U + url)
            pg.wait_for_load_state("domcontentloaded")
            if name == "tab click":
                pg.click("a[aria-controls=poce]")
            pg.wait_for_timeout(delay)
            # expand product -> "Files Config" -> click sip.cfg, like a user
            pg.wait_for_function("() => window.jQuery && $('#poce_tree_files').jstree(true) && $('#poce_tree_files').jstree(true).get_node('43')", timeout=20000)
            pg.evaluate("() => $('#poce_tree_files').jstree('open_node', '43')")
            pg.wait_for_function("() => $('#poce_tree_files').jstree(true).get_node('43_file')", timeout=20000)
            pg.evaluate("() => $('#poce_tree_files').jstree('open_node', '43_file')")
            pg.wait_for_function("() => $('#poce_tree_files').jstree(true).get_node('file_43_sip.cfg')", timeout=20000)
            pg.click("#poce_tree_files a.jstree-anchor:text-is('sip.cfg')")
            pg.wait_for_timeout(2500)
            toasts = pg.evaluate("() => [...document.querySelectorAll('.toast, .toast-message, .jq-toast-single, #toast-container *')].map(e => e.textContent.trim()).filter(Boolean).slice(0,5)")
            cm = pg.evaluate("() => typeof cmeditor !== 'undefined' && cmeditor !== null ? [cmeditor.lineCount(), [...document.querySelectorAll('#config_textarea .CodeMirror-line')].filter(l => l.getBoundingClientRect().width > 0).length, Math.round(document.querySelector('#config_textarea').getBoundingClientRect().width)] : null")
            ver = pg.evaluate("() => [...document.scripts].map(s => s.src).filter(s => s.includes('epm_advanced.js'))")
            print(f"{name:10} delay={delay:4} cmeditor(lines,visible,width)={cm} {ver} errors={[l for l in log if l.startswith('ERR') and 'removeCookie' not in l]}")
            pg.close()
    b.close()
