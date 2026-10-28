<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Listeners;

use App\Extension\HookManager;
use Plugins\Sirsoft\Popup\Listeners\ActivityLogDescriptionResolver;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 활동 로그 설명 해석기 테스트 (매니페스트 listeners.yaml — 이름 해석 케이스)
 *
 * 해석 순서: 기록 시점 스냅샷(`properties.name`) → DB 의 현재 관리용 제목 → 「ID: {id}」.
 */
class ActivityLogDescriptionResolverTest extends PluginTestCase
{
    /**
     * 이 플러그인 설명 키
     */
    private const KEY = 'sirsoft-popup::activity_log.description.popup_update';

    /**
     * 필터 훅으로 등록돼 코어 필터 호출이 해석기를 거친다.
     *
     * @scenario surface=resolver_registered_on_core_filter
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_registered_on_core_filter(): void
    {
        $params = HookManager::applyFilters('core.activity_log.filter_description_params', ['popup_id' => 7], self::KEY, ['name' => '가을 감사 이벤트']);

        $this->assertSame('가을 감사 이벤트', $params['popup_title']);
    }

    /**
     * 스냅샷 이름을 먼저 쓴다(지금 제목이 바뀌었어도 기록 시점 이름).
     *
     * @scenario surface=resolver_uses_snapshot_name
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_uses_snapshot_name(): void
    {
        $popup = Popup::factory()->create(['title' => '지금 제목']);

        $params = (new ActivityLogDescriptionResolver)->resolveDescriptionParams(['popup_id' => $popup->id], self::KEY, ['name' => '그때 제목']);

        $this->assertSame('그때 제목', $params['popup_title']);
    }

    /**
     * 스냅샷이 없으면 DB 의 지금 제목, 그것도 없으면 「ID: {id}」.
     *
     * @scenario surface=resolver_falls_back_to_db_then_id
     *
     * @effects activity_log_recorded
     *
     * @return void 반환값 없음
     */
    public function test_falls_back_to_db_then_id(): void
    {
        $popup = Popup::factory()->create(['title' => 'DB 제목']);
        $resolver = new ActivityLogDescriptionResolver;

        $this->assertSame('DB 제목', $resolver->resolveDescriptionParams(['popup_id' => $popup->id], self::KEY, [])['popup_title']);
        $this->assertSame('ID: 999999', $resolver->resolveDescriptionParams(['popup_id' => 999999], self::KEY, [])['popup_title']);
    }

    /**
     * 다른 확장의 설명 키와 이미 이름이 있는 파라미터는 건드리지 않는다.
     *
     * @scenario surface=resolver_ignores_other_extension_keys
     *
     * @return void 반환값 없음
     */
    public function test_ignores_other_extension_keys(): void
    {
        $resolver = new ActivityLogDescriptionResolver;

        $this->assertSame(['popup_id' => 1], $resolver->resolveDescriptionParams(['popup_id' => 1], 'sirsoft-board::activity_log.description.x', ['name' => '남의 것']));
        $this->assertSame('이미 있음', $resolver->resolveDescriptionParams(['popup_id' => 1, 'popup_title' => '이미 있음'], self::KEY, ['name' => '새 이름'])['popup_title']);
    }
}
