#!/usr/bin/env node
'use strict';

// Minifies theme JS/CSS into *.min.* siblings for production. Source files
// are the ones edited and enqueued when SCRIPT_DEBUG is on; the .min files
// are what ships and what WordPress enqueues by default.
//
// style.css is special-cased: WordPress reads its header comment block to
// identify the theme, so that file must stay intact and unminified. We copy
// the header comment into style.min.css followed by the minified rules, and
// enqueue style.min.css instead.

const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
const CleanCSS = require('clean-css');
const { verify: verifyCssParity } = require('./verify-css-parity');

const JS_DIR = 'assets/js';

async function buildJs() {
    const absDir = path.join(__dirname, '..', JS_DIR);
    for (const file of fs.readdirSync(absDir)) {
        if (!file.endsWith('.js') || file.endsWith('.min.js')) continue;
        const srcPath = path.join(absDir, file);
        const outPath = path.join(absDir, file.replace(/\.js$/, '.min.js'));
        const src = fs.readFileSync(srcPath, 'utf8');
        const result = await minify(src, { mangle: true, compress: true });
        if (result.error) throw result.error;
        fs.writeFileSync(outPath, result.code);
        console.log(`  ${path.join(JS_DIR, file)} -> ${path.join(JS_DIR, path.basename(outPath))}`);
    }
}

function buildStyleCss() {
    const srcPath = path.join(__dirname, '..', 'style.css');
    const outPath = path.join(__dirname, '..', 'style.min.css');
    const src = fs.readFileSync(srcPath, 'utf8');

    const headerMatch = src.match(/^\/\*[\s\S]*?\*\//);
    const header = headerMatch ? headerMatch[0] : '';
    const body = headerMatch ? src.slice(header.length) : src;

    const output = new CleanCSS({}).minify(body);
    if (output.errors.length) throw new Error(output.errors.join('\n'));

    fs.writeFileSync(outPath, header + '\n' + output.styles);
    console.log(`  style.css -> style.min.css`);

    if (!verifyCssParity(srcPath, outPath)) {
        throw new Error('Minified style.css is not equivalent to source');
    }
}

(async () => {
    await buildJs();
    buildStyleCss();
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
