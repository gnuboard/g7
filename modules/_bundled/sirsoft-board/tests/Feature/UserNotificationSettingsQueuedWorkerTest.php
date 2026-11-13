<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Contracts\Repositories\FieldDefinitionRepositoryInterface;
use App\Extension\Helpers\FieldDefinitionSyncHelper;
use App\Extension\ModuleManager;
use App\Services\UserService;
use Illuminate\Support\Facades\Artisan;
use Modules\Sirsoft\Board\Models\UserNotificationSetting;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 회원 추가 — 큐 워커 환경에서도 게시판 알림 설정이 저장된다 (#138 후속 전수 점검 4번)
 *
 * 예전에는 생성 후 액션(큐)이 알림 설정을 저장해, 워커가 돌기 전에는 값이 없었고 세션에 기대던 때에는 조용히 빠졌다.
 * 지금은 알림 4칸이 회원 항목이라 코어가 회원 생성과 같은 트랜잭션에서 값 핸들러로 저장한다 — 워커와 세션에 기대지 않는다.
 * 워커가 돈 뒤에도 값이 덮이거나 지워지지 않아야 한다.
 */
#[Group('board')]
#[Group('listener')]
class UserNotificationSettingsQueuedWorkerTest extends ModuleTestCase
{
    /**
     * 큐를 database 로 바꾸고 모듈의 항목 선언을 동기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);

        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionSyncHelper::class)->sync('module', 'sirsoft-board', (new Module)->getFields());
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();
    }

    /**
     * 모듈 상태 캐시와 항목 정의 메모를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        ModuleManager::invalidateModuleStatusCache();
        app(FieldDefinitionRepositoryInterface::class)->forgetMemo();

        parent::tearDown();
    }

    /**
     * 알림 설정을 켜고 회원을 만들면 워커가 돌기 전에 이미 저장돼 있고, 워커가 돈 뒤에도 그대로다.
     *
     * @scenario case=queued_worker_create
     *
     * @effects notification_settings_saved_without_session
     *
     * @return void 반환값 없음
     */
    public function test_notification_settings_are_saved_in_request_and_survive_worker(): void
    {
        $user = app(UserService::class)->createUser([
            'name' => '알림 회원',
            'email' => 'notify-'.uniqid().'@example.com',
            'password' => 'Password1234!',
            'notify_post_complete' => true,
            'notify_comment' => true,
        ]);

        $before = UserNotificationSetting::where('user_id', $user->id)->first();
        $this->assertNotNull($before, '관리자가 켠 게시판 알림 설정이 회원 생성과 함께 저장되지 않았다');
        $this->assertTrue((bool) $before->notify_post_complete);
        $this->assertTrue((bool) $before->notify_comment);

        // 실제 워커는 별도 프로세스라 웹 요청의 세션이 없다
        session()->flush();
        // 워커 기본 메모리 한도(128MB)는 테스트 프로세스(수백 MB)가 이미 넘어, 첫 작업 뒤 워커가 멈추고 나머지 작업이 남는다
        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--memory' => 4096]);

        $setting = UserNotificationSetting::where('user_id', $user->id)->first();

        $this->assertNotNull($setting);
        $this->assertTrue((bool) $setting->notify_post_complete);
        $this->assertTrue((bool) $setting->notify_comment);
        $this->assertFalse((bool) $setting->notify_post_reply, '보내지 않은 칸은 기본값(false)이다');
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
