<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReceiveGitHubWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GitHubWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveGitHubWebhook $receive): Response
    {
        $receive->handle($request->getContent(), (string) $request->header('X-Hub-Signature-256'),
            (string) $request->header('X-GitHub-Delivery'), (string) $request->header('X-GitHub-Event'));

        return response('', 202);
    }
}
