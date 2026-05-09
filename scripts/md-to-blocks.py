#!/usr/bin/env python3
"""Convert a project copy markdown file to Gutenberg block markup.

Handles the narrow subset our copy actually uses:
- # Title (stripped — post title is separate)
- *italic line* (whole line in italics)
- ## Heading (level-2 heading block)
- --- (separator block)
- - bullet list
- **Bold:** prefix + content (paragraph with leading <strong>)
- plain paragraphs

Inline emphasis (**bold** / *italic*) inside paragraphs and bullets is preserved
as <strong>/<em>.
"""

import re
import sys
from pathlib import Path


def inline(text: str) -> str:
    """Render inline markdown emphasis to HTML."""
    text = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)", r"<em>\1</em>", text)
    return text


def convert(md: str) -> str:
    lines = md.splitlines()
    out: list[str] = []
    i = 0
    n = len(lines)

    while i < n:
        line = lines[i].rstrip()

        if not line:
            i += 1
            continue

        # H1 — strip (post title)
        if line.startswith("# "):
            i += 1
            continue

        # H2
        if line.startswith("## "):
            text = inline(line[3:].strip())
            out.append('<!-- wp:heading -->')
            out.append(f"<h2 class=\"wp-block-heading\">{text}</h2>")
            out.append('<!-- /wp:heading -->')
            i += 1
            continue

        # Separator
        if line == "---":
            out.append('<!-- wp:separator -->')
            out.append('<hr class="wp-block-separator has-alpha-channel-opacity"/>')
            out.append('<!-- /wp:separator -->')
            i += 1
            continue

        # Bullet list
        if line.startswith("- "):
            items: list[str] = []
            while i < n and lines[i].rstrip().startswith("- "):
                items.append(inline(lines[i].rstrip()[2:]))
                i += 1
            out.append('<!-- wp:list -->')
            out.append('<ul class="wp-block-list">')
            for it in items:
                out.append(f"<!-- wp:list-item --><li>{it}</li><!-- /wp:list-item -->")
            out.append('</ul>')
            out.append('<!-- /wp:list -->')
            continue

        # Whole-line italic — `*text*`
        m = re.fullmatch(r"\*(.+)\*", line)
        if m:
            text = inline(line)  # falls through to <em>
            out.append('<!-- wp:paragraph -->')
            out.append(f"<p>{text}</p>")
            out.append('<!-- /wp:paragraph -->')
            i += 1
            continue

        # Paragraph — gather contiguous non-blank, non-special lines
        para: list[str] = [line]
        i += 1
        while i < n:
            nxt = lines[i].rstrip()
            if (not nxt
                    or nxt.startswith("# ")
                    or nxt.startswith("## ")
                    or nxt.startswith("- ")
                    or nxt == "---"):
                break
            para.append(nxt)
            i += 1
        joined = "<br>".join(inline(p) for p in para)
        out.append('<!-- wp:paragraph -->')
        out.append(f"<p>{joined}</p>")
        out.append('<!-- /wp:paragraph -->')

    return "\n\n".join(out) + "\n"


def main() -> None:
    if len(sys.argv) < 2:
        sys.exit("usage: md2blocks.py <input.md> [output.html]")
    src = Path(sys.argv[1])
    dst = Path(sys.argv[2]) if len(sys.argv) >= 3 else src.with_suffix(".html")
    text = src.read_text(encoding="utf-8")
    blocks = convert(text)
    dst.write_text(blocks, encoding="utf-8")
    print(f"wrote {dst} ({len(blocks)} chars)")


if __name__ == "__main__":
    main()
