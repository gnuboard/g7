<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InquiryFlowTest extends TestCase
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

    public function test_full_client_flow_create_message_cancel(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // 1) Create inquiry
        $created = $this->postJson('/api/modules/sirsoft-inquiry/inquiries', [
            'title' => '쇼핑몰 리뉴얼',
            'content' => '디자인 + 결제 연동',
            'category' => 'web',
        ])->assertCreated()->json('data');
        $uuid = $created['uuid'];

        // 2) Upload inquiry body attachment
        $upload = $this->postJson(
            "/api/modules/sirsoft-inquiry/inquiries/{$uuid}/attachments",
            ['file' => UploadedFile::fake()->create('brief.pdf', 50, 'application/pdf')]
        )->assertCreated()->json('data');

        // 3) Post message
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/messages", [
            'body' => '추가 안내 드립니다',
        ])->assertCreated();

        // 4) List inquiries — should appear
        $list = $this->getJson('/api/modules/sirsoft-inquiry/inquiries')->assertOk()->json('data');
        $this->assertTrue(collect($list)->contains('uuid', $uuid));

        // 5) Show with attachments + messages embedded
        $show = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->json('data');
        $this->assertCount(1, $show['attachments']);

        $msgs = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/messages")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $msgs);

        // 6) Cancel
        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        // 7) After cancel, system message appended
        $msgs = $this->getJson("/api/modules/sirsoft-inquiry/inquiries/{$uuid}/messages")->json('data');
        $systemMsg = collect($msgs)->firstWhere('sender_role', 'system');
        $this->assertNotNull($systemMsg);
        $this->assertSame('inquiry::system.message.canceled_by_client', $systemMsg['meta']['key']);
    }
}
