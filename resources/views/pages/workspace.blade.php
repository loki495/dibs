<?php declare(strict_types=1);

use App\Actions\BuildIssueTree;
use App\Actions\CreateTodoComment;
use App\Actions\CreateTodoIssue;
use App\Actions\DeleteTodoIssue;
use App\Actions\EnqueueGitHubPush;
use App\Actions\GetIssueDetails;
use App\Actions\ReleaseAbandonedTaskClaim;
use App\Actions\RestoreTodoIssue;
use App\Actions\ReviseTodoComment;
use App\Actions\UpdateGitHubProject;
use App\Exceptions\TodoRecordNotFoundException;
use App\Exceptions\TodoRecordUnavailableException;
use App\Exceptions\TodoStaleRevisionException;
use App\Exceptions\TodoValidationException;
use App\Models\Comment;
use App\Models\GitHubProject;
use App\Models\GitHubRepository;
use App\Models\Issue;
use App\Models\Label;
use App\Models\ProjectFieldOption;
use App\Models\ProjectItem;
use App\Services\GitHub\GitHubSyncException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public int $area = 0;

    #[Url]
    public string $view = 'tasks';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $state = 'OPEN';

    #[Url]
    public int $group = 0;

    #[Url]
    public int $priority = 0;

    #[Url]
    public string $sortBy = 'project';

    #[Url]
    public array $labels = [];

    #[Url(as: 'issue')]
    public int $selected = 0;

    public string $newTitle = '';

    public string $newBody = '';

    public bool $editingIssue = false;

    public string $editTitle = '';

    public string $editBody = '';

    public ?string $editError = null;

    public int $editArea = 0;

    public int $editGroup = 0;

    public int $editPriority = 0;

    public array $editLabels = [];

    public string $editNewGroup = '';

    public string $editNewLabel = '';

    public int $editParent = 0;

    public string $editParentSearch = '';

    public string $newCommentBody = '';

    public ?string $commentError = null;

    public int $editingComment = 0;

    public string $editCommentBody = '';

    public int $editCommentRevision = 0;

    public int $captureArea = 0;

    public int $captureParent = 0;

    public int $captureGroup = 0;

    public int $capturePriority = 0;

    public array $captureLabels = [];

    public string $captureNewGroup = '';

    public string $captureNewLabel = '';

    public string $captureParentSearch = '';

    public bool $captureOpen = false;

    public bool $projectSettingsOpen = false;

    public int $projectSettingsProject = 0;

    public string $projectSettingsTitle = '';

    public string $projectSettingsColor = '';

    public ?string $projectSettingsError = null;

    public ?string $captureError = null;

    public ?string $claimError = null;

    public bool $deleteConfirmOpen = false;

    public int $deletingIssue = 0;

    public int $deletingIssueChildrenCount = 0;

    public ?string $deleteError = null;

    public ?string $restoreMessage = null;

    public function mount(): void
    {
        $this->captureArea = $this->area;
        $this->captureParent = $this->selectedParentId();
    }

    public function updatedSelected(): void
    {
        $this->captureParent = $this->selectedParentId();
        $this->reset('editingIssue', 'editTitle', 'editBody', 'editError', 'editArea', 'editGroup', 'editPriority', 'editLabels', 'editNewGroup', 'editNewLabel', 'editParent', 'editParentSearch', 'newCommentBody', 'commentError', 'editingComment', 'editCommentBody', 'editCommentRevision', 'claimError');
    }

    public function chooseArea(int $id): void
    {
        $this->area = $id;
        $this->captureArea = $id;
        if ($this->view === 'daily') {
            $this->view = 'tasks';
        }
        $this->group = 0;
        $this->selected = 0;
        $this->captureParent = 0;
        $this->dispatch('area-changed', area: $this->area);
    }

    public function daily(): void
    {
        $this->reset('area', 'group', 'priority', 'sortBy', 'labels', 'search', 'selected', 'state', 'captureArea', 'captureParent');
        $this->view = 'daily';
        $this->dispatch('area-changed', area: $this->area);
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'group', 'priority', 'sortBy', 'labels', 'state');
    }

    public function toggleLabel(string $name): void
    {
        $this->labels = in_array($name, $this->labels, true)
            ? array_values(array_diff($this->labels, [$name]))
            : [...$this->labels, $name];
    }

    public function openCapture(): void
    {
        $this->reset('captureError', 'newTitle', 'newBody', 'captureNewGroup', 'captureNewLabel', 'captureParentSearch', 'captureLabels');
        $this->captureArea = $this->area;
        $this->captureGroup = $this->group;
        $this->capturePriority = 0;
        $this->captureParent = $this->selectedParentId();
        $this->captureOpen = true;
    }

    #[On('open-project-settings')]
    public function openProjectSettings(): void
    {
        $project = $this->area > 0 ? GitHubProject::query()->where('is_available', true)->find($this->area) : null;
        if (! $project instanceof GitHubProject) {
            return;
        }

        $this->reset('projectSettingsError');
        $this->projectSettingsProject = $project->id;
        $this->projectSettingsTitle = $project->title;
        $this->projectSettingsColor = strtolower($project->color);
        $this->projectSettingsOpen = true;
    }

    public function saveProjectSettings(): void
    {
        $this->reset('projectSettingsError');
        $this->validate([
            'projectSettingsTitle' => ['required', 'string', 'max:255'],
            'projectSettingsColor' => ['required', 'string', 'regex:/^[a-fA-F0-9]{6}$/'],
        ]);
        $project = GitHubProject::query()->where('is_available', true)->find($this->projectSettingsProject);
        if (! $project instanceof GitHubProject) {
            $this->projectSettingsError = 'The selected project is no longer available. Refresh and try again.';

            return;
        }
        $token = (string) config('github.token');
        if ($token === '') {
            $this->projectSettingsError = 'Project settings need a server-side GitHub token. Set GITHUB_TOKEN and try again.';

            return;
        }

        try {
            if (trim($this->projectSettingsTitle) !== $project->title) {
                $project = app(UpdateGitHubProject::class)->handle($token, $project, $this->projectSettingsTitle);
            }
            $project->update(['color' => strtolower($this->projectSettingsColor)]);
        } catch (GitHubSyncException $exception) {
            $this->projectSettingsError = $exception->getMessage();

            return;
        }

        $this->projectSettingsOpen = false;
    }

    public function updatedCaptureGroup(int $value): void
    {
        if ($value !== -1) {
            $this->captureNewGroup = '';
        }
    }

    public function updatedEditGroup(int $value): void
    {
        if ($value !== -1) {
            $this->editNewGroup = '';
        }
    }

    public function toggleCaptureLabel(int $id): void
    {
        $this->captureLabels = in_array($id, $this->captureLabels, true)
            ? array_values(array_diff($this->captureLabels, [$id]))
            : [...$this->captureLabels, $id];
    }

    public function capture(): void
    {
        $this->reset('captureError');
        $this->validate(['newTitle' => ['required', 'string', 'max:255'], 'newBody' => ['nullable', 'string', 'max:65535'], 'captureNewGroup' => ['nullable', 'string', 'max:50'], 'captureNewLabel' => ['nullable', 'string', 'max:50'], 'captureLabels' => ['array'], 'captureLabels.*' => ['integer']]);
        try {
            $issue = app(CreateTodoIssue::class)->handle(
                title: $this->newTitle,
                body: $this->newBody === '' ? null : $this->newBody,
                area: $this->captureArea > 0 ? $this->captureArea : null,
                parentId: $this->captureParent > 0 ? $this->captureParent : null,
                groupId: $this->captureGroup > 0 ? $this->captureGroup : null,
                priorityId: $this->capturePriority > 0 ? $this->capturePriority : null,
                labelIds: $this->captureLabels,
                newGroupName: $this->captureNewGroup,
                newLabelName: $this->captureNewLabel,
            );
        } catch (TodoValidationException $exception) {
            $this->captureError = $exception->getMessage();

            return;
        }
        $this->selected = $issue->id;
        $this->reset('newTitle', 'newBody', 'captureGroup', 'capturePriority', 'captureLabels', 'captureNewGroup', 'captureNewLabel', 'captureParentSearch');
        $this->captureOpen = false;
    }

    public function beginEdit(): void
    {
        $issue = Issue::query()->where('is_available', true)->with(['labels', 'projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')])->find($this->selected);
        if (! $issue instanceof Issue) {
            $this->selected = 0;

            return;
        }
        $membership = $issue->projectItems->first();
        $this->editTitle = $issue->title;
        $this->editBody = $issue->body ?? '';
        $this->editArea = $membership?->project_id ?? 0;
        $this->editGroup = $membership?->group_option_id ?? 0;
        $this->editPriority = $membership?->priority_option_id ?? 0;
        $this->editLabels = $issue->labels->where('is_available', true)->pluck('id')->all();
        $this->editParent = $issue->parent_issue_id ?? 0;
        $this->reset('editNewGroup', 'editNewLabel', 'editParentSearch', 'editError');
        $this->editingIssue = true;
    }

    public function toggleEditLabel(int $id): void
    {
        $this->editLabels = in_array($id, $this->editLabels, true)
            ? array_values(array_diff($this->editLabels, [$id]))
            : [...$this->editLabels, $id];
    }

    public function cancelEdit(): void
    {
        $this->reset('editingIssue', 'editTitle', 'editBody', 'editError', 'editArea', 'editGroup', 'editPriority', 'editLabels', 'editNewGroup', 'editNewLabel', 'editParent', 'editParentSearch');
    }

    public function saveIssue(): void
    {
        $this->reset('editError');
        $this->validate(['editTitle' => ['required', 'string', 'max:255'], 'editBody' => ['nullable', 'string', 'max:65535'], 'editNewGroup' => ['nullable', 'string', 'max:50'], 'editNewLabel' => ['nullable', 'string', 'max:50'], 'editLabels' => ['array'], 'editLabels.*' => ['integer']]);
        $issue = Issue::query()->where('is_available', true)->with(['projectItems' => fn ($query) => $query->where('is_available', true)->whereNull('archived_at')])->find($this->selected);
        if (! $issue instanceof Issue) {
            $this->cancelEdit();
            $this->selected = 0;

            return;
        }
        $repository = GitHubRepository::query()->where('full_name', config('github.owner').'/'.config('github.repository'))->first();
        if (! $repository instanceof GitHubRepository) {
            $this->editError = 'The repository is not configured or not available locally. Refresh and try again.';
            $this->cancelEdit();

            return;
        }
        $project = $this->editArea > 0 ? GitHubProject::query()->where('is_available', true)->find($this->editArea) : null;
        $parent = $this->editParent > 0 ? Issue::query()->where('is_available', true)->find($this->editParent) : null;
        $group = $this->editGroup > 0 ? ProjectFieldOption::query()->with('field')->find($this->editGroup) : null;
        $priority = $this->editPriority > 0 ? ProjectFieldOption::query()->with('field')->find($this->editPriority) : null;
        $labels = Label::query()->where('is_available', true)->whereIn('id', $this->editLabels)->get();
        if (($this->editArea > 0 && ! $project instanceof GitHubProject) || ($this->editParent > 0 && ! $parent instanceof Issue) || $labels->count() !== count($this->editLabels)) {
            $this->editError = 'One or more selected task fields are no longer available. Refresh and try again.';

            return;
        }
        if ($group instanceof ProjectFieldOption && (! $project instanceof GitHubProject || $group->field?->project_id !== $project->id)) {
            $this->editError = 'The selected Group is not available in this Area. Refresh and try again.';

            return;
        }
        if ($priority instanceof ProjectFieldOption && (! $project instanceof GitHubProject || $priority->field?->semantic_key !== 'priority' || $priority->field?->project_id !== $project->id)) {
            $this->editError = 'The selected Priority is not available in this Area. Refresh and try again.';

            return;
        }
        if (trim($this->editNewGroup) !== '' && ! $project instanceof GitHubProject) {
            $this->editError = 'Choose an area before creating a Group.';

            return;
        }
        DB::transaction(function () use (&$group, &$labels, $project, $priority, $parent, $repository, $issue): void {
            if (trim($this->editNewGroup) !== '') {
                $groupField = $project->fields()->where('semantic_key', 'group')->where('is_available', true)->first();
                $existingGroup = $groupField?->options()->whereRaw('LOWER(name) = LOWER(?)', [trim($this->editNewGroup)])->first();
                if ($existingGroup instanceof ProjectFieldOption) {
                    $group = $existingGroup;
                } else {
                    $newOption = ProjectFieldOption::create([
                        'project_field_id' => $groupField->id,
                        'github_option_id' => null,
                        'name' => trim($this->editNewGroup),
                        'color' => 'GRAY',
                        'position' => (int) $groupField->options()->max('position') + 1,
                    ]);
                    app(EnqueueGitHubPush::class)->handle('create_group_option', 'project_field_option', $newOption->id, ['name' => $newOption->name, 'color' => 'GRAY'], 'group_option:create:'.$newOption->id);
                    $group = $newOption;
                }
            }
            if (trim($this->editNewLabel) !== '') {
                $existingLabel = Label::query()->where('repository_id', $repository->id)->whereRaw('LOWER(name) = LOWER(?)', [trim($this->editNewLabel)])->first();
                if ($existingLabel instanceof Label) {
                    $labels->push($existingLabel);
                } else {
                    $newLabel = Label::create([
                        'repository_id' => $repository->id,
                        'github_node_id' => null,
                        'name' => trim($this->editNewLabel),
                        'color' => '6B7280',
                        'is_available' => true,
                    ]);
                    app(EnqueueGitHubPush::class)->handle('create_label', 'label', $newLabel->id, ['name' => $newLabel->name, 'color' => '6B7280', 'description' => null], 'label:create:'.$newLabel->id);
                    $labels->push($newLabel);
                }
            }
            $issue->update(['title' => $this->editTitle, 'body' => $this->editBody === '' ? null : $this->editBody]);
            app(EnqueueGitHubPush::class)->handle('update_issue_body', 'issue', $issue->id, ['title' => $issue->title, 'body' => $issue->body], 'issue:update:'.$issue->id.':'.now()->timestamp);

            $current = $issue->labels()->where('is_available', true)->get();
            $currentIds = $current->pluck('id')->all();
            $requestedIds = $labels->pluck('id')->all();
            $addIds = array_values(array_diff($requestedIds, $currentIds));
            $removeIds = array_values(array_diff($currentIds, $requestedIds));
            if ($addIds !== [] || $removeIds !== []) {
                $issue->labels()->sync($requestedIds);
                app(EnqueueGitHubPush::class)->handle('set_issue_labels', 'issue', $issue->id, ['add_label_ids' => $addIds, 'remove_label_ids' => $removeIds], 'issue:labels:'.$issue->id.':'.now()->timestamp);
            }

            if ($parent instanceof Issue && $issue->parent_issue_id !== $parent->id) {
                $sibling_position = (int) Issue::query()->where('parent_issue_id', $parent->id)->max('sibling_position') + 1;
                $issue->update(['parent_issue_id' => $parent->id, 'sibling_position' => $sibling_position]);
                app(EnqueueGitHubPush::class)->handle('set_issue_parent', 'issue', $issue->id, ['parent_issue_id' => $parent->id], 'issue:parent:'.$issue->id.':'.now()->timestamp);
            } elseif ($parent === null && $issue->parent_issue_id !== null) {
                $previousParentGithubNodeId = $issue->github_parent_node_id;
                $issue->update(['parent_issue_id' => null, 'github_parent_node_id' => null, 'sibling_position' => 0]);
                if ($previousParentGithubNodeId !== null) {
                    app(EnqueueGitHubPush::class)->handle('remove_issue_parent', 'issue', $issue->id, ['parent_github_node_id' => $previousParentGithubNodeId], 'issue:remove_parent:'.$issue->id.':'.now()->timestamp);
                }
            }

            if ($project instanceof GitHubProject) {
                $item = $issue->projectItems->firstWhere('project_id', $project->id);
                if (! $item instanceof ProjectItem) {
                    $item = ProjectItem::create([
                        'project_id' => $project->id,
                        'issue_id' => $issue->id,
                        'github_node_id' => null,
                        'content_type' => 'ISSUE',
                        'is_available' => true,
                        'last_seen_at' => now(),
                    ]);
                    app(EnqueueGitHubPush::class)->handle('add_project_membership', 'project_item', $item->id, [], 'project_item:create:'.$item->id);
                }
                foreach ($issue->projectItems->where('project_id', '!==', $project->id) as $obsolete) {
                    if ($obsolete->github_node_id !== null) {
                        app(EnqueueGitHubPush::class)->handle('delete_project_item', 'project_item', $obsolete->id, [], 'project_item:delete:'.$obsolete->id);
                    } else {
                        $obsolete->update(['is_available' => false]);
                    }
                }
                if ($group instanceof ProjectFieldOption) {
                    $item->update(['group_option_id' => $group->id]);
                    app(EnqueueGitHubPush::class)->handle('set_project_item_group', 'project_item', $item->id, ['group_option_id' => $group->id], 'project_item:group:'.$item->id);
                } elseif ($item->group_option_id !== null) {
                    $item->update(['group_option_id' => null]);
                    app(EnqueueGitHubPush::class)->handle('clear_project_item_group', 'project_item', $item->id, [], 'project_item:clear_group:'.$item->id.':'.now()->timestamp);
                }
                if ($priority instanceof ProjectFieldOption) {
                    $item->update(['priority_option_id' => $priority->id]);
                    app(EnqueueGitHubPush::class)->handle('set_project_item_priority', 'project_item', $item->id, ['priority_option_id' => $priority->id], 'project_item:priority:'.$item->id);
                } elseif ($item->priority_option_id !== null) {
                    $item->update(['priority_option_id' => null]);
                    app(EnqueueGitHubPush::class)->handle('clear_project_item_priority', 'project_item', $item->id, [], 'project_item:clear_priority:'.$item->id.':'.now()->timestamp);
                }
            } else {
                foreach ($issue->projectItems as $obsolete) {
                    if ($obsolete->github_node_id !== null) {
                        app(EnqueueGitHubPush::class)->handle('delete_project_item', 'project_item', $obsolete->id, [], 'project_item:delete:'.$obsolete->id);
                    } else {
                        $obsolete->update(['is_available' => false]);
                    }
                }
            }
        });
        $this->cancelEdit();
    }

    public function closeIssue(): void
    {
        $this->reset('editError');
        $issue = Issue::query()->where('is_available', true)->find($this->selected);
        if (! $issue instanceof Issue) {
            $this->selected = 0;

            return;
        }
        $issue->update(['state' => 'CLOSED']);
        app(EnqueueGitHubPush::class)->handle('close_issue', 'issue', $issue->id, [], 'issue:close:'.$issue->id);
    }

    public function openDeleteConfirm(): void
    {
        $this->reset('deleteError');
        $issue = Issue::query()->where('is_available', true)->find($this->selected);
        if (! $issue instanceof Issue) {
            return;
        }
        $this->deletingIssue = $issue->id;
        $this->deletingIssueChildrenCount = $issue->children()->where('is_available', true)->count();
        $this->deleteConfirmOpen = true;
    }

    public function confirmDelete(bool $cascadeChildren): void
    {
        $this->reset('deleteError');
        $issue = Issue::query()->where('is_available', true)->find($this->deletingIssue);
        if (! $issue instanceof Issue) {
            $this->deleteError = 'The selected task is no longer available. Refresh and try again.';

            return;
        }

        try {
            $result = app(DeleteTodoIssue::class)->handle($issue, $cascadeChildren);
        } catch (TodoValidationException $exception) {
            $this->deleteError = $exception->getMessage();

            return;
        }

        $this->deleteConfirmOpen = false;
        $this->deletingIssue = 0;
        if (in_array($this->selected, $result, true)) {
            $this->selected = 0;
        }
    }

    public function restoreIssue(int $id): void
    {
        $this->reset('restoreMessage');
        $issue = Issue::query()->where('is_available', false)->find($id);
        if (! $issue instanceof Issue) {
            return;
        }
        app(RestoreTodoIssue::class)->handle($issue);
        $this->restoreMessage = __('Restored ":title".', ['title' => $issue->title]);
    }

    public function releaseClaim(): void
    {
        $this->reset('claimError');
        try {
            app(ReleaseAbandonedTaskClaim::class)->handle($this->selected);
        } catch (DomainException $exception) {
            $this->claimError = $exception->getMessage();
        }
    }

    public function addComment(): void
    {
        $this->reset('commentError');
        $this->validate(['newCommentBody' => ['required', 'string', 'max:65535']]);
        $issue = Issue::query()->where('is_available', true)->find($this->selected);
        if (! $issue instanceof Issue) {
            $this->selected = 0;

            return;
        }
        app(CreateTodoComment::class)->handle($issue->id, $this->newCommentBody);
        $this->reset('newCommentBody', 'commentError');
    }

    public function beginEditComment(int $id): void
    {
        $comment = Comment::query()->where('is_available', true)->where('issue_id', $this->selected)->find($id);
        if (! $comment instanceof Comment) {
            return;
        }
        $this->editingComment = $comment->id;
        $this->editCommentBody = $comment->body;
        $this->editCommentRevision = $comment->revision;
        $this->reset('commentError');
    }

    public function cancelEditComment(): void
    {
        $this->reset('editingComment', 'editCommentBody', 'editCommentRevision', 'commentError');
    }

    public function saveComment(): void
    {
        $this->reset('commentError');
        $this->validate(['editCommentBody' => ['required', 'string', 'max:65535']]);
        try {
            app(ReviseTodoComment::class)->handle($this->editingComment, $this->editCommentBody, $this->editCommentRevision);
        } catch (TodoRecordNotFoundException|TodoRecordUnavailableException) {
            $this->cancelEditComment();

            return;
        } catch (TodoStaleRevisionException) {
            $this->commentError = 'This comment changed since you started editing it. Refresh and try again.';

            return;
        }
        $this->cancelEditComment();
    }

    private function selectedParentId(): int
    {
        if ($this->selected === 0) {
            return 0;
        }

        return Issue::query()->where('is_available', true)->whereKey($this->selected)->value('id') ?? 0;
    }

    public function with(): array
    {
        $captureParents = Issue::query()->where('is_available', true)
            ->when($this->captureParentSearch !== '', fn ($query) => $query->where(function ($matches): void {
                $matches->where('title', 'like', '%'.$this->captureParentSearch.'%')
                    ->orWhere('github_number', $this->captureParentSearch);
            }))->orderBy('title')->limit(100)->get(['id', 'github_number', 'title']);
        $captureGroups = ProjectFieldOption::query()->whereHas('field', fn ($field) => $field->where('is_available', true)->where('semantic_key', 'group')->where('project_id', $this->captureArea))->orderBy('position')->get();
        $capturePriorities = ProjectFieldOption::query()->whereHas('field', fn ($field) => $field->where('is_available', true)->where('semantic_key', 'priority')->where('project_id', $this->captureArea))->orderBy('position')->get();
        $editParents = Issue::query()->where('is_available', true)->whereKeyNot($this->selected)
            ->when($this->editParentSearch !== '', fn ($query) => $query->where(function ($matches): void {
                $matches->where('title', 'like', '%'.$this->editParentSearch.'%')->orWhere('github_number', $this->editParentSearch);
            }))->orderBy('title')->limit(100)->get(['id', 'github_number', 'title']);
        $editGroups = ProjectFieldOption::query()->whereHas('field', fn ($field) => $field->where('is_available', true)->where('semantic_key', 'group')->where('project_id', $this->editArea))->orderBy('position')->get();
        $editPriorities = ProjectFieldOption::query()->whereHas('field', fn ($field) => $field->where('is_available', true)->where('semantic_key', 'priority')->where('project_id', $this->editArea))->orderBy('position')->get();
        $labelOptions = Label::query()->where('is_available', true)->orderBy('name')->get(['id', 'name']);
        $deletedRows = $this->view === 'deleted'
            ? Issue::query()->where('is_available', false)
                ->when($this->search !== '', fn ($query) => $query->where(function ($matches): void {
                    $matches->where('title', 'like', '%'.$this->search.'%')->orWhere('github_number', $this->search);
                }))->orderByDesc('updated_at')->limit(100)->get(['id', 'github_number', 'title', 'updated_at'])
            : null;

        return [...app(BuildIssueTree::class)->handle($this->area, $this->view, $this->search, $this->state, $this->group, $this->labels, $this->priority, $this->sortBy),
            'detail' => $this->selected > 0 ? app(GetIssueDetails::class)->handle($this->selected) : null,
            'deletedRows' => $deletedRows,
            'captureParents' => $captureParents, 'captureGroups' => $captureGroups, 'capturePriorities' => $capturePriorities, 'captureLabelOptions' => $labelOptions,
            'editParents' => $editParents, 'editGroups' => $editGroups, 'editPriorities' => $editPriorities, 'editLabelOptions' => $labelOptions];
    }
}; ?>

<div class="pb-8 pt-3" @keydown.escape.window="$wire.set('selected', 0)">
    <div class="grid items-start gap-6 lg:grid-cols-[230px_minmax(0,1fr)] lg:gap-10">
            <div class="mb-4 flex gap-1.5 overflow-x-auto pb-0.5 md:hidden" aria-label="{{ __('Workspace area') }}">
                <button type="button" wire:click="daily" @class(['shrink-0 rounded-full border px-3 py-1 text-xs font-medium transition', 'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => $view === 'daily', 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $view !== 'daily']) aria-pressed="{{ $view === 'daily' ? 'true' : 'false' }}">{{ __('Daily') }} · {{ $dailyCount }}</button>
                <button type="button" wire:click="chooseArea(0)" @class(['shrink-0 rounded-full border px-3 py-1 text-xs font-medium transition', 'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => $area === 0 && $view !== 'daily', 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $area !== 0 || $view === 'daily']) aria-pressed="{{ $area === 0 && $view !== 'daily' ? 'true' : 'false' }}">{{ __('All') }} · {{ $taskCount }}</button>
                @foreach ($projects as $project)
                    <button type="button" wire:click="chooseArea({{ $project->id }})" @class(['flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition', 'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => $area === $project->id && $view !== 'daily', 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => ! ($area === $project->id && $view !== 'daily')]) aria-pressed="{{ $area === $project->id && $view !== 'daily' ? 'true' : 'false' }}">
                        <span class="size-1.5 shrink-0 rounded-full" style="background-color: #{{ $project->color }}"></span>{{ $project->title }} · {{ $areaCounts[$project->id] ?? 0 }}
                    </button>
                @endforeach
            </div>
        <aside class="hidden lg:sticky lg:top-6 md:block">
            <nav aria-label="{{ __('Workspace navigation') }}" class="space-y-1">
                <div class="flex gap-1">
                    <button wire:click="daily" @class(['flex min-h-9 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-sm', 'bg-teal-100 text-teal-900 dark:bg-teal-950 dark:text-teal-200' => $view === 'daily', 'hover:bg-slate-100 dark:hover:bg-slate-900' => $view !== 'daily'])>
                        <flux:icon.sun class="size-4" /><span>{{ __('Daily') }}</span><span class="text-xs tabular-nums">{{ $dailyCount }}</span>
                    </button>
                    <button wire:click="chooseArea(0)" @class(['flex min-h-9 flex-1 items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-sm', 'bg-slate-200/70 dark:bg-slate-800' => $area === 0 && $view !== 'daily', 'hover:bg-slate-100 dark:hover:bg-slate-900' => $area !== 0 || $view === 'daily'])>
                        <flux:icon.squares-2x2 class="size-4" /><span>{{ __('All') }}</span><span class="text-xs tabular-nums">{{ $taskCount }}</span>
                    </button>
                </div>
                <p class="px-3 pb-2 pt-5 text-xs font-medium uppercase tracking-widest text-slate-500">{{ __('Areas') }}</p>
                <div class="grid grid-cols-2 gap-1 lg:grid-cols-1">
                    @foreach ($projects as $project)
                        <button wire:click="chooseArea({{ $project->id }})" @class(['flex min-h-9 items-center gap-2 rounded-xl px-2.5 py-1.5 text-left text-sm', 'bg-slate-200/70 font-medium dark:bg-slate-800' => $area === $project->id, 'hover:bg-slate-100 dark:hover:bg-slate-900' => $area !== $project->id]) aria-pressed="{{ $area === $project->id ? 'true' : 'false' }}">
                            <span class="size-1.5 shrink-0 rounded-full" style="background-color: #{{ $project->color }}"></span><span class="flex-1">{{ $project->title }}</span><span class="text-xs tabular-nums text-slate-500">{{ $areaCounts[$project->id] ?? 0 }}</span>
                        </button>
                    @endforeach
                </div>
            </nav>
            <div class="mt-6 border-t border-slate-200 px-3 pt-4 text-xs leading-relaxed text-slate-500 dark:border-slate-800" aria-live="polite">
                @if ($sync?->last_success_at)
                    <span class="mr-1 inline-block size-1.5 rounded-full bg-teal-600"></span>{{ __('Last synced :time', ['time' => $sync->last_success_at->diffForHumans()]) }}
                @else
                    {{ __('Waiting for the first import') }}
                @endif
            </div>
            <div class="mt-2 border-t border-slate-200 pt-2 dark:border-slate-800">
                <livewire:top-bar variant="labeled" />
            </div>
        </aside>

        <section class="min-w-0">
            @if ($restoreMessage)
                <div role="status" class="mb-4 flex items-center justify-between gap-3 rounded-xl bg-teal-50 px-4 py-3 text-sm text-teal-900 dark:bg-teal-950 dark:text-teal-100">
                    <span>{{ $restoreMessage }}</span>
                    <button type="button" wire:click="$set('restoreMessage', null)" class="flex size-8 shrink-0 items-center justify-center rounded-lg hover:bg-teal-100 dark:hover:bg-teal-900" aria-label="{{ __('Dismiss') }}"><flux:icon.x-mark class="size-4" /></button>
                </div>
            @endif
            <div class="mb-5">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex rounded-lg border border-slate-200 p-1 dark:border-slate-800" aria-label="{{ __('Content type') }}">
                        @foreach (['tasks' => __('Tasks'), 'knowledge' => __('Knowledge'), 'deleted' => __('Deleted')] as $mode => $title)
                            <button wire:click="$set('view', '{{ $mode }}')" @class(['min-h-9 rounded-md px-3 text-sm sm:px-4', 'bg-white font-medium shadow-sm dark:bg-slate-800' => $view === $mode, 'text-slate-500 hover:text-slate-900 dark:hover:text-slate-100' => $view !== $mode]) aria-pressed="{{ $view === $mode ? 'true' : 'false' }}">{{ $title }}</button>
                        @endforeach
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($area > 0 && $view !== 'daily')
                            <div class="hidden md:block">
                                <flux:button type="button" wire:click="openProjectSettings" variant="ghost" size="sm" icon="cog-6-tooth">{{ __('Project settings') }}</flux:button>
                            </div>
                        @endif
                        <flux:button type="button" wire:click="openCapture" icon="plus" size="sm" class="bg-teal-700! text-white! hover:bg-teal-600! dark:bg-teal-600! dark:hover:bg-teal-500!">{{ __('Add task') }}</flux:button>
                    </div>
                </div>
                <div class="mt-3"><flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="Search titles, notes, or #number" aria-label="Search tasks" /></div>
            </div>
            @if ($view !== 'deleted')
            <div class="mb-4 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:select wire:model.live="group" class="min-w-44" aria-label="Website or group">
                        <option value="0">{{ __('All groups') }}</option>
                        @foreach ($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </flux:select>
                    @if ($view !== 'daily')
                        <flux:select wire:model.live="state" class="w-32" aria-label="Issue state"><option value="OPEN">{{ __('Open') }}</option><option value="CLOSED">{{ __('Closed') }}</option><option value="ALL">{{ __('All states') }}</option></flux:select>
                    @endif
                    <flux:select wire:model.live="priority" class="w-36" aria-label="{{ __('Priority') }}"><option value="0">{{ __('Any priority') }}</option>@foreach (range(1, 5) as $value)<option value="{{ $value }}">{{ __('P:value', ['value' => $value]) }}</option>@endforeach</flux:select>
                    <flux:select wire:model.live="sortBy" class="w-40" aria-label="{{ __('Sort by') }}">
                        <option value="project">{{ __('Sort: Project') }}</option>
                        <option value="group">{{ __('Sort: Group') }}</option>
                        <option value="newest_first">{{ __('Sort: Newest first') }}</option>
                        <option value="newest_last">{{ __('Sort: Newest last') }}</option>
                        <option value="priority">{{ __('Sort: Priority') }}</option>
                    </flux:select>
                </div>
                @if ($labelOptions)
                    <div x-data="{ open: false }" class="flex items-start gap-1.5" aria-label="{{ __('Labels') }}">
                        <div class="flex max-h-8 flex-1 flex-nowrap gap-1.5 overflow-auto md:max-h-none md:flex-wrap md:overflow-visible" :class="open ? 'max-h-40 flex-wrap' : ''">
                            @foreach ($labelOptions as $name)
                                <button wire:click="toggleLabel(@js($name))" @class(['shrink-0 rounded-full border px-2 py-1 text-xs transition', 'border-teal-600 bg-teal-100 text-teal-900 dark:border-teal-500 dark:bg-teal-950 dark:text-teal-100' => in_array($name, $labels, true), 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => ! in_array($name, $labels, true)]) aria-pressed="{{ in_array($name, $labels, true) ? 'true' : 'false' }}">{{ $name }}</button>
                            @endforeach
                        </div>
                        <button type="button" @click="open = ! open" class="flex size-8 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 md:hidden dark:hover:bg-slate-800" :aria-expanded="open.toString()" aria-label="{{ __('Show all labels') }}"><flux:icon.chevron-right class="size-4 transition-transform" ::class="open ? 'rotate-90' : ''" /></button>
                    </div>
                @endif
            </div>
            @endif
            @if ($view === 'deleted')
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex min-h-14 items-center px-4 text-xs text-slate-500 dark:border-slate-800">
                        <span aria-live="polite">{{ trans_choice(':count deleted task|:count deleted tasks', $deletedRows->count(), ['count' => $deletedRows->count()]) }}</span>
                    </div>
                    <div role="list" aria-label="{{ __('Deleted tasks') }}">
                        @forelse ($deletedRows as $row)
                            <div wire:key="deleted-row-{{ $row->id }}" role="listitem" class="flex min-h-14 items-center justify-between gap-3 border-b border-slate-100 px-4 py-2 last:border-0 dark:border-slate-800/70">
                                <div class="min-w-0">
                                    <span class="block truncate text-sm text-slate-500 line-through dark:text-slate-400">{{ $row->title }}</span>
                                    <span class="text-[11px] text-slate-500">#{{ $row->github_number }} · {{ __('Deleted :time', ['time' => $row->updated_at->diffForHumans()]) }}</span>
                                </div>
                                <flux:button type="button" wire:click="restoreIssue({{ $row->id }})" size="sm">{{ __('Restore') }}</flux:button>
                            </div>
                        @empty
                            <div class="px-6 py-14 text-center">
                                <flux:icon.inbox class="mx-auto mb-4 size-8 text-slate-400" />
                                <h2 class="font-medium">{{ __('Nothing deleted') }}</h2>
                                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-slate-500">{{ $search !== '' ? __('No deleted tasks match your search.') : __('Deleted tasks appear here and can be restored at any time.') }}</p>
                            </div>
                        @endforelse
                    </div>
                </div>
            @else
            <div wire:key="tree-{{ md5($area.$view.$search.$state.$group.$priority.$sortBy.implode(', ', $labels)) }}" x-data="todoTree(@js($filtered))" class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex min-h-14 items-center justify-between gap-3 border-b border-slate-100 px-4 text-xs text-slate-500 dark:border-slate-800">
                    <span aria-live="polite">{{ trans_choice(':count result|:count results', $matchCount, ['count' => $matchCount]) }}{{ $filtered ? ' · '.__('with parent context') : '' }}</span>
                    <button @click="toggleAll(@js(array_column($rows, 'id')))" x-text="allOpen(@js(array_column($rows, 'id'))) ? @js(__('Collapse all')) : @js(__('Expand all'))" class="min-h-10 px-2 hover:text-slate-900 dark:hover:text-slate-100"></button>
                </div>
                <div role="list" aria-label="{{ __('Task hierarchy') }}">
                    @forelse ($rows as $row)
                        @if ($row['virtual'])
                            <div wire:key="group-row-{{ $row['id'] }}" data-group-root="{{ substr($row['id'], 6) }}" role="listitem" x-show="visible(@js($row['ancestors']))" x-cloak class="border-b border-slate-100 last:border-0 dark:border-slate-800/70">
                                <div data-project-color="{{ $row['projectColor'] }}" role="button" tabindex="0" @click="toggle(@js($row['id']))" @keydown.enter.prevent="toggle(@js($row['id']))" @keydown.space.prevent="toggle(@js($row['id']))" :aria-expanded="isOpen(@js($row['id']))" class="flex min-h-14 cursor-pointer items-center gap-1 py-2 pr-3" style="padding-left: calc(0.5rem + {{ min($row['depth'], 5) }} * 1rem); @if ($row['projectColor']) background-color: color-mix(in srgb, {{ $row['projectColor'] }} 10%, transparent); @else background-color: rgb(248 250 252 / .7); @endif">
                                    <button @click.stop="toggle(@js($row['id']))" :aria-expanded="isOpen(@js($row['id']))" aria-label="{{ __('Expand or collapse :title', ['title' => $row['title']]) }}" class="flex size-10 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><flux:icon.chevron-right class="size-4 transition-transform" ::class="isOpen('{{ $row['id'] }}') ? 'rotate-90' : ''" /></button>
                                    <span class="flex h-10 w-5 shrink-0 items-center text-teal-700 dark:text-teal-400"><flux:icon.folder class="size-4" /></span>
                                    <div class="min-w-0 flex-1 py-1"><span class="block break-words text-sm font-semibold leading-6">{{ $row['title'] }}</span>@if ($area === 0 && $row['projectTitle'])<button type="button" wire:click.stop="chooseArea({{ $row['projectAreaId'] }})" class="mt-1 inline-flex rounded-full border px-2 py-0.5 text-[11px] font-medium hover:brightness-95" style="border-color: {{ $row['projectColor'] }}; background-color: color-mix(in srgb, {{ $row['projectColor'] }} 14%, transparent); color: {{ $row['projectColor'] }}">{{ $row['projectTitle'] }}</button>@endif</div>
                                    <span class="text-xs text-slate-500">{{ __('Group') }}</span>
                                </div>
                            </div>
                        @else
                            <div wire:key="issue-row-{{ $row['id'] }}" data-issue-number="{{ $row['number'] }}" role="listitem" x-show="visible(@js($row['ancestors']))" x-cloak class="border-b border-slate-100 last:border-0 dark:border-slate-800/70">
                                <div data-project-color="{{ $row['projectColor'] }}" role="button" tabindex="0" wire:click="$set('selected', {{ $row['id'] }})" @keydown.enter.prevent="$wire.set('selected', {{ $row['id'] }})" @keydown.space.prevent="$wire.set('selected', {{ $row['id'] }})" aria-label="{{ __('Open issue :number: :title', ['number' => $row['number'], 'title' => $row['title']]) }}" class="flex min-h-16 cursor-pointer items-start gap-1 py-2 pr-3 hover:brightness-[.98] dark:hover:brightness-125" style="padding-left: calc(0.5rem + {{ min($row['depth'], 5) }} * 1rem); @if ($row['projectColor']) background-color: color-mix(in srgb, {{ $row['projectColor'] }} {{ min(9 + ($row['depth'] * 5), 34) }}%, transparent); @endif">
                                    @if ($row['hasChildren'])
                                        <button @click.stop="toggle(@js($row['id']))" :aria-expanded="isOpen(@js($row['id']))" aria-label="{{ __('Expand or collapse :title', ['title' => $row['title']]) }}" class="flex size-10 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><flux:icon.chevron-right class="size-4 transition-transform" ::class="isOpen('{{ $row['id'] }}') ? 'rotate-90' : ''" /></button>
                                    @else <span class="w-10 shrink-0" aria-hidden="true"></span> @endif
                                    <div class="min-w-0 flex-1 py-2 text-left">
                                        <span @class(['block break-words text-sm leading-6', 'font-medium' => $row['container'], 'text-slate-500 dark:text-slate-400' => $row['context'], 'line-through opacity-70' => $row['state'] === 'CLOSED'])>{{ $row['title'] }}</span>
                                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
                                            <span>#{{ $row['number'] }}</span>
                                            @if ($row['parentTitle'] ?? null)<span>{{ __('↳ :title', ['title' => $row['parentTitle']]) }}</span>@endif
                                            @if ($row['outsideArea'])<span>{{ __('Parent from another area') }}</span>@elseif ($row['context'])<span>{{ __('Parent context') }}</span>@endif
                                            @if ($row['unresolvedParent'])<span>{{ __('Parent not imported') }}</span>@endif
                                            @foreach ($row['memberships'] as $membership)
                                                @if ($area === 0 && $row['depth'] === 0 && $membership['groupId'] === null)<button type="button" wire:click.stop="chooseArea({{ $membership['area'] }})" class="rounded-full border px-1.5 py-0.5 font-medium hover:brightness-95" style="border-color: {{ $membership['color'] }}; background-color: color-mix(in srgb, {{ $membership['color'] }} 14%, transparent); color: {{ $membership['color'] }}">{{ $membership['title'] }}</button>@elseif ($area === 0)<span>{{ $membership['title'] }}</span>@endif
                                                @if ($membership['priority'])<span class="rounded-full border border-violet-300 bg-violet-50 px-1.5 py-0.5 font-medium text-violet-800 dark:border-violet-700 dark:bg-violet-950 dark:text-violet-200">{{ __('P:priority', ['priority' => $membership['priority']]) }}</span>@endif
                                                @if ($membership['due'])<span @class(['text-amber-700 dark:text-amber-400' => $membership['due'] <= $today])>{{ __('Due :date', ['date' => $membership['due']]) }}</span>@endif
                                                @if ($membership['planned'])<span>{{ __('Planned :date', ['date' => $membership['planned']]) }}</span>@endif
                                            @endforeach
                                            @foreach ($row['labelData'] as $badge)<button type="button" wire:click.stop="toggleLabel(@js($badge['name']))" data-label="{{ $badge['name'] }}" class="rounded border px-1.5 hover:brightness-95" @if ($badge['color']) style="border-color: {{ $badge['color'] }}; background-color: color-mix(in srgb, {{ $badge['color'] }} 16%, transparent); color: {{ $badge['color'] }}" @endif>{{ $badge['name'] }}</button>@endforeach
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @empty
                        <div class="px-6 py-14 text-center">
                            <flux:icon.inbox class="mx-auto mb-4 size-8 text-slate-400" />
                            <h2 class="font-medium">{{ $projects->isEmpty() ? __('Your workspace is ready') : __('Nothing here just yet') }}</h2>
                            <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-slate-500">{{ $view === 'daily' ? __('No open tasks are planned, due, or picked for today.') : __('Try another area or clear your filters to find more.') }}</p>
                            @if ($filtered || $state !== 'OPEN')<flux:button wire:click="clearFilters" variant="ghost" size="sm" class="mt-4">{{ __('Clear filters') }}</flux:button>@endif
                        </div>
                    @endforelse
                </div>
            </div>
            @endif
        </section>
    </div>
    @if ($detail)
        @include('partials.issue-detail', ['detail' => $detail])
    @endif
    <flux:modal wire:model="captureOpen" name="capture-task" class="w-full max-w-2xl">
        <form wire:submit="capture" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Add task') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Choose only the context this task needs.') }}</flux:text>
            </div>
            @include('partials.task-form-fields', ['autofocus' => true, 'titleModel' => 'newTitle', 'bodyModel' => 'newBody', 'areaModel' => 'captureArea', 'groupModel' => 'captureGroup', 'newGroupModel' => 'captureNewGroup', 'parentSearchModel' => 'captureParentSearch', 'parentModel' => 'captureParent', 'toggleLabelMethod' => 'toggleCaptureLabel', 'selectedLabelsForForm' => $captureLabels, 'labelOptionsForForm' => $captureLabelOptions, 'groupsForForm' => $captureGroups, 'priorityModel' => 'capturePriority', 'prioritiesForForm' => $capturePriorities, 'parentsForForm' => $captureParents, 'newLabelModel' => 'captureNewLabel', 'groupValueForForm' => $captureGroup])
            @error('newTitle')<flux:text class="text-amber-700 dark:text-amber-400">{{ $message }}</flux:text>@enderror
            @if ($captureError)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ $captureError }}</p>@endif
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" wire:loading.attr="disabled" wire:target="capture"><span wire:loading.remove wire:target="capture">{{ __('Create task') }}</span><span wire:loading wire:target="capture">{{ __('Saving…') }}</span></flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="projectSettingsOpen" name="project-settings" class="w-full max-w-lg">
        <form wire:submit="saveProjectSettings" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Project settings') }}</flux:heading>
                <flux:text class="mt-1">{{ __('The name is saved in GitHub. The color is used by Todo to make this area easier to scan.') }}</flux:text>
            </div>
            <flux:input wire:model="projectSettingsTitle" label="{{ __('Project name') }}" autocomplete="off" />
            <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-800">
                <div class="flex items-end gap-3" x-data="{ color: @entangle('projectSettingsColor') }">
                    <label class="relative block size-10 shrink-0 cursor-pointer overflow-hidden rounded-xl border border-slate-300 dark:border-slate-600" :style="{ backgroundColor: '#' + (color || '0f766e') }">
                        <input type="color" class="absolute inset-0 size-full cursor-pointer opacity-0" :value="'#' + (color || '0f766e')" @input="color = $event.target.value.replace('#', '')" aria-label="{{ __('Pick a color') }}">
                    </label>
                    <div class="min-w-0 flex-1"><flux:input wire:model="projectSettingsColor" label="{{ __('Color') }}" prefix="#" maxlength="6" autocomplete="off" /><flux:text class="mt-1">{{ __('Six hexadecimal characters, such as 0f766e.') }}</flux:text></div>
                </div>
            </div>
            @error('projectSettingsTitle')<flux:text class="text-amber-700 dark:text-amber-400">{{ $message }}</flux:text>@enderror
            @error('projectSettingsColor')<flux:text class="text-amber-700 dark:text-amber-400">{{ __('Use a six-character hexadecimal color.') }}</flux:text>@enderror
            @if ($projectSettingsError)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ $projectSettingsError }}</p>@endif
            <div class="flex justify-end gap-2"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close><flux:button type="submit" wire:loading.attr="disabled" wire:target="saveProjectSettings"><span wire:loading.remove wire:target="saveProjectSettings">{{ __('Save project') }}</span><span wire:loading wire:target="saveProjectSettings">{{ __('Saving…') }}</span></flux:button></div>
        </form>
    </flux:modal>
    <flux:modal wire:model="deleteConfirmOpen" name="delete-task" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Delete task') }}</flux:heading>
                @if ($deletingIssueChildrenCount > 0)
                    <flux:text class="mt-1">{{ trans_choice('This task has :count sub-task. What should happen to it?|This task has :count sub-tasks. What should happen to them?', $deletingIssueChildrenCount, ['count' => $deletingIssueChildrenCount]) }}</flux:text>
                @else
                    <flux:text class="mt-1">{{ __('This deletes the task on GitHub. You can undo it right after, as long as it has not synced yet.') }}</flux:text>
                @endif
            </div>
            @if ($deleteError)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ $deleteError }}</p>@endif
            <div class="flex flex-wrap justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                @if ($deletingIssueChildrenCount > 0)
                    <flux:button type="button" wire:click="confirmDelete(false)" variant="ghost">{{ __('Move them up a level') }}</flux:button>
                    <flux:button type="button" wire:click="confirmDelete(true)" variant="danger">{{ __('Delete them too') }}</flux:button>
                @else
                    <flux:button type="button" wire:click="confirmDelete(false)" variant="danger">{{ __('Delete task') }}</flux:button>
                @endif
            </div>
        </div>
    </flux:modal>
</div>
