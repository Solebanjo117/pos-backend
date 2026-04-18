<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns changelog payload for authenticated users', function () {
    $user = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/changelog');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['version', 'date', 'title', 'category', 'changes'],
            ],
            'message',
        ]);

    $releases = $response->json('data');
    expect($releases)->not->toBeEmpty();

    foreach ($releases as $release) {
        expect($release['version'])->not->toBe('');
        expect($release['date'])->not->toBe('');
        expect($release['title'])->not->toBe('');
        expect($release['category'])->not->toBe('');
        expect($release['changes'])->toBeArray();
        expect($release['changes'])->not->toBeEmpty();
    }
});

it('returns releases sorted by date descending', function () {
    $user = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
    ]);

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/changelog');
    $response->assertOk();

    $releases = $response->json('data');
    $timestamps = array_map(fn ($release) => strtotime($release['date']) ?: 0, $releases);

    for ($i = 0; $i < count($timestamps) - 1; $i++) {
        expect($timestamps[$i])->toBeGreaterThanOrEqual($timestamps[$i + 1]);
    }
});

it('blocks changelog endpoint for unauthenticated requests', function () {
    $response = $this->getJson('/api/v1/changelog');

    expect(in_array($response->status(), [401, 302], true))->toBeTrue();
});
