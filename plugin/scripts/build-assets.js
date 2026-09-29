#!/usr/bin/env node
'use strict';

// Minifies plugin JS/CSS into *.min.* siblings for production. Source files
// are the ones edited and enqueued when SCRIPT_DEBUG is on; the .min files
// are what ships and what WordPress enqueues by default.

const fs = require('fs');
const path = require('path');
const { minify } = require('terser');
const CleanCSS = require('clean-css');
const { verify: verifyCssParity } = require('./verify-css-parity');

const JS_DIRS = ['public/js', 'admin/js'];
const CSS_DIRS = ['public/css', 'admin/css'];

async function buildJs(dir) {
    const absDir = path.join(__dirname, '..', dir);
    for (const file of fs.readdirSync(absDir)) {
        if (!file.endsWith('.js') || file.endsWith('.min.js')) continue;
        const srcPath = path.join(absDir, file);
        const outPath = path.join(absDir, file.replace(/\.js$/, '.min.js'));
        const src = fs.readFileSync(srcPath, 'utf8');
        const result = await minify(src, { mangle: true, compress: true });
        if (result.error) throw result.error;
        fs.writeFileSync(outPath, result.code);
        console.log(`  ${path.join(dir, file)} -> ${path.join(dir, path.basename(outPath))}`);
    }
}

function buildCss(dir) {
    const absDir = path.join(__dirname, '..', dir);
    for (const file of fs.readdirSync(absDir)) {
        if (!file.endsWith('.css') || file.endsWith('.min.css')) continue;
        const srcPath = path.join(absDir, file);
        const outPath = path.join(absDir, file.replace(/\.css$/, '.min.css'));
        const src = fs.readFileSync(srcPath, 'utf8');
        const output = new CleanCSS({}).minify(src);
        if (output.errors.length) throw new Error(output.errors.join('\n'));
        fs.writeFileSync(outPath, output.styles);
        console.log(`  ${path.join(dir, file)} -> ${path.join(dir, path.basename(outPath))}`);

        if (!verifyCssParity(srcPath, outPath)) {
            throw new Error(`Minified CSS is not equivalent to source: ${srcPath}`);
        }
    }
}

(async () => {
    for (const dir of JS_DIRS) await buildJs(dir);
    for (const dir of CSS_DIRS) buildCss(dir);
})().catch((err) => {
    console.error(err);
    process.exit(1);
});
