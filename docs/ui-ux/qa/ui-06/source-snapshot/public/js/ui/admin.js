/* UI-06: apertura/cierre visual exclusivamente por atributos optativos. */
(() => {
    'use strict';
    if (window.FresaUIAdminReady) return;
    window.FresaUIAdminReady = true;
    const returns = new WeakMap();
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) return;
        const opener = event.target.closest('button[data-ui-dialog-open]');
        if (opener && opener.type === 'button' && !opener.disabled) {
            const dialog = document.getElementById(opener.dataset.uiDialogOpen);
            if (!(dialog instanceof HTMLDialogElement) || !dialog.hasAttribute('data-ui-dialog') || dialog.open) return;
            if (document.querySelector('dialog[open]') || document.documentElement.dataset.uiNavOpen === 'true') return;
            returns.set(dialog, opener);
            dialog.showModal();
        }
        const closer = event.target.closest('button[data-ui-dialog-close]');
        if (closer && closer.type === 'button' && !closer.disabled) closer.closest('dialog[data-ui-dialog]')?.close();
        const dismiss = event.target.closest('button[data-ui-notice-dismiss]');
        if (dismiss && dismiss.type === 'button' && !dismiss.disabled) {
            const notice = dismiss.closest('[data-ui-notice][data-ui-dismissible="true"]');
            if (notice) {
                // Antes de ocultar, evita dejar el foco en un botón que desaparece.
                const target = document.getElementById(notice.dataset.uiNoticeReturn) || document.getElementById('ui-main');
                if (notice.contains(document.activeElement) && target instanceof HTMLElement) target.focus();
                notice.hidden = true;
            }
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Tab') return;
        const dialog = document.querySelector('dialog[data-ui-dialog][open]');
        if (!dialog) return;
        const nodes = [...dialog.querySelectorAll('a[href],button,input,select,textarea,[tabindex]')].filter(node => !node.disabled && node.tabIndex >= 0 && node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
        const first = nodes[0], last = nodes[nodes.length - 1];
        if (!first) { event.preventDefault(); return; }
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    document.addEventListener('close', event => {
        const dialog = event.target;
        if (!(dialog instanceof HTMLDialogElement) || !dialog.hasAttribute('data-ui-dialog')) return;
        const opener = returns.get(dialog);
        returns.delete(dialog);
        if (opener?.isConnected && !opener.disabled && opener.getClientRects().length) opener.focus();
    }, true);
})();
