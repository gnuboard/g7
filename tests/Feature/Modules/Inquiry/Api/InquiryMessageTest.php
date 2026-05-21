<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class InquiryMessageTest extends TestCase
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

    private function setupInquiry(): array
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'title' => 'X', 'content' => 'Y', 'status' => 'received',
        ]);
        return [$user, $inquiry];
    }

    public function test_post_message_creates_record(): void
    {
        [$user, $inquiry] = $this->setupInquiry();
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages", [
            'body' => '추가 자료 첨부합니다',
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.body', '추가 자료 첨부합니다');
        $res->assertJsonPath('data.sender_role', 'client');

        $this->assertDatabaseHas('inquiry_messages', [
            'inquiry_id' => $inquiry->id,
            'body' => '추가 자료 첨부합니다',
            'sender_role' => 'client',
        ]);
    }

    public function test_index_returns_messages_ordered(): void
    {
        [$user, $inquiry] = $this->setupInquiry();
        $inquiry->messages()->create(['sender_user_id' => $user->id, 'sender_role' => 'client', 'body' => 'first', 'created_at' => now()->subHour()]);
        $inquiry->messages()->create(['sender_user_id' => $user->id, 'sender_role' => 'client', 'body' => 'second', 'created_at' => now()]);

        Sanctum::actingAs($user);
        $res = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages");

        $res->assertOk();
        $bodies = array_column($res->json('data'), 'body');
        $this->assertSame(['first', 'second'], $bodies);
    }

    public function test_post_message_marks_opposite_role_messages_as_read(): void
    {
        [$user, $inquiry] = $this->setupInquiry();
        $op = User::factory()->create();
        $inquiry->messages()->create(['sender_user_id' => $op->id, 'sender_role' => 'operator', 'body' => 'hi', 'read_at' => null]);

        Sanctum::actingAs($user);
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages", ['body' => 'reply']);

        $this->assertNotNull($inquiry->fresh()->messages()->where('sender_role', 'operator')->first()->read_at);
    }

    public function test_index_requires_owner(): void
    {
        [, $inquiry] = $this->setupInquiry();
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/messages")
            ->assertForbidden();
    }
}
