<?php

namespace Tests\Unit\Thumbnails;

use App\Extension\HookManager;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailUrl;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * 썸네일 srcset 조립 테스트 (`ThumbnailUrl::srcset`·`contentSrcset`)
 *
 * 프론트 `resources/js/core/support/thumbnailUrl.ts` 의 `thumbnailSrcset`·`contentSrcset` 과 같은 기대값이어야 한다 — 봇 화면과
 * 사람 화면이 다른 후보를 내면 같은 이미지의 파생본이 두 벌로 갈린다. 규격은 고정 기본표(xs 64×64 자르기·
 * sm 160×160 자르기·md 640·lg 1280·xl 1920 가로만, og 1200×630 자르기)로 고정한다
 * (`mem:core-settings-boot-config-leaks-into-unit-tests`).
 */
class ThumbnailUrlSrcsetTest extends TestCase
{
    /**
     * 규격·패턴·og 크기를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('core.thumbnail.presets', null);
        Config::set('core.thumbnail.enabled', true);
        Config::set('core.thumbnail.content_preset', 'lg');
        Config::set('attachment.image_quality', 85);
        Config::set('g7_settings.core.seo.og_image_default_width', 1200);
        Config::set('g7_settings.core.seo.og_image_default_height', 630);
        HookManager::clearFilter('core.thumbnail.filter_presets');
        HookManager::clearFilter('core.thumbnail.filter_url_patterns');
        HookManager::clearFilter('core.thumbnail.filter_url');
        app(ThumbnailPresetRegistry::class)->flush();
    }

    /**
     * 필터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        HookManager::clearFilter('core.thumbnail.filter_presets');
        HookManager::clearFilter('core.thumbnail.filter_url_patterns');
        HookManager::clearFilter('core.thumbnail.filter_url');
        app(ThumbnailPresetRegistry::class)->flush();

        parent::tearDown();
    }

    /**
     * 같은 맞춤·같은 비의 1.5배 이상 최소 규격이 2x 가 된다.
     *
     * @effects srcset_2x_is_smallest_same_shape_preset_at_one_and_half_times
     *
     * @return void 반환값 없음
     */
    public function test_srcset_picks_smallest_same_fit_candidate_at_least_one_and_half_times(): void
    {
        $url = '/api/attachment/abc';

        $this->assertSame('/api/attachment/abc?size=xs 1x, /api/attachment/abc?size=sm 2x', ThumbnailUrl::srcset($url, 'xs'));
        $this->assertSame('/api/attachment/abc?size=md 1x, /api/attachment/abc?size=lg 2x', ThumbnailUrl::srcset($url, 'md'));
        $this->assertSame('/api/attachment/abc?size=lg 1x, /api/attachment/abc?size=xl 2x', ThumbnailUrl::srcset($url, 'lg'));
    }

    /**
     * 비가 다른 자르기 규격(og)은 2x 후보가 아니다 — sm 은 1x 만.
     *
     * @effects srcset_2x_is_smallest_same_shape_preset_at_one_and_half_times
     *
     * @return void 반환값 없음
     */
    public function test_srcset_ignores_candidate_with_different_aspect_ratio(): void
    {
        $this->assertSame('/api/attachment/abc?size=sm 1x', ThumbnailUrl::srcset('/api/attachment/abc', 'sm'));
        $this->assertSame('/api/attachment/abc?size=xl 1x', ThumbnailUrl::srcset('/api/attachment/abc', 'xl'));
    }

    /**
     * 기존 쿼리(서명)는 원문 그대로, 기존 size 는 교체된다.
     *
     * @effects srcset_keeps_query_and_replaces_existing_size
     *
     * @return void 반환값 없음
     */
    public function test_srcset_keeps_signed_query_and_replaces_existing_size(): void
    {
        $srcset = ThumbnailUrl::srcset('/api/attachment/abc?expires=1&signature=a%2Bb&size=xl', 'md');

        $this->assertSame(
            '/api/attachment/abc?expires=1&signature=a%2Bb&size=md 1x, /api/attachment/abc?expires=1&signature=a%2Bb&size=lg 2x',
            $srcset
        );
    }

    /**
     * 대상이 아닌 주소·빈 규격·기능 꺼짐은 빈 문자열.
     *
     * @effects srcset_empty_for_non_target_or_disabled
     *
     * @return void 반환값 없음
     */
    public function test_srcset_is_empty_for_non_target(): void
    {
        $this->assertSame('', ThumbnailUrl::srcset('https://cdn.example.com/a.jpg', 'md'));
        $this->assertSame('', ThumbnailUrl::srcset('/storage/a.jpg', 'md'));
        $this->assertSame('', ThumbnailUrl::srcset('data:image/png;base64,AAAA', 'md'));
        $this->assertSame('', ThumbnailUrl::srcset('/api/attachment/abc', ''));

        Config::set('core.thumbnail.enabled', false);
        $this->assertSame('', ThumbnailUrl::srcset('/api/attachment/abc', 'md'));
    }

    /**
     * 모르는 규격은 1x 만 (서버가 원본으로 응답 — 후보를 지어내지 않는다).
     *
     * @effects srcset_unknown_preset_is_one_x_only
     *
     * @return void 반환값 없음
     */
    public function test_srcset_unknown_preset_is_one_x_only(): void
    {
        $this->assertSame('/api/attachment/abc?size=nope 1x', ThumbnailUrl::srcset('/api/attachment/abc', 'nope'));
    }

    /**
     * 본문 srcset 은 숫자 요청 대상 규격(md·lg·xl) 전부를 가로 기술자로 낸다.
     *
     * @effects content_srcset_lists_numeric_presets_by_width
     *
     * @return void 반환값 없음
     */
    public function test_content_srcset_lists_numeric_presets_with_width_descriptors(): void
    {
        $this->assertSame(
            '/api/attachment/abc?size=md 640w, /api/attachment/abc?size=lg 1280w, /api/attachment/abc?size=xl 1920w',
            ThumbnailUrl::contentSrcset('/api/attachment/abc')
        );
        $this->assertSame('', ThumbnailUrl::contentSrcset('https://cdn.example.com/a.jpg'));
        $this->assertSame('lg', ThumbnailUrl::contentPreset());
    }

    /**
     * 공백은 srcset 후보 구분자라 인코딩된다.
     *
     * @effects srcset_encodes_spaces_in_candidates
     *
     * @return void 반환값 없음
     */
    public function test_srcset_encodes_spaces(): void
    {
        $srcset = ThumbnailUrl::srcset('/api/templates/t/layout-attachments/a b/file', 'md');

        $this->assertSame(
            '/api/templates/t/layout-attachments/a%20b/file?size=md 1x, /api/templates/t/layout-attachments/a%20b/file?size=lg 2x',
            $srcset
        );
    }
}
