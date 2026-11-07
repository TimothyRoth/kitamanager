#!/usr/bin/env python3
"""Export a Markdown manual from docs/ to PDF.

    docs/build-manual.py [docs/benutzerhandbuch-kita.md]

Uses only the Python standard library and LibreOffice (soffice), which renders the
intermediate HTML. The Markdown subset is the one the manuals use: # headings,
paragraphs, - and 1. lists, | tables |, **bold**, `code` and [links](url).
"""
import html
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

DEFAULT_SOURCE = Path(__file__).with_name("benutzerhandbuch-kita.md")

# LibreOffice's HTML import honours only part of CSS: fonts must be set per
# element and table borders come from the HTML attributes.
STYLE = """
@page { size: A4; margin: 2cm; }
body, p, li, th, td, h1, h2, h3 { font-family: "Liberation Sans", Arial, sans-serif; color: #000000; }
body, p, li, td { font-size: 11pt; }
h1 { font-size: 22pt; margin: 0 0 0.6em; }
h2 { font-size: 15pt; margin: 1.2em 0 0.4em; page-break-after: avoid; }
p, li { margin: 0 0 0.4em; line-height: 1.4; }
th { font-size: 11pt; background: #e5e7eb; text-align: left; }
th, td { vertical-align: top; }
code { font-family: "Liberation Mono", monospace; font-size: 10pt; }
"""


def inline(text):
    text = html.escape(text, quote=False)
    text = re.sub(r"`([^`]+)`", r"<code>\1</code>", text)
    text = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", r'<a href="\2">\1</a>', text)
    return text


def table(lines):
    rows = [[cell.strip() for cell in line.strip().strip("|").split("|")] for line in lines]
    header, body = rows[0], rows[2:]
    out = ['<table border="1" cellpadding="5" cellspacing="0" width="100%">',
           "<thead><tr>" + "".join(f"<th>{inline(c)}</th>" for c in header) + "</tr></thead><tbody>"]
    out += ["<tr>" + "".join(f"<td>{inline(c)}</td>" for c in row) + "</tr>" for row in body]
    return "\n".join(out + ["</tbody></table>"])


def list_block(lines, ordered):
    marker = re.compile(r"^\s*(\d+\.|-)\s+")
    items = []
    for line in lines:
        if marker.match(line):
            items.append(marker.sub("", line))
        else:
            items[-1] += " " + line.strip()
    tag = "ol" if ordered else "ul"
    return f"<{tag}>" + "".join(f"<li>{inline(item)}</li>" for item in items) + f"</{tag}>"


def block(lines):
    first = lines[0]
    if first.startswith("#"):
        level = len(first) - len(first.lstrip("#"))
        return f"<h{level}>{inline(first[level:].strip())}</h{level}>"
    if first.startswith("|"):
        return table(lines)
    if re.match(r"^\d+\.\s", first):
        return list_block(lines, ordered=True)
    if first.startswith("- "):
        return list_block(lines, ordered=False)
    return "<p>" + inline(" ".join(line.strip() for line in lines)) + "</p>"


def markdown_to_html(source):
    blocks = [chunk.splitlines() for chunk in re.split(r"\n\s*\n", source.strip())]
    title = blocks[0][0].lstrip("# ").strip()
    body = "\n".join(block(lines) for lines in blocks)
    return (f"<!DOCTYPE html><html lang=\"de\"><head><meta charset=\"utf-8\">"
            f"<title>{html.escape(title)}</title><style>{STYLE}</style></head><body>{body}</body></html>")


def main():
    source = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_SOURCE
    soffice = shutil.which("soffice") or shutil.which("libreoffice")
    if soffice is None:
        sys.exit("LibreOffice (soffice) is needed to render the PDF.")

    with tempfile.TemporaryDirectory() as workdir:
        page = Path(workdir) / source.with_suffix(".html").name
        page.write_text(markdown_to_html(source.read_text(encoding="utf-8")), encoding="utf-8")
        subprocess.run(
            [soffice, "--headless", "--infilter=HTML (StarWriter)", "--convert-to", "pdf", "--outdir", str(source.parent), str(page)],
            check=True, stdout=subprocess.DEVNULL,
        )
    print(source.with_suffix(".pdf"))


if __name__ == "__main__":
    main()
