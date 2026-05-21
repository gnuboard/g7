<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class InquiryCrudTest extends TestCase
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

    public function test_index_returns_only_my_inquiries(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'Mine', 'content' => 'x', 'status' => 'received']);
        Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $other->id, 'title' => 'Theirs', 'content' => 'x', 'status' => 'received']);

        Sanctum::actingAs($me);
        $res = $this->getJson('/api/modules/sirsoft-inquiry/inquiries');
        $res->assertOk();
        $titles = array_column($res->json('data'), 'title');
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Theirs', $titles);
    }

    public function test_show_returns_inquiry_for_owner(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($me);
        $res = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}");
        $res->assertOk();
        $res->assertJsonPath('data.uuid', $inquiry->uuid);
        $res->assertJsonPath('data.is_owner', true);
    }

    public function test_show_returns_403_for_others(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $owner->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($other);
        $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}")
            ->assertForbidden();
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/modules/sirsoft-inquiry/inquiries')
            ->assertUnauthorized();
    }

    public function test_store_creates_inquiry(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs($me);

        $res = $this->postJson('/api/modules/sirsoft-inquiry/inquiries', [
            'title' => '홈페이지 리뉴얼',
            'content' => '기존 사이트를 모던하게 개편 부탁드립니다.',
            'category' => 'web',
            'budget_range' => '300-500만원',
            'desired_due_at' => now()->addMonth()->toDateString(),
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.title', '홈페이지 리뉴얼');
        $res->assertJsonPath('data.status', 'received');
        $res->assertJsonPath('data.is_owner', true);

        $this->assertDatabaseHas('inquiries', [
            'user_id' => $me->id,
            'title' => '홈페이지 리뉴얼',
            'status' => 'received',
        ]);
    }

    public function test_update_only_in_received_state(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'old', 'content' => 'old', 'status' => 'received']);

        Sanctum::actingAs($me);
        $this->patchJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}", ['title' => 'new'])
            ->assertOk()
            ->assertJsonPath('data.title', 'new');

        $inquiry->update(['status' => 'quoted', 'quoted_at' => now()]);
        $this->patchJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}", ['title' => 'newer'])
            ->assertForbidden();
    }

    public function test_cancel_transitions_status(): void
    {
        $me = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $me->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        Sanctum::actingAs($me);
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->assertNotNull($inquiry->fresh()->canceled_at);
    }
}
