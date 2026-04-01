<?php

namespace Tests\Unit\Services;

use App\Services\LayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutServiceSeoMergeTest extends TestCase
{
    use RefreshDatabase;

    private LayoutService $layoutService;

    /**
     * 테스트 초기화 - LayoutService 인스턴스를 준비합니다.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->layoutService = app(LayoutService::class);
    }

    /**
     * 부모만 meta.seo가 있을 때 부모의 seo가 사용됩니다.
     */
    public function test_parent_only_seo_is_used(): void
    {
        $parentSeo = [
            'enabled' => true,
            'priority' => 'high',
            'og' => ['type' => 'website'],
        ];

        $parent = $this->buildLayout(seo: $parentSeo);
        $child = $this->buildLayout();

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertTrue($result['meta']['seo']['enabled']);
        $this->assertSame('high', $result['meta']['seo']['priority']);
        $this->assertSame('website', $result['meta']['seo']['og']['type']);
    }

    /**
     * 자식만 meta.seo가 있을 때 자식의 seo가 사용됩니다.
     */
    public function test_child_only_seo_is_used(): void
    {
        $childSeo = [
            'enabled' => true,
            'priority' => 'low',
            'og' => ['title' => '자식 페이지'],
        ];

        $parent = $this->buildLayout();
        $child = $this->buildLayout(seo: $childSeo);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertTrue($result['meta']['seo']['enabled']);
        $this->assertSame('low', $result['meta']['seo']['priority']);
        $this->assertSame('자식 페이지', $result['meta']['seo']['og']['title']);
    }

    /**
     * 부모와 자식 모두 meta.seo가 있을 때 자식의 priority가 우선됩니다.
     */
    public function test_child_overrides_priority_parent_rest_preserved(): void
    {
        $parentSeo = [
            'enabled' => true,
            'priority' => 'high',
            'title_template' => '{{title}} - 사이트명',
            'description' => '기본 설명',
        ];

        $childSeo = [
            'priority' => 'low',
        ];

        $parent = $this->buildLayout(seo: $parentSeo);
        $child = $this->buildLayout(seo: $childSeo);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // 자식이 오버라이드한 값
        $this->assertSame('low', $result['meta']['seo']['priority']);
        // 부모에서 보존된 값
        $this->assertTrue($result['meta']['seo']['enabled']);
        $this->assertSame('{{title}} - 사이트명', $result['meta']['seo']['title_template']);
        $this->assertSame('기본 설명', $result['meta']['seo']['description']);
    }

    /**
     * 자식이 og를 추가하면 부모의 기본값과 함께 병합됩니다.
     */
    public function test_child_adds_og_merged_with_parent_defaults(): void
    {
        $parentSeo = [
            'enabled' => true,
            'priority' => 'medium',
        ];

        $childSeo = [
            'og' => [
                'title' => '상품 상세',
                'type' => 'product',
            ],
        ];

        $parent = $this->buildLayout(seo: $parentSeo);
        $child = $this->buildLayout(seo: $childSeo);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // 부모 기본값 보존
        $this->assertTrue($result['meta']['seo']['enabled']);
        $this->assertSame('medium', $result['meta']['seo']['priority']);
        // 자식이 추가한 og
        $this->assertSame('상품 상세', $result['meta']['seo']['og']['title']);
        $this->assertSame('product', $result['meta']['seo']['og']['type']);
    }

    /**
     * 자식이 enabled=false로 설정하면 최종 결과에서 enabled=false가 됩니다.
     */
    public function test_child_sets_enabled_false(): void
    {
        $parentSeo = [
            'enabled' => true,
            'priority' => 'high',
        ];

        $childSeo = [
            'enabled' => false,
        ];

        $parent = $this->buildLayout(seo: $parentSeo);
        $child = $this->buildLayout(seo: $childSeo);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertFalse($result['meta']['seo']['enabled']);
        // 부모 나머지 보존
        $this->assertSame('high', $result['meta']['seo']['priority']);
    }

    /**
     * 부모 seo.og.type + 자식 seo.og.title → og가 deep merge됩니다.
     */
    public function test_og_deep_merge_parent_type_child_title(): void
    {
        $parentSeo = [
            'enabled' => true,
            'og' => [
                'type' => 'website',
                'site_name' => 'G7 Store',
            ],
        ];

        $childSeo = [
            'og' => [
                'title' => '카테고리 페이지',
            ],
        ];

        $parent = $this->buildLayout(seo: $parentSeo);
        $child = $this->buildLayout(seo: $childSeo);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // 부모 og 보존
        $this->assertSame('website', $result['meta']['seo']['og']['type']);
        $this->assertSame('G7 Store', $result['meta']['seo']['og']['site_name']);
        // 자식 og 추가
        $this->assertSame('카테고리 페이지', $result['meta']['seo']['og']['title']);
    }

    /**
     * seo 이외의 meta 키(title, description)는 기존 얕은 병합이 유지됩니다.
     */
    public function test_non_seo_meta_keys_use_shallow_merge(): void
    {
        $parent = [
            'meta' => [
                'title' => 'Parent Title',
                'description' => 'Parent Description',
                'seo' => ['enabled' => true, 'priority' => 'high'],
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $child = [
            'meta' => [
                'title' => 'Child Title',
                'seo' => ['priority' => 'low'],
            ],
            'data_sources' => [],
            'components' => [],
        ];

        $result = $this->layoutService->mergeLayouts($parent, $child);

        // 얕은 병합: 자식 title 우선
        $this->assertSame('Child Title', $result['meta']['title']);
        // 얕은 병합: 자식에 없는 description 보존
        $this->assertSame('Parent Description', $result['meta']['description']);
        // seo는 deep merge
        $this->assertSame('low', $result['meta']['seo']['priority']);
        $this->assertTrue($result['meta']['seo']['enabled']);
    }

    /**
     * 3단계 상속(mergeLayouts 2회 호출)에서 모든 레벨의 seo가 누적됩니다.
     */
    public function test_three_level_inheritance_accumulates_seo(): void
    {
        // Level 1: _base (최상위 부모)
        $base = [
            'meta' => [
                'seo' => [
                    'enabled' => true,
                    'title_template' => '{{title}} - G7',
                    'og' => [
                        'type' => 'website',
                        'site_name' => 'G7',
                    ],
                ],
            ],
            'data_sources' => [],
            'components' => [
                ['component' => 'Div', 'slot' => 'content'],
            ],
        ];

        // Level 2: shop 기본 (중간 레벨)
        $shopBase = [
            'meta' => [
                'seo' => [
                    'priority' => 'medium',
                    'og' => [
                        'type' => 'product.group',
                    ],
                    'structured_data' => [
                        'type' => 'ItemList',
                    ],
                ],
            ],
            'data_sources' => [],
            'slots' => [
                'content' => [
                    'component' => 'Div',
                    'slot' => 'shop_content',
                ],
            ],
        ];

        // Level 3: 상품 상세 (최하위)
        $shopShow = [
            'meta' => [
                'seo' => [
                    'priority' => 'high',
                    'og' => [
                        'title' => '{{product.name}}',
                        'image' => '{{product.image}}',
                    ],
                    'data_sources' => ['product'],
                ],
            ],
            'data_sources' => [],
            'slots' => [
                'shop_content' => [
                    'component' => 'Div',
                    'props' => ['text' => '상품 상세'],
                ],
            ],
        ];

        // 3단계 병합: _base + shop_base → 중간 결과 + shop_show
        $merged1 = $this->layoutService->mergeLayouts($base, $shopBase);
        $result = $this->layoutService->mergeLayouts($merged1, $shopShow);

        $seo = $result['meta']['seo'];

        // _base에서 상속
        $this->assertTrue($seo['enabled']);
        $this->assertSame('{{title}} - G7', $seo['title_template']);

        // shop_base에서 상속 (deep merge)
        $this->assertSame('ItemList', $seo['structured_data']['type']);

        // shop_show에서 오버라이드
        $this->assertSame('high', $seo['priority']);
        // data_sources는 합집합 병합 (Level 3의 'product'만 존재 — Level 1,2는 미정의)
        $this->assertSame(['product'], $seo['data_sources']);

        // og deep merge: _base의 site_name + shop_base의 type 오버라이드 + shop_show의 title, image
        $this->assertSame('G7', $seo['og']['site_name']);
        $this->assertSame('product.group', $seo['og']['type']);
        $this->assertSame('{{product.name}}', $seo['og']['title']);
        $this->assertSame('{{product.image}}', $seo['og']['image']);
    }

    // ─── data_sources 합집합 병합 테스트 ──────────────────────────────

    /**
     * 부모와 자식의 data_sources가 합집합으로 병합됩니다.
     */
    public function test_data_sources_union_merge_parent_and_child(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'data_sources' => ['stats', 'menu'],
        ]);
        $child = $this->buildLayout(seo: [
            'data_sources' => ['product'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(['stats', 'menu', 'product'], $result['meta']['seo']['data_sources']);
    }

    /**
     * 부모와 자식의 data_sources 중복이 제거됩니다.
     */
    public function test_data_sources_union_dedup(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'data_sources' => ['products', 'categories'],
        ]);
        $child = $this->buildLayout(seo: [
            'data_sources' => ['products', 'reviews'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(['products', 'categories', 'reviews'], $result['meta']['seo']['data_sources']);
    }

    /**
     * 자식만 data_sources를 정의하면 그대로 사용됩니다.
     */
    public function test_data_sources_child_only(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
        ]);
        $child = $this->buildLayout(seo: [
            'data_sources' => ['product'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(['product'], $result['meta']['seo']['data_sources']);
    }

    /**
     * 부모만 data_sources를 정의하면 자식이 상속합니다.
     */
    public function test_data_sources_parent_only(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'data_sources' => ['stats'],
        ]);
        $child = $this->buildLayout(seo: [
            'priority' => 0.8,
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame(['stats'], $result['meta']['seo']['data_sources']);
    }

    /**
     * 양쪽 모두 data_sources 미정의 시 키가 없습니다.
     */
    public function test_data_sources_both_empty(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
        ]);
        $child = $this->buildLayout(seo: [
            'priority' => 0.5,
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertArrayNotHasKey('data_sources', $result['meta']['seo']);
    }

    // ─── vars deep merge 테스트 ──────────────────────────────────────

    /**
     * 부모와 자식의 vars가 deep merge되어 양쪽 모두 보존됩니다.
     */
    public function test_vars_deep_merge_parent_child(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'vars' => ['site_name' => '$core_settings:general.site_name'],
        ]);
        $child = $this->buildLayout(seo: [
            'vars' => ['product_name' => '{{product.data.name}}'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $vars = $result['meta']['seo']['vars'];
        $this->assertSame('$core_settings:general.site_name', $vars['site_name']);
        $this->assertSame('{{product.data.name}}', $vars['product_name']);
    }

    /**
     * 자식의 vars가 부모의 동일 키를 오버라이드합니다.
     */
    public function test_vars_child_overrides_parent_key(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'vars' => ['name' => 'Parent Name'],
        ]);
        $child = $this->buildLayout(seo: [
            'vars' => ['name' => 'Child Name'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame('Child Name', $result['meta']['seo']['vars']['name']);
    }

    // ─── base enabled 상속 테스트 ────────────────────────────────────

    /**
     * 부모 enabled=false + 자식 enabled=true → 자식 우선으로 활성화됩니다.
     */
    public function test_base_seo_disabled_child_enables(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => false,
            'og' => ['type' => 'website'],
        ]);
        $child = $this->buildLayout(seo: [
            'enabled' => true,
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertTrue($result['meta']['seo']['enabled']);
        $this->assertSame('website', $result['meta']['seo']['og']['type']);
    }

    /**
     * 부모의 og.type을 자식이 오버라이드합니다.
     */
    public function test_base_og_type_inherited_child_overrides(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'og' => ['type' => 'website'],
        ]);
        $child = $this->buildLayout(seo: [
            'og' => ['type' => 'product'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame('product', $result['meta']['seo']['og']['type']);
    }

    /**
     * 부모의 og.type이 보존되면서 자식이 og.title을 추가합니다.
     */
    public function test_base_og_type_inherited_child_adds_title(): void
    {
        $parent = $this->buildLayout(seo: [
            'enabled' => true,
            'og' => ['type' => 'website'],
        ]);
        $child = $this->buildLayout(seo: [
            'og' => ['title' => '상품 페이지'],
        ]);

        $result = $this->layoutService->mergeLayouts($parent, $child);

        $this->assertSame('website', $result['meta']['seo']['og']['type']);
        $this->assertSame('상품 페이지', $result['meta']['seo']['og']['title']);
    }

    // ─── 3단계 상속 data_sources 누적 테스트 ─────────────────────────

    /**
     * 3단계 상속에서 각 레벨의 data_sources가 합집합으로 누적됩니다.
     */
    public function test_three_level_data_sources_accumulate(): void
    {
        $base = [
            'meta' => [
                'seo' => [
                    'enabled' => true,
                    'data_sources' => ['stats'],
                ],
            ],
            'data_sources' => [],
            'components' => [
                ['component' => 'Div', 'slot' => 'content'],
            ],
        ];

        $mid = [
            'meta' => [
                'seo' => [
                    'data_sources' => ['categories'],
                ],
            ],
            'data_sources' => [],
            'slots' => [
                'content' => [
                    'component' => 'Div',
                    'slot' => 'inner',
                ],
            ],
        ];

        $leaf = [
            'meta' => [
                'seo' => [
                    'data_sources' => ['product', 'stats'],
                ],
            ],
            'data_sources' => [],
            'slots' => [
                'inner' => [
                    'component' => 'Div',
                    'props' => ['text' => 'leaf'],
                ],
            ],
        ];

        $merged1 = $this->layoutService->mergeLayouts($base, $mid);
        $result = $this->layoutService->mergeLayouts($merged1, $leaf);

        // stats(base) + categories(mid) + product(leaf) — stats 중복 제거
        $this->assertSame(['stats', 'categories', 'product'], $result['meta']['seo']['data_sources']);
        $this->assertTrue($result['meta']['seo']['enabled']);
    }

    /**
     * 테스트용 레이아웃 배열을 빌드합니다.
     *
     * @param  array|null  $seo  SEO 설정 배열 (null이면 seo 키 없음)
     * @return array 레이아웃 배열
     */
    private function buildLayout(?array $seo = null): array
    {
        $meta = [];
        if ($seo !== null) {
            $meta['seo'] = $seo;
        }

        return [
            'meta' => $meta,
            'data_sources' => [],
            'components' => [],
        ];
    }
}
