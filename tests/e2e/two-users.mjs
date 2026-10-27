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
    console.log(results.at(-1));
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
// Board snapshot fetches per page, to tell a deliberate resync from none.
const snapshots = { A: 0, B: 0 };
for (const [who, page] of [['A', a], ['B', b]]) {
    page.on('pageerror', (e) => results.push(`PAGE ERROR ${who}: ${e.message}`));
    page.on('dialog', (d) => d.accept());
    page.on('request', (r) => r.method() === 'GET' && /\/api\/boards\/\d+$/.test(r.url()) && snapshots[who]++);
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
let inviteUrl;
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
    inviteUrl = await a.inputValue('#invite-link');
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

await step('keyboard: Alt+Arrow moves a card between columns, focus stays on it, and Bob sees it', async () => {
    await card(a, 'Hello realtime').focus();
    await a.keyboard.press('Alt+ArrowLeft');
    await column(b, 'In progress').locator('[data-card-id]', { hasText: 'Hello realtime' }).waitFor({ timeout: 5000 });
    if (!(await a.evaluate(() => document.activeElement?.closest('[data-card-id]')?.textContent.includes('Hello realtime')))) throw new Error('focus left the card');
    await a.locator('[aria-live=assertive]', { hasText: 'Moved “Hello realtime” to position 1 of 1 in In progress.' }).waitFor({ state: 'attached', timeout: 2000 });

    await a.keyboard.press('Alt+ArrowRight');
    await column(b, 'Done').locator('[data-card-id]', { hasText: 'Hello realtime' }).waitFor({ timeout: 5000 });
});

await step('drag and drop: reordering within a column puts the card where it was dropped', async () => {
    await addCard(a, 'Done', 'Goes on top');
    await card(a, 'Goes on top').waitFor();
    await card(a, 'Goes on top').dragTo(card(a, 'Hello realtime'), { targetPosition: { x: 20, y: 3 } });
    for (const page of [a, b]) {
        await column(page, 'Done').locator('[data-card-id]').first().filter({ hasText: 'Goes on top' }).waitFor({ timeout: 5000 });
    }
    const titles = await column(b, 'Done').locator('[data-card-id]').allTextContents();
    if (!titles[0].includes('Goes on top') || !titles[1].includes('Hello realtime')) throw new Error(`unexpected order: ${titles.join(' / ')}`);
});

const columnOrder = (page) => page.locator('section[data-column-id] h2').allTextContents().then((names) => names.map((n) => n.trim()).join(' / '));
const waitForColumnOrder = (page, expected) =>
    page.waitForFunction((expected) => [...document.querySelectorAll('section[data-column-id] h2')].map((h) => h.textContent.trim()).join(' / ') === expected, expected, { timeout: 5000 });

await step('columns: Ada drags Done to the front by its header, Bob sees the new order', async () => {
    await column(a, 'Done').locator('header').dragTo(column(a, 'To do').locator('header'), { targetPosition: { x: 8, y: 10 } });
    await waitForColumnOrder(b, 'Done / To do / In progress');
    if ((await columnOrder(a)) !== 'Done / To do / In progress') throw new Error(`Ada sees ${await columnOrder(a)}`);
});

await step('columns: Alt+Arrow on a column name moves it back, focus stays on it', async () => {
    await column(a, 'Done').locator('h2 button').focus();
    await a.keyboard.press('Alt+ArrowRight');
    await a.keyboard.press('Alt+ArrowRight');
    await waitForColumnOrder(b, 'To do / In progress / Done');
    if (!(await a.evaluate(() => document.activeElement?.textContent.trim() === 'Done'))) throw new Error('focus left the column name');
    await a.locator('[aria-live=assertive]', { hasText: 'Moved column “Done” to position 3 of 3.' }).waitFor({ state: 'attached', timeout: 2000 });
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

await step('sequence numbers: ordinary traffic in both directions causes no resync', async () => {
    const before = { ...snapshots };
    await addCard(a, 'Done', 'Numbered by Ada');
    await card(b, 'Numbered by Ada').waitFor({ timeout: 5000 });
    await addCard(b, 'Done', 'Numbered by Bob');
    await card(a, 'Numbered by Bob').waitFor({ timeout: 5000 });
    await a.waitForTimeout(3500); // longer than the client's gap grace period
    if (snapshots.A !== before.A || snapshots.B !== before.B) throw new Error(`unexpected resync: ${JSON.stringify({ before, after: snapshots })}`);
});

await step('sequence numbers: a message lost without a disconnect is noticed and resynced', async () => {
    // Swallow the next card.created on Bob's socket, as a flaky proxy might.
    await b.evaluate(() => {
        const id = window.location.hash.match(/boards\/(\d+)/)[1];
        const channel = window.Pusher.instances[0].channel(`presence-board.${id}`);
        const handle = channel.handleEvent.bind(channel);
        let dropped = false;
        channel.handleEvent = (event) => {
            if (!dropped && event.event === 'card.created') return (dropped = true);
            return handle(event);
        };
    });
    const before = snapshots.B;
    await addCard(a, 'Done', 'Lost in transit');
    await card(b, 'Lost in transit').waitFor({ timeout: 8000 });
    if (snapshots.B === before) throw new Error('the card arrived without a resync, so the message was not dropped');
});

await step('ordering: an older move delivered late does not undo a newer one', async () => {
    // Hold back the next card.moved on Bob's socket until the one after it
    // has been delivered, as two concurrent requests can.
    await b.evaluate(() => {
        const id = window.location.hash.match(/boards\/(\d+)/)[1];
        const channel = window.Pusher.instances[0].channel(`presence-board.${id}`);
        const handle = channel.handleEvent.bind(channel);
        let held = null;
        let done = false;
        channel.handleEvent = (event) => {
            if (!done && !held && event.event === 'card.moved') return (held = event);
            handle(event);
            if (!done && held && event.event === 'card.moved') {
                done = true;
                handle(held);
            }
        };
    });
    const before = snapshots.B;
    await card(a, 'Numbered by Ada').focus();
    await a.keyboard.press('Alt+ArrowLeft'); // Done → In progress (held back on Bob's side)
    await a.keyboard.press('Alt+ArrowLeft'); // In progress → To do (delivered first)
    await column(a, 'To do').locator('[data-card-id]', { hasText: 'Numbered by Ada' }).waitFor({ timeout: 5000 });
    await b.waitForTimeout(1500);
    if (!(await column(b, 'To do').locator('[data-card-id]', { hasText: 'Numbered by Ada' }).count())) {
        throw new Error('Bob applied the older move last and shows the card in the wrong column');
    }
    if (snapshots.B !== before) throw new Error('Bob resynced, so the late event was never tested');
});

await step('ordering: a card deleted while its move is in flight does not come back', async () => {
    // Delay the move's response on Ada's side; the server has already moved
    // the card by the time Ada deletes it.
    await a.route('**/api/cards/*/move', async (route) => {
        const response = await route.fetch();
        await new Promise((resolve) => setTimeout(resolve, 1500));
        await route.fulfill({ response });
    });
    await card(a, 'Numbered by Bob').focus();
    await a.keyboard.press('Alt+ArrowLeft');
    await card(a, 'Numbered by Bob').click();
    await a.click('[role=dialog] >> text=Delete');
    await a.waitForTimeout(2500); // the delayed move response has arrived by now
    await a.unroute('**/api/cards/*/move');
    if (await card(a, 'Numbered by Bob').count()) throw new Error('the deleted card came back when its move returned');
    await card(b, 'Numbered by Bob').waitFor({ state: 'detached', timeout: 5000 });
});

await step('drag: a card someone deletes mid-drag is dropped cleanly', async () => {
    await addCard(b, 'Done', 'Deleted mid-drag');
    await card(a, 'Deleted mid-drag').waitFor({ timeout: 5000 });
    const box = await card(a, 'Deleted mid-drag').boundingBox();
    await a.mouse.move(box.x + 20, box.y + 10);
    await a.mouse.down();
    await a.mouse.move(box.x + 120, box.y + 60, { steps: 5 });

    await card(b, 'Deleted mid-drag').click();
    await b.click('[role=dialog] >> text=Delete');
    await card(a, 'Deleted mid-drag').waitFor({ state: 'detached', timeout: 5000 });

    const target = await column(a, 'To do').locator('ol').boundingBox();
    await a.mouse.move(target.x + 20, target.y + 10, { steps: 5 });
    await a.mouse.up();
    await a.waitForTimeout(300);
    if (await a.locator('.bg-indigo-500').count()) throw new Error('the drop indicator was left on screen');
    if (await a.evaluate(() => document.documentElement.classList.contains('is-dragging'))) throw new Error('the drag never ended');
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

await step('whispers: a client cannot speak for someone else', async () => {
    // A modified client claims to be Ada while flagging a card as being
    // edited. The server stamps whispers with the sender's real id, so Ada
    // sees Bob's name on it rather than her own (which her UI would hide).
    const trigger = (payload) => b.evaluate(async (payload) => {
        const id = window.location.hash.match(/boards\/(\d+)/)[1];
        const res = await fetch(`/api/boards/${id}/members`, { headers: { Accept: 'application/json' } });
        const ada = (await res.json()).data.find((m) => m.name === 'Ada');
        const cardId = Number(document.querySelector('[data-card-id]').dataset.cardId);
        window.Pusher.instances[0].channel(`presence-board.${id}`).trigger('client-editing', { userId: ada.id, cardId, ...payload });
    }, payload);
    const badge = a.locator('[data-card-id]').first().locator('text=Bob is editing');
    await trigger({});
    await badge.waitFor({ timeout: 5000 });
    await trigger({ cardId: null });
    await badge.waitFor({ state: 'detached', timeout: 5000 });
});

await a.mouse.move(700, 300);
await b.mouse.move(400, 250);
await a.waitForTimeout(400);
await a.screenshot({ path: `${OUT}/ada.png` });
await b.screenshot({ path: `${OUT}/bob.png` });

await step('membership: Ada removes Bob, and Bob is sent back to his boards', async () => {
    // Play a modified client that ignores the removal: keep the subscription
    // when the app leaves, and log every board event the socket still gets.
    await b.evaluate(() => {
        const pusher = window.Pusher.instances[0];
        pusher.unsubscribe = () => {};
        window.leaked = [];
        pusher.connection.bind('message', (event) => window.watchLeaks && event.channel?.startsWith('presence-board.') && window.leaked.push(event.event));
    });
    await a.click('button:has-text("Share")');
    await a.locator('[data-testid=members] li', { hasText: 'Bob' }).locator('text=Remove').click();
    await a.locator('[data-testid=members] li', { hasText: 'Bob' }).waitFor({ state: 'detached', timeout: 5000 });
    await a.keyboard.press('Escape');

    await b.locator('text=You were removed from this board.').waitFor({ timeout: 5000 });
    await b.waitForSelector('text=Boards');
    if (await b.locator('a', { hasText: 'E2E board' }).count()) throw new Error('the board is still in Bob\'s list');
});

await step('membership: the removed member\'s socket stops receiving the board', async () => {
    await b.evaluate(() => (window.watchLeaks = true));
    await addCard(a, 'Done', 'After Bob left');
    await card(a, 'After Bob left').waitFor({ timeout: 5000 });
    await b.waitForTimeout(2000);
    const leaked = await b.evaluate(() => window.leaked);
    if (leaked.length) throw new Error(`Bob's socket still got ${leaked.join(', ')}`);
});

await step('membership: the removed member cannot rejoin with the invite link he had', async () => {
    await b.goto(inviteUrl);
    await b.locator('text=This invite link is no longer valid.').waitFor({ timeout: 5000 });

    // The link was replaced; Ada's Share dialog shows the new one.
    await a.click('button:has-text("Share")');
    const fresh = await a.inputValue('#invite-link');
    await a.keyboard.press('Escape');
    if (fresh === inviteUrl) throw new Error('the invite link was not replaced');
    inviteUrl = fresh;
});

await step('touch: a long press drags a card to another column, a quick swipe scrolls instead', async () => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 780 }, hasTouch: true, isMobile: true });
    const phone = await ctx.newPage();
    phone.on('pageerror', (e) => results.push(`PAGE ERROR phone: ${e.message}`));
    phone.on('dialog', (d) => d.accept());
    await join(phone, 'Tess');
    await phone.goto(inviteUrl);
    await phone.waitForSelector('[data-testid=connection-status]:has-text("Live")');
    const cdp = await ctx.newCDPSession(phone);
    const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });

    // Long press, then drag into the next column (its left edge shows at x ≈ 316).
    await addCard(phone, 'To do', 'Moved by touch');
    let box = await card(phone, 'Moved by touch').boundingBox();
    let [x, y] = [box.x + 40, box.y + box.height / 2];
    const target = await column(phone, 'In progress').locator('ol').boundingBox();
    await touch('touchStart', x, y);
    await phone.waitForTimeout(450);
    const [tx, ty] = [Math.min(target.x + 24, 335), target.y + 20];
    for (let i = 1; i <= 8; i++) await touch('touchMove', x + ((tx - x) * i) / 8, y + ((ty - y) * i) / 8);
    await touch('touchEnd');
    await column(a, 'In progress').locator('[data-card-id]', { hasText: 'Moved by touch' }).waitFor({ timeout: 5000 });

    // A quick horizontal swipe across a card scrolls the board and moves nothing.
    await addCard(phone, 'To do', 'Swiped across');
    box = await card(phone, 'Swiped across').boundingBox();
    [x, y] = [box.x + 190, box.y + box.height / 2];
    const scroller = phone.locator('.overflow-auto').first();
    const scrolledBefore = await scroller.evaluate((el) => el.scrollLeft);
    await touch('touchStart', x, y);
    for (let i = 1; i <= 6; i++) await touch('touchMove', x - i * 25, y);
    await touch('touchEnd');
    await phone.waitForTimeout(600); // let the fling settle
    if ((await scroller.evaluate((el) => el.scrollLeft)) <= scrolledBefore) throw new Error('the swipe did not scroll the board');
    if (!(await column(phone, 'To do').locator('[data-card-id]', { hasText: 'Swiped across' }).count())) throw new Error('the swipe moved the card');
    await phone.screenshot({ path: `${OUT}/touch.png` });

    // Leaving on purpose isn't reported as being removed.
    await phone.click('button:has-text("Share")');
    await phone.locator('[data-testid=members] li', { hasText: 'Tess' }).locator('text=Leave').click();
    await phone.waitForSelector('text=Boards');
    await phone.waitForTimeout(500);
    if (await phone.locator('text=You were removed from this board.').count()) throw new Error('leaving was reported as a removal');
    await ctx.close();
});

if (boardUrl) {
    const mobile = await ctxA.newPage();
    await mobile.setViewportSize({ width: 390, height: 780 });
    await mobile.goto(boardUrl);
    await mobile.waitForSelector('[data-testid=connection-status]:has-text("Live")');
    await mobile.screenshot({ path: `${OUT}/mobile.png` });
}

await browser.close();
process.exit(results.some((r) => !r.startsWith('PASS')) ? 1 : 0);
