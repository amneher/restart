#!/usr/bin/env node
/**
 * Capture full-page screenshots of the running local stack for the docs site.
 *
 * Prerequisite: `make up && make seed` so WordPress is available at
 * http://localhost:8083 with the demo user present.
 *
 * Usage:
 *   node docs/scripts/screenshots.js
 *
 * Output:
 *   docs/docs/theme/screenshots/{homepage,login,register,my-registries,start-registry}.png
 */

'use strict';

const fs = require('fs');
const path = require('path');

let chromium;
try {
    ({ chromium } = require('playwright'));
} catch (err) {
    console.error('Playwright is not installed.');
    console.error('Install it with:');
    console.error('  cd docs && npm install');
    console.error('  npx playwright install chromium');
    process.exit(1);
}

const BASE_URL = process.env.WP_URL || 'http://localhost:8083';
const DEMO_USER = process.env.DEMO_USER || 'demo';
const DEMO_PASS = process.env.DEMO_PASS || 'demo';

const OUT_DIR = path.resolve(__dirname, '..', 'docs', 'theme', 'screenshots');
fs.mkdirSync(OUT_DIR, { recursive: true });

const outPath = (name) => path.join(OUT_DIR, `${name}.png`);

async function capture(page, name, url, { authenticated = false } = {}) {
    console.log(`  → ${name} (${url})`);
    await page.goto(url, { waitUntil: 'networkidle' });
    // Brief settle to allow webfonts and animations to finish.
    await page.waitForTimeout(500);
    await page.screenshot({ path: outPath(name), fullPage: true });
    console.log(`    saved → ${outPath(name)}`);
}

async function login(page) {
    console.log(`  → logging in as ${DEMO_USER}`);
    await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'networkidle' });
    await page.fill('#user_login', DEMO_USER);
    await page.fill('#user_pass', DEMO_PASS);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('#wp-submit'),
    ]);
}

async function main() {
    console.log(`Capturing screenshots from ${BASE_URL}`);
    console.log(`Output directory: ${OUT_DIR}`);

    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
    });
    const page = await context.newPage();

    try {
        // Public pages (no auth)
        await capture(page, 'homepage', `${BASE_URL}/`);
        await capture(page, 'login', `${BASE_URL}/login/`);
        await capture(page, 'register', `${BASE_URL}/register/`);

        // Authenticated pages
        await login(page);
        await capture(page, 'my-registries', `${BASE_URL}/my-registries/`, { authenticated: true });
        await capture(page, 'start-registry', `${BASE_URL}/start-a-registry/`, { authenticated: true });

        console.log('\nDone.');
    } catch (err) {
        console.error('\nScreenshot run failed:');
        console.error(err && err.message ? err.message : err);
        process.exitCode = 1;
    } finally {
        await context.close();
        await browser.close();
    }
}

main();
