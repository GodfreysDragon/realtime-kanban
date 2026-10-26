import { nextTick, onBeforeUnmount, onMounted } from 'vue';

/**
 * Keyboard behaviour shared by the modal dialogs: Escape asks to close, Tab
 * and Shift+Tab cycle inside the dialog, and focus goes back to whatever
 * opened it once it closes (if that element is still on the page).
 *
 * @param {import('vue').Ref<HTMLElement|null>} dialog
 * @param {() => void} requestClose
 */
export function useDialog(dialog, requestClose) {
    let returnFocusTo = null;

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
        window.addEventListener('keydown', onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('keydown', onKeydown);
        nextTick(() => returnFocusTo?.isConnected && returnFocusTo.focus());
    });
}
