<?php

namespace Tests\Unit\Services;

use App\Exceptions\CircularReferenceException;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutServiceCircularReferenceTest extends TestCase
{
    use RefreshDatabase;

    private LayoutService $layoutService;
    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layoutService = app(LayoutService::class);

        // 템플릿 생성 - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $this->template = Template::create([
            'identifier' => 'test-template-'.uniqid(),
            'vendor' => 'test',
            'name' => 'Test Template',
            'version' => '1.0.0',
            'type' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * A→B→A 형태의 직접 순환 참조 테스트
     */
    public function test_detects_direct_circular_reference(): void
    {
        // Arrange: A→B→A 순환 참조 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'layout-a',
            'content' => [
                'extends' => 'layout-b',
                'meta' => ['title' => 'Layout A'],
            ],
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'layout-b',
            'content' => [
                'extends' => 'layout-a',
                'meta' => ['title' => 'Layout B'],
            ],
        ]);

        // Act & Assert
        $this->expectException(CircularReferenceException::class);
        $this->expectExceptionMessage('layout-a → layout-b');

        $this->layoutService->loadAndMergeLayout($this->template->id, 'layout-a');
    }

    /**
     * A→B→C→A 형태의 간접 순환 참조 테스트
     */
    public function test_detects_indirect_circular_reference(): void
    {
        // Arrange: A→B→C→A 순환 참조 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'layout-a',
            'content' => [
                'extends' => 'layout-b',
                'meta' => ['title' => 'Layout A'],
            ],
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'layout-b',
            'content' => [
                'extends' => 'layout-c',
                'meta' => ['title' => 'Layout B'],
            ],
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'layout-c',
            'content' => [
                'extends' => 'layout-a',
                'meta' => ['title' => 'Layout C'],
            ],
        ]);

        // Act & Assert
        $this->expectException(CircularReferenceException::class);
        $this->expectExceptionMessage('layout-a → layout-b → layout-c');

        $this->layoutService->loadAndMergeLayout($this->template->id, 'layout-a');
    }

    /**
     * 10단계 이상 깊이 테스트
     */
    public function test_prevents_max_depth_exceeded(): void
    {
        // Arrange: 11단계 상속 구조 생성 (최대 10단계 초과)
        for ($i = 0; $i <= 10; $i++) {
            $layoutData = ['meta' => ['title' => "Layout {$i}"]];

            if ($i < 10) {
                $layoutData['extends'] = 'layout-' . ($i + 1);
            }

            TemplateLayout::create([
                'template_id' => $this->template->id,
                'name' => 'layout-' . $i,
                'content' => $layoutData,
            ]);
        }

        // Act & Assert
        $this->expectException(\Exception::class);
        // 다국어 메시지 지원: 한국어 또는 영어
        $this->expectExceptionMessageMatches('/(최대 허용 깊이|maximum allowed depth)/');

        $this->layoutService->loadAndMergeLayout($this->template->id, 'layout-0');
    }

    /**
     * 정상적인 상속 구조 테스트 (순환 참조 없음)
     *
     * 현재 구현: 슬롯 래퍼의 children에 슬롯 내용이 삽입됨
     */
    public function test_loads_valid_inheritance_structure(): void
    {
        // Arrange: 정상적인 상속 구조 생성
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'base',
            'content' => [
                'meta' => ['title' => 'Base Layout'],
                'components' => [
                    ['type' => 'Header', 'slot' => 'header'],
                ],
            ],
        ]);

        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'child',
            'content' => [
                'extends' => 'base',
                'meta' => ['title' => 'Child Layout'],
                'slots' => [
                    'header' => [['type' => 'CustomHeader']],
                ],
            ],
        ]);

        // Act
        $result = $this->layoutService->loadAndMergeLayout($this->template->id, 'child');

        // Assert
        $this->assertEquals('Child Layout', $result['meta']['title']);
        // 슬롯 래퍼(Header)의 children에 CustomHeader가 삽입됨
        $this->assertEquals('Header', $result['components'][0]['type']);
        $this->assertEquals('CustomHeader', $result['components'][0]['children'][0]['type']);
    }

    /**
     * extends가 없는 단일 레이아웃 테스트
     */
    public function test_loads_single_layout_without_extends(): void
    {
        // Arrange
        TemplateLayout::create([
            'template_id' => $this->template->id,
            'name' => 'standalone',
            'content' => [
                'meta' => ['title' => 'Standalone Layout'],
                'components' => [
                    ['type' => 'Header'],
                ],
            ],
        ]);

        // Act
        $result = $this->layoutService->loadAndMergeLayout($this->template->id, 'standalone');

        // Assert
        $this->assertEquals('Standalone Layout', $result['meta']['title']);
        $this->assertCount(1, $result['components']);
    }
}
