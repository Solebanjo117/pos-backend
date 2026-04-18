<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ChangelogController extends Controller
{
    /**
     * GET /api/v1/changelog
     * Return authenticated changelog feed.
     */
    public function index(): JsonResponse
    {
        $releases = collect(config('changelog.releases', []))
            ->filter(fn ($release) => is_array($release))
            ->map(function (array $release): array {
                $changes = collect($release['changes'] ?? [])
                    ->filter(fn ($item) => is_string($item) && trim($item) !== '')
                    ->values()
                    ->all();

                return [
                    'version' => (string) ($release['version'] ?? ''),
                    'date' => (string) ($release['date'] ?? ''),
                    'title' => (string) ($release['title'] ?? ''),
                    'category' => (string) ($release['category'] ?? 'general'),
                    'changes' => $changes,
                ];
            })
            ->filter(fn (array $release) =>
                $release['version'] !== ''
                && $release['date'] !== ''
                && $release['title'] !== ''
                && !empty($release['changes'])
            )
            ->sortByDesc(fn (array $release) => strtotime($release['date']) ?: 0)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $releases,
            'message' => 'Changelog loaded successfully.',
        ]);
    }
}
