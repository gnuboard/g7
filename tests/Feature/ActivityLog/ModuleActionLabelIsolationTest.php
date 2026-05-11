<?php

namespace Tests\Feature\ActivityLog;

use App\Enums\ActivityLogType;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * 모듈 origin action 라벨이 자체 lang 으로 격리되어 코어 lang 을 오염시키지 않는지 검증.
 *
 * 5단계 fallback 전체 경로를 통합 시나리오로 회귀 차단:
 *   - sirsoft-ecommerce origin → 모듈 lang 의 라벨 표시
 *   - 코어 origin → 코어 lang 의 라벨 표시
 *   - 두 origin 의 라벨이 서로 다르더라도 충돌 없이 각자 영역 보존
 */
class ModuleActionLabelIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        App::setLocale('ko');
    }

    public function test_module_origin_action_resolves_from_module_lang_not_core_lang(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addLines([
            'activity_log.action.confirm' => '구매 확정',
        ], 'ko', 'sirsoft-ecommerce');
        Lang::addLines([
            'activity_log.action.confirm' => '코어가 사용해서는 안 되는 라벨',
        ], 'ko');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::User,
            'action' => 'order.confirm',
            'loggable_type' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Order',
            'loggable_id' => 1,
        ]);

        $this->assertSame('구매 확정', $log->action_label);
    }

    public function test_core_origin_action_unaffected_by_module_lang_namespace(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addLines([
            'activity_log.action.create' => '잘못된 모듈 라벨',
        ], 'ko', 'sirsoft-ecommerce');

        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.create',
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
        ]);

        // 코어 origin → 모듈 lang 무시 + 코어 lang 의 'create' => '생성' 매칭
        $this->assertSame('생성', $log->action_label);
    }

    public function test_two_modules_with_same_last_segment_keep_their_own_labels(): void
    {
        Lang::addNamespace('sirsoft-ecommerce', __DIR__);
        Lang::addNamespace('sirsoft-board', __DIR__);

        Lang::addLines(['activity_log.action.restore' => '주문 복원'], 'ko', 'sirsoft-ecommerce');
        Lang::addLines(['activity_log.action.restore' => '게시글 복원'], 'ko', 'sirsoft-board');

        $ecommerceLog = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'coupon.restore',
            'loggable_type' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Coupon',
            'loggable_id' => 1,
        ]);
        $boardLog = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'post.restore',
            'loggable_type' => 'Modules\\Sirsoft\\Board\\Models\\Post',
            'loggable_id' => 1,
        ]);

        $this->assertSame('주문 복원', $ecommerceLog->action_label);
        $this->assertSame('게시글 복원', $boardLog->action_label);
    }

    public function test_module_lang_missing_falls_back_to_core_lang(): void
    {
        // 모듈 lang 에 정의 없는 케이스 — 코어 lang fallback 으로 회귀 0
        $log = ActivityLog::create([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
        ]);

        $this->assertSame('수정', $log->action_label);
    }
}
