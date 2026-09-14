{{--
    Demo mode only (config('dibs.demo_mode')) -- self-guarded so it's safe to include anywhere
    without remembering the check at the call site.

    Unlike the sibling homie project's equivalent panel, this can't walk a visitor through
    *trying* Dibs' most distinctive feature themselves: MCP agent coordination needs a real MCP
    client (Claude Code, Codex, etc.), not a browser. So instead of "try this yourself" steps,
    this points at what DemoSeeder already seeded to make that feature visible passively -- a
    claimed task, a plan hierarchy, and mid-flight push-queue state.
--}}
@if (config('dibs.demo_mode'))
    <div
        x-data="{ open: false }"
        class="mb-4 rounded-xl border border-sky-200 bg-sky-50 text-sm dark:border-sky-900 dark:bg-sky-950/40"
    >
        <button
            type="button"
            x-on:click="open = !open"
            x-bind:aria-expanded="open"
            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-sky-800 dark:text-sky-200"
        >
            <span>{{ __('This is a live demo — a few things worth clicking into') }}</span>
            <span x-text="open ? '−' : '+'" class="text-lg leading-none" aria-hidden="true"></span>
        </button>

        <div x-show="open" x-cloak class="space-y-3 border-t border-sky-200 px-4 py-4 text-sky-900 dark:border-sky-900 dark:text-sky-100">
            <p>
                <strong>{{ __('Agent coordination:') }}</strong>
                {{ __('open Personal Projects → Dibs → "Build the in-app notification bell" to see a task actively claimed by an agent — the same claim/heartbeat/release lifecycle real AI agents use through Dibs\' MCP server, not just a status label.') }}
            </p>
            <p>
                <strong>{{ __('Plans, not just tasks:') }}</strong>
                {{ __('"Plan: Add notification digests" (same Group) is a parent issue with its own children — one claimed, two open — showing how a multi-step plan and its tasks stay linked.') }}
            </p>
            <p>
                <strong>{{ __('Local-first sync:') }}</strong>
                {{ __('the Push queue link in the sidebar shows two items mid-flight — one already delivered, one still pending — a live view of the same durable queue that syncs every real edit to GitHub in the background.') }}
            </p>
            <p class="text-xs text-sky-700/80 dark:text-sky-300/80">
                {{ __("This demo has no real GitHub connection, so the pending item stays pending — that's expected, not a bug.") }}
            </p>
        </div>
    </div>
@endif
