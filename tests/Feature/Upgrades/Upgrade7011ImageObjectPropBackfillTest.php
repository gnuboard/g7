<?php

// @scenario apply_type=propValue, consumer=header_logo, storage_scope=layout_previews_untouched, stored_shape=binding_expression, widget_output=scalar_string
// @scenario apply_type=propValue, consumer=img_src, storage_scope=layout_previews_untouched, stored_shape=object, widget_output=image_object_nonstring_url
// @scenario apply_type=propValue, consumer=none, storage_scope=layout_previews_untouched, stored_shape=string, widget_output=image_object

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\V7_0_11\Migrations\NarrowImageObjectPropsInLayouts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Tests\TestCase;

/**
 * 7.0.11 업그레이드 스텝 — prop 자리 이미지 값 객체 축약 백필 검증.
 *
 * 레이아웃 편집기의 `image` 위젯이 내보내는 `{url,size,repeat,position}` 객체가
 * `propValue` 경로로 `props[key]` 에 그대로 기록되어 소비 컴포넌트가
 * `<Img src={객체}>` 를 받던 결함(공개 #135)의 기존 데이터 보정.
 *
 * 검증 축은 셋이다:
 *  ① **판정식 엄격도** — 느슨한 판정식(4키 중 하나라도)을 쓰면 정상 props 를 파괴한다.
 *     케이스 9·10·11·14 가 그 반증 가드다.
 *  ② **순회 범위** — prop 자리는 키 allowlist 로 정의 불가하므로 모드 플래그 전역 재귀.
 *     중첩 노드·responsive 분기·확장 injections 가 포섭되고 style 자리는 배제된다.
 *  ③ **안전성** — 멱등 / 무변경 행 UPDATE 금지 / chunkById / lock_version /
 *     original_content_hash 불변 / 버전·미리보기 스냅샷 미개입.
 */
class Upgrade7011ImageObjectPropBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** 청크 크기(100) 를 넘겨야 OFFSET 누락이 드러난다 — Beta2LayoutHashBackfillTest 관례 계승 */
    private const SEED_COUNT = 250;

    private NarrowImageObjectPropsInLayouts $migration;

    private UpgradeContext $context;

    private int $templateId;

    protected function setUp(): void
    {
        parent::setUp();

        // upgrade data 파일은 composer autoload 대상이 아니며 AbstractUpgradeStep 이
        // 실행 시점에 require_once 로 수동 로드한다. 테스트에서도 동일하게 수동 로드.
        require_once base_path('upgrades/data/7.0.11/migrations/01_NarrowImageObjectPropsInLayouts.php');

        $this->migration = new NarrowImageObjectPropsInLayouts;
        $this->context = new UpgradeContext(
            fromVersion: '7.0.10',
            toVersion: '7.0.11',
            currentStep: '7.0.11',
        );

        $this->templateId = DB::table('templates')->insertGetId([
            'identifier' => 'test-imgobj_template',
            'vendor' => 'test',
            'name' => json_encode(['ko' => '이미지 축약 테스트', 'en' => 'Image Narrow Test']),
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** 완전한 4키 이미지 값 객체 */
    private function imageValue(string $url = '/api/attachment/X'): array
    {
        return ['url' => $url, 'size' => 'cover', 'repeat' => 'no-repeat', 'position' => 'center'];
    }

    /**
     * 레이아웃 1건을 시드하고 id 를 돌려줍니다.
     *
     * @param  array  $content  레이아웃 content 배열
     * @param  array  $extra  추가 컬럼 오버라이드
     * @return int 생성된 레이아웃 id
     */
    private function seedLayout(array $content, array $extra = []): int
    {
        static $seq = 0;
        $seq++;

        return DB::table('template_layouts')->insertGetId(array_merge([
            'template_id' => $this->templateId,
            'name' => 'imgobj/layout_'.$seq,
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_type' => 'template',
            'source_identifier' => 'test-imgobj_template',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    /** 저장된 content 를 배열로 되읽는다 */
    private function readContent(int $id, string $table = 'template_layouts'): array
    {
        return json_decode(DB::table($table)->where('id', $id)->value('content'), true);
    }

    private function runMigration(): void
    {
        $this->migration->run($this->context);
    }

    /**
     * 로거를 감시 대역으로 바꾼 컨텍스트로 마이그레이션을 실행합니다.
     *
     * 파괴적 동작(prop 키 제거)의 유일한 흔적은 `upgrade` 채널 로그다 — 그 호출이
     * 사라져도 데이터 단언만으로는 드러나지 않으므로 로그 발생 자체를 계약으로 고정한다.
     * `UpgradeContext` 는 생성자에서 `Log::channel()` 을 1회 해석하므로 페이크를
     * **컨텍스트 생성 전에** 심어야 한다.
     *
     * @return MockInterface 감시 대역 로거
     */
    private function runMigrationWithLogSpy(): MockInterface
    {
        $spy = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->with('upgrade')
            ->andReturn($spy);

        $this->migration->run(new UpgradeContext(
            fromVersion: '7.0.10',
            toVersion: '7.0.11',
            currentStep: '7.0.11',
        ));

        return $spy;
    }

    // ── ① 기본 축약 ────────────────────────────────────────────────────────

    /** @effects backfill_narrows_prop_slot_objects_in_layouts_and_extensions */
    public function test_1_prop_slot_image_object_is_narrowed_to_url(): void
    {
        $id = $this->seedLayout([
            'components' => [
                ['name' => 'Header', 'props' => ['logo' => $this->imageValue(), 'siteName' => '샘플']],
            ],
        ]);

        $this->runMigration();

        $content = $this->readContent($id);
        $this->assertSame('/api/attachment/X', $content['components'][0]['props']['logo']);
        $this->assertSame('샘플', $content['components'][0]['props']['siteName'], '나머지 props 는 불변이어야 합니다.');
    }

    public function test_2_string_value_is_untouched_and_row_is_not_updated(): void
    {
        $id = $this->seedLayout([
            'components' => [['name' => 'Header', 'props' => ['logo' => '/img/a.png']]],
        ]);
        $before = DB::table('template_layouts')->where('id', $id)->first();

        $this->runMigration();

        $after = DB::table('template_layouts')->where('id', $id)->first();
        $this->assertSame($before->content, $after->content);
        $this->assertSame($before->updated_at, $after->updated_at, '무변경 행은 UPDATE 되지 않아야 합니다.');
        $this->assertSame($before->lock_version, $after->lock_version);
    }

    public function test_3_binding_expression_string_is_untouched(): void
    {
        $expr = '{{_global.settings?.general?.site_logo_url}}';
        $id = $this->seedLayout([
            'components' => [['name' => 'Header', 'props' => ['logo' => $expr]]],
        ]);
        $before = DB::table('template_layouts')->where('id', $id)->first();

        $this->runMigration();

        $after = DB::table('template_layouts')->where('id', $id)->first();
        $this->assertSame($expr, $this->readContent($id)['components'][0]['props']['logo']);
        $this->assertSame($before->updated_at, $after->updated_at);
    }

    // ── ② style 자리는 대상이 아니다 ───────────────────────────────────────

    /** @effects backfill_excludes_style_slot_where_background_decomposition_lives */
    public function test_4_style_background_four_props_are_untouched(): void
    {
        $style = [
            'backgroundImage' => 'url(/a.png)',
            'backgroundSize' => 'cover',
            'backgroundRepeat' => 'no-repeat',
            'backgroundPosition' => 'center',
        ];
        $id = $this->seedLayout([
            'components' => [['name' => 'Div', 'props' => ['style' => $style]]],
        ]);
        $before = DB::table('template_layouts')->where('id', $id)->first();

        $this->runMigration();

        $this->assertSame($style, $this->readContent($id)['components'][0]['props']['style']);
        $this->assertSame($before->updated_at, DB::table('template_layouts')->where('id', $id)->first()->updated_at);
    }

    public function test_5_corrupted_value_inside_style_slot_is_untouched(): void
    {
        // style 자리의 손상값 복구는 엔진 `sanitizeBgScalar` 소관 — 백필은 건드리지 않는다.
        $style = ['backgroundPosition' => $this->imageValue()];
        $id = $this->seedLayout([
            'components' => [['name' => 'Div', 'props' => ['style' => $style]]],
        ]);

        $this->runMigration();

        $this->assertSame($style, $this->readContent($id)['components'][0]['props']['style']);
    }

    // ── ③ 순회 범위 ────────────────────────────────────────────────────────

    /** @effects backfill_traverses_responsive_branches_nested_nodes_and_injections */
    public function test_6_responsive_branch_props_are_narrowed(): void
    {
        $id = $this->seedLayout([
            'components' => [
                [
                    'name' => 'Header',
                    'responsive' => ['md' => ['props' => ['logo' => $this->imageValue('/md.png')]]],
                ],
            ],
        ]);

        $this->runMigration();

        $this->assertSame('/md.png', $this->readContent($id)['components'][0]['responsive']['md']['props']['logo']);
    }

    public function test_7_nested_node_inside_props_is_narrowed(): void
    {
        $id = $this->seedLayout([
            'components' => [
                [
                    'name' => 'DataGrid',
                    'props' => [
                        'cardColumns' => [
                            ['cellChildren' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue('/nested.png')]]]],
                        ],
                    ],
                ],
            ],
        ]);

        $this->runMigration();

        $content = $this->readContent($id);
        $this->assertSame(
            '/nested.png',
            $content['components'][0]['props']['cardColumns'][0]['cellChildren'][0]['props']['logo']
        );
    }

    public function test_8_style_inside_nested_node_is_still_excluded(): void
    {
        $style = ['backgroundImage' => 'url(/n.png)', 'backgroundSize' => 'cover'];
        $id = $this->seedLayout([
            'components' => [
                [
                    'name' => 'Div',
                    'props' => [
                        'expandChildren' => [['name' => 'Div', 'props' => ['style' => $style]]],
                    ],
                ],
            ],
        ]);

        $this->runMigration();

        $content = $this->readContent($id);
        $this->assertSame($style, $content['components'][0]['props']['expandChildren'][0]['props']['style']);
    }

    // ── ④ 판정식 엄격도 (느슨한 판정식이면 여기서 파괴된다) ───────────────

    /** @effects backfill_strict_predicate_leaves_normal_props_untouched */
    public function test_9_normal_props_with_size_key_are_untouched(): void
    {
        // 실측 674건 패턴. 코어 엔진의 느슨한 판정식(4키 중 하나라도)이면 이 props 가
        // 이미지 값 객체로 오인되어 파괴된다.
        $props = ['className' => 'x', 'name' => 'user', 'size' => 'lg'];
        $id = $this->seedLayout(['components' => [['name' => 'Icon', 'props' => $props]]]);
        $before = DB::table('template_layouts')->where('id', $id)->first();

        $this->runMigration();

        $this->assertSame($props, $this->readContent($id)['components'][0]['props']);
        $this->assertSame($before->updated_at, DB::table('template_layouts')->where('id', $id)->first()->updated_at);
    }

    public function test_10_normal_props_with_position_key_are_untouched(): void
    {
        // 실측 26건 패턴.
        $props = ['items' => [['label' => 'a']], 'position' => 'bottom'];
        $id = $this->seedLayout(['components' => [['name' => 'Toast', 'props' => $props]]]);

        $this->runMigration();

        $this->assertSame($props, $this->readContent($id)['components'][0]['props']);
    }

    public function test_11_object_with_key_outside_the_four_is_untouched(): void
    {
        $value = ['url' => '/x', 'label' => 'y'];
        $id = $this->seedLayout(['components' => [['name' => 'X', 'props' => ['foo' => $value]]]]);

        $this->runMigration();

        $this->assertSame($value, $this->readContent($id)['components'][0]['props']['foo']);
    }

    public function test_14_image_object_without_url_key_is_untouched(): void
    {
        // `{size,repeat,position}` 은 정상 props 와 정적으로 구분 불가 — 건드리면 오탐.
        $value = ['size' => 'cover', 'repeat' => 'no-repeat', 'position' => 'center'];
        $id = $this->seedLayout(['components' => [['name' => 'X', 'props' => ['bg' => $value]]]]);

        $this->runMigration();

        $this->assertSame($value, $this->readContent($id)['components'][0]['props']['bg']);
    }

    public function test_27_props_container_itself_is_never_treated_as_an_image_value(): void
    {
        // `props` 는 값이 아니라 **컨테이너**다. `url` prop 을 받는 컴포넌트(임베드·영상 등)는
        // props 맵 자신이 「4키 부분집합 + url 보유」 형태가 되는데, 그것을 값으로 오인하면
        // props 가 통째로 문자열로 붕괴해 그 노드의 설정이 전부 사라진다.
        $props = ['url' => '/embed/x', 'size' => 'lg'];
        $id = $this->seedLayout(['components' => [['name' => 'Iframe', 'props' => $props]]]);

        $this->runMigration();

        $this->assertSame($props, $this->readContent($id)['components'][0]['props']);
    }

    public function test_28_props_container_with_corrupted_url_is_never_dropped(): void
    {
        // 같은 오인이 손상 url 분기로 가면 props 키 자체가 unset 되어 노드가 빈 껍데기가 된다.
        $props = ['url' => ['nested' => 1], 'size' => 'lg'];
        $id = $this->seedLayout(['components' => [['name' => 'Video', 'props' => $props]]]);

        $this->runMigration();

        $node = $this->readContent($id)['components'][0];
        $this->assertArrayHasKey('props', $node, 'props 컨테이너는 손상 url 분기의 대상이 아니다.');
        $this->assertSame($props, $node['props']);
    }

    public function test_29_style_container_itself_is_never_treated_as_an_image_value(): void
    {
        // `style` 도 같은 컨테이너 키다 — prop 자리 안에 있어도 값으로 평가하지 않는다.
        $style = ['url' => '/legacy.png', 'position' => 'center'];
        $id = $this->seedLayout(['components' => [['name' => 'X', 'props' => ['style' => $style]]]]);

        $this->runMigration();

        $this->assertSame($style, $this->readContent($id)['components'][0]['props']['style']);
    }

    // ── ⑤ 손상 url ─────────────────────────────────────────────────────────

    /** @effects backfill_drops_prop_key_on_corrupted_url_with_warning_log */
    public function test_12_empty_url_drops_the_prop_key(): void
    {
        $id = $this->seedLayout([
            'components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue(''), 'siteName' => '샘플']]],
        ]);

        $spy = $this->runMigrationWithLogSpy();

        $props = $this->readContent($id)['components'][0]['props'];
        $this->assertArrayNotHasKey('logo', $props, 'src="" 는 현재 문서 재요청이므로 키를 제거해 폴백을 살린다.');
        $this->assertSame('샘플', $props['siteName']);

        // 파괴적 동작이므로 원본이 로그에 남아야 한다 — 행 id·JSON 경로·원본 값 3요소.
        $spy->shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, "id={$id}")
                && str_contains($m, 'components[0].props.logo')
                && str_contains($m, '"url":""'))
            ->once();
    }

    public function test_13_non_string_url_drops_the_prop_key(): void
    {
        $id = $this->seedLayout([
            'components' => [['name' => 'Header', 'props' => ['logo' => ['url' => ['nested' => 1], 'size' => 'cover']]]],
        ]);

        $spy = $this->runMigrationWithLogSpy();

        $this->assertArrayNotHasKey('logo', $this->readContent($id)['components'][0]['props']);

        $spy->shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, "id={$id}")
                && str_contains($m, 'components[0].props.logo')
                && str_contains($m, '"nested":1'))
            ->once();
    }

    // ── ⑥ 확장 테이블 ──────────────────────────────────────────────────────

    /**
     * 확장 레이아웃 1건을 시드합니다.
     *
     * @param  array  $content  content 배열
     * @return int 생성된 행 id
     */
    private function seedExtension(array $content): int
    {
        static $seq = 0;
        $seq++;

        return DB::table('template_layout_extensions')->insertGetId([
            'template_id' => $this->templateId,
            'extension_type' => 'module',
            'target_name' => 'home_'.$seq,
            'source_type' => 'module',
            'source_identifier' => 'test-module',
            'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'priority' => 10,
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_15_extension_injection_component_props_are_narrowed(): void
    {
        $id = $this->seedExtension([
            'injections' => [
                ['components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue('/ext.png')]]]],
            ],
        ]);

        $this->runMigration();

        $content = $this->readContent($id, 'template_layout_extensions');
        $this->assertSame('/ext.png', $content['injections'][0]['components'][0]['props']['logo']);
    }

    public function test_16_extension_inject_props_slot_is_narrowed(): void
    {
        $id = $this->seedExtension([
            'injections' => [['props' => ['logo' => $this->imageValue('/inject.png')]]],
        ]);

        $this->runMigration();

        $content = $this->readContent($id, 'template_layout_extensions');
        $this->assertSame('/inject.png', $content['injections'][0]['props']['logo']);
    }

    /** @effects backfill_traverses_modals_in_both_list_and_map_shape */
    public function test_17_modals_in_both_list_and_map_shape_are_narrowed(): void
    {
        $listId = $this->seedLayout([
            'modals' => [['id' => 'm1', 'components' => [['name' => 'Img', 'props' => ['src' => $this->imageValue('/list.png')]]]]],
        ]);
        $mapId = $this->seedLayout([
            'modals' => [
                'channel_edit_modal' => [
                    'components' => [['name' => 'Img', 'props' => ['src' => $this->imageValue('/map.png')]]],
                ],
            ],
        ]);

        $this->runMigration();

        $this->assertSame('/list.png', $this->readContent($listId)['modals'][0]['components'][0]['props']['src']);
        $this->assertSame(
            '/map.png',
            $this->readContent($mapId)['modals']['channel_edit_modal']['components'][0]['props']['src']
        );
    }

    // ── ⑦ 안전성 ───────────────────────────────────────────────────────────

    /** @effects backfill_is_idempotent_and_performs_no_update_on_unchanged_rows */
    public function test_18_second_run_is_idempotent_and_performs_no_update(): void
    {
        $id = $this->seedLayout([
            'components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue()]]],
        ]);

        $this->runMigration();
        $afterFirst = DB::table('template_layouts')->where('id', $id)->first();

        $this->runMigration();
        $afterSecond = DB::table('template_layouts')->where('id', $id)->first();

        $this->assertSame($afterFirst->content, $afterSecond->content);
        $this->assertSame($afterFirst->lock_version, $afterSecond->lock_version, '2회차는 UPDATE 가 없어야 합니다.');
        $this->assertSame($afterFirst->updated_at, $afterSecond->updated_at);
    }

    /** @effects backfill_increments_lock_version_only_on_changed_rows */
    public function test_19_lock_version_is_incremented_only_on_changed_rows(): void
    {
        $dirty = $this->seedLayout(
            ['components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue()]]]],
            ['lock_version' => 3],
        );
        $clean = $this->seedLayout(
            ['components' => [['name' => 'Header', 'props' => ['logo' => '/ok.png']]]],
            ['lock_version' => 3],
        );

        $this->runMigration();

        $this->assertSame(4, (int) DB::table('template_layouts')->where('id', $dirty)->value('lock_version'));
        $this->assertSame(3, (int) DB::table('template_layouts')->where('id', $clean)->value('lock_version'));
    }

    /** @effects backfill_preserves_original_content_hash_so_modified_judgement_holds */
    public function test_20_original_content_hash_is_preserved(): void
    {
        $id = $this->seedLayout(
            ['components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue()]]]],
            ['original_content_hash' => str_repeat('a', 64), 'original_content_size' => 123],
        );

        $this->runMigration();

        $row = DB::table('template_layouts')->where('id', $id)->first();
        $this->assertSame(str_repeat('a', 64), $row->original_content_hash, '재계산하면 사용자 편집본이 「원본 그대로」로 위장된다.');
        $this->assertSame(123, (int) $row->original_content_size);
        // 백필 후에도 content 는 원본 파일과 다르므로 「수정됨」 판정이 유지된다.
        $this->assertNotSame(hash('sha256', $row->content), $row->original_content_hash);
    }

    /** @effects backfill_uses_keyset_chunking_so_no_row_is_skipped_past_the_boundary */
    public function test_21_all_rows_are_converted_beyond_the_chunk_boundary(): void
    {
        $ids = [];
        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $ids[] = $this->seedLayout([
                'components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue('/a'.$i.'.png')]]],
            ]);
        }

        $this->runMigration();

        $remaining = 0;
        foreach ($ids as $i => $id) {
            $logo = $this->readContent($id)['components'][0]['props']['logo'];
            if (! is_string($logo)) {
                $remaining++;

                continue;
            }
            $this->assertSame('/a'.$i.'.png', $logo);
        }

        $this->assertSame(0, $remaining, 'OFFSET 순회면 청크 경계 이후 행이 조용히 누락된다 (chunkById 필요).');
    }

    /** @effects backfill_skips_broken_json_rows_without_aborting_the_run */
    public function test_22_broken_json_row_is_skipped_without_aborting_the_run(): void
    {
        $broken = $this->seedLayout(['components' => []]);
        DB::table('template_layouts')->where('id', $broken)->update(['content' => '{not json']);
        $good = $this->seedLayout([
            'components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue('/good.png')]]],
        ]);

        $spy = $this->runMigrationWithLogSpy();

        $this->assertSame('{not json', DB::table('template_layouts')->where('id', $broken)->value('content'));
        $this->assertSame('/good.png', $this->readContent($good)['components'][0]['props']['logo']);

        // 건너뛴 행은 조용히 사라지면 안 된다 — 그 행 id 가 로그에 남아야 운영자가 찾아간다.
        $spy->shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, "id={$broken}"))
            ->atLeast()->once();
    }

    /** @effects backfill_leaves_version_and_preview_snapshots_untouched */
    public function test_23_version_and_preview_snapshots_are_untouched(): void
    {
        $layoutId = $this->seedLayout(['components' => []]);
        $poisoned = json_encode(
            ['components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue('/snap.png')]]]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $versionId = DB::table('template_layout_versions')->insertGetId([
            'layout_id' => $layoutId,
            'version' => 1,
            'content' => $poisoned,
            'created_at' => now(),
        ]);
        $previewId = DB::table('template_layout_previews')->insertGetId([
            'token' => 'imgobj-preview-token',
            'template_id' => $this->templateId,
            'layout_name' => 'imgobj/preview',
            'preview_type' => 'layout',
            'content' => $poisoned,
            'admin_id' => 1,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame(
            $poisoned,
            DB::table('template_layout_versions')->where('id', $versionId)->value('content'),
            '과거 스냅샷을 고치면 「이전 버전으로 되돌리기」가 원본과 달라진다.'
        );
        $this->assertSame(
            $poisoned,
            DB::table('template_layout_previews')->where('id', $previewId)->value('content'),
            '미리보기 스냅샷도 백필 대상이 아니다.'
        );
    }

    /** @effects backfill_converts_soft_deleted_rows_for_restore_safety */
    public function test_24_soft_deleted_rows_are_converted(): void
    {
        $id = $this->seedLayout(
            ['components' => [['name' => 'Header', 'props' => ['logo' => $this->imageValue('/deleted.png')]]]],
            ['deleted_at' => now()],
        );

        $this->runMigration();

        $this->assertSame(
            '/deleted.png',
            $this->readContent($id)['components'][0]['props']['logo'],
            '삭제된 레이아웃은 복원 가능하므로 고치지 않으면 복원 시 결함이 되살아난다.'
        );
    }

    public function test_25_missing_table_is_skipped_without_error(): void
    {
        // 실제 `Schema::drop` 은 쓰지 않는다 — 이 테이블은 versions 테이블의 FK 대상이라
        // 드롭 자체가 실패하고, DDL 은 RefreshDatabase 트랜잭션을 암묵 커밋시켜 뒤따르는
        // 테스트까지 오염시킨다. 검증 대상은 `Schema::hasTable` 게이트이므로 존재하지 않는
        // 테이블명으로 같은 코드 경로를 직접 태운다.
        $ref = new ReflectionClass($this->migration);
        $method = $ref->getMethod('processTable');
        $method->setAccessible(true);

        $this->assertFalse(Schema::hasTable('g7_no_such_layout_table'));
        $method->invoke($this->migration, $this->context, 'g7_no_such_layout_table');

        // 예외 없이 통과 + 실제 테이블은 그대로다.
        $this->assertTrue(Schema::hasTable('template_layout_extensions'));
    }

    // ── ⑧ 변환 함수 직접 호출 (DB 없이 판정식만) ──────────────────────────

    public function test_26_transform_is_callable_directly_and_matches_db_path_results(): void
    {
        $ref = new ReflectionClass($this->migration);
        $method = $ref->getMethod('transform');
        $method->setAccessible(true);

        $call = function (array $input) use ($method) {
            $changed = 0;
            $args = [$input, false, &$changed, $this->context, 't', '1', ''];

            return [$method->invokeArgs($this->migration, $args), $changed];
        };

        // 케이스 1 — 축약
        [$out, $changed] = $call(['components' => [['props' => ['logo' => $this->imageValue()]]]]);
        $this->assertSame('/api/attachment/X', $out['components'][0]['props']['logo']);
        $this->assertSame(1, $changed);

        // 케이스 4 — style 자리 불변
        $style = ['backgroundImage' => 'url(/a.png)', 'backgroundSize' => 'cover'];
        [$out, $changed] = $call(['components' => [['props' => ['style' => $style]]]]);
        $this->assertSame($style, $out['components'][0]['props']['style']);
        $this->assertSame(0, $changed);

        // 케이스 9 — 정상 props 불변
        $props = ['className' => 'x', 'name' => 'user', 'size' => 'lg'];
        [$out, $changed] = $call(['components' => [['props' => $props]]]);
        $this->assertSame($props, $out['components'][0]['props']);
        $this->assertSame(0, $changed);
    }
}
