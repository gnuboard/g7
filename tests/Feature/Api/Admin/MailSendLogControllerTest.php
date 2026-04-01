<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\MailSendStatus;
use App\Models\MailSendLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MailSendLogController 테스트
 *
 * 메일 발송 이력 API 엔드포인트를 테스트합니다.
 */
class MailSendLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     * @return User
     */
    private function createAdminUser(array $permissions = ['core.mail-send-logs.read']): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 역할 생성
        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할 (admin 미들웨어 통과용)
        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // 테스트용 역할에 권한 할당
        $adminRole->permissions()->sync($permissionIds);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminBaseRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증 헤더와 함께 요청
     *
     * @return $this
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/mail-send-logs');
        $response->assertStatus(401);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->createAdminUser([]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(403);
    }

    // ========================================================================
    // index 엔드포인트 - 응답 구조
    // ========================================================================

    public function test_index_returns_data_and_pagination_structure(): void
    {
        MailSendLog::factory()->count(3)->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
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
                ],
            ]);
    }

    public function test_index_data_items_have_correct_fields(): void
    {
        MailSendLog::factory()->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);

        $item = $data[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('recipient_email', $item);
        $this->assertArrayHasKey('recipient_name', $item);
        $this->assertArrayHasKey('subject', $item);
        $this->assertArrayHasKey('template_type', $item);
        $this->assertArrayHasKey('extension_type', $item);
        $this->assertArrayHasKey('extension_identifier', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('sent_at', $item);
        $this->assertArrayHasKey('body', $item);
        $this->assertArrayHasKey('sender_email', $item);
        $this->assertArrayHasKey('sender_name', $item);
        $this->assertArrayHasKey('number', $item);
    }

    public function test_index_data_items_include_body_content(): void
    {
        $body = '<p>Test email body</p>';
        MailSendLog::factory()->create(['body' => $body]);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals($body, $data[0]['body']);
    }

    // ========================================================================
    // index 엔드포인트 - 필터
    // ========================================================================

    public function test_index_filters_by_single_extension_type(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->count(2)->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-ecommerce')->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?extension_type[]=core');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_index_filters_by_multiple_extension_types(): void
    {
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Core, 'core')->count(2)->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Module, 'sirsoft-board')->create();
        MailSendLog::factory()->forExtension(ExtensionOwnerType::Plugin, 'sirsoft-payment')->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?extension_type[]=core&extension_type[]=module');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_index_filters_by_single_status(): void
    {
        MailSendLog::factory()->count(3)->create(['status' => MailSendStatus::Sent->value]);
        MailSendLog::factory()->failed()->count(2)->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?status[]=failed');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_index_filters_by_multiple_statuses(): void
    {
        MailSendLog::factory()->count(2)->create(['status' => MailSendStatus::Sent->value]);
        MailSendLog::factory()->failed()->count(1)->create();
        MailSendLog::factory()->create(['status' => MailSendStatus::Skipped->value]);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?status[]=sent&status[]=failed');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_index_filters_by_search(): void
    {
        MailSendLog::factory()->create(['recipient_email' => 'findme@example.com']);
        MailSendLog::factory()->create(['recipient_email' => 'other@example.com']);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?search=findme');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_filters_by_search_type_recipient_email(): void
    {
        MailSendLog::factory()->create([
            'recipient_email' => 'findme@example.com',
            'recipient_name' => '다른이름',
            'subject' => '다른제목',
        ]);
        MailSendLog::factory()->create([
            'recipient_email' => 'other@example.com',
            'recipient_name' => 'findme',
            'subject' => '기타',
        ]);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?search=findme&search_type=recipient_email');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('findme@example.com', $response->json('data.data.0.recipient_email'));
    }

    public function test_index_filters_by_search_type_subject(): void
    {
        MailSendLog::factory()->create([
            'recipient_email' => 'a@example.com',
            'subject' => '주문 완료 안내',
        ]);
        MailSendLog::factory()->create([
            'recipient_email' => 'b@example.com',
            'subject' => '비밀번호 재설정',
        ]);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?search=주문&search_type=subject');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_search_type_all_searches_all_fields(): void
    {
        MailSendLog::factory()->create(['recipient_email' => 'keyword@example.com', 'subject' => '기타']);
        MailSendLog::factory()->create(['recipient_email' => 'a@example.com', 'subject' => 'keyword 포함']);
        MailSendLog::factory()->create(['recipient_email' => 'b@example.com', 'recipient_name' => 'keyword맨', 'subject' => '기타']);
        MailSendLog::factory()->create(['recipient_email' => 'c@example.com', 'subject' => '관련없음']);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?search=keyword&search_type=all');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_index_filters_by_date_range(): void
    {
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-01-15')]);
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-03-15')]);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?date_from=2026-03-01&date_to=2026-03-31');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    // ========================================================================
    // index 엔드포인트 - 페이지네이션
    // ========================================================================

    public function test_index_respects_per_page(): void
    {
        MailSendLog::factory()->count(10)->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data.data'));
        $this->assertEquals(10, $response->json('data.pagination.total'));
        $this->assertEquals(5, $response->json('data.pagination.per_page'));
        $this->assertTrue($response->json('data.pagination.has_more_pages'));
    }

    // ========================================================================
    // index 엔드포인트 - 정렬
    // ========================================================================

    public function test_index_sorts_by_sent_at_desc_by_default(): void
    {
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-03-01')]);
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-03-05')]);
        MailSendLog::factory()->create(['sent_at' => Carbon::parse('2026-03-03')]);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(3, $data);
        // 기본 정렬: sent_at desc (최신순)
        $this->assertGreaterThanOrEqual(
            $data[1]['sent_at'],
            $data[0]['sent_at']
        );
    }

    public function test_index_sorts_by_recipient_email_asc(): void
    {
        MailSendLog::factory()->create(['recipient_email' => 'charlie@example.com']);
        MailSendLog::factory()->create(['recipient_email' => 'alice@example.com']);
        MailSendLog::factory()->create(['recipient_email' => 'bob@example.com']);

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?sort_by=recipient_email&sort_order=asc');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals('alice@example.com', $data[0]['recipient_email']);
        $this->assertEquals('bob@example.com', $data[1]['recipient_email']);
        $this->assertEquals('charlie@example.com', $data[2]['recipient_email']);
    }

    public function test_index_rejects_invalid_sort_by(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?sort_by=invalid_column');

        $response->assertStatus(422);
    }

    public function test_index_rejects_invalid_sort_order(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs?sort_order=invalid');

        $response->assertStatus(422);
    }

    // ========================================================================
    // index 엔드포인트 - abilities
    // ========================================================================

    public function test_index_includes_abilities_in_response(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        MailSendLog::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_delete', true);
    }

    public function test_index_abilities_can_delete_false_without_permission(): void
    {
        MailSendLog::factory()->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_delete', false);
    }

    public function test_index_data_items_include_abilities(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        MailSendLog::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertArrayHasKey('abilities', $data[0]);
        $this->assertTrue($data[0]['abilities']['can_delete']);
    }

    public function test_index_data_items_abilities_can_delete_false_without_permission(): void
    {
        MailSendLog::factory()->create();

        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertArrayHasKey('abilities', $data[0]);
        $this->assertFalse($data[0]['abilities']['can_delete']);
    }

    // ========================================================================
    // destroy 엔드포인트
    // ========================================================================

    public function test_destroy_returns_401_without_authentication(): void
    {
        $log = MailSendLog::factory()->create();

        $response = $this->deleteJson("/api/admin/mail-send-logs/{$log->id}");
        $response->assertStatus(401);
    }

    public function test_destroy_returns_403_without_permission(): void
    {
        $log = MailSendLog::factory()->create();

        $response = $this->authRequest()->deleteJson("/api/admin/mail-send-logs/{$log->id}");
        $response->assertStatus(403);
    }

    public function test_destroy_deletes_mail_send_log(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        $log = MailSendLog::factory()->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson("/api/admin/mail-send-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('mail_send_logs', ['id' => $log->id]);
    }

    public function test_destroy_returns_404_for_nonexistent_log(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson('/api/admin/mail-send-logs/99999');

        $response->assertStatus(404);
    }

    // ========================================================================
    // bulkDestroy 엔드포인트
    // ========================================================================

    public function test_bulk_destroy_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/mail-send-logs/bulk-delete', [
            'ids' => [1],
        ]);
        $response->assertStatus(401);
    }

    public function test_bulk_destroy_returns_403_without_permission(): void
    {
        $logs = MailSendLog::factory()->count(2)->create();

        $response = $this->authRequest()->postJson('/api/admin/mail-send-logs/bulk-delete', [
            'ids' => $logs->pluck('id')->toArray(),
        ]);

        $response->assertStatus(403);
    }

    public function test_bulk_destroy_deletes_multiple_logs(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        $logs = MailSendLog::factory()->count(3)->create();
        $idsToDelete = $logs->take(2)->pluck('id')->toArray();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/mail-send-logs/bulk-delete', [
            'ids' => $idsToDelete,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted_count', 2);

        foreach ($idsToDelete as $id) {
            $this->assertDatabaseMissing('mail_send_logs', ['id' => $id]);
        }
        // 삭제되지 않은 항목은 여전히 존재
        $this->assertDatabaseHas('mail_send_logs', ['id' => $logs->last()->id]);
    }

    public function test_bulk_destroy_validates_ids_required(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/mail-send-logs/bulk-delete', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ids']);
    }

    public function test_bulk_destroy_validates_ids_must_exist(): void
    {
        $user = $this->createAdminUser(['core.mail-send-logs.read', 'core.mail-send-logs.delete']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/mail-send-logs/bulk-delete', [
            'ids' => [99999],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ids.0']);
    }

    // ========================================================================
    // statistics 라우트 제거 확인
    // ========================================================================

    public function test_statistics_route_does_not_exist(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/mail-send-logs/statistics');

        // statistics 라우트가 제거되었으므로 404 또는 405 반환
        // (DELETE {mailSendLog} 라우트가 'statistics'를 파라미터로 매칭하여 405 반환 가능)
        $this->assertTrue(in_array($response->status(), [404, 405]));
    }
}
