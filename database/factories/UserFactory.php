<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Compte de démonstration vivant, tel que DemoAccountService le crée.
     */
    public function demo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email' => 'demo+'.Str::uuid().'@demo.invalid',
            'email_verified_at' => null,
            'password' => null,
            'remember_token' => null,
            'is_demo' => true,
            'expires_at' => now()->addMinutes((int) config('demo.ttl_minutes')),
        ]);
    }

    /**
     * Compte de démonstration expiré, pas encore purgé.
     */
    public function expired(): static
    {
        return $this->demo()->state(fn (array $attributes): array => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
