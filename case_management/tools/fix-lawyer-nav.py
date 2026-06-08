import re
from pathlib import Path

pages = Path(__file__).resolve().parent.parent / "pages"
repl = "ob_start();\ninclude __DIR__ . '/../inc/lawyer-menunav.php';\n$navHtml = ob_get_clean();\n"
pat = re.compile(
    r"// Create navigation HTML[^\n]*\r?\n\$navHtml = <<<'NAV'.*?^NAV;\r?\n\r?\n\$navHtml = str_replace\('\{LAWYER_NAME\}'[^\n]*\r?\n",
    re.MULTILINE | re.DOTALL,
)

for f in pages.glob("lawyer*.php"):
    text = f.read_text(encoding="utf-8")
    if "<<<'NAV'" not in text:
        continue
    new, n = pat.subn(repl, text)
    if n:
        f.write_text(new, encoding="utf-8")
        print("updated", f.name)
    else:
        print("no match", f.name)
