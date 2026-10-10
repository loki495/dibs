# JSON workspace export and import

Open **Settings → Data export/import** (`/data-transfer`) while signed in. **Download JSON** produces a versioned `dibs-workspace` archive from a consistent database read transaction. No records are changed. It includes available and unavailable records: repositories, projects, fields and options (groups/statuses/priorities), issues (tasks/plans/knowledge), labels and their assignments, memberships and schedules, comments and closing references, capture settings/drafts, MCP call history and change history. The file contains private workspace text and should be stored privately.

This is a workspace-content archive, not a full operational database backup. Accounts/passwords, sessions, credentials/environment configuration, claims/agent sessions, MCP write receipts, jobs, GitHub push queues and sync cursors are excluded. Pending mirror operations cannot be recovered from this file; keep a separate operational backup if disaster recovery requires them. GitHub identities and author trust metadata on content records are preserved. Import itself never contacts GitHub or enqueues pushes; ordinary future operations retain the application's existing mirror behavior.

## Import

1. Use a fresh DIBS instance, create a login account, and sign in. The workspace content and coordination tables must be empty; an existing account/session is allowed. This version does not merge, replace or delete existing workspace records.
2. Upload a JSON file exported by this format/version and choose **Validate and preview**. The server checks section/column shape, scalar types, dates/states/JSON fields, unique identities, foreign keys, option scope and parent cycles. Preview shows counts only and writes no workspace data.
3. Review the counts and explicitly confirm **Import archive**. The uploaded bytes are rechecked and revalidated, and the empty-destination check is repeated inside the restore transaction. Any failure rolls back all inserted content. IDs, parent trees and relationships are preserved; capture drafts are assigned to the signed-in account because source accounts are excluded.
4. The preview is removed after success or **Discard preview**. Uploading a replacement clears the previous preview. Previews are encrypted with the destination application's key on its private local disk, bound to the account and session, hashed and valid for 30 minutes. Expired files for that account are pruned when staging another upload. If an abandoned session never returns, its encrypted preview can remain until that account stages again; server administrators may clear only `storage/app/private/workspace-imports` when no previews are needed.

Defaults in `config/workspace-transfer.php`: 50 MiB maximum file size, 200,000 records and 30-minute previews. PHP/web-server upload limits may be lower. Archives with a different format version or incompatible table columns are rejected; no schema migrations are run by import. Do not hand-edit IDs or reinterpret parent links as dependencies.

## Implementation and tests

`WorkspaceTransferController` handles authenticated HTTP/forms, delegating exports, validation, staging, preview reads/cleanup and restoration to typed Actions. Restore inserts in schema dependency order and applies parent links after all issues exist, allowing a parent ID greater than its child. It uses plain inserts into an empty destination, never upserts/deletes. The existing UI/MCP write-boundary tests remain in force.

Action and HTTP tests cover content round trips, relationships/schedules/history, accounts exclusion, malformed/incompatible/oversized input, invalid states/dates/foreign keys/cycles/options, nonempty and changed destinations, missing/expired/tampered/cross-account previews, confirmation and rollback. Browser tests exercise Settings navigation/mobile layout and dark mode. Run tests only with the existing in-memory SQLite guard and fake network/storage; never against a real workspace.
