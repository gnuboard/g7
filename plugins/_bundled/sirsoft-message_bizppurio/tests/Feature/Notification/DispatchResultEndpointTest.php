<?php

namespace Plugins\Sirsoft\MessageBizppurio\Tests\Feature\Notification;

use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioDispatch;
use Plugins\Sirsoft\MessageBizppurio\Tests\PluginTestCase;

/**
 * 코어 알림 발송 이력 결과 조회 엔드포인트 테스트 (A-2 표시 주입).
 *
 * 코어 "알림 발송 이력" 화면에 얹은 결과 컬럼이 현재 페이지의 코어 알림 로그 id 배열을 넘겨
 * 비즈뿌리오 결과(상태·사유·잔액부족·대체발송)를 한 번에 조회한다. 조회는 messaging.view 권한을
 * 요구하고, 매칭되지 않는 로그 id 는 결과 맵에서 빠진다(빈 셀).
 *
 * @scenario dispatch-result-injection
 */
class DispatchResultEndpointTest extends PluginTestCase
{
    private const URL = '/api/plugins/sirsoft-message_bizppurio/admin/dispatch-results/lookup';

    /**
     * 지정 권한을 가진 admin 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissionIds  부여할 권한 식별자
     * @return User 권한이 부여된 관리자 사용자
     */
    private function adminWith(array $permissionIds): User
    {
        $user = User::factory()->create();

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'type' => 'admin']
        );

        $permIds = [];
        foreach ($permissionIds as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => json_encode(['ko' => $identifier, 'en' => $identifier]), 'type' => 'admin']
            );
            $permIds[] = $permission->id;
        }

        $testRole = Role::create([
            'identifier' => 'bizppurio_result_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트', 'en' => 'Test']),
            'type' => 'admin',
        ]);
        $testRole->permissions()->sync($permIds);

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($testRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 사용자 토큰 헤더를 만듭니다.
     *
     * @param  User  $user  토큰을 발급할 사용자
     * @return array<string, string> Authorization 과 Accept 요청 헤더
     */
    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    /**
     * 코어 알림 로그 1건 + 연결된 dispatch 1건을 만들고 로그 id 를 반환합니다.
     *
     * @param  array<string, mixed>  $dispatchAttrs  dispatch 오버라이드
     * @return int 생성한 코어 알림 로그 id
     */
    private function seedLinkedLog(array $dispatchAttrs = []): int
    {
        $log = NotificationLog::create([
            'channel' => 'sms',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => '01011112222',
            'recipient_name' => '홍길동',
            'status' => 'sent',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        BizppurioDispatch::create(array_merge([
            'refkey' => 'rk_'.uniqid(),
            'channel' => 'sms',
            'to_number' => '01011112222',
            'content' => '본문',
            'notification_type' => 'welcome',
            'notification_log_id' => $log->id,
            'status' => 'success',
            'result_code' => '4100',
            'source' => 'auto',
            'sent_at' => now(),
        ], $dispatchAttrs));

        return (int) $log->id;
    }

    private const RECENT_URL = '/api/plugins/sirsoft-message_bizppurio/admin/dispatch-results/recent';

    /**
     * 토큰 없이 결과 조회 API 를 부르면 401 로 막히는지 확인한다.
     *
     * @effects lookup_rejects_unauthenticated_with_401
     *
     * @return void 반환값 없음
     */
    public function test_인증_없이_조회는_401이다(): void
    {
        $this->postJson(self::URL, ['notification_log_ids' => [1]])->assertStatus(401);
    }

    /**
     * messaging.view 권한이 없는 관리자가 최근 결과 조회 API 를 부르면 403 인지 확인한다.
     *
     * @effects recent_lookup_requires_messaging_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_recent_는_view_권한_없으면_403이다(): void
    {
        $user = $this->adminWith([]);

        $this->getJson(self::RECENT_URL, $this->authHeaders($user))->assertStatus(403);
    }

    /**
     * 최근 결과 조회 API 가 파라미터 없이도 연결된 로그 id 를 키로 한 결과 맵(상태와 결과코드)을 돌려주는지 확인한다.
     *
     * @scenario channel=sms, result_category=success, link_outcome=linked
     * @effects recent_lookup_returns_result_map_without_params
     *
     * @return void 반환값 없음
     */
    public function test_recent_는_파라미터_없이_최근_결과맵을_반환한다(): void
    {
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);
        $logId = $this->seedLinkedLog(['result_code' => '4100']);

        $result = $this->getJson(self::RECENT_URL, $this->authHeaders($user))
            ->assertOk()
            ->json("data.results.{$logId}");

        $this->assertSame('success', $result['status']);
        $this->assertSame('4100', $result['result_code']);
    }

    /**
     * messaging.view 권한이 없는 관리자가 로그 id 로 결과를 조회하면 403 인지 확인한다.
     *
     * @effects lookup_requires_messaging_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_view_권한_없으면_403이다(): void
    {
        $user = $this->adminWith([]); // 권한 없음
        $logId = $this->seedLinkedLog();

        $this->postJson(self::URL, ['notification_log_ids' => [$logId]], $this->authHeaders($user))
            ->assertStatus(403);
    }

    /**
     * notification_log_ids 키 없이 조회하면 422 와 그 필드 검증 오류가 나는지 확인한다.
     *
     * @effects lookup_requires_log_ids_array
     *
     * @return void 반환값 없음
     */
    public function test_로그_id_키가_아예_없으면_422다(): void
    {
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);

        $this->postJson(self::URL, [], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notification_log_ids']);
    }

    /**
     * 로그 id 빈 배열을 보내면 422 가 아니라 200 과 빈 결과 맵을 돌려주는지 확인한다(이력 0건 화면 회귀).
     *
     * @effects lookup_empty_log_ids_returns_200_with_empty_map
     *
     * @return void 반환값 없음
     */
    public function test_빈_배열은_200과_빈_결과맵을_반환한다(): void
    {
        // 코어 알림 발송 이력이 0건(또는 아직 미로드)일 때 화면은 빈 배열을 보낸다.
        // 이 경우 422 가 아니라 200 + 빈 결과 맵이어야 화면이 깨지지 않는다(회귀: 브라우저 실측).
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);

        $response = $this->postJson(self::URL, ['notification_log_ids' => []], $this->authHeaders($user))
            ->assertOk();

        $this->assertSame([], $response->json('data.results'));
    }

    /**
     * 연결된 성공 발송의 결과가 로그 id 키 아래 상태 success, 결과코드 4100, 잔액부족 아님으로 담기는지 확인한다.
     *
     * @scenario channel=sms, result_category=success, link_outcome=linked
     * @effects lookup_returns_results_keyed_by_log_id
     *
     * @return void 반환값 없음
     */
    public function test_성공_결과를_로그id_키_맵으로_반환한다(): void
    {
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);
        $logId = $this->seedLinkedLog();

        $response = $this->postJson(self::URL, ['notification_log_ids' => [$logId]], $this->authHeaders($user))
            ->assertOk();

        $result = $response->json("data.results.{$logId}");
        $this->assertSame('success', $result['status']);
        $this->assertSame('4100', $result['result_code']);
        $this->assertFalse($result['is_low_balance']);
    }

    /**
     * 음영지역 실패(4400) 결과가 상태 failed 와 코드가 들어간 사유 라벨로 반환되는지 확인한다.
     *
     * @scenario channel=sms, result_category=failure, link_outcome=linked
     * @effects lookup_failure_result_includes_reason_code_label
     *
     * @return void 반환값 없음
     */
    public function test_실패_음영지역은_사유_코드_라벨을_반환한다(): void
    {
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);
        $logId = $this->seedLinkedLog(['status' => 'failed', 'result_code' => '4400']);

        $result = $this->postJson(self::URL, ['notification_log_ids' => [$logId]], $this->authHeaders($user))
            ->assertOk()
            ->json("data.results.{$logId}");

        // result_label 은 `사유 (코드)` 형식 — result_codes lang 에 4400 정의가 있으면 사유가 붙는다.
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('4400', (string) $result['result_label']);
    }

    /**
     * 알림톡 잔액부족 코드(7436) 결과에 is_low_balance 가 참으로 담기는지 확인한다.
     *
     * @scenario channel=alimtalk, result_category=balance_low, link_outcome=linked
     * @effects balance_low_result_flags_is_low_balance_true
     *
     * @return void 반환값 없음
     */
    public function test_잔액부족_코드는_is_low_balance_true다(): void
    {
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);
        $logId = $this->seedLinkedLog(['channel' => 'alimtalk', 'status' => 'failed', 'result_code' => '7436']);

        $result = $this->postJson(self::URL, ['notification_log_ids' => [$logId]], $this->authHeaders($user))
            ->assertOk()
            ->json("data.results.{$logId}");

        $this->assertTrue($result['is_low_balance']);
    }

    /**
     * dispatch 가 연결되지 않은 메일 로그 id 는 결과 맵에서 빠지고 연결된 로그만 남는지 확인한다.
     *
     * @scenario link_outcome=unmatched
     * @effects unmatched_log_id_excluded_from_result_map
     *
     * @return void 반환값 없음
     */
    public function test_매칭되지_않는_로그id는_결과맵에서_빠진다(): void
    {
        $user = $this->adminWith(['sirsoft-message_bizppurio.messaging.view']);
        $linkedId = $this->seedLinkedLog();

        // 연결되지 않은 코어 로그(메일 등) — dispatch 없음
        $mailLog = NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => 'a@b.com',
            'status' => 'sent',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        $results = $this->postJson(
            self::URL,
            ['notification_log_ids' => [$linkedId, $mailLog->id]],
            $this->authHeaders($user)
        )->assertOk()->json('data.results');

        $this->assertArrayHasKey((string) $linkedId, $results);
        $this->assertArrayNotHasKey((string) $mailLog->id, $results);
    }
}
