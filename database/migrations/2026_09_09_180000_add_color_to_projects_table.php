<?php

declare(strict_types=1);

use App\Support\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('color', 6)->nullable()->after('title');
        });

        DB::table('projects')->orderBy('id')->each(function (object $project): void {
            DB::table('projects')->where('id', $project->id)->update(['color' => ProjectColor::default($project->github_node_id)]);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('color');
        });
    }
};
