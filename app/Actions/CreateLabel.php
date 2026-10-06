<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Label;
use App\Support\LabelName;
use Illuminate\Support\Facades\DB;

/**
 * Creates a bare label, not attached to any task — the "manage labels" popup's own create field.
 * Unlike ResolveLabels (which reuses an existing same-named label when resolving an issue's labels),
 * a duplicate name here is a validation error: the whole point of this action is a brand new label.
 */
class CreateLabel
{
    public function __construct(private readonly ResolveLabels $resolveLabels, private readonly ResolveActiveRepository $activeRepository) {}

    public function handle(string $name): Label
    {
        $normalized = LabelName::normalize($name);
        if ($normalized === '') {
            throw new TodoValidationException('A label needs a name.');
        }

        $repository = $this->activeRepository->handle();

        $duplicate = Label::query()->where('repository_id', $repository->id)->whereRaw('LOWER(name) = ?', [$normalized])->exists();
        if ($duplicate) {
            throw new TodoValidationException('A label with this name already exists.');
        }

        return DB::transaction(fn (): Label => $this->resolveLabels->handle($repository, collect(), [$normalized])->sole());
    }
}
