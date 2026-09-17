<?php

namespace Tests\Feature\Analytics;

use App\Extension\HookManager;
use App\Listeners\CoreActivityLogListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 활동 로그 — 통계 운영 훅 9종이 관리자 활동으로 남는다.
 *
 * 설정 변경·삭제·익명화·정보주체 처리는 되돌릴 수 없거나 법적 의미가 있는 결정이다.
 * 누가 언제 했는지가 활동 로그에 없으면 나중에 아무도 답할 수 없다.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /** 계획서 §4.4 가 정한 훅 9종 */
    private const HOOKS = [
        'settings_updated',
        'privacy_mode_changed',
        'data_purged',
        'anonymized',
        'subject_exported',
        'subject_deleted',
        'opt_out_changed',
        'archive_requested',
        'extension_purged',
    ];

    /**
     * 9훅 전부가 리스너에 구독돼 있고 발화하면 활동 로그 행이 남는다.
     *
     * @scenario visitor=admin
     *
     * @effects activity_log_records_analytics_hooks_as_admin
     *
     * @return void 없음
     */
    public function test_nine_hooks_write_admin_activity_rows(): void
    {
        $subscribed = CoreActivityLogListener::getSubscribedHooks();

        foreach (self::HOOKS as $action) {
            $hook = 'core.analytics.'.$action;

            $this->assertArrayHasKey($hook, $subscribed, $hook.' 구독이 없다');
            $this->assertSame('handleAnalyticsHook', $subscribed[$hook]['method']);

            HookManager::doAction($hook, ['changes' => ['privacy_mode' => 'anonymous'], 'visitor_key' => 'abcd']);

            $row = DB::table('activity_logs')->where('action', 'analytics.'.$action)->first();

            $this->assertNotNull($row, $hook.' 발화가 활동 로그 행을 남기지 않았다');
            $this->assertSame('admin', $row->log_type, $hook.' 은 관리자 활동으로 남아야 한다(콘솔·워커 발화 포함)');
            $this->assertSame('activity_log.analytics.'.$action, $row->description_key);
        }

        $this->assertSame(count(self::HOOKS), DB::table('activity_logs')->where('action', 'like', 'analytics.%')->count());
    }

    /**
     * 요청 경로가 관리자 API 가 아니어도(콘솔 맥락) 관리자 활동으로 남는다.
     *
     * @scenario visitor=admin, dispatch=queue
     *
     * @effects activity_log_records_analytics_hooks_as_admin
     *
     * @return void 없음
     */
    public function test_log_type_is_admin_outside_admin_request_path(): void
    {
        // 테스트의 request() 는 경로가 '/' 라 트레이트 폴백이면 system 이 된다.
        HookManager::doAction('core.analytics.archive_requested', ['run_type' => 'hour']);

        $this->assertSame('admin', DB::table('activity_logs')->where('action', 'analytics.archive_requested')->value('log_type'));
    }
}
