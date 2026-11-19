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

    /**
     * 목록 한 줄에서 자리를 알아보게 위치 키를 위치 이름으로 바꾸고, 동적 위치면 기록 시점 대상 이름을 덧붙이는지 확인합니다.
     * 대상 이름 스냅샷이 없으면(기록 때 이미 지워진 대상) 대상 키를 쓰고, 정적 위치·예전 기록은 덧붙이지 않습니다.
     *
     * @scenario surface=placement_named_in_summary
     *
     * @effects banner_title_resolved
     *
     * @return void 반환값 없음
     */
    public function test_summary_names_position_and_target(): void
    {
        $resolver = new ActivityLogDescriptionResolver;
        $key = 'sirsoft-hero_section::activity_log.description.';

        $created = $resolver->resolveDescriptionParams(['banner_id' => 1, 'position' => 'board_hero', 'target_key' => '2'], $key.'hero_banner_create', ['name' => '가을 신상품', 'target' => '자유게시판']);
        $this->assertSame('히어로섹션 배너 「가을 신상품」을(를) 등록했습니다. (대상: 자유게시판)', __($key.'hero_banner_create', $created));

        $gone = $resolver->resolveDescriptionParams(['banner_id' => 1, 'position' => 'board_hero', 'target_key' => '10'], $key.'hero_banner_delete', ['name' => '가을 신상품', 'target' => null]);
        $this->assertSame('히어로섹션 배너 「가을 신상품」을(를) 삭제했습니다. (대상: 10)', __($key.'hero_banner_delete', $gone));

        $reorder = $resolver->resolveDescriptionParams(['position' => 'board_hero', 'count' => 2, 'target_key' => '2'], $key.'hero_banner_reorder', ['target' => '자유게시판']);
        $this->assertSame('히어로섹션 게시판 목록 위치의 배너 2개 순서를 바꿨습니다. (대상: 자유게시판)', __($key.'hero_banner_reorder', $reorder));

        $static = $resolver->resolveDescriptionParams(['position' => 'home_hero'], $key.'hero_position_update', []);
        $this->assertSame('히어로섹션 사이트 홈 위치의 슬라이더 설정을 바꿨습니다.', __($key.'hero_position_update', $static));

        // 대상 키가 없는 예전 기록에 자리 표시(:target_note)가 그대로 남지 않는다
        $old = $resolver->resolveDescriptionParams(['banner_id' => 1, 'position' => 'home_hero'], $key.'hero_banner_bulk_hide', ['name' => '가을 신상품']);
        $this->assertSame('히어로섹션 배너 「가을 신상품」을(를) 비노출로 바꿨습니다.', __($key.'hero_banner_bulk_hide', $old));

        // 위치를 더한 확장이 꺼져 이름을 모르는 위치는 키를 그대로 둔다
        $this->assertSame('gone_hero', $resolver->resolveDescriptionParams(['position' => 'gone_hero'], $key.'hero_position_update', [])['position']);
    }

    /**
     * 「이 대상 설정」 저장·되돌리기 로그가 대상 키(게시판 id 등) 대신 기록 시점 대상 이름을 보이는지 확인합니다.
     *
     * 종전에는 문구가 `:target_key` 를 그대로 끼워 「게시판 목록 위치의 대상 5 슬라이더 설정을 바꿨습니다」로 보였다.
     * 이름 스냅샷이 없는 기록(대상 해석 실패·지워진 대상)은 대상 키를 그대로 둔다.
     *
     * @scenario surface=target_setting_names_target
     *
     * @effects internal_keys_shown_as_names
     *
     * @return void 반환값 없음
     */
    public function test_target_setting_logs_name_the_target(): void
    {
        $resolver = new ActivityLogDescriptionResolver;
        $key = 'sirsoft-hero_section::activity_log.description.';

        $update = $resolver->resolveDescriptionParams(['position' => 'board_hero', 'target_key' => '5'], $key.'hero_target_setting_update', ['target' => 'Q&A']);
        $this->assertSame('히어로섹션 게시판 목록 위치의 대상 Q&A 슬라이더 설정을 바꿨습니다.', __($key.'hero_target_setting_update', $update));

        $reset = $resolver->resolveDescriptionParams(['position' => 'board_hero', 'target_key' => '5'], $key.'hero_target_setting_reset', ['target' => 'Q&A']);
        $this->assertSame('히어로섹션 게시판 목록 위치의 대상 Q&A 를 위치 기본값으로 되돌렸습니다.', __($key.'hero_target_setting_reset', $reset));

        $unknown = $resolver->resolveDescriptionParams(['position' => 'gone_hero', 'target_key' => '9'], $key.'hero_target_setting_reset', []);
        $this->assertSame('9', $unknown['target_key']);
    }

    /**
     * 기기별 이미지·영상 교체·삭제 로그가 기기 키(pc·mobile) 대신 기기 이름을 보이는지 확인합니다.
     *
     * @scenario surface=device_named_in_summary
     *
     * @effects internal_keys_shown_as_names
     *
     * @return void 반환값 없음
     */
    public function test_device_logs_name_the_device(): void
    {
        $resolver = new ActivityLogDescriptionResolver;
        $key = 'sirsoft-hero_section::activity_log.description.';

        $image = $resolver->resolveDescriptionParams(['banner_id' => 1, 'device' => 'mobile'], $key.'hero_banner_image_update', ['name' => '가을 신상품']);
        $this->assertSame('히어로섹션 배너 「가을 신상품」의 모바일 이미지를 교체했습니다.', __($key.'hero_banner_image_update', $image));

        $video = $resolver->resolveDescriptionParams(['banner_id' => 1, 'device' => 'pc'], $key.'hero_banner_video_remove', ['name' => '가을 신상품']);
        $this->assertSame('히어로섹션 배너 「가을 신상품」의 PC 영상을 뺐습니다.', __($key.'hero_banner_video_remove', $video));

        // 모르는 기기 키는 그대로 둔다
        $this->assertSame('tablet', $resolver->resolveDescriptionParams(['banner_id' => 1, 'device' => 'tablet'], $key.'hero_banner_image_update', ['name' => 'x'])['device']);
    }
}
