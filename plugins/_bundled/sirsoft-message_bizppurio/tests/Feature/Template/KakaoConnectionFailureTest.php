<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Template;

use App\Models\NotificationDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PluginSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Services\AlimtalkTemplateService;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 카카오 관리 API 연결 실패 응답 테스트.
 *
 * 비즈뿌리오 서버에 닿지 못하면(타임아웃, DNS, 프록시 실패) 예외가 ConnectionException 으로
 * 올라온다. 알림톡 편집 창의 분류·발신 프로필 조회와 템플릿 새로고침이 이것을 500 이 아니라
 * 연결 실패 안내 422 로 돌려주는지, 새로고침이면 행 상태를 그대로 두는지 검증한다. 템플릿 삭제는
 * 연결에 실패해도 DB 행을 지우고 200 과 사유로 끝나는지, 예약 동기화는 명령을 실패시키지 않고
 * 그 주기만 건너뛰는지도 본다.
 */
class KakaoConnectionFailureTest extends PluginTestCase
{
    private const REFERENCE_BASE = '/api/plugins/sirsoft-message_bizppurio/admin/alimtalk-templates';

    private const TEMPLATE_BASE = '/api/plugins/sirsoft-message_bizppurio/admin/templates';

    private const VIEW = 'sirsoft-message_bizppurio.messaging.view';

    private const MANAGE = 'sirsoft-message_bizppurio.messaging.manage';

    /**
     * 지정 권한을 가진 관리자를 만들고 토큰 헤더를 돌려줍니다.
     *
     * @param  array<int, string>  $permissionIds  부여할 권한 식별자
     * @return array<string, string> 인증 헤더
     */
    private function headersFor(array $permissionIds): array
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $testRole = Role::create([
            'identifier' => 'bizppurio_conn_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync(array_map(
            fn (string $identifier) => Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => json_encode(['ko' => $identifier, 'en' => $identifier]), 'type' => 'admin']
            )->id,
            $permissionIds,
        ));

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return [
            'Authorization' => 'Bearer '.$user->fresh()->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 참조 조회 서비스가 연결 실패를 던지도록 컨테이너에 바인딩합니다.
     *
     * @param  string  $method  실패시킬 서비스 메서드(categories, senderProfiles)
     * @return void 반환값 없음
     */
    private function failReferenceLookup(string $method): void
    {
        $mock = Mockery::mock(AlimtalkTemplateService::class);
        $mock->shouldReceive($method)->once()->andThrow(new ConnectionException('cURL error 7: Failed to connect'));
        $this->app->instance(AlimtalkTemplateService::class, $mock);
    }

    /**
     * 편집 창의 분류 조회가 연결에 실패하면 500 대신 연결 실패 안내 422 다.
     *
     * @scenario endpoint=categories, outcome=connection_failure
     *
     * @effects kapi_connection_failure_returns_422_not_500
     *
     * @return void 반환값 없음
     */
    public function test_분류_조회가_연결에_실패하면_500_대신_422다(): void
    {
        $this->failReferenceLookup('categories');

        $this->withHeaders($this->headersFor([self::VIEW]))
            ->getJson(self::REFERENCE_BASE.'/categories')
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-message_bizppurio::messages.error.connection_failed'));
    }

    /**
     * 편집 창의 발신 프로필 조회가 연결에 실패해도 같은 안내 422 다.
     *
     * @scenario endpoint=profiles, outcome=connection_failure
     *
     * @effects kapi_connection_failure_returns_422_not_500
     *
     * @return void 반환값 없음
     */
    public function test_발신_프로필_조회가_연결에_실패하면_500_대신_422다(): void
    {
        $this->failReferenceLookup('senderProfiles');

        $this->withHeaders($this->headersFor([self::VIEW]))
            ->getJson(self::REFERENCE_BASE.'/profiles')
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-message_bizppurio::messages.error.connection_failed'));
    }

    /**
     * 템플릿 새로고침이 연결에 실패하면 500 대신 안내 422 이고 행 상태는 그대로다.
     *
     * @scenario endpoint=sync, outcome=connection_failure
     *
     * @effects kapi_connection_failure_returns_422_not_500, lifecycle_state_unchanged_on_connection_failure
     *
     * @return void 반환값 없음
     */
    public function test_새로고침이_연결에_실패하면_500_대신_422이고_상태를_바꾸지_않는다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => fn () => throw new ConnectionException('Connection refused')]);
        app(PluginSettingsService::class)->save('sirsoft-message_bizppurio', [
            'bizppurio_id' => 'biz1',
            'api_key' => 'key1',
            'sender_key' => 'SK_TEST',
        ]);
        NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
        ]);
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'template_code' => 'g7_deadbeef_1',
            'sender_key' => 'SK_TEST',
            'content' => [
                'templateName' => '환영 알림톡',
                'templateMessageType' => 'BA',
                'templateEmphasizeType' => 'NONE',
                'templateContent' => '#{name}님 가입을 환영합니다.',
                'categoryCode' => '001001',
            ],
        ]);

        $this->withHeaders($this->headersFor([self::MANAGE]))
            ->postJson(self::TEMPLATE_BASE.'/'.$template->id.'/sync')
            ->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-message_bizppurio::messages.error.connection_failed'));

        $this->assertSame(BizppurioTemplateStatus::Requested, $template->fresh()->status);
    }

    /**
     * 템플릿 삭제는 카카오측 삭제가 연결에 실패해도 DB 행을 지우고 200 으로 끝난다.
     *
     * 삭제는 카카오측 실패가 있어도 DB 행을 지우고 그 사유를 응답에 적는 계약이다. 연결 실패도 같아야 한다.
     *
     * @scenario endpoint=delete, outcome=connection_failure
     *
     * @effects delete_removes_row_and_reports_reason_on_connection_failure
     *
     * @return void 반환값 없음
     */
    public function test_삭제는_카카오측_연결에_실패해도_행을_지우고_200이다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => fn () => throw new ConnectionException('Connection refused')]);
        app(PluginSettingsService::class)->save('sirsoft-message_bizppurio', [
            'bizppurio_id' => 'biz1',
            'api_key' => 'key1',
            'sender_key' => 'SK_TEST',
        ]);
        NotificationDefinition::create([
            'type' => 'welcome',
            'hook_prefix' => 'core.auth',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'variables' => [['key' => 'name', 'description' => '이름']],
            'channels' => ['mail'],
            'hooks' => ['core.auth.after_register'],
            'is_active' => true,
        ]);
        $template = BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'draft',
            'template_code' => 'g7_deadbeef_2',
            'sender_key' => 'SK_TEST',
            'content' => [
                'templateName' => '환영 알림톡',
                'templateMessageType' => 'BA',
                'templateEmphasizeType' => 'NONE',
                'templateContent' => '#{name}님 가입을 환영합니다.',
                'categoryCode' => '001001',
            ],
        ]);

        $this->withHeaders($this->headersFor([self::MANAGE]))
            ->deleteJson(self::TEMPLATE_BASE.'/'.$template->id)
            ->assertOk()
            ->assertJsonPath('data.kakao_deleted', false)
            ->assertJsonPath('data.kakao_skip_reason', __('sirsoft-message_bizppurio::messages.error.connection_failed'));

        $this->assertNull(BizppurioTemplate::find($template->id));
    }

    /**
     * 예약 동기화에 쓸 자격증명과 검수중 행을 만듭니다.
     *
     * @param  string  $templateCode  행의 템플릿 코드
     * @return BizppurioTemplate 검수중(requested) 행
     */
    private function seedRequestedRow(string $templateCode): BizppurioTemplate
    {
        app(PluginSettingsService::class)->save('sirsoft-message_bizppurio', [
            'bizppurio_id' => 'biz1',
            'api_key' => 'key1',
            'sender_key' => 'SK_TEST',
        ]);

        return BizppurioTemplate::create([
            'notification_type' => 'welcome',
            'status' => 'requested',
            'template_code' => $templateCode,
            'sender_key' => 'SK_TEST',
            'content' => ['templateContent' => '본문'],
        ]);
    }

    /**
     * 예약 동기화는 상태 목록 조회가 연결에 실패해도 명령을 실패시키지 않고 그 주기만 건너뛴다.
     *
     * 명령은 「항상 성공으로 끝나고 조회 실패는 다음 주기에 다시 본다」는 계약이다. 카카오가 실패 코드를 준
     * 경우만 잡고 연결 실패는 놓쳐, 30분마다 명령이 예외로 끝났다.
     *
     * @scenario endpoint=scheduled_sync, outcome=connection_failure
     *
     * @effects scheduled_sync_skips_cycle_on_connection_failure
     *
     * @return void 반환값 없음
     */
    public function test_예약_동기화는_목록_조회_연결이_끊겨도_명령을_실패시키지_않는다(): void
    {
        Http::fake(['kapi.ppurio.com/*' => fn () => throw new ConnectionException('Connection refused')]);
        $row = $this->seedRequestedRow('g7_deadbeef_3');

        $this->artisan('bizppurio:sync-template-status')->assertExitCode(0);

        $this->assertSame(BizppurioTemplateStatus::Requested, $row->fresh()->status);
    }

    /**
     * 예약 동기화는 반려 사유 조회가 연결에 실패해도 반려 전이를 마치고 사유만 비워 둔다.
     *
     * 반려 사유는 부가 정보라 조회 실패는 null 로 넘기는 계약이다(카카오 실패 코드와 같은 처리).
     *
     * @scenario endpoint=scheduled_sync, outcome=connection_failure
     *
     * @effects scheduled_sync_skips_cycle_on_connection_failure
     *
     * @return void 반환값 없음
     */
    public function test_예약_동기화는_반려_사유_조회_연결이_끊겨도_반려_전이를_마친다(): void
    {
        Http::fake([
            '*template/list*' => Http::response([
                'code' => '200', 'message' => 'ok',
                'data' => ['totalPage' => 1, 'list' => [['templateCode' => 'g7_deadbeef_4', 'serviceStatus' => 'REJ']]],
            ]),
            '*template/detail*' => fn () => throw new ConnectionException('Connection refused'),
        ]);
        $row = $this->seedRequestedRow('g7_deadbeef_4');

        $this->artisan('bizppurio:sync-template-status')->assertExitCode(0);

        $this->assertSame(BizppurioTemplateStatus::Rejected, $row->fresh()->status);
        $this->assertNull($row->fresh()->inspection_detail);
    }

    /**
     * Mockery 기대값을 닫습니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
