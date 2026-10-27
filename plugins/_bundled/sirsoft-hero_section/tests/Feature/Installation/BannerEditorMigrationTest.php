<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Installation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerRepositoryInterface;
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
}
