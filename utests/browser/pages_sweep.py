import sys
import os
from playwright.sync_api import sync_playwright
U = os.environ.get("EPM_URL", "https://pbx.example.local/admin/config.php")
PAGES = ["epm_devices", "epm_templates", "epm_templates&subpage=edit&custom=0&idsel=1", "epm_templates&subpage=edit&custom=1&idsel=4",
         "epm_config", "epm_advanced&subpage=settings", "epm_advanced&subpage=oui_manager", "epm_advanced&subpage=poce",
         "epm_advanced&subpage=manual_upload", "epm_placeholders", "epm_oss", "extensions&extdisplay=141"]
IGNORE = ["removeCookie"]   # FreePBX core (pbxlib.js)
with sync_playwright() as p:
    b = p.chromium.launch()
    for vw in (1366, 1024):
        pg = b.new_page(ignore_https_errors=True, viewport={"width": vw, "height": 768})
        pg.goto(U)
        pg.request.post(U, form={"username": "root", "password": sys.argv[1]}, headers={"Referer": U})
        for page in PAGES:
            errors = []
            handler = lambda e: errors.append(str(e).split("\n")[0][:140])
            pg.on("pageerror", handler)
            pg.goto(f"{U}?display={page}")
            pg.wait_for_load_state("networkidle")
            pg.wait_for_timeout(800)
            m = pg.evaluate("() => [document.documentElement.clientWidth, document.documentElement.scrollWidth]")
            errs = [e for e in errors if not any(i in e for i in IGNORE)]
            ok = m[1] <= m[0] + 1 and not errs
            print(f"{vw} {'OK  ' if ok else 'FAIL'} {page:50} width {m[1]}/{m[0]} {errs}")
            pg.remove_listener("pageerror", handler)
        pg.close()
    b.close()
