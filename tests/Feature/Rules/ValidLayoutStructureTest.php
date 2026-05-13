<?php

namespace Tests\Feature\Rules;

use App\Rules\ValidLayoutStructure;
use Tests\TestCase;

class ValidLayoutStructureTest extends TestCase
{
    private ValidLayoutStructure $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new ValidLayoutStructure;
    }

    /**
     * 유효한 레이아웃 구조 테스트
     */
    public function test_allows_valid_layout_structure(): void
    {
        $validLayout = [
            'version' => '1.0',
            'layout_name' => 'Main Layout',
            'components' => [
                [
                    'id' => 'header-1',
                    'type' => 'basic',
                    'name' => 'Header',
                    'props' => ['title' => 'Welcome'],
                ],
                [
                    'id' => 'content-1',
                    'type' => 'composite',
                    'name' => 'Content',
                    'props' => ['class' => 'container'],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $validLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected valid layout structure to be allowed');
    }

    public function test_allows_layout_with_nested_children(): void
    {
        $layoutWithChildren = [
            'version' => '1.0',
            'layout_name' => 'Complex Layout',
            'components' => [
                [
                    'id' => 'container-1',
                    'type' => 'layout',
                    'name' => 'Container',
                    'props' => ['class' => 'wrapper'],
                    'children' => [
                        [
                            'id' => 'header-1',
                            'type' => 'basic',
                            'name' => 'Header',
                            'props' => ['title' => 'Title'],
                        ],
                        [
                            'id' => 'body-1',
                            'type' => 'composite',
                            'name' => 'Body',
                            'props' => [],
                            'children' => [
                                [
                                    'id' => 'section-1',
                                    'type' => 'layout',
                                    'name' => 'Section',
                                    'props' => ['id' => 'main'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithChildren, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected layout with nested children to be allowed');
    }

    public function test_allows_layout_with_actions(): void
    {
        $layoutWithActions = [
            'version' => '1.0',
            'layout_name' => 'Interactive Layout',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => ['text' => 'Click Me'],
                    'actions' => [
                        [
                            'type' => 'click',
                            'handler' => 'handleClick',
                        ],
                        [
                            'type' => 'hover',
                            'handler' => 'handleHover',
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layoutWithActions, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected layout with actions to be allowed');
    }

    public function test_allows_layout_from_json_string(): void
    {
        $jsonLayout = json_encode([
            'version' => '1.0',
            'layout_name' => 'JSON Layout',
            'components' => [
                [
                    'id' => 'card-1',
                    'type' => 'composite',
                    'name' => 'Card',
                    'props' => ['title' => 'Card Title'],
                ],
            ],
        ]);

        $failed = false;
        $this->rule->validate('layout', $jsonLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected valid JSON string to be allowed');
    }

    /**
     * 필수 필드 누락 테스트
     */
    public function test_blocks_layout_missing_version(): void
    {
        $layout = [
            'layout_name' => 'Test Layout',
            'components' => [],
        ];

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $layout, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected layout without version to be blocked');
        $this->assertStringContainsString('version', $failMessage);
    }

    public function test_blocks_layout_missing_layout_name(): void
    {
        $layout = [
            'version' => '1.0',
            'components' => [],
        ];

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $layout, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected layout without layout_name to be blocked');
        $this->assertStringContainsString('layout_name', $failMessage);
    }

    public function test_blocks_layout_missing_components(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test Layout',
        ];

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $layout, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected layout without components to be blocked');
        $this->assertStringContainsString('components', $failMessage);
    }

    /**
     * 타입 검증 테스트
     */
    public function test_blocks_non_array_layout(): void
    {
        // 문자열은 JSON 디코딩 시도 후 실패
        $stringLayout = 'string layout';
        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $stringLayout, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });
        $this->assertTrue($failed, 'Expected string layout to be blocked');
        $this->assertStringContainsString('JSON', $failMessage);

        // 숫자, boolean, null은 배열이 아니므로 차단
        $nonArrayLayouts = [123, true, null];
        foreach ($nonArrayLayouts as $layout) {
            $failed = false;
            $failMessage = '';
            $this->rule->validate('layout', $layout, function ($message) use (&$failed, &$failMessage) {
                $failed = true;
                $failMessage = $message;
            });

            $this->assertTrue($failed, 'Expected non-array layout to be blocked');
            // 메시지는 다국어로 반환되므로 실패 여부만 확인
            $this->assertNotEmpty($failMessage);
        }
    }

    public function test_blocks_invalid_json_string(): void
    {
        $invalidJson = '{invalid json}';

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $invalidJson, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected invalid JSON to be blocked');
        $this->assertStringContainsString('JSON', $failMessage);
    }

    public function test_blocks_version_not_string(): void
    {
        $layout = [
            'version' => 123,
            'layout_name' => 'Test',
            'components' => [],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected numeric version to be blocked');
    }

    public function test_blocks_layout_name_not_string(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 123,
            'components' => [],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected numeric layout_name to be blocked');
    }

    public function test_blocks_components_not_array(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => 'not an array',
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-array components to be blocked');
    }

    /**
     * 컴포넌트 구조 검증 테스트
     */
    public function test_blocks_component_missing_component_field(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'comp-1',
                    'type' => 'basic',
                    'props' => [],
                ],
            ],
        ];

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $layout, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected component without name field to be blocked');
        $this->assertStringContainsString('name', $failMessage);
    }

    /**
     * props 필드가 없어도 유효한지 테스트 (선택적 필드)
     */
    public function test_allows_component_without_props_field(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'header-1',
                    'type' => 'basic',
                    'name' => 'Header',
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Component without props field should be valid (optional field)');
    }

    public function test_blocks_component_field_not_string(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'comp-1',
                    'type' => 'basic',
                    'name' => 123,
                    'props' => [],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-string name field to be blocked');
    }

    public function test_blocks_props_not_array_or_object(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'header-1',
                    'type' => 'basic',
                    'name' => 'Header',
                    'props' => 'not an object',
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-object props to be blocked');
    }

    public function test_blocks_non_array_component(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                'not an array',
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-array component to be blocked');
    }

    /**
     * children 필드 검증 테스트
     */
    public function test_blocks_children_not_array(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'container-1',
                    'type' => 'layout',
                    'name' => 'Container',
                    'props' => [],
                    'children' => 'not an array',
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-array children to be blocked');
    }

    /**
     * 필수 필드가 누락된 자식 컴포넌트 테스트 (type 필드 누락)
     */
    public function test_blocks_invalid_child_component(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'container-1',
                    'type' => 'layout',
                    'name' => 'Container',
                    'props' => [],
                    'children' => [
                        [
                            // type 필드 누락 (필수 필드)
                            'id' => 'header-1',
                            'name' => 'Header',
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected invalid child component (missing type) to be blocked');
    }

    /**
     * actions 필드 검증 테스트
     */
    public function test_blocks_actions_not_array(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                    'actions' => 'not an array',
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-array actions to be blocked');
    }

    public function test_blocks_action_not_array(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                    'actions' => [
                        'not an array',
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-array action to be blocked');
    }

    public function test_blocks_action_missing_type(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                    'actions' => [
                        [
                            'handler' => 'handleClick',
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $layout, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected action without type to be blocked');
        $this->assertStringContainsString('type', $failMessage);
    }

    public function test_blocks_action_type_not_string(): void
    {
        $layout = [
            'version' => '1.0',
            'layout_name' => 'Test',
            'components' => [
                [
                    'id' => 'button-1',
                    'type' => 'basic',
                    'name' => 'Button',
                    'props' => [],
                    'actions' => [
                        [
                            'type' => 123,
                        ],
                    ],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $layout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertTrue($failed, 'Expected non-string action type to be blocked');
    }

    /**
     * 최대 중첩 깊이 테스트
     */
    public function test_blocks_excessive_nesting_depth(): void
    {
        // MAX_DEPTH=10이므로, depth가 11이 되려면 12단계 중첩 필요
        // depth는 0부터 시작하므로 0,1,2,...,11 = 12단계
        $deeplyNested = [
            'version' => '1.0',
            'layout_name' => 'Deep Layout',
            'components' => [
                $this->createNestedComponent(12),
            ],
        ];

        $failed = false;
        $failMessage = '';
        $this->rule->validate('layout', $deeplyNested, function ($message) use (&$failed, &$failMessage) {
            $failed = true;
            $failMessage = $message;
        });

        $this->assertTrue($failed, 'Expected excessive nesting to be blocked');
        // 메시지는 다국어로 반환되므로 실패 여부만 확인
        $this->assertNotEmpty($failMessage);
    }

    public function test_allows_maximum_nesting_depth(): void
    {
        // MAX_DEPTH=10이므로 depth는 0~10까지 허용 (11단계)
        $maxNested = [
            'version' => '1.0',
            'layout_name' => 'Max Depth Layout',
            'components' => [
                $this->createNestedComponent(11),
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $maxNested, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected maximum allowed nesting to be allowed');
    }

    /**
     * 복합 시나리오 테스트
     */
    public function test_allows_complex_real_world_layout(): void
    {
        $complexLayout = [
            'version' => '2.0',
            'layout_name' => 'E-commerce Product Page',
            'components' => [
                [
                    'id' => 'header-1',
                    'type' => 'composite',
                    'name' => 'Header',
                    'props' => [
                        'logo' => '/images/logo.png',
                        'menu' => ['Home', 'Products', 'About'],
                    ],
                    'actions' => [
                        [
                            'type' => 'search',
                            'handler' => 'handleSearch',
                        ],
                    ],
                ],
                [
                    'id' => 'product-container-1',
                    'type' => 'layout',
                    'name' => 'ProductContainer',
                    'props' => ['class' => 'container mx-auto'],
                    'children' => [
                        [
                            'id' => 'product-gallery-1',
                            'type' => 'composite',
                            'name' => 'ProductGallery',
                            'props' => ['images' => []],
                        ],
                        [
                            'id' => 'product-details-1',
                            'type' => 'composite',
                            'name' => 'ProductDetails',
                            'props' => [],
                            'children' => [
                                [
                                    'id' => 'product-title-1',
                                    'type' => 'basic',
                                    'name' => 'ProductTitle',
                                    'props' => ['tag' => 'h1'],
                                ],
                                [
                                    'id' => 'product-price-1',
                                    'type' => 'basic',
                                    'name' => 'ProductPrice',
                                    'props' => ['currency' => 'USD'],
                                ],
                                [
                                    'id' => 'add-to-cart-1',
                                    'type' => 'basic',
                                    'name' => 'AddToCartButton',
                                    'props' => ['text' => 'Add to Cart'],
                                    'actions' => [
                                        [
                                            'type' => 'click',
                                            'handler' => 'addToCart',
                                            'params' => ['productId' => 123],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'footer-1',
                    'type' => 'composite',
                    'name' => 'Footer',
                    'props' => ['year' => 2025],
                ],
            ],
        ];

        $failed = false;
        $this->rule->validate('layout', $complexLayout, function () use (&$failed) {
            $failed = true;
        });

        $this->assertFalse($failed, 'Expected complex real-world layout to be allowed');
    }

    /**
     * 헬퍼 메서드: 재귀적 중첩 컴포넌트 생성
     */
    private function createNestedComponent(int $depth): array
    {
        $component = [
            'id' => 'container-'.$depth,
            'type' => 'layout',
            'name' => 'Container',
            'props' => ['level' => $depth],
        ];

        if ($depth > 1) {
            $component['children'] = [
                $this->createNestedComponent($depth - 1),
            ];
        }

        return $component;
    }
}
