<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\TodoValidationException;
use App\Models\ProjectFieldOption;
use Illuminate\Support\Facades\DB;

class RenameGroupOption
{
    public function handle(ProjectFieldOption $option, string $name): ProjectFieldOption
    {
        $name = trim($name);
        if ($name === '') {
            throw new TodoValidationException('A Group needs a name.');
        }
        $duplicate = ProjectFieldOption::query()->where('project_field_id', $option->project_field_id)
            ->whereKeyNot($option->id)->whereRaw('LOWER(name) = LOWER(?)', [$name])->exists();
        if ($duplicate) {
            throw new TodoValidationException('Another Group in this area already has this name.');
        }
        if ($name === $option->name) {
            return $option;
        }

        return DB::transaction(function () use ($option, $name): ProjectFieldOption {
            $option->update(['name' => $name]);
            app(EnqueueGitHubPush::class)->handle('rename_group_option', 'project_field_option', $option->id, ['name' => $name], 'group_option:rename:'.$option->id.':'.now()->timestamp);

            return $option->refresh();
        });
    }
}
