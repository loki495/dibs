window.todoTree = (defaultOpen = false) => ({
    expanded: {},
    overrides: {},
    init() {
        try {
            const saved = JSON.parse(localStorage.getItem('todo-tree-expanded') || '{}');
            if (saved && typeof saved === 'object' && !Array.isArray(saved)) this.expanded = saved;
        } catch {}
    },
    isOpen(id) { return this.overrides[id] ?? (defaultOpen || this.expanded[id] === true); },
    visible(ancestors) { return ancestors.every(id => this.isOpen(id)); },
    toggle(id) {
        this.expanded[id] = this.overrides[id] = !this.isOpen(id);
        try { localStorage.setItem('todo-tree-expanded', JSON.stringify(this.expanded)); } catch {}
    },
    allOpen(ids) { return ids.length > 0 && ids.every(id => this.isOpen(id)); },
    toggleAll(ids) {
        const open = !this.allOpen(ids);
        ids.forEach(id => { this.expanded[id] = this.overrides[id] = open; });
        try { localStorage.setItem('todo-tree-expanded', JSON.stringify(this.expanded)); } catch {}
    },
});

window.todoFreshness = ({ endpoint, version }) => ({
    endpoint,
    version,
    offline: false,
    activeUntil: Date.now() + 45_000,
    timer: null,
    running: false,
    listeners: [],
    init() {
        const activity = () => { this.activeUntil = Date.now() + 45_000; };
        ['pointerdown', 'keydown', 'input', 'focusin'].forEach((event) => {
            document.addEventListener(event, activity);
            this.listeners.push([event, activity]);
        });
        const visibility = () => {
            if (!document.hidden) {
                activity();
                this.schedule(0);
            }
        };
        document.addEventListener('visibilitychange', visibility);
        this.listeners.push(['visibilitychange', visibility]);
        this.schedule(0);
    },
    destroy() {
        clearTimeout(this.timer);
        this.listeners.forEach(([event, listener]) => document.removeEventListener(event, listener));
    },
    schedule(delay) {
        clearTimeout(this.timer);
        if (!document.hidden) this.timer = setTimeout(() => this.check(), delay);
    },
    async check() {
        if (document.hidden || this.running) return;
        this.running = true;
        const active = Date.now() < this.activeUntil;
        try {
            const response = await fetch(`${this.endpoint}?active=${active ? '1' : '0'}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`Freshness request failed with ${response.status}`);
            const freshness = await response.json();
            this.offline = false;
            if (this.version !== freshness.version) {
                this.version = freshness.version;
                await this.$wire.$refresh();
            }
            this.schedule((active ? freshness.activePollSeconds : freshness.idlePollSeconds) * 1000);
        } catch {
            this.offline = true;
            this.schedule((active ? 10 : 60) * 1000);
        } finally {
            this.running = false;
        }
    },
});
