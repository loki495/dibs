<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\DiscardWorkspaceArchivePreview;
use App\Actions\ExportWorkspaceArchive;
use App\Actions\ReadWorkspaceArchivePreview;
use App\Actions\RestoreWorkspaceArchive;
use App\Actions\StageWorkspaceArchive;
use App\Support\WorkspaceArchive;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkspaceTransferController
{
    public function index(Request $request): View
    {
        return view('pages.data-transfer', ['preview' => $request->session()->get('workspace-transfer'), 'empty' => WorkspaceArchive::isEmpty()]);
    }

    public function export(ExportWorkspaceArchive $export): StreamedResponse
    {
        $json = json_encode($export->handle(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return response()->streamDownload(function () use ($json): void {
            echo $json;
        }, 'dibs-workspace-'.now()->format('Y-m-d-His').'.json', ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(Request $request, StageWorkspaceArchive $stage, DiscardWorkspaceArchivePreview $discard): RedirectResponse
    {
        $discard->handle($request->session()->pull('workspace-transfer'), (int) $request->user()->id);
        $request->validate(['archive' => ['required', 'file', 'max:'.config('workspace-transfer.max_file_kib')]]);
        $file = $request->file('archive');
        assert($file instanceof UploadedFile);
        $preview = $stage->handle($file->getContent(), (int) $request->user()->id);
        $request->session()->put('workspace-transfer', $preview);

        return redirect()->route('data-transfer');
    }

    public function import(Request $request, ReadWorkspaceArchivePreview $read, RestoreWorkspaceArchive $restore, DiscardWorkspaceArchivePreview $discard): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']]);
        $preview = $request->session()->get('workspace-transfer');
        $json = $read->handle($preview, (int) $request->user()->id);
        $restore->handle($json, (int) $request->user()->id);
        $request->session()->forget('workspace-transfer');
        $discard->handle($preview, (int) $request->user()->id);

        return redirect()->route('data-transfer')->with('transfer-message', __('Workspace imported. No existing records were overwritten.'));
    }

    public function cancel(Request $request, DiscardWorkspaceArchivePreview $discard): RedirectResponse
    {
        $discard->handle($request->session()->pull('workspace-transfer'), (int) $request->user()->id);

        return redirect()->route('data-transfer');
    }
}
