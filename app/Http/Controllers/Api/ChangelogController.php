<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChangelogEntryRequest;
use App\Models\ChangelogEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChangelogController extends Controller
{
    /**
     * GET /api/v1/changelog
     * Return authenticated changelog feed.
     */
    public function index(Request $request): JsonResponse
    {
        $configuredReleases = collect(config('changelog.releases', []))
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
                    'source' => 'config',
                ];
            })
            ->filter(fn (array $release) => $release['version'] !== ''
                && $release['date'] !== ''
                && $release['title'] !== ''
                && ! empty($release['changes'])
            )
            ->values();

        $publishedEntries = ChangelogEntry::query()
            ->with('creator:id,name')
            ->published()
            ->latest('published_at')
            ->limit(50)
            ->get()
            ->map(fn (ChangelogEntry $entry): array => $this->entryToRelease($entry));

        $releases = $configuredReleases
            ->concat($publishedEntries)
            ->sortByDesc(fn (array $release) => strtotime($release['date']) ?: 0)
            ->values();

        $payload = [
            'success' => true,
            'data' => $releases,
            'message' => 'Changelog loaded successfully.',
        ];

        if ($request->user()?->hasRole(['admin', 'manager'])) {
            $payload['drafts'] = ChangelogEntry::query()
                ->with('creator:id,name')
                ->draft()
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn (ChangelogEntry $entry): array => $this->entryToDraft($entry))
                ->values();
        }

        return response()->json($payload);
    }

    public function store(StoreChangelogEntryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $publish = (bool) ($validated['publish'] ?? false);

        $entry = ChangelogEntry::query()->create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'category' => $validated['category'],
            'version' => $validated['version'] ?? null,
            'status' => $publish ? ChangelogEntry::STATUS_PUBLISHED : ChangelogEntry::STATUS_DRAFT,
            'published_at' => $publish ? now() : null,
            'created_by' => $request->user()?->id,
        ]);

        activity('changelog')
            ->causedBy($request->user())
            ->performedOn($entry)
            ->event($publish ? 'published' : 'created')
            ->withProperties([
                'status' => $entry->status,
                'category' => $entry->category,
            ])
            ->log($publish ? 'Nota de changelog publicada' : 'Nota de changelog creada');

        return response()->json([
            'success' => true,
            'data' => $publish ? $this->entryToRelease($entry) : $this->entryToDraft($entry),
            'message' => $publish
                ? 'Nota publicada en el changelog.'
                : 'Nota guardada como borrador.',
        ], 201);
    }

    public function publish(Request $request, ChangelogEntry $changelogEntry): JsonResponse
    {
        $changelogEntry->publish();

        activity('changelog')
            ->causedBy($request->user())
            ->performedOn($changelogEntry)
            ->event('published')
            ->withProperties([
                'status' => $changelogEntry->status,
                'category' => $changelogEntry->category,
            ])
            ->log('Nota de changelog publicada');

        return response()->json([
            'success' => true,
            'data' => $this->entryToRelease($changelogEntry),
            'message' => 'Nota publicada en el changelog.',
        ]);
    }

    private function entryToRelease(ChangelogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'source' => 'entry',
            'version' => $entry->version ?: 'nota-'.$entry->id,
            'date' => ($entry->published_at ?? $entry->created_at)->toDateString(),
            'title' => $entry->title,
            'category' => $entry->category,
            'changes' => [$entry->body],
            'status' => $entry->status,
            'created_by' => $entry->creator?->name,
        ];
    }

    private function entryToDraft(ChangelogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'body' => $entry->body,
            'category' => $entry->category,
            'version' => $entry->version,
            'status' => $entry->status,
            'created_at' => $entry->created_at?->toDateTimeString(),
            'created_by' => $entry->creator?->name,
        ];
    }
}
