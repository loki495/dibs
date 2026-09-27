// Optimistic pressed state: a filter chip, area pill or tab that opts in with data-optimistic looks
// selected the instant it is tapped, instead of after the server round trip. It only restyles (see
// "Optimistic pressed state" in app.css): the click still runs the normal wire:click, the server stays
// the source of truth, and the marks are dropped when requests go idle (the re-render normally replaces
// them first; a failed request leaves the real, unchanged state showing).
document.addEventListener('click', event => {
    const element = event.target.closest?.('[data-optimistic]');
    if (! element || element.disabled) return;

    if (element.dataset.optimisticMode === 'toggle') {
        element.dataset.pending = element.getAttribute('aria-pressed') === 'true' ? 'off' : 'on';
        return;
    }

    const group = element.closest('[data-optimistic-group]');
    group?.querySelectorAll('[data-pending]').forEach(other => delete other.dataset.pending);
    group?.setAttribute('data-pending-group', '');
    element.dataset.pending = 'on';
});

document.addEventListener('dibs:idle', () => {
    document.querySelectorAll('[data-pending]').forEach(element => delete element.dataset.pending);
    document.querySelectorAll('[data-pending-group]').forEach(element => element.removeAttribute('data-pending-group'));
});
