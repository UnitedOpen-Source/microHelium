<?php

namespace Database\Factories;

use Helium\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = User::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fullname' => $this->faker->name,
            'username' => $this->faker->unique()->userName,
            'email' => $this->faker->unique()->safeEmail,
            'password' => Hash::make('password'),
            'user_type' => 'team',
            'is_enabled' => true,
        ];
    }

    /**
     * Issue #47 -- a managed account as created by
     * Api\Frontend\ManagedAccountsController::store(): no usable password,
     * private by default, tied to the admin who created it.
     */
    public function managed(?string $birthdate = null, ?int $managedBy = null): static
    {
        return $this->state(fn () => [
            'birthdate' => $birthdate,
            'managed_by' => $managedBy,
            'managed_at' => now(),
            'profile_visibility' => 'private',
            'is_enabled' => false,
        ]);
    }
}
