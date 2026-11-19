<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 동적 위치(게시판마다·상품마다·카테고리마다)의 대상 키를 추가하고, 순서·노출 후보 인덱스에 대상을 넣습니다.
     * 기존 배너는 모두 정적 위치(사이트 홈·쇼핑몰 메인)라 대상이 null 이다 — 채울 값이 없다.
     * 인덱스를 대상 포함으로 바꾸는 이유: 상품 상세처럼 대상이 많은 위치에서 한 대상의 배너를 찾을 때 위치 전체를 훑지 않게.
     * 위치만 거는 조회(정적 위치·등록되지 않은 위치)는 새 인덱스의 앞 열로 그대로 탄다.
     *
     * @return void 반환값 없음
     */
    public function up(): void
    {
        if (! Schema::hasColumn('hero_section_banners', 'target_key')) {
            Schema::table('hero_section_banners', function (Blueprint $table) {
                $table->string('target_key', 100)->nullable()->after('position')->comment('동적 위치의 대상 키 (게시판·상품·카테고리 id 등, 정적 위치는 null)');
            });
        }

        Schema::table('hero_section_banners', function (Blueprint $table) {
            if (! Schema::hasIndex('hero_section_banners', 'hero_banners_placement_order_idx')) {
                $table->index(['position', 'target_key', 'sort_order'], 'hero_banners_placement_order_idx');
            }
            if (! Schema::hasIndex('hero_section_banners', 'hero_banners_placement_exposure_idx')) {
                $table->index(['position', 'target_key', 'is_active', 'display_period', 'starts_at', 'ends_at'], 'hero_banners_placement_exposure_idx');
            }
        });

        Schema::table('hero_section_banners', function (Blueprint $table) {
            if (Schema::hasIndex('hero_section_banners', 'hero_banners_position_order_idx')) {
                $table->dropIndex('hero_banners_position_order_idx');
            }
            if (Schema::hasIndex('hero_section_banners', 'hero_banners_exposure_idx')) {
                $table->dropIndex('hero_banners_exposure_idx');
            }
        });
    }

    /**
     * 위치 인덱스를 되살리고 대상 인덱스·컬럼을 지웁니다.
     *
     * @return void 반환값 없음
     */
    public function down(): void
    {
        Schema::table('hero_section_banners', function (Blueprint $table) {
            if (! Schema::hasIndex('hero_section_banners', 'hero_banners_position_order_idx')) {
                $table->index(['position', 'sort_order'], 'hero_banners_position_order_idx');
            }
            if (! Schema::hasIndex('hero_section_banners', 'hero_banners_exposure_idx')) {
                $table->index(['position', 'is_active', 'display_period', 'starts_at', 'ends_at'], 'hero_banners_exposure_idx');
            }
        });

        Schema::table('hero_section_banners', function (Blueprint $table) {
            foreach (['hero_banners_placement_order_idx', 'hero_banners_placement_exposure_idx'] as $index) {
                if (Schema::hasIndex('hero_section_banners', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        if (Schema::hasColumn('hero_section_banners', 'target_key')) {
            Schema::table('hero_section_banners', fn (Blueprint $table) => $table->dropColumn('target_key'));
        }
    }
};
