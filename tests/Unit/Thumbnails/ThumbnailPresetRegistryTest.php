<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ThumbnailPresetRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 썸네일 규격 레지스트리 테스트
 *
 * `?size=`·`?w=` 해석 표, 고정 슬롯(defaults.json)·og(SEO 탭 크기)·확장(필터)·운영자 규격의 병합,
 * 이름 규칙(예약어·형식·확장 이름)을 잰다. 저장된 규격 행과 SEO 크기는 운영자 저장값이 스며들지 않게
 * setUp 에서 고정한다(`mem:core-settings-boot-config-leaks-into-unit-tests`).
 */
class ThumbnailPresetRegistryTest extends TestCase
{
    /**
     * 저장 규격·og 크기·품질을 고정하고 규격 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.enabled', true);
        Config::set('attachment.image_quality', 85);
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);
        HookManager::clearFilter('core.thumbnail.filter_presets');
        app(ThumbnailPresetRegistry::class)->flush();
    }

    /**
     * 규격 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.thumbnail.filter_presets');

        parent::tearDown();
    }

    /**
     * 레지스트리 (요청 안 캐시를 비운 새 상태)
     *
     * @return ThumbnailPresetRegistry 레지스트리
     */
    private function registry(): ThumbnailPresetRegistry
    {
        $registry = app(ThumbnailPresetRegistry::class);
        $registry->flush();

        return $registry;
    }

    /**
     * 원문 쿼리로 요청을 만듭니다 (같은 키 두 번·배열 키를 그대로 싣는다).
     *
     * @param  string  $query  원문 쿼리
     * @return Request 요청
     */
    private function request(string $query): Request
    {
        return Request::create('/api/attachment/abc?'.$query);
    }

    /**
     * 해석 표 — 원문 쿼리 → 기대 규격 이름(null 이면 원본).
     *
     * @return array<string, array{0:string,1:?string}> 쿼리, 기대 규격
     */
    public static function resolveProvider(): array
    {
        return [
            'size=sm' => ['size=sm', 'sm'],
            'size=xs' => ['size=xs', 'xs'],
            'size=og' => ['size=og', 'og'],
            'w=300 → md(640)' => ['w=300', 'md'],
            'w=1 → md' => ['w=1', 'md'],
            'w=640 → md(같으면 그 규격)' => ['w=640', 'md'],
            'w=641 → lg' => ['w=641', 'lg'],
            'w=700 → lg(1280)' => ['w=700', 'lg'],
            'w=1281 → xl' => ['w=1281', 'xl'],
            'w=5000 → xl(최대로)' => ['w=5000', 'xl'],
            'w=10000 → xl' => ['w=10000', 'xl'],
            'size 가 w 보다 우선' => ['w=300&size=sm', 'sm'],
            'size=unknown' => ['size=unknown', null],
            'size=../../x' => ['size=..%2F..%2Fx', null],
            'size 빈 값' => ['size=', null],
            'size[]=x' => ['size[]=x', null],
            'size%5B%5D=x' => ['size%5B%5D=x', null],
            'size 두 번' => ['size=sm&size=md', null],
            'w 두 번' => ['w=300&w=700', null],
            'w=-1' => ['w=-1', null],
            'w=abc' => ['w=abc', null],
            'w=1.5' => ['w=1.5', null],
            'w=0' => ['w=0', null],
            'w=10001(상한 밖)' => ['w=10001', null],
            'w[]=300' => ['w[]=300', null],
            '파라미터 없음' => ['v=1', null],
        ];
    }

    /**
     * `size`·`w` 해석 표 전부.
     *
     * @effects preset_resolution_table_holds, invalid_params_fall_back_to_original
     *
     * @param  string  $query  원문 쿼리
     * @param  string|null  $expected  기대 규격 이름
     * @return void 반환값 없음
     */
    #[DataProvider('resolveProvider')]
    public function test_resolve_table(string $query, ?string $expected): void
    {
        $this->assertSame($expected, $this->registry()->resolve($this->request($query))?->name);
    }

    /**
     * 꺼진 규격은 이름으로도 숫자로도 해석되지 않는다.
     *
     * @effects preset_resolution_table_holds
     *
     * @return void 반환값 없음
     */
    public function test_disabled_presets_are_not_resolved(): void
    {
        Config::set('core.thumbnail.presets', [
            ['name' => 'sm', 'enabled' => false],
            ['name' => 'md', 'enabled' => false],
        ]);

        $registry = $this->registry();

        $this->assertNull($registry->resolve($this->request('size=sm')));
        $this->assertSame('lg', $registry->resolve($this->request('w=300'))?->name, '꺼진 md 를 건너뛰고 다음으로 올려 맞춘다');
        $this->assertNotNull($registry->find('sm'), 'find 는 꺼진 규격도 돌려준다(관리 화면용)');
        $this->assertNotContains('sm', array_map(fn (ThumbnailSpec $s) => $s->name, $registry->enabled()));
    }

    /**
     * 숫자 요청 대상이 하나도 없으면 `w` 는 원본이다.
     *
     * @effects preset_resolution_table_holds
     *
     * @return void 반환값 없음
     */
    public function test_numeric_requests_need_a_numeric_preset(): void
    {
        Config::set('core.thumbnail.presets', [
            ['name' => 'md', 'numeric' => false],
            ['name' => 'lg', 'numeric' => false],
            ['name' => 'xl', 'numeric' => false],
        ]);

        $this->assertNull($this->registry()->resolveNumeric(300));
    }

    /**
     * 고정 슬롯 기본표는 defaults.json 에서 온다(D33) — 순서·크기·맞춤·숫자 요청 대상.
     *
     * @effects fixed_slots_follow_defaults_json
     *
     * @return void 반환값 없음
     */
    public function test_fixed_slots_follow_the_defaults_table(): void
    {
        $specs = $this->registry()->all();
        $byName = [];

        foreach ($specs as $spec) {
            $byName[$spec->name] = $spec;
        }

        $this->assertSame(['xs', 'sm', 'md', 'lg', 'xl', 'og'], array_slice(array_keys($byName), 0, 6));
        $this->assertSame([64, 64, 'cover'], [$byName['xs']->width, $byName['xs']->height, $byName['xs']->fit]);
        $this->assertSame([160, 160, 'cover'], [$byName['sm']->width, $byName['sm']->height, $byName['sm']->fit]);
        $this->assertSame([640, null, 'width'], [$byName['md']->width, $byName['md']->height, $byName['md']->fit]);
        $this->assertSame([1280, 1920], [$byName['lg']->width, $byName['xl']->width]);
        $this->assertSame(['md', 'lg', 'xl'], array_values(array_map(
            fn (ThumbnailSpec $s) => $s->name,
            array_filter($specs, fn (ThumbnailSpec $s) => $s->numeric)
        )));

        foreach (ThumbnailPresetRegistry::FIXED_SLOTS as $slot) {
            $this->assertTrue($byName[$slot]->locked, "{$slot} 잠금");
            $this->assertSame('core', $byName[$slot]->source);
            $this->assertFalse($byName[$slot]->upscale);
            $this->assertFalse($byName[$slot]->pregenerate);
        }

        $this->assertCount(5, $this->registry()->fixedDefaults());
    }

    /**
     * 저장 행은 고정 슬롯의 값만 덮는다 — 이름·출처·잠금은 바뀌지 않고, 같은 이름 운영자 행은 추가되지 않는다.
     *
     * @effects saved_rows_overlay_values_only
     *
     * @return void 반환값 없음
     */
    public function test_saved_rows_overlay_fixed_slot_values_only(): void
    {
        Config::set('core.thumbnail.presets', [
            ['name' => 'sm', 'source' => 'custom', 'width' => 200, 'height' => 120, 'anchor' => 'top', 'quality' => 70, 'locked' => false],
            ['name' => 'sm', 'source' => 'custom', 'width' => 999],
        ]);

        $registry = $this->registry();
        $sm = $registry->find('sm');

        $this->assertSame([200, 120, 'top', 70], [$sm->width, $sm->height, $sm->anchor, $sm->quality]);
        $this->assertSame('core', $sm->source);
        $this->assertTrue($sm->locked);
        $this->assertCount(1, array_filter($registry->all(), fn (ThumbnailSpec $s) => $s->name === 'sm'), '같은 이름이 두 번 실렸다');
    }

    /**
     * 망가진 저장 행(가로 0)이 고정 슬롯을 지우지 않는다 — 기본값으로 되돌아간다.
     *
     * @effects saved_rows_overlay_values_only
     *
     * @return void 반환값 없음
     */
    public function test_broken_saved_row_falls_back_to_the_fixed_default(): void
    {
        Config::set('core.thumbnail.presets', [
            ['name' => 'sm', 'width' => 0],
            ['name' => 'md', 'width' => 'abc'],
        ]);

        $registry = $this->registry();

        $this->assertSame(160, $registry->find('sm')?->width);
        $this->assertSame(640, $registry->find('md')?->width);
        $this->assertSame('md', $registry->resolveNumeric(300)?->name);
    }

    /**
     * og 규격 크기는 SEO 탭 og 기본 크기를 따른다(D40) — 업로드 탭 저장 행으로 못 바꾼다.
     *
     * @effects og_preset_follows_seo_size
     *
     * @return void 반환값 없음
     */
    public function test_og_preset_follows_the_seo_tab_size(): void
    {
        Config::set('g7_settings.core.seo.og_image_default_width', 800);
        Config::set('g7_settings.core.seo.og_image_default_height', 400);
        Config::set('core.thumbnail.presets', [['name' => 'og', 'source' => 'custom', 'width' => 50, 'height' => 50]]);

        $og = $this->registry()->find(ThumbnailPresetRegistry::OG);

        $this->assertSame([800, 400, 'cover', 'center'], [$og->width, $og->height, $og->fit, $og->anchor]);
        $this->assertTrue($og->locked);
        $this->assertFalse($og->numeric, 'og 는 숫자 요청 대상이 아니다');

        // 값이 비면 1200×630 기본
        Config::set('g7_settings.core.seo.og_image_default_width', 0);
        Config::set('g7_settings.core.seo.og_image_default_height', null);
        $og = $this->registry()->find(ThumbnailPresetRegistry::OG);
        $this->assertSame([1200, 630], [$og->width, $og->height]);
    }

    /**
     * SEO 크기를 바꾸면 요청 안 캐시를 비우지 않아도 새 크기를 본다(캐시 기준에 og 크기가 들어 있다).
     *
     * @effects og_preset_follows_seo_size
     *
     * @return void 반환값 없음
     */
    public function test_registry_cache_follows_og_size_changes(): void
    {
        $registry = $this->registry();
        $this->assertSame(1200, $registry->find('og')?->width);

        Config::set('g7_settings.core.seo.og_image_default_width', 600);

        $this->assertSame(600, $registry->find('og')?->width);
    }

    /**
     * 확장 규격 — 이름 형식 `{vendor-name}.{key}` 만 받고, 출처는 이름 앞부분, 저장값이 값만 덮는다.
     *
     * @effects extension_presets_merged_by_filter, saved_rows_overlay_values_only
     *
     * @return void 반환값 없음
     */
    public function test_extension_presets_are_merged_through_the_filter(): void
    {
        HookManager::addFilter('core.thumbnail.filter_presets', fn (array $rows) => [
            ...$rows,
            ['name' => 'sirsoft-board.list', 'source' => 'core', 'width' => 300, 'height' => 200, 'fit' => 'cover', 'pregenerate' => true],
            ['name' => 'sirsoft-board.list', 'width' => 999],
            ['name' => 'board.list', 'width' => 100],
            ['name' => 'sirsoft-board.List', 'width' => 100],
            ['name' => 'sirsoft-board.', 'width' => 100],
            ['name' => 'sirsoft-board.nowidth'],
            ['name' => 'sm', 'width' => 10],
            'not-a-row',
        ]);
        Config::set('core.thumbnail.presets', [
            ['name' => 'sirsoft-board.list', 'width' => 360, 'source' => 'custom', 'name_override' => 'x'],
        ]);

        $registry = $this->registry();
        $defaults = $registry->extensionDefaults();

        $this->assertSame(['sirsoft-board.list'], array_keys($defaults));
        $this->assertSame(300, $defaults['sirsoft-board.list']['width'], '기본값은 필터가 준 값(되돌리기 근거)');
        $this->assertSame('sirsoft-board', $defaults['sirsoft-board.list']['source'], '출처는 이름 앞부분');

        $spec = $registry->find('sirsoft-board.list');
        $this->assertSame([360, 200, 'cover'], [$spec->width, $spec->height, $spec->fit], '저장값이 가로만 덮었다');
        $this->assertSame('sirsoft-board', $spec->source);
        $this->assertTrue($spec->locked);
        $this->assertSame(160, $registry->find('sm')?->width, '확장이 고정 슬롯 이름을 가로채지 못한다');
        $this->assertSame(['sirsoft-board.list'], array_map(fn (ThumbnailSpec $s) => $s->name, $registry->pregenerated()));
    }

    /**
     * 확장 필터가 빠지면(비활성) 그 규격이 사라지고, 다시 걸면 저장값이 되살아난다.
     *
     * @effects extension_presets_merged_by_filter
     *
     * @return void 반환값 없음
     */
    public function test_extension_presets_disappear_with_the_filter_and_return_with_saved_values(): void
    {
        Config::set('core.thumbnail.presets', [['name' => 'sirsoft-board.list', 'width' => 360]]);

        $this->assertNull($this->registry()->find('sirsoft-board.list'), '필터가 없으면 확장 규격도 없다');
        $this->assertSame([], array_values(array_filter(
            $this->registry()->all(),
            fn (ThumbnailSpec $s) => $s->name === 'sirsoft-board.list'
        )), '저장 행만으로 운영자 규격처럼 실리지 않는다');

        HookManager::addFilter('core.thumbnail.filter_presets', fn (array $rows) => [...$rows, ['name' => 'sirsoft-board.list', 'width' => 300]]);

        $this->assertSame(360, $this->registry()->find('sirsoft-board.list')?->width);
    }

    /**
     * 운영자 규격 — 형식·예약어를 지킨 행만, 같은 이름은 처음 것만 싣는다.
     *
     * @effects custom_preset_name_rules
     *
     * @return void 반환값 없음
     */
    public function test_custom_presets_follow_name_rules(): void
    {
        Config::set('core.thumbnail.presets', [
            ['name' => 'banner_wide', 'source' => 'custom', 'width' => 300, 'numeric' => true, 'locked' => true],
            ['name' => 'banner_wide', 'source' => 'custom', 'width' => 900],
            ['name' => 'Banner', 'source' => 'custom', 'width' => 300],
            ['name' => 'x', 'source' => 'custom', 'width' => 300],
            ['name' => '1abc', 'source' => 'custom', 'width' => 300],
            ['name' => 'og', 'source' => 'custom', 'width' => 300],
            ['name' => 'card', 'width' => 120],
        ]);

        $registry = $this->registry();
        $custom = array_values(array_filter($registry->all(), fn (ThumbnailSpec $s) => $s->source === 'custom'));

        $this->assertSame(['banner_wide', 'card'], array_map(fn (ThumbnailSpec $s) => $s->name, $custom));
        $this->assertSame(300, $custom[0]->width);
        $this->assertFalse($custom[0]->locked, '운영자 규격은 잠기지 않는다');
        $this->assertSame('banner_wide', $registry->resolve($this->request('w=200'))?->name, '숫자 요청 대상 운영자 규격도 올려 맞춤 후보');
        $this->assertSame('card', $registry->resolve($this->request('size=card'))?->name);
    }

    /**
     * 운영자 규격 이름 규칙 — 정규식과 예약어.
     *
     * @effects custom_preset_name_rules
     *
     * @return void 반환값 없음
     */
    public function test_custom_name_validation(): void
    {
        foreach (['ab', 'banner_wide', 'a1', str_repeat('a', 32)] as $ok) {
            $this->assertTrue(ThumbnailPresetRegistry::isValidCustomName($ok), $ok);
        }

        foreach ([...ThumbnailPresetRegistry::RESERVED, 'a', 'Ab', '1a', '_a', 'a-b', 'a.b', str_repeat('a', 33), ''] as $bad) {
            $this->assertFalse(ThumbnailPresetRegistry::isValidCustomName($bad), $bad);
        }

        $this->assertSame(1, preg_match(ThumbnailPresetRegistry::EXTENSION_NAME_PATTERN, 'sirsoft-ecommerce.product_card'));
        $this->assertSame(0, preg_match(ThumbnailPresetRegistry::EXTENSION_NAME_PATTERN, 'sirsoft.product'));
    }

    /**
     * 파이프 인자에서 리터럴로 바뀌는 낱말(true, false, null, undefined)은 운영자 규격 이름이 될 수 없고,
     * 저장 목록에 그런 행이 남아 있어도 레지스트리가 규격으로 올리지 않습니다.
     *
     * `thumb('true')` 는 파이프 해석이 따옴표를 벗긴 뒤 불리언으로 바꿔 규격 대신 원본을 낸다.
     *
     * @scenario layer=registry
     *
     * @effects literal_word_preset_name_rejected
     *
     * @return void 반환값 없음
     */
    public function test_pipe_literal_words_are_not_custom_names(): void
    {
        $this->assertSame(['true', 'false', 'null', 'undefined'], ThumbnailPresetRegistry::LITERAL_NAMES);

        foreach (ThumbnailPresetRegistry::LITERAL_NAMES as $word) {
            $this->assertSame(1, preg_match(ThumbnailPresetRegistry::CUSTOM_NAME_PATTERN, $word), "{$word} 는 형식만으로는 통과한다");
            $this->assertFalse(ThumbnailPresetRegistry::isValidCustomName($word), $word);
        }

        Config::set('core.thumbnail.presets', [
            ['name' => 'true', 'source' => 'custom', 'width' => 300],
            ['name' => 'banner', 'source' => 'custom', 'width' => 300],
        ]);

        $registry = $this->registry();
        $custom = array_values(array_filter($registry->all(), fn (ThumbnailSpec $s) => $s->source === 'custom'));

        $this->assertSame(['banner'], array_map(fn (ThumbnailSpec $s) => $s->name, $custom));
        $this->assertNull($registry->resolve($this->request('size=true')));
    }

    /**
     * 화면에 내보내는 목록은 켜진 규격의 이름·크기·맞춤·숫자 요청·형식만 싣는다.
     *
     * @effects frontend_preset_list_shape
     *
     * @return void 반환값 없음
     */
    public function test_frontend_list_shape(): void
    {
        Config::set('core.thumbnail.presets', [['name' => 'xs', 'enabled' => false]]);

        $list = $this->registry()->forFrontend();
        $names = array_column($list, 'name');

        $this->assertNotContains('xs', $names);
        $this->assertContains('og', $names);
        $this->assertSame(['name', 'width', 'height', 'fit', 'numeric', 'format'], array_keys($list[0]));
    }

    /**
     * 규격 지문은 결과에 영향을 주는 값과 엔진으로만 정해진다.
     *
     * @effects fingerprint_tracks_output_affecting_values
     *
     * @return void 반환값 없음
     */
    public function test_fingerprint_tracks_output_affecting_values_only(): void
    {
        $base = ThumbnailSpec::fromArray(['name' => 'card', 'width' => 300, 'height' => 200, 'fit' => 'cover']);
        $renamed = ThumbnailSpec::fromArray(['name' => 'other', 'width' => 300, 'height' => 200, 'fit' => 'cover', 'pregenerate' => true, 'numeric' => true]);
        $wider = ThumbnailSpec::fromArray(['name' => 'card', 'width' => 301, 'height' => 200, 'fit' => 'cover']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $base->fingerprint('gd'));
        $this->assertSame($base->fingerprint('gd'), $renamed->fingerprint('gd'));
        $this->assertNotSame($base->fingerprint('gd'), $wider->fingerprint('gd'));
        $this->assertNotSame($base->fingerprint('gd'), $base->fingerprint('imagick'));

        // 품질이 비면 업로드 탭 품질을 따른다 — 그 값이 바뀌면 지문도 바뀐다
        Config::set('attachment.image_quality', 60);
        $this->assertNotSame($renamed->fingerprint('gd'), ThumbnailSpec::fromArray(['name' => 'card', 'width' => 300, 'height' => 200, 'fit' => 'cover', 'quality' => 85])->fingerprint('gd'));
    }
}
