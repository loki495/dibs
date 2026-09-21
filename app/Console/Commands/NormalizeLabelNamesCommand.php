<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RenameLabel;
use App\Exceptions\TodoValidationException;
use App\Models\Label;
use App\Support\LabelName;
use Illuminate\Console\Command;

/** Renames labels stored with capitals or odd spacing through the normal push queue; lists them only unless --apply is given. */
class NormalizeLabelNamesCommand extends Command
{
    protected $signature = 'labels:normalize {--apply : Rename the labels instead of only listing them}';

    protected $description = 'List (or with --apply, rename) labels whose stored name is not lowercase with single spaces';

    public function handle(RenameLabel $rename): int
    {
        $labels = Label::query()->where('is_available', true)->orderBy('name')->get()
            ->filter(fn (Label $label): bool => LabelName::normalize($label->name) !== $label->name);

        if ($labels->isEmpty()) {
            $this->info('All label names are already lowercase.');

            return self::SUCCESS;
        }

        foreach ($labels as $label) {
            $this->line($label->name.' => '.LabelName::normalize($label->name));
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing changed. Re-run with --apply to rename these through the push queue.');

            return self::SUCCESS;
        }

        $renamed = 0;
        $failed = false;

        foreach ($labels as $label) {
            try {
                $rename->handle($label, $label->name);
                $renamed++;
            } catch (TodoValidationException $exception) {
                $this->error("Skipped {$label->name}: {$exception->getMessage()}");
                $failed = true;
            }
        }

        $this->info("Renamed {$renamed} label(s). They reach GitHub through the push queue.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
