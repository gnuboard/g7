<?php

namespace Tests\Feature\Api\Admin\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Models\IdentityVerificationLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 관리자 — IDV 이력 목록 조회 (회귀/통합).
 *
 * 본인인증 이력 검색이 user_id 외(target 해시 등)로 동작하지 않던 문제 회귀 방지.
 * 응답 구조가 알림 발송 이력과 동일하게 `{data: {data, pagination, abilities}}` 인지 함께 검증.
 */
class AdminIdentityLogIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    /**
     * 권한 시더를 돌리고 슈퍼 관리자와 인증 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create(['is_super' => true]);
        $adminRole = Role::where('identifier', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }
        $this->admin = $this->admin->fresh();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 인증 헤더를 붙여 GET 요청을 보낸다.
     *
     * @param string $url 요청할 API 경로
     * @return \Illuminate\Testing\TestResponse 요청 응답
     */
    private function authGet(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson($url);
    }

    /**
     * 기본값 위에 덮어쓸 값을 얹어 본인인증 이력 한 건을 만든다.
     *
     * @param array $overrides 기본값을 덮어쓸 컬럼 값
     * @return IdentityVerificationLog 만든 이력
     */
    private function makeLog(array $overrides = []): IdentityVerificationLog
    {
        return IdentityVerificationLog::create(array_merge([
            'id' => (string) Str::uuid(),
            'provider_id' => 'mail',
            'purpose' => 'signup',
            'channel' => 'email',
            'user_id' => null,
            'target_hash' => hash('sha256', 'user@example.com'),
            'status' => IdentityVerificationStatus::Verified->value,
            'attempts' => 1,
            'max_attempts' => 5,
        ], $overrides));
    }

    /**
     * 목록 응답이 알림 발송 이력과 같은 {data, pagination, abilities} 구조인지 확인한다.
     *
     * @scenario query=envelope
     *
     * @effects identity_log_index_envelope_matches_notification_log
     *
     * @return void 반환값 없음
     */
    public function test_response_envelope_matches_notification_log_shape(): void
    {
        $this->makeLog();

        $response = $this->authGet('/api/admin/identity/logs');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                        'has_more_pages',
                    ],
                    'abilities' => [
                        'can_purge',
                    ],
                ],
            ]);
    }

    /**
     * target_hash 필터가 그 해시의 이력만 돌려주는지 확인한다.
     *
     * @scenario query=target_hash
     *
     * @effects identity_log_target_hash_filter_matches_only_that_hash
     *
     * @return void 반환값 없음
     */
    public function test_target_hash_filter_returns_matching_records(): void
    {
        $hashA = hash('sha256', 'a@example.com');
        $hashB = hash('sha256', 'b@example.com');

        $this->makeLog(['target_hash' => $hashA]);
        $this->makeLog(['target_hash' => $hashB]);

        $response = $this->authGet('/api/admin/identity/logs?target_hash='.$hashA);

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame($hashA, $items[0]['target_hash']);
    }

    /**
     * 자동 검색에 16진 해시를 넣으면 target_hash 로 검색되는지 확인한다.
     *
     * @scenario query=search_auto
     *
     * @effects identity_log_auto_search_routes_hex_to_target_hash
     *
     * @return void 반환값 없음
     */
    public function test_search_auto_detect_routes_hex_to_target_hash(): void
    {
        $hash = hash('sha256', 'auto@example.com');
        $this->makeLog(['target_hash' => $hash]);
        $this->makeLog(['target_hash' => hash('sha256', 'other@example.com')]);

        $response = $this->authGet('/api/admin/identity/logs?search='.$hash.'&search_type=auto');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame($hash, $items[0]['target_hash']);
    }

    /**
     * 자동 검색에 숫자를 넣으면 user_id 로 검색되는지 확인한다.
     *
     * @scenario query=search_auto
     *
     * @effects identity_log_auto_search_routes_digits_to_user_id
     *
     * @return void 반환값 없음
     */
    public function test_search_auto_detect_routes_digits_to_user_id(): void
    {
        $other = User::factory()->create();
        $target = User::factory()->create();

        $this->makeLog(['user_id' => $target->id]);
        $this->makeLog(['user_id' => $other->id]);

        $response = $this->authGet('/api/admin/identity/logs?search='.$target->id.'&search_type=auto');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame($target->id, $items[0]['user_id']);
    }

    /**
     * 없는 user_id 로 걸러도 422 가 아니라 200 과 빈 목록이 오는지 확인한다(삭제된 회원 이력 조회용).
     *
     * @scenario query=user_id
     *
     * @effects identity_log_user_id_filter_accepts_missing_user
     *
     * @return void 반환값 없음
     */
    public function test_user_id_filter_no_longer_requires_existing_user(): void
    {
        // 이전: Rule::exists(User::class, 'id') 때문에 미존재 user_id 는 422.
        // 변경 후: 200 + 빈 배열 (감사 로그 — 삭제된 user_id 도 조회 가능해야 함).
        $response = $this->authGet('/api/admin/identity/logs?user_id=999999');

        $response->assertOk();
        $this->assertSame([], $response->json('data.data'));
    }

    /**
     * 생성 시각 오름차순 정렬이 적용되는지 확인한다.
     *
     * @scenario query=sort
     *
     * @effects identity_log_sorts_by_requested_column
     *
     * @return void 반환값 없음
     */
    public function test_sort_by_created_at_asc(): void
    {
        $first = $this->makeLog(['target_hash' => hash('sha256', 'first')]);
        // 두 번째 레코드는 명시적으로 더 늦은 시간으로 생성
        $second = $this->makeLog(['target_hash' => hash('sha256', 'second')]);
        $second->forceFill(['created_at' => now()->addMinutes(5)])->save();

        $response = $this->authGet('/api/admin/identity/logs?sort_by=created_at&sort_order=asc');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(2, $items);
        $this->assertSame($first->id, $items[0]['id']);
        $this->assertSame($second->id, $items[1]['id']);
    }

    /**
     * 시도 횟수 내림차순 정렬이 적용되는지 확인한다.
     *
     * @scenario query=sort
     *
     * @effects identity_log_sorts_by_requested_column
     *
     * @return void 반환값 없음
     */
    public function test_sort_by_attempts_desc(): void
    {
        $low = $this->makeLog(['attempts' => 1, 'target_hash' => hash('sha256', 'low')]);
        $high = $this->makeLog(['attempts' => 4, 'target_hash' => hash('sha256', 'high')]);

        $response = $this->authGet('/api/admin/identity/logs?sort_by=attempts&sort_order=desc');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertSame($high->id, $items[0]['id']);
        $this->assertSame($low->id, $items[1]['id']);
    }

    /**
     * 상태 배열 필터가 고른 상태들의 이력만 돌려주는지 확인한다.
     *
     * @scenario query=status_filter
     *
     * @effects identity_log_status_array_filter_matches_any_selected
     *
     * @return void 반환값 없음
     */
    public function test_array_statuses_filter_uses_whereIn(): void
    {
        $this->makeLog(['target_hash' => hash('sha256', 'verified-a'), 'status' => IdentityVerificationStatus::Verified->value]);
        $this->makeLog(['target_hash' => hash('sha256', 'sent-b'), 'status' => IdentityVerificationStatus::Sent->value]);
        $this->makeLog(['target_hash' => hash('sha256', 'failed-c'), 'status' => IdentityVerificationStatus::Failed->value]);

        $response = $this->authGet('/api/admin/identity/logs?statuses[]=verified&statuses[]=failed');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(2, $items);
        $statuses = array_column($items, 'status');
        $this->assertContains('verified', $statuses);
        $this->assertContains('failed', $statuses);
    }

    /**
     * 예전 단일 status 쿼리도 계속 동작하는지 확인한다.
     *
     * @scenario query=status_filter
     *
     * @effects identity_log_legacy_single_status_still_filters
     *
     * @return void 반환값 없음
     */
    public function test_legacy_single_status_query_still_works(): void
    {
        $this->makeLog(['target_hash' => hash('sha256', 'a'), 'status' => IdentityVerificationStatus::Verified->value]);
        $this->makeLog(['target_hash' => hash('sha256', 'b'), 'status' => IdentityVerificationStatus::Sent->value]);

        $response = $this->authGet('/api/admin/identity/logs?status=verified');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame('verified', $items[0]['status']);
    }

    /**
     * 목적 배열 필터가 고른 목적들의 이력만 돌려주는지 확인한다.
     *
     * @scenario query=purpose_filter
     *
     * @effects identity_log_purpose_array_filter_matches_any_selected
     *
     * @return void 반환값 없음
     */
    public function test_array_purposes_filter_uses_whereIn(): void
    {
        $this->makeLog(['target_hash' => hash('sha256', 'sup'), 'purpose' => 'signup']);
        $this->makeLog(['target_hash' => hash('sha256', 'pwd'), 'purpose' => 'password_reset']);
        $this->makeLog(['target_hash' => hash('sha256', 'self'), 'purpose' => 'self_update']);

        $response = $this->authGet('/api/admin/identity/logs?purposes[]=signup&purposes[]=password_reset');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(2, $items);
    }

    /**
     * 기간 필터가 범위 밖 이력을 빼는지 확인한다.
     *
     * @scenario query=date_range
     *
     * @effects identity_log_date_range_excludes_outside_rows
     *
     * @return void 반환값 없음
     */
    public function test_date_range_filter_excludes_outside(): void
    {
        $oldLog = $this->makeLog(['target_hash' => hash('sha256', 'old')]);
        $oldLog->forceFill(['created_at' => now()->subDays(10)])->save();
        $recentLog = $this->makeLog(['target_hash' => hash('sha256', 'recent')]);
        $recentLog->forceFill(['created_at' => now()->subDay()])->save();

        $from = now()->subDays(2)->toDateString();
        $to = now()->toDateString();
        $response = $this->authGet("/api/admin/identity/logs?date_from={$from}&date_to={$to}");

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame($recentLog->id, $items[0]['id']);
    }

    /**
     * 검색 대상을 IP 주소로 고르면 그 IP 의 이력만 돌려주는지 확인한다.
     *
     * @scenario query=search_type
     *
     * @effects identity_log_ip_address_search_matches_exact_ip
     *
     * @return void 반환값 없음
     */
    public function test_search_type_ip_address(): void
    {
        $this->makeLog(['target_hash' => hash('sha256', 'a'), 'ip_address' => '127.0.0.1']);
        $this->makeLog(['target_hash' => hash('sha256', 'b'), 'ip_address' => '10.0.0.5']);

        $response = $this->authGet('/api/admin/identity/logs?search_type=ip_address&search=127.0.0.1');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame('127.0.0.1', $items[0]['ip_address']);
    }

    /**
     * 검색 대상을 정책 키로 고르면 앞부분 일치로 찾는지 확인한다.
     *
     * @scenario query=search_type
     *
     * @effects identity_log_policy_key_search_matches_prefix
     *
     * @return void 반환값 없음
     */
    public function test_search_type_policy_key_prefix_match(): void
    {
        $this->makeLog(['target_hash' => hash('sha256', 'a'), 'origin_policy_key' => 'core.profile.password_change']);
        $this->makeLog(['target_hash' => hash('sha256', 'b'), 'origin_policy_key' => 'sirsoft-board.post.create']);

        $response = $this->authGet('/api/admin/identity/logs?search_type=policy_key&search=core.profile');

        $response->assertOk();
        $items = $response->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame('core.profile.password_change', $items[0]['origin_policy_key']);
    }

    /**
     * 상태 배열에 없는 값이 섞이면 422 로 거부되는지 확인한다.
     *
     * @scenario query=validation
     *
     * @effects identity_log_invalid_status_value_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_invalid_status_in_array_returns_422(): void
    {
        $response = $this->authGet('/api/admin/identity/logs?statuses[]=verified&statuses[]=invalid_value');
        $response->assertStatus(422);
    }

    /**
     * 페이지 정보가 pagination 키에 현재·마지막 페이지·총건수·다음 페이지 여부로 실리는지 확인한다.
     *
     * @scenario query=pagination
     *
     * @effects identity_log_pagination_meta_under_pagination_key
     *
     * @return void 반환값 없음
     */
    public function test_pagination_meta_uses_pagination_key(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeLog(['target_hash' => hash('sha256', 'p'.$i)]);
        }

        $response = $this->authGet('/api/admin/identity/logs?per_page=2');

        $response->assertOk();
        $pagination = $response->json('data.pagination');
        $this->assertSame(1, $pagination['current_page']);
        $this->assertSame(2, $pagination['last_page']);
        $this->assertSame(2, $pagination['per_page']);
        $this->assertSame(3, $pagination['total']);
        $this->assertTrue($pagination['has_more_pages']);
    }
}
