<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Builds (or rebuilds) the demo database template that ResolveDemoDatabase copies for every
 * new demo visitor -- see config('dibs.demo_db_template_path'). Never run on a schedule:
 * DemoSeeder's content isn't date-sensitive, so there's no staleness reason to automate this.
 * Run manually whenever the demo dataset itself should change.
 */
class BuildDemoTemplateCommand extends Command
{
    protected $signature = 'demo:build-template';

    protected $description = 'Build the demo database template (migrate + seed DemoSeeder)';

    public function handle(): int
    {
        $path = config('dibs.demo_db_template_path');

        if (! is_string($path) || $path === '') {
            $this->error('dibs.demo_db_template_path is not configured.');

            return self::FAILURE;
        }

        config(['database.connections.sqlite.database' => $path]);
        DB::purge('sqlite');

        $this->info("Building demo template at {$path}");

        Artisan::call('migrate:fresh', ['--force' => true], $this->output);
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true], $this->output);

        $this->info('Demo template built.');

        return self::SUCCESS;
    }
}
