<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\PositionSettingRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Support\Positions\Placement;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/** 신규 설치·기설치 컬럼 보강과 실제 Repository의 결합을 확인합니다. */
class BannerEditorMigrationTest extends PluginTestCase
{
    /**
     * 시험 DB의 별도 접두 테이블에서 up→down→up과 기존 행 보존을 확인합니다.
     *
     * @scenario case=migration_roundtrip
     *
     * @effects editor_state_protected
     *
     * @return void 반환값 없음
     */
    public function test_migration_roundtrip_and_repository_smoke(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_editor_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h8_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_editor_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $repository = app(BannerRepositoryInterface::class);
            $banner = $repository->create($this->bannerPayload());
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_27_000004_add_presentation_and_lock_version_to_hero_section_banners.php';
            $migration->up();
            $this->assertTrue(Schema::hasColumn('hero_section_banners', 'lock_version'));
            $migration->down();
            $this->assertFalse(Schema::hasColumn('hero_section_banners', 'presentation'));
            $migration->up();
            $fresh = $repository->findForUpdate($banner->id);
            $this->assertSame(0, $fresh->lock_version);
            $this->assertNull($fresh->presentation);
            $updated = $repository->update($fresh, ['presentation' => ['mobile_x' => 80]]);
            $this->assertSame(1, $updated->lock_version);
            $this->assertSame(['mobile_x' => 80], $updated->presentation);
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_editor_smoke');
        }
    }

    /**
     * 유튜브 제거 마이그레이션은 기존 유튜브 배너를 이미지 배너로 바꾸고 `youtube_id` 컬럼을 지운다 — 대표 이미지(PC 이미지)는
     * 유튜브 배너에도 필수였으므로 그대로 보인다. 두 번 올려도 오류가 없고, 내리면 빈 컬럼만 되살린다(바꾼 종류는 되돌리지 않는다).
     *
     * @scenario case=youtube_removal_migration
     *
     * @return void 반환값 없음
     */
    public function test_youtube_removal_migration_turns_youtube_banners_into_images(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_yt_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h8_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_yt_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_09_000009_remove_youtube_from_hero_section_banners.php';
            $this->assertFalse(Schema::hasColumn('hero_section_banners', 'youtube_id'));

            $migration->down();
            $this->assertTrue(Schema::hasColumn('hero_section_banners', 'youtube_id'));
            $row = ['position' => 'home_hero', 'title' => '{"ko":"유튜브"}', 'created_at' => now(), 'updated_at' => now()];
            $youtube = DB::table('hero_section_banners')->insertGetId($row + ['media_type' => 'youtube', 'youtube_id' => 'dQw4w9WgXcQ']);
            $video = DB::table('hero_section_banners')->insertGetId($row + ['media_type' => 'video']);

            $migration->up();
            $migration->up();
            $this->assertFalse(Schema::hasColumn('hero_section_banners', 'youtube_id'));
            $this->assertSame('image', DB::table('hero_section_banners')->where('id', $youtube)->value('media_type'));
            $this->assertSame('video', DB::table('hero_section_banners')->where('id', $video)->value('media_type'));
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_yt_smoke');
        }
    }

    /**
     * 미디어 컬럼 마이그레이션의 up→down→up 에서 기존 배너·이미지 행이 이미지로 남고, 영상 관계를 읽는 Repository 가 그대로 돈다.
     *
     * @scenario case=media_migration_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_media_migration_roundtrip_keeps_existing_rows_as_image(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_media_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h9_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_media_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_30_000006_add_media_to_hero_section_banners.php';
            $migration->down();
            $this->assertFalse(Schema::hasColumn('hero_section_banners', 'media_type'));
            $this->assertFalse(Schema::hasColumn('hero_section_banner_images', 'kind'));

            // 미디어 컬럼이 없던 때의 행 — 다시 올리면 이미지로 채워진다
            $bannerId = DB::table('hero_section_banners')->insertGetId(['position' => 'home_hero', 'title' => '{"ko":"기존"}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('hero_section_banner_images')->insert(['banner_id' => $bannerId, 'device' => 'pc', 'hash' => str_repeat('a', 64), 'path' => 'x.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'created_at' => now(), 'updated_at' => now()]);

            $migration->up();
            $migration->up();
            $this->assertSame('image', DB::table('hero_section_banners')->where('id', $bannerId)->value('media_type'));
            $this->assertNull(DB::table('hero_section_banners')->where('id', $bannerId)->value('youtube_id'));
            $this->assertSame('image', DB::table('hero_section_banner_images')->where('banner_id', $bannerId)->value('kind'));

            $banner = app(BannerRepositoryInterface::class)->findForUpdate($bannerId);
            $this->assertNotNull($banner->pcImage);
            $this->assertNull($banner->pcVideo);
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_media_smoke');
        }
    }

    /**
     * 머리글·메뉴 겹치기 마이그레이션의 up→down→up 에서 기존 배너는 머리글 없음(보임·흰색), 기존 위치는 메뉴 겹치기 꺼짐으로 채워지고
     * 위치 설정을 읽는 Repository 가 그대로 돈다.
     *
     * @scenario case=text_layout_migration_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_eyebrow_overlay_migration_roundtrip_keeps_existing_rows(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_text_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h7_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_text_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_31_000007_add_eyebrow_and_header_overlay_to_hero_section.php';
            $migration->down();
            foreach (['eyebrow', 'eyebrow_visible', 'eyebrow_color'] as $column) {
                $this->assertFalse(Schema::hasColumn('hero_section_banners', $column));
            }
            $this->assertFalse(Schema::hasColumn('hero_section_position_settings', 'header_overlay'));

            // 머리글·겹치기 컬럼이 없던 때의 행
            $bannerId = DB::table('hero_section_banners')->insertGetId(['position' => 'home_hero', 'title' => '{"ko":"기존"}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('hero_section_position_settings')->insert(['position' => 'home_hero', 'autoplay' => 1, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'full', 'created_at' => now(), 'updated_at' => now()]);

            $migration->up();
            $migration->up();
            $row = DB::table('hero_section_banners')->where('id', $bannerId)->first();
            $this->assertNull($row->eyebrow);
            $this->assertSame(1, (int) $row->eyebrow_visible);
            $this->assertSame('#FFFFFF', $row->eyebrow_color);

            $setting = app(PositionSettingRepositoryInterface::class)->findByPosition('home_hero');
            $this->assertFalse($setting->toSettings()['header_overlay']);
            $this->assertSame('full', $setting->toSettings()['width']);
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_text_smoke');
        }
    }

    /**
     * 페이징 모양 마이그레이션의 up→down→up 에서 기존 위치 설정은 점(dots)으로 채워지고 위치 설정을 읽는 Repository 가 그대로 돈다.
     *
     * @scenario case=pagination_migration_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_pagination_migration_roundtrip_keeps_existing_rows(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_page_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h8_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_page_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_02_000008_add_pagination_to_hero_section_position_settings.php';
            $migration->down();
            $this->assertFalse(Schema::hasColumn('hero_section_position_settings', 'pagination'));

            // 페이징 컬럼이 없던 때의 행
            DB::table('hero_section_position_settings')->insert(['position' => 'shop_hero', 'autoplay' => 1, 'interval_seconds' => 7, 'effect' => 'fade', 'width' => 'full', 'header_overlay' => 1, 'created_at' => now(), 'updated_at' => now()]);

            $migration->up();
            $migration->up();
            $setting = app(PositionSettingRepositoryInterface::class)->findByPosition('shop_hero');
            $this->assertSame('dots', $setting->toSettings()['pagination']);
            $this->assertSame(['autoplay' => true, 'interval_seconds' => 7, 'effect' => 'fade', 'width' => 'full', 'header_overlay' => true, 'pagination' => 'dots', 'max_height_pc' => null, 'max_height_mobile' => null], $setting->toSettings());
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_page_smoke');
        }
    }

    /**
     * 최대 높이 마이그레이션의 up→down→up 에서 기존 위치 설정은 비운 채(null, 비율대로) 남고, 두 번 올려도 오류가 없으며 위치 설정을 읽는
     * Repository·저장이 그대로 돈다.
     *
     * @scenario case=max_height_migration_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_max_height_migration_roundtrip_keeps_existing_rows(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_height_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h11_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_height_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_11_000011_add_max_height_to_hero_section_position_settings.php';
            $migration->down();
            $this->assertFalse(Schema::hasColumn('hero_section_position_settings', 'max_height_pc'));
            $this->assertFalse(Schema::hasColumn('hero_section_position_settings', 'max_height_mobile'));

            // 최대 높이 열이 없던 때의 행
            DB::table('hero_section_position_settings')->insert(['position' => 'home_hero', 'autoplay' => 1, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'full', 'header_overlay' => 0, 'pagination' => 'tabs', 'created_at' => now(), 'updated_at' => now()]);

            $migration->up();
            $migration->up();
            $repository = app(PositionSettingRepositoryInterface::class);
            $setting = $repository->findByPosition('home_hero');
            $this->assertSame(['autoplay' => true, 'interval_seconds' => 5, 'effect' => 'slide', 'width' => 'full', 'header_overlay' => false, 'pagination' => 'tabs', 'max_height_pc' => null, 'max_height_mobile' => null], $setting->toSettings());

            $saved = $repository->update($setting, ['max_height_pc' => 480, 'max_height_mobile' => 2000])->toSettings();
            $this->assertSame([480, 2000], [$saved['max_height_pc'], $saved['max_height_mobile']]);
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_height_smoke');
        }
    }

    /**
     * 대상 키 마이그레이션의 up→down→up — 기존 배너는 대상 null(정적 위치)로 남고, 순서·노출 후보 인덱스가 위치 + 대상으로 바뀌며,
     * 내리면 옛 위치 인덱스가 돌아온다. 두 번 올려도 오류가 없고, 자리 단위 Repository 조회가 그대로 돈다.
     *
     * @scenario case=target_key_migration_roundtrip
     *
     * @return void 반환값 없음
     */
    public function test_target_key_migration_roundtrip(): void
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.hero_target_smoke' => array_replace(config('database.connections.'.$original), [
            'prefix' => 'h10_'.bin2hex(random_bytes(3)).'_', 'prefix_indexes' => true,
        ])]);
        DB::setDefaultConnection('hero_target_smoke');
        try {
            foreach (glob(dirname(__DIR__, 3).'/database/migrations/*.php') as $file) {
                (require $file)->up();
            }
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_11_10_000010_add_target_key_to_hero_section_banners.php';
            $this->assertTrue(Schema::hasIndex('hero_section_banners', 'hero_banners_placement_order_idx'));
            $this->assertFalse(Schema::hasIndex('hero_section_banners', 'hero_banners_position_order_idx'));

            $migration->down();
            $this->assertFalse(Schema::hasColumn('hero_section_banners', 'target_key'));
            $this->assertTrue(Schema::hasIndex('hero_section_banners', 'hero_banners_position_order_idx'));
            $this->assertTrue(Schema::hasIndex('hero_section_banners', 'hero_banners_exposure_idx'));
            $this->assertFalse(Schema::hasIndex('hero_section_banners', 'hero_banners_placement_exposure_idx'));

            // 대상 열이 없던 때의 배너 — 다시 올리면 정적 위치(대상 null)로 남는다
            $bannerId = DB::table('hero_section_banners')->insertGetId(['position' => 'home_hero', 'title' => '{"ko":"기존"}', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $migration->up();
            $migration->up();
            $this->assertTrue(Schema::hasColumn('hero_section_banners', 'target_key'));
            $this->assertNull(DB::table('hero_section_banners')->where('id', $bannerId)->value('target_key'));
            $this->assertTrue(Schema::hasIndex('hero_section_banners', 'hero_banners_placement_exposure_idx'));
            $this->assertFalse(Schema::hasIndex('hero_section_banners', 'hero_banners_exposure_idx'));

            $repository = app(BannerRepositoryInterface::class);
            $this->assertSame([$bannerId], $repository->idsForPlacement(Placement::of('home_hero')));
            $this->assertSame(1, $repository->nextSortOrder(Placement::of('home_hero')));
            $this->assertSame(0, $repository->nextSortOrder(Placement::of('board_hero', '2')));
        } finally {
            foreach (['hero_section_banner_images', 'hero_section_position_settings', 'hero_section_banners'] as $table) {
                Schema::dropIfExists($table);
            }
            DB::setDefaultConnection($original);
            DB::purge('hero_target_smoke');
        }
    }
}
