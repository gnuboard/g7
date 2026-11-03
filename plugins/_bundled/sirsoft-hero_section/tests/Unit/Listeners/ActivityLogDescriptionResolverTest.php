<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Plugins\Sirsoft\HeroSection\Listeners\ActivityLogDescriptionResolver;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 활동 로그 설명 해석기 테스트
 *
 * 이 플러그인의 설명 키만 처리하고, 기록 시점 제목 스냅샷 → 「ID: {id}」 순으로 이름을 채우는지 확인한다.
 * 훅 체인은 PluginTestCase 가 실제로 등록한 필터를 통해 부른다(목 사용 안 함).
 */
class ActivityLogDescriptionResolverTest extends PluginTestCase
{
    /**
     * 이 플러그인 키 접두
     */
    private const KEY = 'sirsoft-hero_section::activity_log.description.hero_banner_update';

    /**
     * 필터 훅으로 등록돼 있어 코어 필터 호출이 해석기를 거치는지 확인합니다.
     *
     * @scenario surface=registered_on_core_filter
     *
     * @effects banner_title_resolved
     *
     * @return void 반환값 없음
     */
    public function test_resolver_is_registered_on_core_filter(): void
    {
        $params = HookManager::applyFilters(
            'core.activity_log.filter_description_params',
            ['banner_id' => 7],
            self::KEY,
            ['name' => '가을 신상품']
        );

        $this->assertSame('가을 신상품', $params['banner_title']);
    }

    /**
     * 다국어 제목 스냅샷은 현재 언어 값으로 고르는지 확인합니다.
     *
     * @scenario surface=localized_snapshot_current_locale
     *
     * @effects banner_title_resolved
     *
     * @return void 반환값 없음
     */
    public function test_localized_snapshot_uses_current_locale(): void
    {
        app()->setLocale('en');

        $params = (new ActivityLogDescriptionResolver)->resolveDescriptionParams(
            ['banner_id' => 3],
            self::KEY,
            ['name' => ['ko' => '가을 신상품', 'en' => 'Autumn arrivals']]
        );

        $this->assertSame('Autumn arrivals', $params['banner_title']);
    }

    /**
     * 스냅샷이 없으면 「ID: {id}」 로 채우는지 확인합니다.
     *
     * @scenario surface=falls_back_to_id
     *
     * @effects banner_title_resolved
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_id_without_snapshot(): void
    {
        $params = (new ActivityLogDescriptionResolver)->resolveDescriptionParams(['banner_id' => 12], self::KEY, []);

        $this->assertSame('ID: 12', $params['banner_title']);
    }

    /**
     * 다른 확장의 설명 키는 건드리지 않는지 확인합니다.
     *
     * @scenario surface=ignores_other_extension_keys
     *
     * @effects banner_title_resolved
     *
     * @return void 반환값 없음
     */
    public function test_ignores_other_extension_keys(): void
    {
        $params = (new ActivityLogDescriptionResolver)->resolveDescriptionParams(
            ['banner_id' => 1],
            'sirsoft-ecommerce::activity_log.description.product_update',
            ['name' => 'x']
        );

        $this->assertSame(['banner_id' => 1], $params);
    }

    /**
     * 이미 이름이 채워졌으면 덮어쓰지 않는지 확인합니다.
     *
     * @scenario surface=keeps_already_resolved_title
     *
     * @effects banner_title_resolved
     *
     * @return void 반환값 없음
     */
    public function test_keeps_already_resolved_title(): void
    {
        $params = (new ActivityLogDescriptionResolver)->resolveDescriptionParams(
            ['banner_id' => 1, 'banner_title' => '앞선 필터 값'],
            self::KEY,
            ['name' => '스냅샷']
        );

        $this->assertSame('앞선 필터 값', $params['banner_title']);
    }
}
