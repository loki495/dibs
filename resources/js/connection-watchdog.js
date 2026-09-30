// Livewire 4 shows nothing when a request fails at the network level and has no request timeout, so one
// lost or hung response leaves taps changing the URL while the page never re-renders (later actions queue
// behind the stuck request). This surfaces that state and frees the queue.
const timeoutMs = Number(document.querySelector('meta[name="dibs-request-timeout"]')?.content ?? 0) * 1000;

document.addEventListener('livewire:init', () => {
    const banner = document.querySelector('[data-connection-banner]');
    if (! banner) return;

    let leaving = false;
    addEventListener('pagehide', () => { leaving = true; });
    banner.querySelector('[data-reload]')?.addEventListener('click', () => window.location.reload());

    const show = () => { if (! leaving) banner.hidden = false; };
    const hide = () => { banner.hidden = true; };

    window.Livewire.interceptRequest(({ request, onSend, onResponse, onCancel, onFailure, onSuccess }) => {
        let timer = null;
        const settle = () => clearTimeout(timer);

        onSend(() => {
            if (timeoutMs > 0) {
                timer = setTimeout(() => { show(); request.cancel(); }, timeoutMs);
            }
        });
        onResponse(settle);
        onCancel(settle);
        onSuccess(() => { settle(); hide(); });
        onFailure(() => {
            settle();
            if (! request.isCancelled()) show();
        });
    });
});
