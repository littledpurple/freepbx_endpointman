import sys, json
import os
from playwright.sync_api import sync_playwright

U = os.environ.get("EPM_URL", "https://pbx.example.local/admin/config.php")
CHAIN = r"""() => {
  const out = [];
  let e = document.querySelector('#config_textarea .CodeMirror') || document.querySelector('#config_textarea');
  while (e && e.id !== 'page') {
    const cs = getComputedStyle(e);
    out.push([e.tagName + (e.id ? '#' + e.id : '') + '.' + String(e.className).split(' ').slice(0, 3).join('.'),
      Math.round(e.getBoundingClientRect().width), cs.display, 'min=' + cs.minWidth, 'flex=' + cs.flex, 'w=' + cs.width].join(' | '));
    e = e.parentElement;
  }
  return out;
}"""
MEASURE = r"""() => ({W: document.documentElement.clientWidth, scroll: document.documentElement.scrollWidth})"""

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(ignore_https_errors=True, viewport={"width": 1366, "height": 768})
    errors = []
    pg.on("pageerror", lambda e: errors.append(str(e)))
    pg.goto(U)
    pg.request.post(U, form={"username": "root", "password": sys.argv[1]}, headers={"Referer": U})
    pg.goto(U + "?display=epm_advanced&subpage=poce")
    pg.wait_for_load_state("networkidle")
    print("BEFORE", pg.evaluate(MEASURE))
    pg.evaluate("() => epm_advanced_tab_poce_edit_file(null, {product: '43', type: 'file', name_file: 'sip.cfg', id_file: '43'})")
    pg.wait_for_timeout(2500)
    print("AFTER", pg.evaluate(MEASURE))
    for line in pg.evaluate(CHAIN):
        print("  ", line)
    pg.screenshot(path="/root/pwtest/poce.png")
    print("JS errors:", errors)
    b.close()
