import { nextTick, onBeforeUnmount, onMounted } from 'vue';

/**
 * Keyboard behaviour shared by the modal dialogs: Escape asks to close, Tab
 * and Shift+Tab cycle inside the dialog, and focus goes back to whatever
 * opened it once it closes. A card re-renders as a new element when it
 * changes column (from the dialog or someone else), so a card is found again
 * by its id.
 *
 * @param {import('vue').Ref<HTMLElement|null>} dialog
 * @param {() => void} requestClose
 */
export function useDialog(dialog, requestClose) {
    let returnFocusTo = null;
    let returnToCardId = null;

    function onKeydown(event) {
        if (event.key === 'Escape') requestClose();
        else if (event.key === 'Tab') trapFocus(event);
    }

    function trapFocus(event) {
        const focusable = [...dialog.value.querySelectorAll('button, input, select, textarea, a[href]')].filter((el) => !el.disabled);
        const first = focusable[0];
        const last = focusable.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    onMounted(() => {
        returnFocusTo = document.activeElement;
        returnToCardId = returnFocusTo?.closest?.('[data-card-id]')?.dataset.cardId ?? null;
        window.addEventListener('keydown', onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeydown);
        nextTick(() => {
            const target = returnFocusTo?.isConnected
                ? returnFocusTo
                : returnToCardId && document.querySelector(`[data-card-id="${returnToCardId}"]`);
            target?.focus();
        });
    });
}
