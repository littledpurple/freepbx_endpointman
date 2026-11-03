Проверки в настоящем браузере (Playwright + Chromium). Нужен FreePBX с установленным модулем,
Playwright и пароль администратора FreePBX.

    export EPM_URL='https://pbx.example.local/admin/config.php'
    python utests/browser/<скрипт>.py '<пароль администратора FreePBX>'

- `pages_sweep.py` — все страницы модуля при 1366 и 1024 px: ошибки JS и выход за ширину экрана
- `poce_click.py` — POCE: вкладка/прямой URL, выбор файла сразу и с задержкой; строки редактора видны
- `poce_width.py` — цепочка ширин от CodeMirror до `#page_body` (поиск растяжения страницы)
