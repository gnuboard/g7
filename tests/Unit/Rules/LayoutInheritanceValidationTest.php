<?php

namespace Tests\Unit\Rules;

use App\Models\Template;
use App\Models\TemplateLayout;
use App\Rules\ValidDataSourceMerge;
use App\Rules\ValidParentLayout;
use App\Rules\ValidSlotStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutInheritanceValidationTest extends TestCase
{
    use RefreshDatabase;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        // 한국어 로케일 설정
        app()->setLocale('ko');

        // 테스트용 템플릿 생성 - 고유 identifier 사용하여 트랜잭션 락 충돌 방지
        $this->template = Template::factory()->create([
            'identifier' => 'test-template-'.uniqid(),
            'type' => 'user',
            'status' => 'active',
        ]);
    }

    /**
     * ValidParentLayout: 존재하지 않는 부모 레이아웃 검증 실패
     */
    public function test_valid_parent_layout_fails_when_parent_not_found(): void
    {
        $rule = new ValidParentLayout;
        $rule->setData([
            'template_id' => $this->template->id,
            'layout_name' => 'child',
        ]);

        $failed = false;
        $message = '';

        $rule->validate('content.extends', 'non_existent_parent', function ($msg) use (&$failed, &$message) {
            $failed = true;
            $message = $msg;
        });

        $this->assertTrue($failed);
        $this->assertStringContainsString('non_existent_parent', $message);
    }

    /**
     * ValidParentLayout: 순환 참조 검증 실패
     */
    public function test_valid_parent_layout_fails_on_circular_reference(): void
    {
        // 부모 레이아웃 생성
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'parent',
            'content' => json_encode([
                'version' => '1.0.0',
                'layout_name' => 'parent',
                'extends' => 'child', // 순환 참조: parent → child
                'components' => [],
            ]),
        ]);

        // 자식 레이아웃에서 parent를 extends로 지정
        $rule = new ValidParentLayout;
        $rule->setData([
            'template_id' => $this->template->id,
            'layout_name' => 'child',
        ]);

        $failed = false;
        $message = '';

        $rule->validate('content.extends', 'parent', function ($msg) use (&$failed, &$message) {
            $failed = true;
            $message = $msg;
        });

        $this->assertTrue($failed);
        $this->assertStringContainsString('순환', $message);
    }

    /**
     * ValidParentLayout: 최대 상속 깊이 초과 검증 실패
     */
    public function test_valid_parent_layout_fails_on_max_depth_exceeded(): void
    {
        // 깊은 상속 체인 생성 (11단계)
        $previousName = null;
        for ($i = 1; $i <= 11; $i++) {
            $layoutName = "layout_{$i}";
            $content = [
                'version' => '1.0.0',
                'layout_name' => $layoutName,
                'components' => [],
            ];

            if ($previousName) {
                $content['extends'] = $previousName;
            }

            TemplateLayout::factory()->create([
                'template_id' => $this->template->id,
                'name' => $layoutName,
                'content' => json_encode($content),
            ]);

            $previousName = $layoutName;
        }

        // 새 레이아웃에서 layout_11을 extends로 지정 (12단계가 됨)
        $rule = new ValidParentLayout;
        $rule->setData([
            'template_id' => $this->template->id,
            'layout_name' => 'child',
        ]);

        $failed = false;
        $message = '';

        $rule->validate('content.extends', 'layout_11', function ($msg) use (&$failed, &$message) {
            $failed = true;
            $message = $msg;
        });

        $this->assertTrue($failed);
        $this->assertStringContainsString('깊이', $message);
    }

    /**
     * ValidParentLayout: 유효한 부모 레이아웃 검증 통과
     */
    public function test_valid_parent_layout_passes_with_valid_parent(): void
    {
        // 부모 레이아웃 생성
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'parent',
            'content' => json_encode([
                'version' => '1.0.0',
                'layout_name' => 'parent',
                'components' => [],
            ]),
        ]);

        $rule = new ValidParentLayout;
        $rule->setData([
            'template_id' => $this->template->id,
            'layout_name' => 'child',
        ]);

        $failed = false;

        $rule->validate('content.extends', 'parent', function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * ValidSlotStructure: 부모에 없는 슬롯 검증 실패
     */
    public function test_valid_slot_structure_fails_when_slot_not_in_parent(): void
    {
        // 부모 레이아웃 생성 (content 슬롯만 있음)
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'parent',
            'content' => json_encode([
                'version' => '1.0.0',
                'layout_name' => 'parent',
                'components' => [
                    [
                        'id' => 'slot_container',
                        'type' => 'layout',
                        'name' => 'Div',
                        'slot' => 'content',
                        'children' => [],
                    ],
                ],
            ]),
        ]);

        $rule = new ValidSlotStructure;
        $rule->setData([
            'template_id' => $this->template->id,
            'content' => [
                'extends' => 'parent',
            ],
        ]);

        $failed = false;
        $message = '';

        // sidebar 슬롯은 부모에 정의되지 않음
        $slots = [
            'content' => [],
            'sidebar' => [], // 존재하지 않는 슬롯
        ];

        $rule->validate('content.slots', $slots, function ($msg) use (&$failed, &$message) {
            $failed = true;
            $message = $msg;
        });

        $this->assertTrue($failed);
        $this->assertStringContainsString('sidebar', $message);
    }

    /**
     * ValidSlotStructure: 유효한 슬롯 구조 검증 통과
     */
    public function test_valid_slot_structure_passes_with_valid_slots(): void
    {
        // 부모 레이아웃 생성
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'parent',
            'content' => json_encode([
                'version' => '1.0.0',
                'layout_name' => 'parent',
                'components' => [
                    [
                        'id' => 'slot_content',
                        'type' => 'layout',
                        'name' => 'Div',
                        'slot' => 'content',
                        'children' => [],
                    ],
                    [
                        'id' => 'slot_sidebar',
                        'type' => 'layout',
                        'name' => 'Div',
                        'slot' => 'sidebar',
                        'children' => [],
                    ],
                ],
            ]),
        ]);

        $rule = new ValidSlotStructure;
        $rule->setData([
            'template_id' => $this->template->id,
            'content' => [
                'extends' => 'parent',
            ],
        ]);

        $failed = false;

        $slots = [
            'content' => [],
            'sidebar' => [],
        ];

        $rule->validate('content.slots', $slots, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }

    /**
     * ValidDataSourceMerge: 중복 ID 검증 실패 (현재 레이아웃 내)
     */
    public function test_valid_data_source_merge_fails_on_duplicate_id_in_current(): void
    {
        $rule = new ValidDataSourceMerge;
        $rule->setData([
            'template_id' => $this->template->id,
        ]);

        $failed = false;
        $message = '';

        $dataSources = [
            ['id' => 'users', 'type' => 'api', 'endpoint' => '/api/users'],
            ['id' => 'users', 'type' => 'api', 'endpoint' => '/api/admin/users'], // 중복 ID
        ];

        $rule->validate('content.data_sources', $dataSources, function ($msg) use (&$failed, &$message) {
            $failed = true;
            $message = $msg;
        });

        $this->assertTrue($failed);
        $this->assertStringContainsString('users', $message);
    }

    /**
     * ValidDataSourceMerge: 부모와 중복 ID 검증 실패
     */
    public function test_valid_data_source_merge_fails_on_duplicate_id_with_parent(): void
    {
        // 부모 레이아웃 생성 (stats data_source 포함)
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'parent',
            'content' => json_encode([
                'version' => '1.0.0',
                'layout_name' => 'parent',
                'data_sources' => [
                    ['id' => 'stats', 'type' => 'api', 'endpoint' => '/api/stats'],
                ],
                'components' => [],
            ]),
        ]);

        $rule = new ValidDataSourceMerge;
        $rule->setData([
            'template_id' => $this->template->id,
            'content' => [
                'extends' => 'parent',
            ],
        ]);

        $failed = false;
        $message = '';

        // 자식 레이아웃에서 stats ID 재사용 (중복)
        $dataSources = [
            ['id' => 'stats', 'type' => 'api', 'endpoint' => '/api/admin/stats'],
        ];

        $rule->validate('content.data_sources', $dataSources, function ($msg) use (&$failed, &$message) {
            $failed = true;
            $message = $msg;
        });

        $this->assertTrue($failed);
        $this->assertStringContainsString('stats', $message);
    }

    /**
     * ValidDataSourceMerge: 유효한 data_sources 검증 통과
     */
    public function test_valid_data_source_merge_passes_with_unique_ids(): void
    {
        // 부모 레이아웃 생성
        TemplateLayout::factory()->create([
            'template_id' => $this->template->id,
            'name' => 'parent',
            'content' => json_encode([
                'version' => '1.0.0',
                'layout_name' => 'parent',
                'data_sources' => [
                    ['id' => 'stats', 'type' => 'api', 'endpoint' => '/api/stats'],
                ],
                'components' => [],
            ]),
        ]);

        $rule = new ValidDataSourceMerge;
        $rule->setData([
            'template_id' => $this->template->id,
            'content' => [
                'extends' => 'parent',
            ],
        ]);

        $failed = false;

        // 자식 레이아웃에서 다른 ID 사용
        $dataSources = [
            ['id' => 'users', 'type' => 'api', 'endpoint' => '/api/users'],
        ];

        $rule->validate('content.data_sources', $dataSources, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed);
    }
}
