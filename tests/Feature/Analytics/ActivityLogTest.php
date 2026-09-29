<?php

namespace Tests\Feature\Analytics;

use App\Extension\HookManager;
use App\Listeners\CoreActivityLogListener;
use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    /** 계획서 §4.4 가 정한 훅 9종 — 파생 모집단이 이것을 모두 품는지(하한)만 본다 */
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
     * 리스너가 실제로 구독한 통계 훅 전부(모집단 파생).
     *
     * 손으로 적은 목록만 돌면 열 번째 훅이 구독에 더해져도 그 훅의 로그 행·설명 키를 아무도 재지
     * 않는다. 구독 맵에서 파생하고, 위 9종은 빠지면 안 되는 하한으로만 쓴다.
     *
     * @return array<int, string> `core.analytics.` 뒤의 이름 목록
     */
    private function subscribedActions(): array
    {
        $actions = [];

        foreach (CoreActivityLogListener::getSubscribedHooks() as $hook => $config) {
            if (str_starts_with($hook, 'core.analytics.') && ($config['method'] ?? null) === 'handleAnalyticsHook') {
                $actions[] = substr($hook, strlen('core.analytics.'));
            }
        }

        return $actions;
    }

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
        $actions = $this->subscribedActions();

        $this->assertNotEmpty($actions);
        $this->assertSame([], array_values(array_diff(self::HOOKS, $actions)), '§4.4 의 훅이 구독에서 빠졌다');

        foreach ($actions as $action) {
            $hook = 'core.analytics.'.$action;

            $this->assertArrayHasKey($hook, $subscribed, $hook.' 구독이 없다');
            $this->assertSame('handleAnalyticsHook', $subscribed[$hook]['method']);

            HookManager::doAction($hook, ['changes' => ['privacy_mode' => 'anonymous'], 'visitor_key' => 'abcd']);

            $row = DB::table('activity_logs')->where('action', 'analytics.'.$action)->first();

            $this->assertNotNull($row, $hook.' 발화가 활동 로그 행을 남기지 않았다');
            $this->assertSame('admin', $row->log_type, $hook.' 은 관리자 활동으로 남아야 한다(콘솔·워커 발화 포함)');
            $this->assertSame('activity_log.analytics.'.$action, $row->description_key);
        }

        $this->assertSame(count($actions), DB::table('activity_logs')->where('action', 'like', 'analytics.%')->count());
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

    /**
     * 큐 워커가 도는 운영 환경에서도 감사 기록이 남는다.
     *
     * 테스트 환경의 큐는 sync 라 큐로 보낸 리스너도 발화 자리에서 곧바로 돈다. 운영 기본값
     * (database)에서는 워커가 훅 바깥에서 돌려 「어느 훅이었는지」를 잃는다 — 큐를 막아 둔
     * 채로 발화해 그 차이를 드러낸다. 훅 목록은 리스너 구독에서 뽑는다.
     *
     * @scenario visitor=admin, dispatch=queue
     *
     * @effects activity_log_survives_queued_listener_dispatch
     *
     * @return void 없음
     */
    public function test_analytics_hooks_are_logged_even_when_the_queue_is_not_immediate(): void
    {
        $hooks = array_values(array_filter(
            array_keys(CoreActivityLogListener::getSubscribedHooks()),
            fn (string $hook): bool => str_starts_with($hook, 'core.analytics.'),
        ));

        $this->assertNotEmpty($hooks, '통계 활동 로그 훅 구독을 하나도 찾지 못했다');

        Queue::fake();

        foreach ($hooks as $hook) {
            HookManager::doAction($hook, ['run_type' => 'hour']);

            $this->assertTrue(
                DB::table('activity_logs')->where('action', 'analytics.'.substr($hook, strlen('core.analytics.')))->exists(),
                $hook.' 이 큐에 넘어가 워커에서 훅 이름을 잃는다',
            );
        }
    }

    /**
     * 설명 문구의 자리표시자가 발화부가 넘기는 값으로 모두 채워진다.
     *
     * 발화부의 키 이름(`to`·`extension_identifier`·배열 `subject`)이 문구의 자리표시자와
     * 달라 활동 로그 화면에 `(:mode)` 같은 글자가 그대로 보이던 자리를 잠근다. 자리표시자를
     * 가진 문구는 언어 파일에서 뽑으므로 새 문구가 생기면 여기 발화 예시가 없어 red 가 된다.
     *
     * @scenario visitor=admin
     *
     * @effects activity_log_descriptions_fill_every_placeholder
     *
     * @return void 없음
     */
    public function test_every_description_placeholder_is_filled(): void
    {
        // 발화부(SettingsController·DataSubjectService·ExtensionPurgeService·ArchiveRunController)가
        // 실제로 넘기는 모양 그대로.
        $payloads = [
            'privacy_mode_changed' => ['from' => 'full', 'to' => 'anonymous'],
            'subject_exported' => ['subject' => ['ip', 'from'], 'visits' => 2],
            'subject_deleted' => ['subject' => ['user_id'], 'counts' => ['visits' => 1]],
            'extension_purged' => ['extension_type' => null, 'extension_identifier' => 'sirsoft-board', 'counts' => [], 'failed' => false],
            'archive_requested' => ['run_type' => 'hour', 'from' => null, 'to' => null, 'triggered_by' => 1],
        ];

        $withPlaceholders = array_keys(array_filter(
            (array) trans('activity_log.analytics', [], 'ko'),
            fn ($text): bool => is_string($text) && preg_match('/:[a-z_]+/', $text) === 1,
        ));

        $this->assertNotEmpty($withPlaceholders, '자리표시자를 가진 통계 활동 로그 문구를 찾지 못했다');

        foreach ($withPlaceholders as $action) {
            $this->assertArrayHasKey($action, $payloads, $action.' 의 발화 예시가 없다 — 자리표시자가 채워지는지 잴 수 없다');

            HookManager::doAction('core.analytics.'.$action, $payloads[$action]);

            foreach (['ko', 'en'] as $locale) {
                app()->setLocale($locale);
                $description = ActivityLog::query()->where('action', 'analytics.'.$action)->latest('id')->firstOrFail()->localized_description;

                $this->assertDoesNotMatchRegularExpression('/:[a-z_]+/', $description, $action.' ('.$locale.') 설명에 자리표시자가 남았다: '.$description);
            }
        }
    }

    /**
     * 활동 로그 목록의 「동작」 칸이 통계 훅 전부에서 번역된 라벨로 보인다 — 원문 키(analytics.xxx)로 새지 않는다.
     *
     * 설명 문구(activity_log.analytics.*)만 있고 동작 라벨(activity_log.action.analytics.*)이 없어, 목록의
     * 동작 칸에 `analytics.extension_purged` 같은 원문 키가 그대로 보였다(#138 실측 ⑧).
     *
     * @scenario visitor=admin
     *
     * @effects analytics_action_labels_are_translated
     *
     * @return void 없음
     */
    public function test_every_analytics_action_has_a_translated_label(): void
    {
        foreach ($this->subscribedActions() as $action) {
            $log = new ActivityLog(['action' => 'analytics.'.$action]);

            foreach (['ko', 'en'] as $locale) {
                app()->setLocale($locale);

                $this->assertNotSame('analytics.'.$action, $log->action_label, "analytics.{$action} ({$locale}) 동작 라벨이 원문 키로 보인다");
            }
        }
    }
}
