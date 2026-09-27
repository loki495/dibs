// One indicator for every Livewire request and full-page navigation: after a short delay a thin bar shows
// at the top and the lists marked [data-busy] dim, so a slow filter change, popup or page load doesn't
// look like nothing happened. Delay comes from the dibs-loading-delay meta tag.
const delayMs = Number(document.querySelector('meta[name="dibs-loading-delay"]')?.content ?? 150);
const bar = document.querySelector('[data-loading-bar]');
const root = document.documentElement;

let pending = 0;
let timer = null;
let visible = false;

const show = () => {
    visible = true;
    root.dataset.loading = '';
    document.querySelectorAll('[data-busy]').forEach(element => element.setAttribute('aria-busy', 'true'));
    if (bar && ! bar.matches(':popover-open')) bar.showPopover?.();
};

const hide = () => {
    clearTimeout(timer);
    visible = false;
    delete root.dataset.loading;
    document.querySelectorAll('[data-busy]').forEach(element => element.removeAttribute('aria-busy'));
    if (bar?.matches(':popover-open')) bar.hidePopover();
};

const start = () => {
    pending++;
    if (pending === 1) timer = setTimeout(show, delayMs);
};

const finish = () => {
    pending = Math.max(0, pending - 1);
    if (pending === 0) hide();
};

document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onSend, onFinish }) => {
        onSend(start);
        onFinish(finish);
    });
});

// A plain link click is a full page load, which the installed app shows no browser progress for.
document.addEventListener('click', event => {
    const link = event.target.closest?.('a[href]');
    if (! link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.target && link.target !== '_self') return;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search)) return;
    start();
});

// Coming back through the back/forward cache restores the page as it was left, including a shown bar.
addEventListener('pageshow', event => {
    if (event.persisted) { pending = 0; hide(); }
});
