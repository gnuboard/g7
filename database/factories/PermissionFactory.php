<?php

namespace Database\Factories;

use App\Enums\ExtensionOwnerType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * 다국어 필드 생성
     */
    protected function generateTranslatableField(callable $generator): array
    {
        $locales = config('app.translatable_locales', ['ko', 'en']);
        $data = [];

        foreach ($locales as $locale) {
            $data[$locale] = $generator();
        }

        return $data;
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'identifier' => $this->faker->unique()->slug(2),
            'name' => $this->generateTranslatableField(fn () => $this->faker->words(2, true)),
            'description' => $this->generateTranslatableField(fn () => $this->faker->sentence()),
            'extension_type' => null,
            'extension_identifier' => null,
        ];
    }

    /**
     * 시스템(코어) 권한 상태
     */
    public function system(): static
    {
        return $this->core();
    }

    /**
     * 코어 권한 상태
     */
    public function core(): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);
    }

    /**
     * 사용자 관리 권한 상태
     */
    public function userManagement(): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);
    }

    /**
     * 모듈 권한 상태
     *
     * @param  string  $identifier  모듈 식별자
     */
    public function forModule(string $identifier): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => $identifier,
        ]);
    }

    /**
     * 플러그인 권한 상태
     *
     * @param  string  $identifier  플러그인 식별자
     */
    public function forPlugin(string $identifier): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => $identifier,
        ]);
    }
}
