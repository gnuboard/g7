<?php

namespace Database\Factories;

use App\Enums\LayoutSourceType;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TemplateLayout>
 */
class TemplateLayoutFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 유니크 제약조건 (template_id, name, source_type)을 위해 고유한 name 생성
        $layoutName = fake()->unique()->slug(3);

        return [
            'template_id' => Template::factory(),
            'name' => $layoutName,
            'content' => [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'endpoint' => '/api/'.fake()->word(),
                'components' => [],
                'data_sources' => [],
                'metadata' => [],
            ],
            'extends' => null,
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => null,
        ];
    }

    /**
     * 모듈 소스 타입으로 설정
     */
    public function fromModule(string $moduleIdentifier): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $moduleIdentifier,
        ]);
    }

    /**
     * 플러그인 소스 타입으로 설정
     */
    public function fromPlugin(string $pluginIdentifier): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => $pluginIdentifier,
        ]);
    }

    /**
     * 템플릿 오버라이드로 설정
     */
    public function asOverride(string $templateIdentifier): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $templateIdentifier,
        ]);
    }
}
