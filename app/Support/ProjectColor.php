<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\GitHubProject;

class ProjectColor
{
    /** @var list<string> */
    private const array PALETTE = ['0f766e', '2563eb', '7c3aed', 'be123c', 'c2410c', '4d7c0f'];

    public function for(GitHubProject $project): string
    {
        $color = strtolower((string) $project->color);

        return ctype_xdigit($color) && strlen($color) === 6
            ? '#'.$color
            : '#'.self::default($project->github_node_id);
    }

    public static function default(string $identity): string
    {
        return self::PALETTE[abs(crc32($identity)) % count(self::PALETTE)];
    }
}
