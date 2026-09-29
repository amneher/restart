#!/usr/bin/env node
'use strict';

// Structural equivalence check between a source stylesheet and its minified
// build. A plain text diff is unreliable here: clean-css legitimately
// reorders/merges selector lists that share an identical declaration block,
// and rewrites values into shorter-but-equivalent forms (#ffffff -> #fff,
// 0.05em -> .05em, background:none -> background:0 0, quotes stripped from
// simple attribute selectors, whitespace collapsed in @media params, etc).
// So this parses both files with postcss, canonicalizes every declaration
// value and selector the same way clean-css's documented "level 1" safe
// optimizations would, and compares the resulting maps — which is what
// actually determines rendered behavior. Any remaining difference after
// canonicalization is a real regression, not a formatting quirk.

const fs = require('fs');
const postcss = require('postcss');
const selectorParser = require('postcss-selector-parser');

// Properties where clean-css treats the literal value "none" as equivalent
// to a shorter shorthand form.
const NONE_EQUIVALENTS = {
    background: '0 0',
    outline: '0',
};
// clean-css treats these values the same way it treats "none" for the props above.
const NONE_ALIASES = ['none', 'transparent'];

function canonicalizeSelector(sel) {
    let out = '';
    selectorParser((selectors) => {
        selectors.walk((node) => {
            node.spaces = { before: '', after: '' };
            if (node.type === 'attribute') {
                // Quotes are only required when the value needs them (whitespace,
                // special chars); clean-css strips them otherwise.
                if (node.value !== undefined && /^[a-zA-Z0-9_-]+$/.test(node.value)) {
                    node.quoteMark = null;
                }
            }
        });
        out = selectors.toString().replace(/\s+/g, ' ').trim();
    }).processSync(sel);
    // "*::before"/"*::after" (universal selector immediately before a
    // pseudo-element) is redundant per spec — clean-css drops the "*".
    out = out.replace(/^\*(::)/, '$1');
    return out;
}

function canonicalizeHex(value) {
    return value.replace(/#([0-9a-fA-F]{6})\b/g, (m, hex) => {
        const lower = hex.toLowerCase();
        if (lower[0] === lower[1] && lower[2] === lower[3] && lower[4] === lower[5]) {
            return `#${lower[0]}${lower[2]}${lower[4]}`;
        }
        return `#${lower}`;
    }).replace(/#([0-9a-fA-F]{3})\b/g, (m, hex) => `#${hex.toLowerCase()}`);
}

function canonicalizeValue(value) {
    let v = value.replace(/\s+/g, ' ').trim();
    v = canonicalizeHex(v);
    // Strip an optional leading zero before a decimal point (0.05 -> .05),
    // including negatives, but not inside larger numbers like "100.5".
    v = v.replace(/(^|[\s,(])-?0(\.\d+)/g, (m, pre, frac) => `${pre}${m.includes('-') ? '-' : ''}${frac}`);
    // Collapse whitespace after commas inside function notation (clean-css
    // strips it: "rgba(0, 0, 0, .2)" -> "rgba(0,0,0,.2)").
    v = v.replace(/,\s+/g, ',');
    // Same for a bare "/" separator (e.g. "4 / 3" aspect-ratio -> "4/3").
    v = v.replace(/\s*\/\s*/g, '/');
    return v;
}

const FONT_WEIGHT_KEYWORDS = { normal: '400', bold: '700' };

function canonicalizeDecl(prop, value, important) {
    let v = canonicalizeValue(value);
    const propLower = prop.toLowerCase();
    if (NONE_ALIASES.includes(v) && Object.prototype.hasOwnProperty.call(NONE_EQUIVALENTS, propLower)) {
        v = NONE_EQUIVALENTS[propLower];
    }
    if (propLower === 'font-weight' && Object.prototype.hasOwnProperty.call(FONT_WEIGHT_KEYWORDS, v)) {
        v = FONT_WEIGHT_KEYWORDS[v];
    }
    return `${propLower}:${v}${important ? ' !important' : ''}`;
}

function canonicalizeAtRuleParams(params) {
    return params.replace(/\s+/g, '');
}

// Builds { key -> [ "prop:value" , ... ] } where key is the at-rule context
// (e.g. an @media prelude) plus the individual selector, all canonicalized.
function buildRuleMap(css, filename) {
    const root = postcss.parse(css, { from: filename });
    const map = new Map();

    root.walkRules((rule) => {
        // Skip rules nested inside @keyframes: percentage "selectors" like
        // "50%" aren't real selectors, and are compared as opaque blocks via
        // the @keyframes at-rule walk below instead.
        if (rule.parent && rule.parent.type === 'atrule' && rule.parent.name === 'keyframes') {
            return;
        }

        const context = [];
        let p = rule.parent;
        while (p && p.type === 'atrule') {
            context.unshift(`@${p.name} ${canonicalizeAtRuleParams(p.params)}`);
            p = p.parent;
        }
        const contextKey = context.join(' > ');

        const decls = [];
        rule.walkDecls((decl) => {
            decls.push(canonicalizeDecl(decl.prop, decl.value, decl.important));
        });

        rule.selectors.forEach((sel) => {
            const key = `${contextKey} :: ${canonicalizeSelector(sel)}`;
            if (!map.has(key)) map.set(key, []);
            map.get(key).push(...decls);
        });
    });

    root.walkAtRules('keyframes', (atRule) => {
        const context = [];
        let p = atRule.parent;
        while (p && p.type === 'atrule') {
            context.unshift(`@${p.name} ${canonicalizeAtRuleParams(p.params)}`);
            p = p.parent;
        }
        const key = `${context.join(' > ')} :: @keyframes ${canonicalizeAtRuleParams(atRule.params)}`;
        const body = [];
        atRule.walkDecls((decl) => {
            body.push(canonicalizeDecl(decl.prop, decl.value, decl.important));
        });
        map.set(key, body);
    });

    // Rules with no declarations are no-ops that clean-css legitimately
    // drops entirely; don't require the minified output to keep them.
    for (const [key, decls] of map) {
        if (decls.length === 0) map.delete(key);
    }

    return map;
}

function diffMaps(sourceMap, minMap) {
    const problems = [];

    for (const [key, decls] of sourceMap) {
        if (!minMap.has(key)) {
            problems.push(`Missing in minified output: ${key}`);
            continue;
        }
        const minDecls = minMap.get(key);
        if (JSON.stringify(decls) !== JSON.stringify(minDecls)) {
            problems.push(`Declarations differ for: ${key}\n    source: ${JSON.stringify(decls)}\n    min:    ${JSON.stringify(minDecls)}`);
        }
    }
    for (const key of minMap.keys()) {
        if (!sourceMap.has(key)) {
            problems.push(`Unexpected new rule in minified output: ${key}`);
        }
    }
    return problems;
}

function verify(sourcePath, minPath) {
    const sourceCss = fs.readFileSync(sourcePath, 'utf8');
    const minCss = fs.readFileSync(minPath, 'utf8');

    const sourceMap = buildRuleMap(sourceCss, sourcePath);
    const minMap = buildRuleMap(minCss, minPath);

    const problems = diffMaps(sourceMap, minMap);
    if (problems.length) {
        console.error(`CSS parity check FAILED: ${sourcePath} vs ${minPath}`);
        problems.forEach((p) => console.error(`  - ${p}`));
        return false;
    }
    console.log(`  ${sourcePath} <-> ${minPath}: ${sourceMap.size} rules match`);
    return true;
}

module.exports = { verify };

if (require.main === module) {
    const [sourcePath, minPath] = process.argv.slice(2);
    if (!sourcePath || !minPath) {
        console.error('Usage: verify-css-parity.js <source.css> <minified.css>');
        process.exit(1);
    }
    process.exit(verify(sourcePath, minPath) ? 0 : 1);
}
