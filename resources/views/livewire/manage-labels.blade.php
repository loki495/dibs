<?php

declare(strict_types=1);

use App\Actions\CreateLabel;
use App\Actions\DeleteLabel;
use App\Actions\RenameLabel;
use App\Exceptions\TodoValidationException;
use App\Models\Label;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $manageLabelsOpen = false;

    public int $managingLabelId = 0;

    public string $managingLabelName = '';

    public ?string $manageLabelsError = null;

    public string $newManageLabelName = '';

    #[On('open-manage-labels')]
    public function openManageLabels(): void
    {
        $this->reset('manageLabelsError', 'managingLabelId', 'managingLabelName', 'newManageLabelName');
        $this->manageLabelsOpen = true;
    }

    public function createManageLabel(): void
    {
        $this->reset('manageLabelsError');
        try {
            app(CreateLabel::class)->handle($this->newManageLabelName);
        } catch (TodoValidationException $exception) {
            $this->manageLabelsError = $exception->getMessage();

            return;
        }
        $this->reset('newManageLabelName', 'manageLabelsError');
        $this->dispatch('labels-changed');
    }

    public function beginRenameLabel(int $id): void
    {
        $label = Label::query()->where('is_available', true)->find($id);
        if (! $label instanceof Label) {
            return;
        }
        $this->reset('manageLabelsError');
        $this->managingLabelId = $id;
        $this->managingLabelName = $label->name;
    }

    public function cancelRenameLabel(): void
    {
        $this->reset('managingLabelId', 'managingLabelName');
    }

    public function saveLabelRename(): void
    {
        $label = Label::query()->where('is_available', true)->find($this->managingLabelId);
        if (! $label instanceof Label) {
            $this->reset('managingLabelId', 'managingLabelName');

            return;
        }
        try {
            app(RenameLabel::class)->handle($label, $this->managingLabelName);
        } catch (TodoValidationException $exception) {
            $this->manageLabelsError = $exception->getMessage();

            return;
        }
        $this->reset('managingLabelId', 'managingLabelName', 'manageLabelsError');
        $this->dispatch('labels-changed');
    }

    public function deleteLabelOption(int $id): void
    {
        $label = Label::query()->where('is_available', true)->find($id);
        if (! $label instanceof Label) {
            return;
        }
        $name = $label->name;
        app(DeleteLabel::class)->handle($label);
        $this->dispatch('label-deleted', id: $id, name: $name);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return ['labels' => $this->manageLabelsOpen ? Label::query()->where('is_available', true)->orderBy('name')->get(['id', 'name']) : collect()];
    }
}; ?>

<div x-effect="document.documentElement.classList.toggle('overflow-hidden', $wire.manageLabelsOpen)">
    <flux:modal wire:model="manageLabelsOpen" name="manage-labels" scroll="body" class="w-full max-w-md">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Manage labels') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Rename or delete a label. New labels are lowercased by default.') }}</flux:text>
            </div>
            <form wire:submit="createManageLabel" class="flex items-center gap-2">
                <flux:input wire:model="newManageLabelName" placeholder="{{ __('New label name') }}" class="flex-1" />
                <flux:button type="submit" size="sm">{{ __('Add') }}</flux:button>
            </form>
            <div class="space-y-2">
                @forelse ($labels as $labelOption)
                    <div class="flex items-center gap-2" wire:key="manage-label-{{ $labelOption->id }}">
                        @if ($managingLabelId === $labelOption->id)
                            <flux:input wire:model="managingLabelName" class="flex-1" autofocus wire:keydown.enter.prevent="saveLabelRename" />
                            <button type="button" wire:click="saveLabelRename" class="flex size-8 shrink-0 items-center justify-center rounded-lg text-teal-700 hover:bg-teal-50 dark:text-teal-400 dark:hover:bg-teal-950" aria-label="{{ __('Save') }}"><flux:icon.check class="size-4" /></button>
                            <button type="button" wire:click="cancelRenameLabel" class="flex size-8 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="{{ __('Cancel') }}"><flux:icon.x-mark class="size-4" /></button>
                        @else
                            <span class="min-w-0 flex-1 truncate text-sm">{{ $labelOption->name }}</span>
                            <button type="button" wire:click="beginRenameLabel({{ $labelOption->id }})" class="flex size-8 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="{{ __('Rename :name', ['name' => $labelOption->name]) }}"><flux:icon.pencil-square class="size-4" /></button>
                            <button type="button" wire:click="deleteLabelOption({{ $labelOption->id }})" wire:confirm="{{ __('Delete the \":name\" label? It will be removed from every task.', ['name' => $labelOption->name]) }}" class="flex size-8 shrink-0 items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40" aria-label="{{ __('Delete :name', ['name' => $labelOption->name]) }}"><flux:icon.trash class="size-4" /></button>
                        @endif
                    </div>
                @empty
                    <p class="text-xs text-slate-500">{{ __('No labels yet.') }}</p>
                @endforelse
            </div>
            @if ($manageLabelsError)<p role="alert" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">{{ $manageLabelsError }}</p>@endif
            <div class="flex justify-end"><flux:modal.close><flux:button type="button" variant="ghost">{{ __('Close') }}</flux:button></flux:modal.close></div>
        </div>
    </flux:modal>
</div>
