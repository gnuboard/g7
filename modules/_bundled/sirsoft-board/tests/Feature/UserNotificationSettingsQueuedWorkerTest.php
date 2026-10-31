<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Services\UserService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 회원 추가 — 큐 워커 환경에서도 게시판 알림 설정이 저장된다 (#138 후속 전수 점검 4번)
 *
 * 생성 데이터 필터가 알림 설정을 세션에 넣고, 생성 후 액션(큐)이 그 세션에서 꺼내 저장했다. 워커는 웹 요청과
 * 다른 프로세스라 그 세션이 없어 설정이 조용히 빠졌다. 생성 후 액션은 원본 요청 데이터를 인자로 받으므로
 * 세션을 거치지 않고 그 인자에서 읽는다.
 */
#[Group('board')]
#[Group('listener')]
class UserNotificationSettingsQueuedWorkerTest extends ModuleTestCase
{
    /**
     * 큐를 database 로 바꿔 생성 후 액션이 워커에서 돌게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    /**
     * 알림 설정을 켜고 회원을 만들면 워커가 돈 뒤 그 설정이 저장돼 있다.
     *
     * @scenario case=queued_worker_create
     *
     * @effects notification_settings_saved_without_session
     *
     * @return void 반환값 없음
     */
    public function test_notification_settings_are_saved_when_after_create_runs_on_worker(): void
    {
        $user = app(UserService::class)->createUser([
            'name' => '알림 회원',
            'email' => 'notify-'.uniqid().'@example.com',
            'password' => 'Password1234!',
            'notify_post_complete' => true,
            'notify_comment' => true,
        ]);

        $this->assertGreaterThan(0, DB::table('jobs')->count(), '생성 후 액션이 큐에 쌓이지 않았다 — 워커 경로를 재지 못한다');

        // 실제 워커는 별도 프로세스라 웹 요청의 세션이 없다
        session()->flush();
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스(수백 MB)가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        $setting = UserNotificationSetting::where('user_id', $user->id)->first();

        $this->assertNotNull($setting, '관리자가 켠 게시판 알림 설정이 저장되지 않았다');
        $this->assertTrue((bool) $setting->notify_post_complete);
        $this->assertTrue((bool) $setting->notify_comment);
        $this->assertFalse((bool) $setting->notify_post_reply, '미전송 체크박스는 false 로 저장된다');
    }

    /**
     * 알림 필드 없이 회원을 만들면 설정 행을 만들지 않는다.
     *
     * @scenario case=queued_worker_no_fields
     *
     * @effects no_settings_row_without_notification_fields
     *
     * @return void 반환값 없음
     */
    public function test_no_settings_row_when_notification_fields_are_absent(): void
    {
        $user = app(UserService::class)->createUser([
            'name' => '알림 없는 회원',
            'email' => 'plain-'.uniqid().'@example.com',
            'password' => 'Password1234!',
        ]);

        session()->flush();
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스(수백 MB)가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        $this->assertNull(UserNotificationSetting::where('user_id', $user->id)->first());
    }
}
