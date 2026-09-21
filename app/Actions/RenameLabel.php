<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\Label;
use App\Support\LabelName;
use Illuminate\Support\Facades\DB;

class RenameLabel
{
    public function handle(Label $label, string $name): Label
    {
        $name = LabelName::normalize($name);
        if ($name === '') {
            throw new TodoValidationException('A label needs a name.');
        }
        $duplicate = Label::query()->where('repository_id', $label->repository_id)
            ->whereKeyNot($label->id)->whereRaw('LOWER(name) = LOWER(?)', [$name])->exists();
        if ($duplicate) {
            throw new TodoValidationException('Another label already has this name.');
        }
        if ($name === $label->name) {
            return $label;
        }

        return DB::transaction(function () use ($label, $name): Label {
            $label->update(['name' => $name]);
            app(EnqueueGitHubPush::class)->handle('rename_label', 'label', $label->id, ['name' => $name], 'label:rename:'.$label->id.':'.now()->timestamp);

            return $label->refresh();
        });
    }
}
