<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 알림 발송 로그 검색대상·다중 필터 검증.
 *
 * 화면은 검색대상(전체/수신자/제목)과 상태·확장 유형 다중 선택을 보내지만, 서버 게이트가
 * `search_type` 을 선언하지 않아 validated() 가 버렸고, 상태·확장 유형은 스칼라만 받아 화면이
 * 첫 값(`[0]`)만 보내고 있었다. 검색대상을 "제목"으로 골라도 수신자 이름이 같은 행까지 나오고,
 * 상태를 두 개 골라도 하나만 적용됐다.
 *
 * 모든 행은 이 테스트 전용 notification_type 으로 만들고 요청마다 그 값으로 좁혀, 같은 DB 의
 * 다른 행과 섞이지 않는다.
 *
 * 시나리오 매니페스트: tests/scenarios/notification-log-search-filter.yaml
 */
class NotificationLogSearchFilterTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 이 테스트가 만드는 행의 알림 유형.
     */
    private const TYPE = 'i17_s7_search_filter';

    /**
     * 알림 로그 읽기 권한을 가진 관리자를 만듭니다.
     *
     * @return User 생성된 관리자
     */
    private function makeAdmin(): User
    {
        $user = User::factory()->create(['is_super' => true]);

        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.notification-logs.read'],
            [
                'name' => ['ko' => '알림 발송 이력 조회', 'en' => 'Read notification logs'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]
        );

        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    /**
     * 이 테스트 전용 발송 로그 한 건을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 컬럼 값
     * @return NotificationLog 생성된 로그
     */
    private function makeLog(array $attributes): NotificationLog
    {
        return NotificationLog::create(array_merge([
            'channel' => 'mail',
            'notification_type' => self::TYPE,
            'recipient_identifier' => 'someone@example.com',
            'recipient_name' => '보통 수신자',
            'subject' => '보통 제목',
            'status' => 'sent',
            'extension_type' => 'core',
        ], $attributes));
    }

    /**
     * 이 테스트 전용 행으로 좁힌 목록을 조회합니다.
     *
     * @param  string  $query  추가 쿼리 문자열
     * @return TestResponse 응답
     */
    private function list(string $query): TestResponse
    {
        $token = $this->makeAdmin()->createToken('test')->plainTextToken;

        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/admin/notification-logs?notification_type='.self::TYPE.'&'.$query);
    }

    /**
     * 응답 행의 id 목록을 정렬해 반환합니다.
     *
     * @param  TestResponse  $response  목록 응답
     * @return array<int, int> 정렬된 id 목록
     */
    private function ids(TestResponse $response): array
    {
        $ids = array_map('intval', array_column($response->json('data.data') ?? [], 'id'));
        sort($ids);

        return $ids;
    }

    /**
     * 검색대상 "제목" 은 제목만 찾고, 수신자 이름이 같은 행은 고르지 않는다.
     *
     * @scenario case=search_subject
     *
     * @effects search_scoped_to_selected_field
     *
     * @return void 반환값 없음
     */
    public function test_검색대상_제목은_제목만_찾는다(): void
    {
        $bySubject = $this->makeLog(['subject' => '주문 완료 안내']);
        $this->makeLog(['recipient_name' => '주문 담당자']);

        $response = $this->list('search=주문&search_type=subject');

        $response->assertOk();
        $this->assertSame([$bySubject->id], $this->ids($response));
    }

    /**
     * 검색대상 "수신자" 는 수신자 이름·식별자만 찾는다.
     *
     * @scenario case=search_recipient
     *
     * @effects search_scoped_to_selected_field
     *
     * @return void 반환값 없음
     */
    public function test_검색대상_수신자는_수신자만_찾는다(): void
    {
        $byName = $this->makeLog(['recipient_name' => '주문 담당자']);
        $byIdentifier = $this->makeLog(['recipient_identifier' => 'order@example.com']);
        $this->makeLog(['subject' => '주문 완료 안내']);

        $response = $this->list('search=order&search_type=recipient');
        $this->assertSame([$byIdentifier->id], $this->ids($response->assertOk()));

        $response = $this->list('search=주문&search_type=recipient');
        $this->assertSame([$byName->id], $this->ids($response->assertOk()));
    }

    /**
     * 검색대상 "전체"(또는 생략)는 수신자·제목을 함께 찾는다 — 기존 동작 보존.
     *
     * @scenario case=search_all
     *
     * @effects search_scoped_to_selected_field
     *
     * @return void 반환값 없음
     */
    public function test_검색대상_전체는_수신자와_제목을_함께_찾는다(): void
    {
        $bySubject = $this->makeLog(['subject' => '주문 완료 안내']);
        $byName = $this->makeLog(['recipient_name' => '주문 담당자']);
        $this->makeLog([]);

        $expected = [$bySubject->id, $byName->id];
        sort($expected);

        $this->assertSame($expected, $this->ids($this->list('search=주문&search_type=all')->assertOk()));
        $this->assertSame($expected, $this->ids($this->list('search=주문')->assertOk()));
    }

    /**
     * 허용하지 않는 검색대상은 422 로 거절한다.
     *
     * @scenario case=search_type_invalid
     *
     * @effects invalid_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_허용하지_않는_검색대상은_거절한다(): void
    {
        $this->list('search=x&search_type=password')->assertStatus(422)
            ->assertJsonValidationErrors('search_type');
    }

    /**
     * 상태를 여러 개 고르면 고른 상태 전부가 적용된다.
     *
     * @scenario case=status_multi
     *
     * @effects multi_value_filter_applied
     *
     * @return void 반환값 없음
     */
    public function test_상태_다중_선택은_모두_적용된다(): void
    {
        $sent = $this->makeLog(['status' => 'sent']);
        $failed = $this->makeLog(['status' => 'failed']);
        $this->makeLog(['status' => 'skipped']);

        $expected = [$sent->id, $failed->id];
        sort($expected);

        $response = $this->list('status[]=sent&status[]=failed');

        $this->assertSame($expected, $this->ids($response->assertOk()));
    }

    /**
     * 상태·확장 유형을 스칼라로 보내도 그대로 동작한다(하위 호환 — 배열로 승격).
     *
     * @scenario case=scalar_compat
     *
     * @effects multi_value_filter_applied
     *
     * @return void 반환값 없음
     */
    public function test_스칼라_상태와_확장유형도_동작한다(): void
    {
        $failedModule = $this->makeLog(['status' => 'failed', 'extension_type' => 'module']);
        $this->makeLog(['status' => 'failed', 'extension_type' => 'core']);
        $this->makeLog(['status' => 'sent', 'extension_type' => 'module']);

        $response = $this->list('status=failed&extension_type=module');

        $this->assertSame([$failedModule->id], $this->ids($response->assertOk()));
    }

    /**
     * 확장 유형을 여러 개 고르면 고른 유형 전부가 적용된다.
     *
     * @scenario case=extension_multi
     *
     * @effects multi_value_filter_applied
     *
     * @return void 반환값 없음
     */
    public function test_확장유형_다중_선택은_모두_적용된다(): void
    {
        $core = $this->makeLog(['extension_type' => 'core']);
        $module = $this->makeLog(['extension_type' => 'module']);
        $this->makeLog(['extension_type' => 'plugin']);

        $expected = [$core->id, $module->id];
        sort($expected);

        $response = $this->list('extension_type[]=core&extension_type[]=module');

        $this->assertSame($expected, $this->ids($response->assertOk()));
    }

    /**
     * 다중 선택 값 중 허용하지 않는 상태가 있으면 422 로 거절한다.
     *
     * @scenario case=status_invalid
     *
     * @effects invalid_input_rejected
     *
     * @return void 반환값 없음
     */
    public function test_허용하지_않는_상태값은_거절한다(): void
    {
        $this->list('status[]=sent&status[]=bogus')->assertStatus(422)
            ->assertJsonValidationErrors('status.1');
    }
}
