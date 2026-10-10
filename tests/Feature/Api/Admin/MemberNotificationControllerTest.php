<?php

namespace Tests\Feature\Api\Admin;

use App\Extension\HookManager;
use App\Models\NotificationDefinition;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 관리자 회원 알림함 API 테스트 (이슈 #524 §4.1)
 *
 * 결함: 회원이 받은 in-app 알림(수신함)에 관리자 표면이 없었다. `admin/notifications` 는
 * **관리자 본인의 수신함**이고 `admin/notification-logs` 는 발송 이력이라, "이 회원에게 무엇이
 * 남아 있는가" 를 볼 화면이 어디에도 없었다.
 *
 * 검증 목적:
 * - 조회는 core.member-notifications.read, 삭제는 .delete (권한 분리)
 * - 회원 상세에서 넘어온 user_uuid 필터, 없는 uuid 는 빈 결과(전체 노출 금지)
 * - search_type 4종(all/recipient/subject/body)이 **실제로** 동작한다 (장식 셀렉트 금지)
 * - 읽음 필터, 기간 필터
 * - ids 는 uuid 문자열 — 정수를 넣으면 422 (발송 이력의 integer 규칙을 베끼면 전건 실패)
 * - 회원 상세에만 notifications_summary 가 실린다
 *
 * URL:
 * - GET    /api/admin/member-notifications
 * - POST   /api/admin/member-notifications/bulk-delete
 * - DELETE /api/admin/member-notifications/{id}
 *
 * 로컬 MySQL 은 migrate:fresh 의 파티션·인덱스 DDL 에서 OOM 이 나므로 트랜잭션 격리를 쓴다.
 *
 * @scenario surface=admin_member_notifications,actor=admin
 *
 * @effects admin_member_notifications_requires_read_permission, admin_member_notifications_search_type_branches
 */
#[Group('core')]
#[Group('admin')]
#[Group('notification')]
class MemberNotificationControllerTest extends TestCase
{
    use DatabaseTransactions;

    private string $apiBase = '/api/admin/member-notifications';

    private User $admin;

    private User $readOnlyAdmin;

    private User $memberA;

    private User $memberB;

    /** @var array<int, array{0: string, 1: callable}> 이 테스트가 건 필터 훅 */
    private array $registered = [];

    /**
     * 권한 조합별 관리자와 수신 회원 두 명을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminWith([
            'core.member-notifications.read',
            'core.member-notifications.delete',
            'core.users.read',
        ], 'mn-full');

        $this->readOnlyAdmin = $this->createAdminWith(['core.member-notifications.read'], 'mn-read');

        $this->memberA = User::factory()->create();
        $this->memberB = User::factory()->create();
    }

    /**
     * 테스트가 건 훅만 걷어냅니다 — 확장이 구독한 같은 이름의 훅은 건드리지 않는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->registered as [$name, $callback]) {
            HookManager::removeFilter($name, $callback);
        }

        parent::tearDown();
    }

    /**
     * 필터 훅을 걸고 tearDown 에서 걷도록 기록합니다.
     *
     * @param  string  $name  훅 이름
     * @param  callable  $callback  콜백
     * @return void 반환값 없음
     */
    private function hook(string $name, callable $callback): void
    {
        HookManager::addFilter($name, $callback);
        $this->registered[] = [$name, $callback];
    }

    // ==========================================
    // 목록 · 권한
    // ==========================================

    /**
     * read 권한 없는 관리자 → 403.
     *
     * @effects admin_member_notifications_requires_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_requires_read_permission(): void
    {
        $noPermission = $this->createAdminWith(['core.users.read'], 'mn-none');

        $this->actingAs($noPermission)->getJson($this->apiBase)->assertStatus(403);
    }

    /**
     * 전 회원의 알림이 최신순으로 한 목록에 나온다.
     *
     * @effects admin_member_notifications_lists_all_members
     *
     * @return void 반환값 없음
     */
    public function test_index_lists_all_members_notifications_recent_first(): void
    {
        $older = $this->seedNotification($this->memberA, ['subject' => '오래된 알림'], null, now()->subDay());
        $newer = $this->seedNotification($this->memberB, ['subject' => '최근 알림']);

        $response = $this->actingAs($this->admin)->getJson($this->apiBase);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonPath('data.data.0.id', $newer);
        $response->assertJsonPath('data.data.1.id', $older);
        $response->assertJsonPath('data.abilities.can_delete', true);
    }

    /**
     * 「종류」는 알림 정의 이름으로 나온다 — Laravel `type` 열의 클래스명이 아니다.
     *
     * 모든 행의 `type` 열은 GenericNotification 클래스명이고, 어떤 알림인지는 `data.type`
     * (알림 정의 키)에 있다. 클래스명을 그대로 내보내면 화면의 종류 열이 모두
     * `App\Notifications\GenericNotification` 으로 같아 알아볼 수 없다.
     *
     * @effects admin_member_notifications_type_label_from_definition
     *
     * @return void 반환값 없음
     */
    public function test_index_type_label_uses_notification_definition_name(): void
    {
        NotificationDefinition::factory()->create([
            'type' => 'mn_label_probe',
            'name' => ['ko' => '라벨 확인용 알림', 'en' => 'Label probe'],
            'is_active' => true,
        ]);
        $this->seedNotification($this->memberA, ['type' => 'mn_label_probe', 'subject' => '정의 있음']);
        $this->seedNotification($this->memberB, ['type' => 'mn_no_definition', 'subject' => '정의 없음'], null, now()->subMinute());

        // 라벨 언어는 요청 로케일을 따른다 — 관리자 화면은 Accept-Language 로 보낸다
        $response = $this->actingAs($this->admin)
            ->withHeaders(['Accept-Language' => 'ko'])
            ->getJson($this->apiBase);

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.type', 'mn_label_probe');
        $response->assertJsonPath('data.data.0.type_label', '라벨 확인용 알림');
        // 정의가 없는 키는 키 그대로 — 클래스명으로 되돌아가지 않는다
        $response->assertJsonPath('data.data.1.type', 'mn_no_definition');
        $response->assertJsonPath('data.data.1.type_label', 'mn_no_definition');
    }

    /**
     * delete 권한 없는 관리자는 목록은 보되 can_delete=false 로 받는다.
     *
     * @effects admin_member_notifications_read_only_cannot_delete
     *
     * @return void 반환값 없음
     */
    public function test_index_read_only_admin_gets_can_delete_false(): void
    {
        $this->seedNotification($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonPath('data.abilities.can_delete', false)
            ->assertJsonPath('data.data.0.abilities.can_delete', false);
    }

    /**
     * 행에도 삭제 능력이 실린다 — 표의 행 메뉴 「삭제」가 `abilities.can_delete` 로 켜고 끈다.
     *
     * 행에 이 값이 없으면 DataGrid 는 disabledField 경로를 falsy 로 읽어 모든 행의 「삭제」를
     * 비활성으로 그리고, 삭제 권한이 있는 관리자도 한 줄 삭제를 할 수 없다.
     *
     * @effects admin_member_notifications_row_abilities_enable_row_delete
     *
     * @return void 반환값 없음
     */
    public function test_index_rows_carry_delete_ability(): void
    {
        $this->seedNotification($this->memberA);
        $this->seedNotification($this->memberB, [], null, now()->subMinute());

        $this->actingAs($this->admin)
            ->getJson($this->apiBase)
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.abilities.can_delete', true)
            ->assertJsonPath('data.data.1.abilities.can_delete', true);
    }

    /**
     * user_uuid 필터 — 없는 uuid 는 빈 결과여야 한다.
     *
     * @effects admin_member_notifications_filters_by_user_uuid
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_user_uuid(): void
    {
        $mine = $this->seedNotification($this->memberA);
        $this->seedNotification($this->memberB);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?user_uuid='.$this->memberA->uuid)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?user_uuid=00000000-0000-4000-8000-000000000000')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * search_type 4종이 실제로 동작한다 (규칙에 없어 무시되는 장식 셀렉트 금지 — 부록 B D7).
     *
     * @effects admin_member_notifications_search_type_branches
     *
     * @return void 반환값 없음
     */
    public function test_index_search_type_recipient_subject_body_all(): void
    {
        $target = $this->seedNotification($this->memberA, [
            'subject' => '유니크제목',
            'body' => '유니크본문',
        ]);
        $this->seedNotification($this->memberB, ['subject' => '다른제목', 'body' => '다른본문']);

        // 제목
        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?search_type=subject&search='.urlencode('유니크제목'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target);

        // 본문
        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?search_type=body&search='.urlencode('유니크본문'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target);

        // 수신자
        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?search_type=recipient&search='.urlencode($this->memberA->email))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target);

        // 전체 — 어느 축이든 매칭되면 잡힌다
        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?search_type=all&search='.urlencode('유니크본문'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $target);
    }

    /**
     * 검색어의 `%`·`_` 는 글자 그대로 찾는다 — 회원 목록 검색과 같은 기준.
     *
     * 와일드카드로 해석하면 `%` 한 글자 검색이 전 회원의 알림을 모두 돌려주고,
     * `할인_쿠폰` 은 `할인-쿠폰`·`할인 쿠폰` 까지 잡는다.
     *
     * @effects admin_member_notifications_search_escapes_like_wildcards
     *
     * @return void 반환값 없음
     */
    public function test_index_search_treats_like_wildcards_literally(): void
    {
        $percent = $this->seedNotification($this->memberA, ['subject' => '최대 30% 할인']);
        $underscore = $this->seedNotification($this->memberA, ['subject' => '할인_쿠폰 지급'], null, now()->subMinute());
        $this->seedNotification($this->memberB, ['subject' => '할인-쿠폰 안내', 'body' => '일반 본문'], null, now()->subMinutes(2));

        foreach (['subject', 'all'] as $searchType) {
            $this->actingAs($this->admin)
                ->getJson($this->apiBase.'?search_type='.$searchType.'&search='.urlencode('%'))
                ->assertStatus(200)
                ->assertJsonCount(1, 'data.data')
                ->assertJsonPath('data.data.0.id', $percent);

            $this->actingAs($this->admin)
                ->getJson($this->apiBase.'?search_type='.$searchType.'&search='.urlencode('할인_쿠폰'))
                ->assertStatus(200)
                ->assertJsonCount(1, 'data.data')
                ->assertJsonPath('data.data.0.id', $underscore);
        }

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?search_type=recipient&search='.urlencode('%'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * 읽음 필터 — unread 는 안 읽은 것만.
     *
     * @effects admin_member_notifications_read_filter
     *
     * @return void 반환값 없음
     */
    public function test_index_read_filter_unread(): void
    {
        $unread = $this->seedNotification($this->memberA);
        $read = $this->seedNotification($this->memberA, [], now());

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?read=unread')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $unread);

        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?read=read')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $read);
    }

    /**
     * 기간 필터 — 종료일은 그날 끝까지 포함한다.
     *
     * @effects admin_member_notifications_date_range
     *
     * @return void 반환값 없음
     */
    public function test_index_date_range(): void
    {
        $old = $this->seedNotification($this->memberA, ['subject' => '옛날'], null, now()->subDays(10));
        $recent = $this->seedNotification($this->memberA, ['subject' => '최근']);

        // 날짜 칸은 보는 사람(운영자)의 시간대 하루로 해석된다 — 운영자 시간대(없으면 기본 시간대)의 오늘로 보낸다.
        $viewerNow = now($this->admin->timezone ?: config('app.default_user_timezone'));
        $response = $this->actingAs($this->admin)->getJson(
            $this->apiBase.'?created_from='.$viewerNow->copy()->subDay()->format('Y-m-d').'&created_to='.$viewerNow->format('Y-m-d')
        );

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $recent);
        $this->assertNotSame($old, $response->json('data.data.0.id'));
    }

    // ==========================================
    // 삭제
    // ==========================================

    /**
     * delete 권한 없는 관리자의 삭제 → 403.
     *
     * @effects admin_member_notifications_destroy_requires_delete_permission
     *
     * @return void 반환값 없음
     */
    public function test_destroy_requires_delete_permission(): void
    {
        $id = $this->seedNotification($this->memberA);

        $this->actingAs($this->readOnlyAdmin)
            ->deleteJson($this->apiBase.'/'.$id)
            ->assertStatus(403);

        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    /**
     * uuid 로 한 건 삭제.
     *
     * @effects admin_member_notifications_destroy_deletes_uuid_row
     *
     * @return void 반환값 없음
     */
    public function test_destroy_deletes_uuid_row(): void
    {
        $id = $this->seedNotification($this->memberA);

        $this->actingAs($this->admin)
            ->deleteJson($this->apiBase.'/'.$id)
            ->assertStatus(200);

        $this->assertDatabaseMissing('notifications', ['id' => $id]);
    }

    /**
     * 일괄 삭제는 uuid 를 받는다 — 정수를 넣으면 422.
     *
     * 발송 이력의 integer 규칙을 복사했다면 정상 요청이 전건 422 로 막혔을 것이다.
     *
     * @effects admin_member_notifications_bulk_delete_uuid_only
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_deletes_and_rejects_non_uuid(): void
    {
        $first = $this->seedNotification($this->memberA);
        $second = $this->seedNotification($this->memberB);

        $response = $this->actingAs($this->admin)
            ->postJson($this->apiBase.'/bulk-delete', ['ids' => [$first, $second]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);
        $this->assertDatabaseMissing('notifications', ['id' => $first]);

        // 정수 id 는 uuid 규칙에서 걸린다
        $this->actingAs($this->admin)
            ->postJson($this->apiBase.'/bulk-delete', ['ids' => [123]])
            ->assertStatus(422);
    }

    /**
     * 일괄 삭제는 관리자 활동 로그를 남긴다.
     *
     * 남의 수신함을 지우는 행위다. 기록이 없으면 회원이 「알림이 사라졌다」 고 물었을 때
     * 되짚을 근거가 없다. 훅은 루프 앞뒤로 한 번씩만 발화하므로 로그도 **일괄 1건**이다.
     *
     * @effects admin_member_notification_bulk_delete_writes_activity_log
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_writes_activity_log(): void
    {
        $first = $this->seedNotification($this->memberA);
        $second = $this->seedNotification($this->memberB);

        $before = (int) DB::table('activity_logs')
            ->where('action', 'member_notification.bulk_delete')->count();

        $this->actingAs($this->admin)
            ->postJson($this->apiBase.'/bulk-delete', ['ids' => [$first, $second]])
            ->assertStatus(200);

        $after = (int) DB::table('activity_logs')
            ->where('action', 'member_notification.bulk_delete')->count();

        $this->assertSame($before + 1, $after, '일괄 삭제는 활동 로그를 한 건 남겨야 한다');
    }

    /**
     * 없는 회원 id 로 조회하면 422 — 빈 알림함처럼 보이지 않는다.
     *
     * 오탈자와 「정말 0건」 을 운영자가 구분할 수 있어야 한다.
     *
     * @effects admin_member_notification_rejects_unknown_user_id
     *
     * @return void 반환값 없음
     */
    public function test_index_rejects_unknown_user_id(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?user_id=99999999')
            ->assertStatus(422);

        // 존재하는 회원은 그대로 통과한다 (규칙이 과하게 막지 않는지)
        $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?user_id='.$this->memberA->id)
            ->assertStatus(200);
    }

    /**
     * 허용 밖 값으로 조회하면 422 문구가 내부 키 대신 항목 이름을 쓴다.
     *
     * 규칙은 있었지만 항목 이름이 없어 `search type` 같은 내부 키가 문구에 그대로 나왔다.
     *
     * @effects admin_member_notification_validation_messages_use_attribute_names
     *
     * @return void 반환값 없음
     */
    public function test_index_validation_messages_use_attribute_names(): void
    {
        $errors = $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?search_type=nickname&read=maybe&sort_by=id')
            ->assertStatus(422)
            ->json('errors');

        foreach (['search_type', 'read', 'sort_by'] as $field) {
            $this->assertStringContainsString(__('member_notification.list_attributes.'.$field), $errors[$field][0] ?? '', $field);
        }

        // 밑줄 든 키는 내부 키 모양(`search type`)이 문구에 남지 않아야 한다
        foreach (['search_type', 'sort_by'] as $field) {
            $this->assertStringNotContainsString(str_replace('_', ' ', $field), $errors[$field][0] ?? '', $field);
        }
    }

    /**
     * 확장이 규칙 필터로 더한 파라미터의 항목 이름도 짝 필터로 붙는다.
     *
     * @effects admin_member_notification_validation_messages_use_attribute_names
     *
     * @return void 반환값 없음
     */
    public function test_index_validation_attribute_filter_names_extension_params(): void
    {
        $this->hook('core.member_notification.index_validation_rules', fn (array $rules) => $rules + ['ext_channel' => ['nullable', 'string', 'in:mail']]);
        $this->hook('core.member_notification.index_validation_attributes', fn (array $attributes) => $attributes + ['ext_channel' => 'ext-channel-label']);

        $message = $this->actingAs($this->admin)
            ->getJson($this->apiBase.'?ext_channel=sms')
            ->assertStatus(422)
            ->json('errors.ext_channel.0');

        $this->assertStringContainsString('ext-channel-label', (string) $message);
    }

    // ==========================================
    // 회원 상세 요약
    // ==========================================

    /**
     * 관리자 회원 상세에 알림 요약(총건·안읽음)이 실린다.
     *
     * @effects admin_user_detail_includes_notifications_summary
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_includes_notifications_summary(): void
    {
        $this->seedNotification($this->memberA);
        $this->seedNotification($this->memberA);
        $this->seedNotification($this->memberA, [], now());

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/'.$this->memberA->uuid);

        $response->assertStatus(200);
        $response->assertJsonPath('data.notifications_summary.total', 3);
        $response->assertJsonPath('data.notifications_summary.unread', 2);
    }

    /**
     * 회원 목록에는 요약을 싣지 않는다 — 행마다 집계하면 N+1 이 된다.
     *
     * @effects admin_user_list_omits_notifications_summary
     *
     * @return void 반환값 없음
     */
    public function test_user_list_omits_notifications_summary(): void
    {
        $this->seedNotification($this->memberA);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertStatus(200);
        $rows = $response->json('data.data') ?? [];
        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('notifications_summary', $row);
        }
    }

    // ==========================================
    // 헬퍼
    // ==========================================

    /**
     * 알림 행을 시드하고 uuid 를 반환합니다.
     *
     * Laravel 표준 notifications 테이블에 직접 넣는다 — 알림 클래스를 통하면 채널·큐까지
     * 타게 되어 이 테스트가 보려는 것(목록·필터·삭제)과 무관한 실패가 섞인다.
     *
     * @param  User  $user  수신 회원
     * @param  array<string, mixed>  $data  data JSON 에 담을 값 (subject/body)
     * @param  Carbon|null  $readAt  읽은 시각 (null 이면 안읽음)
     * @param  Carbon|null  $createdAt  수신 시각
     * @return string 알림 uuid
     */
    private function seedNotification(User $user, array $data = [], $readAt = null, $createdAt = null): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\GenericNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(array_merge([
                'subject' => '테스트 알림',
                'body' => '테스트 본문',
                'click_url' => '/mypage',
            ], $data), JSON_UNESCAPED_UNICODE),
            'read_at' => $readAt,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);

        return $id;
    }

    /**
     * 지정한 권한만 가진 관리자를 만듭니다.
     *
     * 권한별 고유 역할을 만들어야 "조회만 가능한 운영자" 가 정확히 재현된다 —
     * 공용 역할에 붙이면 두 관리자가 같은 권한을 공유해 분리 검증이 불가능해진다.
     *
     * @param  array<int, string>  $identifiers  권한 식별자 목록
     * @param  string  $roleKey  역할 식별자 접두
     * @return User 관리자
     */
    private function createAdminWith(array $identifiers, string $roleKey): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => ['ko' => '테스트 관리자 '.$roleKey, 'en' => 'Test Admin '.$roleKey],
            'identifier' => $roleKey.'-'.$user->id,
            'is_admin' => true,
        ]);

        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin', 'order' => 1]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
