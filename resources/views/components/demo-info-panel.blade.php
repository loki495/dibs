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
                {{ __('open Personal Projects → Dibs → "Plan: Add notification digests". Two simulated agents have claimed separate tasks: Claude owns the bell and Codex owns preferences. Their checkpoints show what is left for the next session.') }}
            </p>
            <p>
                <strong>{{ __('A useful next step:') }}</strong>
                {{ __('the data model is complete, integration is blocked by both agents, and documentation is ready to pick up. Open a child task for its acceptance criteria, evidence, and likely files — the same work order an agent gets through MCP.') }}
            </p>
            <p>
                <strong>{{ __('Context that survives a chat:') }}</strong>
                {{ __('follow the plan\'s linked rule, design decision, and retry lesson. An agent starting fresh can reuse those records instead of asking you to explain the project again. The claims and progress are fictional examples; no agents are running in this demo.') }}
            </p>
            <p>
                <strong>{{ __('Local-first sync:') }}</strong>
                {{ __('the Push queue link in the sidebar shows two seeded example rows — one already delivered, one still pending — to illustrate the durable queue a GitHub-mirrored instance uses. Nothing you do in the demo is queued.') }}
            </p>
            <p class="text-xs text-sky-700/80 dark:text-sky-300/80">
                {{ __("This demo has no real GitHub connection, so the pending example row stays pending — that's expected, not a bug.") }}
            </p>
        </div>
    </div>
@endif
