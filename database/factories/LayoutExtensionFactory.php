<?php

namespace Database\Factories;

use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Models\LayoutExtension;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LayoutExtension>
 */
class LayoutExtensionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<LayoutExtension>
     */
    protected $model = LayoutExtension::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vendor = fake()->randomElement(['sirsoft', 'acme', 'demo']);

        return [
            'template_id' => Template::factory(),
            'extension_type' => fake()->randomElement(LayoutExtensionType::cases()),
            'target_name' => fake()->randomElement(['sidebar-top', 'sidebar-bottom', 'header-actions', 'admin/dashboard', 'admin/settings']),
            // 조각 식별자 — 실제 설치는 소스 파일 이름을 쓰고, 마이그레이션 백필은 target_name 을 쓴다.
            // 같은 대상에 여러 행을 만드는 테스트가 있으므로 고유 키가 충돌하지 않게 접미어를 붙인다.
            'fragment_key' => fn (array $attributes): string => $attributes['target_name'].'-'.fake()->unique()->numerify('####'),
            'source_type' => fake()->randomElement(LayoutSourceType::cases()),
            'source_identifier' => $vendor.'-'.fake()->slug(1),
            'content' => $this->generateExtensionData(),
            'priority' => fake()->numberBetween(1, 100),
            'is_active' => true,
            'override_target' => null,
        ];
    }

    /**
     * 확장 데이터 생성
     *
     * @return array
     */
    private function generateExtensionData(): array
    {
        return [
            'component' => [
                'type' => 'basic',
                'name' => fake()->randomElement(['Button', 'Div', 'Card', 'Alert']),
                'props' => [
                    'className' => 'test-class',
                ],
            ],
        ];
    }

    /**
     * Extension Point 타입 상태
     */
    public function extensionPoint(): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => LayoutExtensionType::ExtensionPoint,
        ]);
    }

    /**
     * Overlay 타입 상태
     */
    public function overlay(): static
    {
        return $this->state(fn (array $attributes) => [
            'extension_type' => LayoutExtensionType::Overlay,
            'content' => [
                'target_component_id' => 'component-'.fake()->uuid(),
                'position' => fake()->randomElement(['append_child', 'prepend', 'replace']),
                'component' => [
                    'type' => 'basic',
                    'name' => 'Div',
                ],
            ],
        ]);
    }

    /**
     * 모듈 출처 상태
     */
    public function fromModule(?string $identifier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => LayoutSourceType::Module,
            'source_identifier' => $identifier ?? 'sample-module',
        ]);
    }

    /**
     * 플러그인 출처 상태
     */
    public function fromPlugin(?string $identifier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => LayoutSourceType::Plugin,
            'source_identifier' => $identifier ?? 'sample-plugin',
        ]);
    }

    /**
     * 템플릿 출처 상태 (오버라이드용)
     */
    public function fromTemplate(?string $identifier = null): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => LayoutSourceType::Template,
            'source_identifier' => $identifier ?? 'sample-template',
        ]);
    }

    /**
     * 오버라이드 타겟 설정
     */
    public function overriding(string $targetIdentifier): static
    {
        return $this->state(fn (array $attributes) => [
            'override_target' => $targetIdentifier,
        ]);
    }

    /**
     * 비활성 상태
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * 우선순위 설정
     */
    public function withPriority(int $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }

    /**
     * 특정 타겟 설정
     */
    public function targeting(string $targetName): static
    {
        return $this->state(fn (array $attributes) => [
            'target_name' => $targetName,
        ]);
    }
}
