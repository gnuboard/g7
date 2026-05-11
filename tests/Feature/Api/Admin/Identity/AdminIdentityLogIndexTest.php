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
 * #297 — 본인인증 이력 검색이 user_id 외(target 해시 등)로 동작하지 않던 문제 회귀 방지.
 * 응답 구조가 알림 발송 이력과 동일하게 `{data: {data, pagination, abilities}}` 인지 함께 검증.
 */
class AdminIdentityLogIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

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

    private function authGet(string $url): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->getJson($url);
    }

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

    public function test_user_id_filter_no_longer_requires_existing_user(): void
    {
        // 이전: Rule::exists(User::class, 'id') 때문에 미존재 user_id 는 422.
        // 변경 후: 200 + 빈 배열 (감사 로그 — 삭제된 user_id 도 조회 가능해야 함).
        $response = $this->authGet('/api/admin/identity/logs?user_id=999999');

        $response->assertOk();
        $this->assertSame([], $response->json('data.data'));
    }

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

    public function test_invalid_status_in_array_returns_422(): void
    {
        $response = $this->authGet('/api/admin/identity/logs?statuses[]=verified&statuses[]=invalid_value');
        $response->assertStatus(422);
    }

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
