<?php

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets managers create quick changelog drafts', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->actingAs($manager);

    $response = $this->postJson('/api/v1/changelog/entries', [
        'title' => 'Layout operativo',
        'body' => 'Se reorganizo el dashboard para lectura rapida.',
        'category' => 'operations',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', ChangelogEntry::STATUS_DRAFT)
        ->assertJsonPath('message', 'Nota guardada como borrador.');

    $this->assertDatabaseHas('changelog_entries', [
        'title' => 'Layout operativo',
        'status' => ChangelogEntry::STATUS_DRAFT,
        'created_by' => $manager->id,
    ]);
});

it('lets managers publish quick changelog entries immediately', function () {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->actingAs($manager);

    $response = $this->postJson('/api/v1/changelog/entries', [
        'title' => 'Release notes visibles',
        'body' => 'Se agrego el bloque de notas de release al dashboard.',
        'category' => 'operations',
        'publish' => true,
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.status', ChangelogEntry::STATUS_PUBLISHED)
        ->assertJsonPath('data.title', 'Release notes visibles');

    $this->getJson('/api/v1/changelog')
        ->assertOk()
        ->assertJsonFragment([
            'title' => 'Release notes visibles',
            'source' => 'entry',
        ]);
});

it('blocks non managers from creating changelog entries', function () {
    $seller = User::factory()->create([
        'role' => 'seller',
        'is_active' => true,
    ]);

    $this->actingAs($seller);

    $this->postJson('/api/v1/changelog/entries', [
        'title' => 'No autorizado',
        'body' => 'Este usuario no debe crear notas.',
        'category' => 'operations',
    ])->assertForbidden();
});
