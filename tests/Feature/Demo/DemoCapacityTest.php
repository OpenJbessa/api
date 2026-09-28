<?php

use App\Models\User;

it('answers 503 demo_capacity beyond DEMO_MAX_ACTIVE, with the delay until a slot frees up', function (): void {
    config()->set('demo.max_active_accounts', 2);
    User::factory()->demo()->create(['expires_at' => now()->addMinutes(10)]);
    User::factory()->demo()->create(['expires_at' => now()->addMinutes(20)]);

    $response = $this->withHeaders(fromFrontend())
        ->postJson('/auth/demo')
        ->assertServiceUnavailable()
        ->assertExactJson([
            'code' => 'demo_capacity',
            'message' => 'Toutes les places de démonstration sont occupées. Réessayez dans quelques minutes.',
        ]);

    // Le plus ancien compte actif expire dans dix minutes.
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(590)->toBeLessThanOrEqual(600)
        ->and(User::query()->count())->toBe(2);
});

it('counts neither expired nor permanent accounts against the capacity', function (): void {
    config()->set('demo.max_active_accounts', 1);
    User::factory()->expired()->count(3)->create();
    User::factory()->create();

    $this->withHeaders(fromFrontend())->postJson('/auth/demo')->assertCreated();
});
