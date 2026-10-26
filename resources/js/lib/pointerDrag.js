/**
 * Drag and drop on Pointer Events, so mouse, touch and pen all work. (The
 * native HTML5 drag API never fires for touch.)
 *
 * - Mouse and pen: the drag starts once the pointer has moved a few pixels,
 *   so a plain click still clicks.
 * - Touch: the drag starts after a long press without moving. Moving sooner
 *   is a scroll, which the browser handles as usual.
 *
 * While dragging, a copy of the element follows the pointer, the scroll
 * containers under it scroll when the pointer nears their edge, and Escape
 * cancels. The caller decides what is under the pointer and what a drop
 * does, through the callbacks given to start().
 */

const MOVE_THRESHOLD_PX = 4;
const TOUCH_SLOP_PX = 8;
const LONG_PRESS_MS = 300;
const EDGE_PX = 48;
const MAX_SCROLL_STEP_PX = 16;

/**
 * @typedef {object} DragHandlers
 * @property {() => void} [onStart]
 * @property {(x: number, y: number) => void} onMove   pointer position, client coordinates
 * @property {() => void} onDrop
 * @property {() => void} onEnd    always called last, after a drop or a cancel
 */

let active = null;

/**
 * Begin tracking a pointerdown on `element`. Nothing happens until the
 * gesture turns into a drag.
 *
 * @param {PointerEvent} event
 * @param {HTMLElement} element
 * @param {DragHandlers} handlers
 */
export function startPointerDrag(event, element, handlers) {
    if (active || event.button !== 0 || !event.isPrimary) return;

    const touch = event.pointerType === 'touch';
    const state = {
        pointerId: event.pointerId,
        element,
        handlers,
        touch,
        startX: event.clientX,
        startY: event.clientY,
        x: event.clientX,
        y: event.clientY,
        dragging: false,
        ghost: null,
        offsetX: 0,
        offsetY: 0,
        longPress: touch ? setTimeout(() => begin(state), LONG_PRESS_MS) : null,
        frame: null,
    };
    active = state;

    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointercancel', onPointerCancel);
    window.addEventListener('keydown', onKeydown);
    // Must be non-passive: once a touch drag starts, the first touchmove is
    // cancelled so the page doesn't scroll under the finger.
    window.addEventListener('touchmove', onTouchMove, { passive: false });
    window.addEventListener('contextmenu', onContextMenu);
}

function begin(state) {
    if (active !== state || state.dragging) return;
    state.dragging = true;
    clearTimeout(state.longPress);

    const rect = state.element.getBoundingClientRect();
    state.offsetX = state.startX - rect.left;
    state.offsetY = state.startY - rect.top;

    // The copy must not answer the selectors the board uses for the real
    // thing (a dragged column contains cards, too).
    const ghost = state.element.cloneNode(true);
    for (const el of [ghost, ...ghost.querySelectorAll('[data-card-id], [data-column-id], [id]')]) {
        el.removeAttribute('data-card-id');
        el.removeAttribute('data-column-id');
        el.removeAttribute('id');
    }
    ghost.setAttribute('aria-hidden', 'true');
    Object.assign(ghost.style, {
        position: 'fixed',
        left: '0',
        top: '0',
        width: `${rect.width}px`,
        height: `${rect.height}px`,
        margin: '0',
        pointerEvents: 'none',
        zIndex: '50',
        opacity: '0.9',
        transform: 'rotate(2deg)',
        transition: 'none',
        boxShadow: '0 12px 24px rgb(0 0 0 / 0.18)',
    });
    document.body.append(ghost);
    state.ghost = ghost;
    document.documentElement.classList.add('is-dragging');

    navigator.vibrate?.(10);
    state.handlers.onStart?.();
    follow(state);
    state.frame = requestAnimationFrame(() => autoScroll(state));
}

function follow(state) {
    state.ghost.style.translate = `${state.x - state.offsetX}px ${state.y - state.offsetY}px`;
    state.handlers.onMove(state.x, state.y);
}

function onPointerMove(event) {
    const state = active;
    if (!state || event.pointerId !== state.pointerId) return;
    state.x = event.clientX;
    state.y = event.clientY;

    if (state.dragging) {
        follow(state);
        return;
    }

    const distance = Math.hypot(state.x - state.startX, state.y - state.startY);
    if (state.touch && distance > TOUCH_SLOP_PX) finish(false); // a scroll, not a press
    else if (!state.touch && distance > MOVE_THRESHOLD_PX) begin(state);
}

function onPointerUp(event) {
    if (active && event.pointerId === active.pointerId) finish(active.dragging);
}

function onPointerCancel(event) {
    if (active && event.pointerId === active.pointerId) finish(false);
}

function onKeydown(event) {
    if (event.key === 'Escape' && active?.dragging) {
        event.preventDefault();
        event.stopPropagation();
        finish(false);
    }
}

function onTouchMove(event) {
    if (active?.dragging && event.cancelable) event.preventDefault();
}

function onContextMenu(event) {
    // A long press would otherwise open the browser's context menu.
    if (active?.touch) event.preventDefault();
}

/** Scroll whichever scroll containers under the pointer are near an edge. */
function autoScroll(state) {
    if (active !== state) return;

    for (const scroller of scrollersAt(state.x, state.y)) {
        const rect = scroller.getBoundingClientRect();
        const dx = edgeStep(state.x, rect.left, rect.right);
        const dy = edgeStep(state.y, rect.top, rect.bottom);
        if (dx && scroller.scrollWidth > scroller.clientWidth) scroller.scrollLeft += dx;
        if (dy && scroller.scrollHeight > scroller.clientHeight) scroller.scrollTop += dy;
    }

    state.handlers.onMove(state.x, state.y);
    state.frame = requestAnimationFrame(() => autoScroll(state));
}

function edgeStep(position, start, end) {
    if (position < start + EDGE_PX) return -Math.ceil(((start + EDGE_PX - position) / EDGE_PX) * MAX_SCROLL_STEP_PX);
    if (position > end - EDGE_PX) return Math.ceil(((position - (end - EDGE_PX)) / EDGE_PX) * MAX_SCROLL_STEP_PX);
    return 0;
}

function scrollersAt(x, y) {
    const scrollers = [];
    for (let el = document.elementFromPoint(x, y); el && el !== document.body; el = el.parentElement) {
        const style = getComputedStyle(el);
        if (/(auto|scroll)/.test(style.overflowX + style.overflowY)) scrollers.push(el);
    }
    return scrollers;
}

function finish(drop) {
    const state = active;
    active = null;

    clearTimeout(state.longPress);
    cancelAnimationFrame(state.frame);
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointerup', onPointerUp);
    window.removeEventListener('pointercancel', onPointerCancel);
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('touchmove', onTouchMove);
    window.removeEventListener('contextmenu', onContextMenu);

    if (!state.dragging) return;

    state.ghost.remove();
    document.documentElement.classList.remove('is-dragging');
    // The click that follows a mouse drag isn't a click on the element.
    window.addEventListener('click', swallowClick, { capture: true, once: true });
    setTimeout(() => window.removeEventListener('click', swallowClick, { capture: true }), 0);

    if (drop) state.handlers.onDrop();
    state.handlers.onEnd();
}

function swallowClick(event) {
    event.stopPropagation();
    event.preventDefault();
}
