#!/usr/bin/env node
'use strict';

// Generates assets/copy/faq.html (core/details blocks) from assets/copy/faq.md.
// faq.md is the single source for FAQ copy: the activator seeds the FAQ page
// from faq.html, and the therestart/faq-content pattern reads faq.html too, so
// editing faq.md and running `npm run build:faq` updates both.
//
// faq.md format: entries separated by `---` lines, each starting with a
// `**Question?**` line followed by one or more answer paragraphs. Inline
// **bold** and [text](url) are supported; HTML comments are dropped.

const fs = require('fs');
const path = require('path');

const COPY_DIR = path.join(__dirname, '..', 'assets', 'copy');

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function renderInline(text) {
    return escapeHtml(text)
        .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, url) => `<a href="${url.replace(/"/g, '&quot;')}">${label}</a>`)
        .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
}

function parseFaqMarkdown(md) {
    const items = [];
    const chunks = md.replace(/<!--[\s\S]*?-->/g, '').split(/^---\s*$/m);
    for (const chunk of chunks) {
        const lines = chunk.trim().split('\n');
        const match = lines[0].match(/^\*\*(.+)\*\*\s*$/);
        if (!match) continue; // title block or stray text

        const paragraphs = lines.slice(1).join('\n').split(/\n\s*\n/)
            .map((p) => p.replace(/\s*\n\s*/g, ' ').trim())
            .filter(Boolean);
        if (paragraphs.length === 0) {
            throw new Error(`FAQ entry has no answer: "${match[1]}"`);
        }
        items.push({ question: match[1].trim(), paragraphs });
    }
    return items;
}

function renderFaqBlocks(items) {
    const blocks = items.map(({ question, paragraphs }) => {
        const body = paragraphs
            .map((p) => `<!-- wp:paragraph -->\n<p>${renderInline(p)}</p>\n<!-- /wp:paragraph -->`)
            .join('\n\n');
        return `<!-- wp:details -->\n<details class="wp-block-details"><summary>${renderInline(question)}</summary>\n${body}</details>\n<!-- /wp:details -->`;
    });
    return `<!-- wp:group {"style":{"spacing":{"blockGap":"0"}}} -->\n<div class="wp-block-group">\n\n${blocks.join('\n\n')}\n\n</div>\n<!-- /wp:group -->\n`;
}

function build() {
    const md = fs.readFileSync(path.join(COPY_DIR, 'faq.md'), 'utf8');
    const items = parseFaqMarkdown(md);
    fs.writeFileSync(path.join(COPY_DIR, 'faq.html'), renderFaqBlocks(items));
    console.log(`  assets/copy/faq.md -> assets/copy/faq.html (${items.length} entries)`);
}

if (require.main === module) {
    build();
}

module.exports = { parseFaqMarkdown, renderFaqBlocks, renderInline };
