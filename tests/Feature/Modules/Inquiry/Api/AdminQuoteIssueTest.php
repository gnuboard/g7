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

class AdminQuoteIssueTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        $this->ensureInquiryModuleActive();
        parent::setUp();
    }

    private function ensureInquiryModuleActive(): void
    {
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

    public function test_issue_quote_transitions_to_quoted(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $res = $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes", [
            'tax_amount' => 0,
            'valid_until' => now()->addDays(14)->toDateString(),
            'items' => [
                ['name' => '메인 페이지', 'qty' => 1, 'unit_price' => 1000000],
                ['name' => '상품 페이지', 'qty' => 3, 'unit_price' => 200000],
            ],
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.version', 1);
        $res->assertJsonPath('data.status', 'issued');
        $this->assertSame(1600000, (int) $res->json('data.total_amount'));

        $this->assertSame('quoted', $inquiry->fresh()->status->value);
    }

    public function test_issue_quote_increments_version(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $op = $this->makeOperator();
        Sanctum::actingAs($op);

        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes", [
            'items' => [['name' => 'A', 'qty' => 1, 'unit_price' => 1000000]],
        ])->assertCreated();

        // Revoke to get back to received state
        $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes/" . $inquiry->fresh()->quotes()->first()->id . "/revoke")
            ->assertOk();

        $res2 = $this->postJson("/api/modules/sirsoft-inquiry/admin/inquiries/{$inquiry->uuid}/quotes", [
            'items' => [['name' => 'B', 'qty' => 1, 'unit_price' => 1200000]],
        ]);
        $res2->assertCreated();
        $this->assertSame(2, (int) $res2->json('data.version'));
    }
}
