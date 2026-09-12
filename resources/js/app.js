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
