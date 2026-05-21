<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class AdminInquiryTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        // ModuleRouteServiceProvider는 앱 부트 시 g7_modules 테이블의 active 레코드로
        // 라우트를 등록합니다. 앱 인스턴스는 같은 테스트 클래스 안에서 1회만 생성되므로,
        // 첫 번째 테스트 메서드가 실행되기 전(= parent::setUp() 이전) 에 레코드를 삽입합니다.
        $this->ensureInquiryModuleActive();

        parent::setUp();
    }

    /**
     * 앱 부트 시 라우트 등록을 위해 sirsoft-inquiry를 g7_modules 테이블에 활성 상태로 삽입합니다.
     * parent::setUp()이 createApplication()을 호출하기 전에 실행되어야 합니다.
     */
    private function ensureInquiryModuleActive(): void
    {
        // .env.testing에서 DB 접속 정보를 직접 읽습니다 (app 부트 전이므로 config() 미사용).
        // base_path() 는 앱 부트 전 사용 불가이므로 __DIR__ 에서 상대 경로로 계산합니다.
        $envFile = dirname(__DIR__, 5) . '/.env.testing';
        $env = [];
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^([^=]+)\s*=\s*(.*)$/', $line, $m)) {
                    $env[trim($m[1])] = trim($m[2], "\"' \t");
                }
            }
        }

        $prefix = $env['DB_PREFIX'] ?? 'g7_';
        $table = $prefix . 'modules';

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env['DB_WRITE_HOST'] ?? '127.0.0.1',
                $env['DB_WRITE_PORT'] ?? '3306',
                $env['DB_WRITE_DATABASE'] ?? 'g7_testing'
            );
            $pdo = new \PDO(
                $dsn,
                $env['DB_WRITE_USERNAME'] ?? 'offsettheme',
                $env['DB_WRITE_PASSWORD'] ?? ''
            );
            $pdo->exec("INSERT IGNORE INTO `{$table}` (identifier, vendor, name, version, status, vendor_mode, update_available, created_at, updated_at) VALUES ('sirsoft-inquiry', 'sirsoft', '{\"ko\":\"제작의뢰\",\"en\":\"Inquiry\"}', '1.0.0', 'active', 'bundled', 0, NOW(), NOW())");
        } catch (\Throwable) {
            // 테이블이 아직 없거나 DB 연결 실패 시 무시
        }
    }

    private function makeOperator(): User
    {
        $user = User::factory()->create();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'inquiry.manage'],
            ['name' => ['ko' => '제작의뢰 관리'], 'type' => 'admin']
        );

        $role = Role::create([
            'identifier' => 'inquiry-test-operator-' . Str::random(6),
            'name' => ['ko' => '의뢰 운영자'],
            'is_active' => true,
        ]);

        $role->permissions()->attach($permission->id, [
            'granted_at' => now(),
        ]);

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
        ]);

        return $user;
    }

    public function test_admin_index_lists_all_inquiries(): void
    {
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $u1->id, 'title' => 'A', 'content' => 'x', 'status' => 'received']);
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $u2->id, 'title' => 'B', 'content' => 'x', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $res = $this->getJson('/api/modules/sirsoft-inquiry/admin/inquiries');
        $res->assertOk();
        $titles = array_column($res->json('data'), 'title');
        $this->assertContains('A', $titles);
        $this->assertContains('B', $titles);
    }

    public function test_admin_show_returns_inquiry(): void
    {
        $owner = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $this->getJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $inquiry->uuid);
    }

    public function test_admin_routes_require_inquiry_manage_permission(): void
    {
        $plain = User::factory()->create();
        Sanctum::actingAs($plain);
        $this->getJson('/api/modules/sirsoft-inquiry/admin/inquiries')
            ->assertForbidden();
    }

    public function test_mark_paid_offline_transitions_to_in_progress(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted', 'quoted_at' => now()]);
        $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);

        Sanctum::actingAs($this->makeOperator());

        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/mark-paid-offline")
            ->assertOk();
        $this->assertSame('in_progress', $inquiry->fresh()->status->value);
    }

    public function test_mark_completed_from_in_progress(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'in_progress', 'started_at' => now(), 'payment_id' => 'p1']);

        Sanctum::actingAs($this->makeOperator());
        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/complete")
            ->assertOk();
        $this->assertSame('completed', $inquiry->fresh()->status->value);
    }

    public function test_admin_cancel_marks_canceled_by_operator(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($this->makeOperator());
        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/cancel")
            ->assertOk();
        $this->assertSame('canceled', $inquiry->fresh()->status->value);

        $sys = $inquiry->fresh()->messages()->where('sender_role', 'system')->latest()->first();
        $this->assertSame('inquiry::system.message.canceled_by_operator', $sys->meta['key']);
    }
}
