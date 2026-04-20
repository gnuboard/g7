<?php

namespace Database\Factories;

use App\Models\NotificationDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NotificationDefinition 팩토리
 *
 * @extends Factory<NotificationDefinition>
 */
class NotificationDefinitionFactory extends Factory
{
    protected $model = NotificationDefinition::class;

    /**
     * 기본 상태를 정의합니다.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->unique()->slug(2),
            'hook_prefix' => 'core',
            'extension_type' => 'core',
            'extension_identifier' => '',
            'name' => ['ko' => $this->faker->words(2, true), 'en' => $this->faker->words(2, true)],
            'description' => ['ko' => $this->faker->sentence(), 'en' => $this->faker->sentence()],
            'variables' => [],
            'channels' => ['database'],
            'hooks' => [],
            'is_active' => true,
            'is_default' => false,
        ];
    }
}
