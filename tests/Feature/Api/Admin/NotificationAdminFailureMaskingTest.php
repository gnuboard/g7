<?php

namespace Tests\Feature\Api\Admin;

use App\Models\NotificationDefinition;
use App\Models\NotificationLog;
use App\Models\Role;
use App\Models\User;
use App\Services\MemberNotificationService;
use App\Services\NotificationDefinitionService;
use App\Services\NotificationLogService;
use App\Services\NotificationTemplateService;
use Database\Seeders\NotificationDefinitionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * 알림 관리 API 가 실패할 때 DB 내부 정보를 응답에 싣지 않는다 (7.1.0 전수 실측 영역 G, 넘어온 보안 관측).
 *
 * 영역 F 후속에서 확장 관리 실패 응답이 SQL·DB 호스트를 화면에 내보낸 사례(O-F7)가 나와, 같은 모양
 * (`'error' => $e->getMessage()`)이 보이는 알림 정의·발송 이력 컨트롤러가 관측으로 넘어왔다. 이 컨트롤러들은
 * 원문을 서버 로그에만 남기고 응답에는 정해진 문구만 싣는다 — 그 계약을 예외 주입으로 잠근다.
 * 회원 알림함·알림 템플릿 컨트롤러도 같은 모양이라 함께 잠근다.
 */
class NotificationAdminFailureMaskingTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    /**
     * 권한·알림 정의를 시딩하고 슈퍼 관리자 토큰을 만들며, 운영 모드로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationDefinitionSeeder::class);
        config(['app.debug' => false]);

        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->value('id'), ['assigned_at' => now(), 'assigned_by' => null]);
        $this->token = $admin->createToken('t')->plainTextToken;
    }

    /**
     * 실측 사례와 같은 모양의 DB 예외(SQL·호스트·DB 이름을 담음)를 만듭니다.
     *
     * @return QueryException DB 예외
     */
    private function dbException(): QueryException
    {
        return new QueryException(
            'mysql',
            'select * from `g7_notification_definitions` where `secret_column` = ?',
            ['x'],
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'secret_column'"),
            ['driver' => 'mysql', 'host' => 'db-internal.example', 'port' => '3306', 'database' => 'g7_secret_db'],
        );
    }

    /**
     * 응답에 DB 내부 정보가 없고 500 일반 문구인지 확인합니다.
     *
     * @param  TestResponse  $response  응답
     * @param  string  $label  실패 메시지에 붙일 경로 이름
     * @return void 반환값 없음
     */
    private function assertMasked(TestResponse $response, string $label): void
    {
        $response->assertStatus(500);
        $body = $response->getContent();

        foreach (['SQLSTATE', 'db-internal.example', 'g7_secret_db', 'secret_column', 'select * from'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$label}: 응답에 내부 정보({$needle})가 실리면 안 된다");
        }
        $this->assertNotEmpty($response->json('message'), "{$label}: 일반 실패 문구는 있어야 한다");
    }

    /**
     * 인증 헤더가 붙은 요청 빌더를 돌려줍니다.
     *
     * @return static 요청 빌더
     */
    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    /**
     * 알림 정의 목록·상세 수정·활성 토글·초기화 실패가 DB 정보를 싣지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects notification_definition_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_notification_definition_failures_do_not_expose_db_detail(): void
    {
        $e = $this->dbException();
        $definitions = Mockery::mock(NotificationDefinitionService::class)->makePartial();
        foreach (['getDefinitions', 'updateDefinition', 'toggleActive', 'createIdentityDefinition', 'markAsDefault'] as $method) {
            $definitions->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(NotificationDefinitionService::class, $definitions);

        $definition = NotificationDefinition::query()->where('type', 'welcome')->firstOrFail();

        $this->assertMasked($this->api()->getJson('/api/admin/notification-definitions'), 'GET definitions');
        $this->assertMasked(
            $this->api()->putJson("/api/admin/notification-definitions/{$definition->id}", ['is_active' => true]),
            'PUT definition'
        );
        $this->assertMasked(
            $this->api()->patchJson("/api/admin/notification-definitions/{$definition->id}/toggle-active"),
            'PATCH definition toggle'
        );
        $this->assertMasked(
            $this->api()->postJson("/api/admin/notification-definitions/{$definition->id}/reset"),
            'POST definition reset'
        );
    }

    /**
     * 알림 발송 이력 목록·삭제·다건 삭제 실패가 DB 정보를 싣지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects notification_log_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_notification_log_failures_do_not_expose_db_detail(): void
    {
        $e = $this->dbException();
        $logs = Mockery::mock(NotificationLogService::class)->makePartial();
        foreach (['getLogs', 'deleteLog', 'bulkDelete'] as $method) {
            $logs->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(NotificationLogService::class, $logs);

        $log = NotificationLog::query()->create([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'extension_type' => 'core',
            'extension_identifier' => 'core',
            'recipient_identifier' => 'masking@example.test',
            'status' => 'skipped',
            'source' => 'notification',
            'sent_at' => now(),
        ]);

        $this->assertMasked($this->api()->getJson('/api/admin/notification-logs'), 'GET logs');
        $this->assertMasked($this->api()->deleteJson("/api/admin/notification-logs/{$log->id}"), 'DELETE log');
        $this->assertMasked($this->api()->postJson('/api/admin/notification-logs/bulk-delete', ['ids' => [$log->id]]), 'POST logs bulk-delete');
    }

    /**
     * 알림 템플릿 수정·활성 토글과 회원 알림함 목록·삭제 실패가 DB 정보를 싣지 않는다.
     *
     * @scenario surface=admin_api
     *
     * @effects notification_template_and_inbox_failure_masks_db_detail
     *
     * @return void 반환값 없음
     */
    public function test_notification_template_and_member_inbox_failures_do_not_expose_db_detail(): void
    {
        $e = $this->dbException();
        $templates = Mockery::mock(NotificationTemplateService::class)->makePartial();
        foreach (['updateTemplate', 'toggleActive'] as $method) {
            $templates->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(NotificationTemplateService::class, $templates);

        $inbox = Mockery::mock(MemberNotificationService::class)->makePartial();
        foreach (['paginateForAdmin', 'deleteByAdmin', 'bulkDeleteByAdmin'] as $method) {
            $inbox->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(MemberNotificationService::class, $inbox);

        $template = NotificationDefinition::query()->where('type', 'welcome')->firstOrFail()->templates()->firstOrFail();

        $this->assertMasked(
            $this->api()->putJson("/api/admin/notification-templates/{$template->id}", ['body' => ['ko' => '본문', 'en' => 'Body']]),
            'PUT template'
        );
        $this->assertMasked($this->api()->patchJson("/api/admin/notification-templates/{$template->id}/toggle-active"), 'PATCH template toggle');
        $this->assertMasked($this->api()->getJson('/api/admin/member-notifications'), 'GET member notifications');
        $this->assertMasked($this->api()->deleteJson('/api/admin/member-notifications/'.Str::uuid()), 'DELETE member notification');
    }
}
