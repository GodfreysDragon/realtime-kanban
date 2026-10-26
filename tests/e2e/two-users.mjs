/**
 * Two-browser end-to-end check against a running app + Reverb server.
 *
 *   php artisan reverb:start & php artisan serve
 *   npm run test:e2e                      # E2E_URL defaults to http://127.0.0.1:8000
 *
 * Uses an installed Edge or Chrome (E2E_BROWSER=msedge|chrome) via playwright-core,
 * so no browser download is needed. E2E_BROWSER_PATH points it at any other
 * Chromium binary instead. Screenshots go to storage/e2e.
 */
import { mkdirSync } from 'node:fs';
import { chromium } from 'playwright-core';

const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8000';
const OUT = 'storage/e2e';
mkdirSync(OUT, { recursive: true });
const results = [];

async function step(name, fn) {
    const started = Date.now();
    try {
        await fn();
        results.push(`PASS ${name} (${Date.now() - started}ms)`);
    } catch (e) {
        results.push(`FAIL ${name}: ${e.message.split('\n')[0]}`);
    }
}

const browser = await chromium.launch({
    ...(process.env.E2E_BROWSER_PATH
        ? { executablePath: process.env.E2E_BROWSER_PATH }
        : { channel: process.env.E2E_BROWSER ?? 'msedge' }),
    headless: true,
});
const ctxA = await browser.newContext({ viewport: { width: 1400, height: 850 } });
const ctxB = await browser.newContext({ viewport: { width: 1400, height: 850 } });
const a = await ctxA.newPage();
const b = await ctxB.newPage();
for (const [who, page] of [['A', a], ['B', b]]) {
    page.on('pageerror', (e) => results.push(`PAGE ERROR ${who}: ${e.message}`));
    page.on('dialog', (d) => d.accept());
}

async function join(page, name) {
    await page.goto(BASE);
    await page.fill('#name', name);
    await page.click('button[type=submit]');
    await page.waitForSelector('text=Boards');
}

const column = (page, name) => page.locator('section[data-column-id]', { has: page.locator('h2', { hasText: name }) });
async function addCard(page, columnName, title) {
    const col = column(page, columnName);
    if ((await col.locator('textarea').count()) === 0) await col.locator('text=+ Add card').click();
    await col.locator('textarea').fill(title);
    await col.locator('textarea').press('Enter');
}
const card = (page, title) => page.locator('[data-card-id]', { hasText: title });

await step('both users join', async () => {
    await join(a, 'Ada');
    await join(b, 'Bob');
});

let boardUrl;
await step('Ada creates a board', async () => {
    await a.fill('input[placeholder="New board name"]', 'E2E board');
    await a.click('text=Create');
    await a.waitForURL(/#\/boards\/\d+/);
    boardUrl = a.url();
    await a.waitForSelector('[data-testid=connection-status]:has-text("Live")');
});

await step('membership: Bob is refused until Ada shares the invite link', async () => {
    await b.goto(boardUrl);
    await b.locator("text=You're not a member of this board").waitFor({ timeout: 5000 });

    await a.click('button:has-text("Share")');
    const inviteUrl = await a.inputValue('#invite-link');
    await a.keyboard.press('Escape');

    await b.goto(inviteUrl);
    await b.waitForURL(boardUrl);
});

await step('Bob opens it and both connect over WebSockets', async () => {
    await b.waitForSelector('[data-testid=connection-status]:has-text("Live")');
});

await step('presence: each sees the other online', async () => {
    await a.locator('[title="Bob"]').first().waitFor({ timeout: 5000 });
    await b.locator('[title="Ada"]').first().waitFor({ timeout: 5000 });
});

await step('card created by Ada appears for Bob', async () => {
    await addCard(a, 'To do', 'Hello realtime');
    await card(b, 'Hello realtime').waitFor({ timeout: 5000 });
});

await step('live cursor: Bob sees Ada\'s pointer', async () => {
    await a.mouse.move(600, 500);
    await a.mouse.move(640, 520, { steps: 5 });
    await b.locator('[aria-hidden=true] >> text=Ada').waitFor({ timeout: 5000 });
});

await step('drag and drop: Ada drags the card to Done, Bob sees it move', async () => {
    await card(a, 'Hello realtime').dragTo(column(a, 'Done').locator('ol'));
    await column(b, 'Done').locator('[data-card-id]', { hasText: 'Hello realtime' }).waitFor({ timeout: 5000 });
    await column(a, 'Done').locator('[data-card-id]', { hasText: 'Hello realtime' }).waitFor({ timeout: 5000 });
});

await step('activity feed on Bob shows the move', async () => {
    await b.locator('[data-testid=activity-feed]', { hasText: 'moved “Hello realtime” from To do to Done' }).waitFor({ timeout: 5000 });
});

await step('editing indicator: Ada opens the card, Bob sees "Ada is editing"', async () => {
    await card(a, 'Hello realtime').click();
    await a.waitForSelector('[role=dialog]');
    await card(b, 'Hello realtime').locator('text=Ada is editing').waitFor({ timeout: 5000 });
});

await step('conflict: Bob saves first, Ada\'s stale save gets a 409 and resolves', async () => {
    await card(b, 'Hello realtime').click();
    await b.locator('[role=dialog]').locator('text=Ada is also editing this card').waitFor({ timeout: 5000 });
    await a.locator('[role=dialog]').locator('text=Bob is also editing this card').waitFor({ timeout: 5000 });

    await a.fill('#card-title', 'Ada title');
    await b.fill('#card-title', 'Bob title');
    await b.click('[role=dialog] button[type=submit]');
    await b.waitForSelector('[role=dialog]', { state: 'detached' });

    // Ada's draft is dirty, so the remote save is flagged rather than applied.
    await a.locator('text=Bob just saved changes').waitFor({ timeout: 5000 });
    await a.click('[role=dialog] button[type=submit]');
    await a.locator('text=Bob changed this card while you were editing it').waitFor({ timeout: 5000 });
    await a.screenshot({ path: `${OUT}/conflict.png` });

    await a.click('text=Overwrite with mine');
    await a.waitForSelector('[role=dialog]', { state: 'detached' });
    await card(b, 'Ada title').waitFor({ timeout: 5000 });
});

await step('reconnect: Bob goes offline, misses a change, then re-syncs', async () => {
    await b.evaluate(() => window.Pusher.instances[0].disconnect());
    await b.waitForSelector('[data-testid=connection-status]:has-text("Disconnected")');

    await addCard(a, 'To do', 'Made while Bob was offline');
    await card(a, 'Made while Bob was offline').waitFor();
    await b.waitForTimeout(500);
    if (await card(b, 'Made while Bob was offline').count()) throw new Error('offline client received an event');

    await b.evaluate(() => window.Pusher.instances[0].connect());
    await card(b, 'Made while Bob was offline').waitFor({ timeout: 8000 });
});

await step('keyboard: Enter opens a card, Tab stays in the dialog, Escape returns focus', async () => {
    await card(a, 'Made while Bob was offline').focus();
    await a.keyboard.press('Enter');
    await a.waitForSelector('[role=dialog]');
    for (let i = 0; i < 15; i++) await a.keyboard.press('Tab');
    if (!(await a.evaluate(() => !!document.activeElement.closest('[role=dialog]')))) throw new Error('focus left the dialog');
    await a.keyboard.press('Escape');
    await a.waitForSelector('[role=dialog]', { state: 'detached' });
    if (!(await a.evaluate(() => document.activeElement?.hasAttribute('data-card-id')))) throw new Error('focus did not return to the card');
});

await step('delete propagates and closes the dialog Bob has open', async () => {
    await card(b, 'Made while Bob was offline').click();
    await b.waitForSelector('[role=dialog]');
    await card(a, 'Made while Bob was offline').click();
    await a.click('[role=dialog] >> text=Delete');
    await card(b, 'Made while Bob was offline').waitFor({ state: 'detached', timeout: 5000 });
    await b.waitForSelector('[role=dialog]', { state: 'detached', timeout: 5000 });
    await b.locator('text=That card was deleted by someone else.').waitFor({ timeout: 5000 });
    if (await a.locator('text=That card was deleted by someone else.').count()) throw new Error('the deleter was told someone else deleted it');
});

await a.mouse.move(700, 300);
await b.mouse.move(400, 250);
await a.waitForTimeout(400);
await a.screenshot({ path: `${OUT}/ada.png` });
await b.screenshot({ path: `${OUT}/bob.png` });

await step('membership: Ada removes Bob, and Bob is sent back to his boards', async () => {
    await a.click('button:has-text("Share")');
    await a.locator('[data-testid=members] li', { hasText: 'Bob' }).locator('text=Remove').click();
    await a.locator('[data-testid=members] li', { hasText: 'Bob' }).waitFor({ state: 'detached', timeout: 5000 });
    await a.keyboard.press('Escape');

    await b.locator('text=You were removed from this board.').waitFor({ timeout: 5000 });
    await b.waitForSelector('text=Boards');
    if (await b.locator('a', { hasText: 'E2E board' }).count()) throw new Error('the board is still in Bob\'s list');
});

if (boardUrl) {
    const mobile = await ctxA.newPage();
    await mobile.setViewportSize({ width: 390, height: 780 });
    await mobile.goto(boardUrl);
    await mobile.waitForSelector('[data-testid=connection-status]:has-text("Live")');
    await mobile.screenshot({ path: `${OUT}/mobile.png` });
}

await browser.close();
console.log(results.join('\n'));
process.exit(results.some((r) => !r.startsWith('PASS')) ? 1 : 0);
