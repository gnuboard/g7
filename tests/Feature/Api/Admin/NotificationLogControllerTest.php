<?php

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 알림 발송 이력 API 테스트
 */
class NotificationLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createAdminWithPermissions();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 목록 조회 성공 — abilities 포함
     */
    public function test_index_returns_notification_logs_with_abilities(): void
    {
        NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'recipient_identifier' => 'test@example.com',
            'status' => 'sent',
        ]);

        $response = $this->authRequest()->getJson('/api/admin/notification-logs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data',
                    'pagination',
                    'abilities' => ['can_delete'],
                ],
            ])
            ->assertJsonPath('data.abilities.can_delete', true);
    }

    /**
     * 채널 필터 적용
     */
    public function test_index_filters_by_channel(): void
    {
        NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'a@test.com', 'status' => 'sent']);
        NotificationLog::create(['channel' => 'database', 'notification_type' => 'test', 'recipient_identifier' => '1', 'status' => 'sent']);

        $response = $this->authRequest()->getJson('/api/admin/notification-logs?channel=mail');

        $response->assertStatus(200)
            ->assertJsonPath('data.pagination.total', 1);
    }

    /**
     * 커서 요청이 정상 응답하는지 확인 (#519 회귀)
     *
     * 이 목록은 커서를 주면 키셋 페이지로 응답한다. 커서 결과에는 총 건수와 마지막 페이지가
     * 없는데, 컬렉션이 페이지네이션 블록을 손으로 조립하면 없는 값을 불러 그 요청만 500 이
     * 된다. 응답 형태를 스스로 판정하는 표준 메타를 쓰는지 여기서 고정한다.
     */
    public function test_index_accepts_cursor_request(): void
    {
        foreach (range(1, 5) as $i) {
            NotificationLog::create([
                'channel' => 'mail',
                'notification_type' => 'test',
                'recipient_identifier' => "c{$i}@test.com",
                'status' => 'sent',
            ]);
        }

        // 커서 파라미터가 있어야 키셋 경로로 들어간다. 형식이 깨진 값은 첫 페이지로
        // 되돌려 주므로(KeysetPaginator::decode), 진입에는 임의 문자열로 충분하다.
        $first = $this->authRequest()
            ->getJson('/api/admin/notification-logs?per_page=2&cursor=first');

        $first->assertStatus(200);
        $this->assertCount(2, $first->json('data.data'));

        $pagination = $first->json('data.pagination');

        // 커서 결과는 총 건수를 세지 않는다 — 없는 값을 채워 내보내지 않아야 한다.
        $this->assertArrayNotHasKey('total', $pagination);
        $this->assertArrayNotHasKey('last_page', $pagination);
        $this->assertArrayHasKey('next_cursor', $pagination, '커서 응답에 다음 커서가 없다');

        // 받은 커서로 실제 다음 페이지까지 이동되는지 확인
        $second = $this->authRequest()
            ->getJson('/api/admin/notification-logs?per_page=2&cursor='.urlencode($pagination['next_cursor']));

        $second->assertStatus(200);
        $this->assertNotEmpty($second->json('data.data'));
    }

    /**
     * 상한형 목록이 정확도 메타를 함께 싣는지 확인 (#519 — 성능 개선 유지)
     */
    public function test_index_carries_total_accuracy_meta(): void
    {
        NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'm@test.com', 'status' => 'sent']);

        $response = $this->authRequest()->getJson('/api/admin/notification-logs?per_page=15');

        $response->assertStatus(200);

        $pagination = $response->json('data.pagination');

        $this->assertArrayHasKey('total_relation', $pagination, '상한 계약의 정확도 메타가 사라졌다');
        $this->assertArrayHasKey('total_is_exact', $pagination);
        $this->assertArrayHasKey('result_cap', $pagination);
    }

    /**
     * 단건 삭제
     */
    public function test_destroy_deletes_log(): void
    {
        $log = NotificationLog::create([
            'channel' => 'mail',
            'notification_type' => 'test',
            'recipient_identifier' => 'test@example.com',
            'status' => 'sent',
        ]);

        $response = $this->authRequest()->deleteJson("/api/admin/notification-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notification_logs', ['id' => $log->id]);
    }

    /**
     * 다건 삭제
     */
    public function test_bulk_destroy(): void
    {
        $log1 = NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'a@test.com', 'status' => 'sent']);
        $log2 = NotificationLog::create(['channel' => 'mail', 'notification_type' => 'test', 'recipient_identifier' => 'b@test.com', 'status' => 'sent']);

        $response = $this->authRequest()->postJson('/api/admin/notification-logs/bulk-delete', [
            'ids' => [$log1->id, $log2->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.deleted_count', 2);
    }

    /**
     * 인증된 요청 헬퍼.
     *
     * @return static
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 관리자 생성 헬퍼.
     *
     * @return User
     */
    private function createAdminWithPermissions(): User
    {
        $user = User::factory()->create();

        $role = Role::create([
            'name' => ['ko' => '최고관리자'],
            'identifier' => 'super_admin',
            'is_admin' => true,
        ]);

        foreach (['core.notification-logs.read', 'core.notification-logs.delete'] as $identifier) {
            $perm = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier], 'type' => 'admin', 'order' => 1]
            );
            $role->permissions()->attach($perm->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
