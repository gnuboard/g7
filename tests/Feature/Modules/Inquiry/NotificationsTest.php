<?php

namespace Tests\Feature\Modules\Inquiry;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Notifications\InquiryReceivedToOperators;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected array $requiredExtensions = ['modules/_bundled/sirsoft-inquiry'];

    protected function setUp(): void
    {
        $this->ensureInquiryModuleActive();
        parent::setUp();
        $this->app->register(\Modules\Sirsoft\Inquiry\Providers\InquiryServiceProvider::class);
        $this->app['translator']->addNamespace(
            'inquiry',
            base_path('modules/_bundled/sirsoft-inquiry/src/lang')
        );
    }

    private function ensureInquiryModuleActive(): void
    {
        $envFile = dirname(__DIR__, 4) . '/.env.testing';
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

    public function test_received_notification_renders_mail(): void
    {
        $client = User::factory()->create(['name' => '홍길동']);
        $inquiry = Inquiry::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'title' => '홈페이지 리뉴얼',
            'content' => 'X',
            'status' => 'received',
        ]);

        $operator = User::factory()->create();
        $notification = new InquiryReceivedToOperators($inquiry);

        $mail = $notification->toMail($operator);
        $this->assertStringContainsString('제작의뢰', $mail->subject ?? '');
        $payload = $notification->toArray($operator);
        $this->assertSame($inquiry->uuid, $payload['inquiry_uuid']);
        $this->assertSame('inquiry_received', $payload['type']);
    }

    public function test_quote_issued_renders(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted']);

        $n = new \Modules\Sirsoft\Inquiry\Notifications\QuoteIssued($inquiry, ['version' => 2, 'total' => 1500000]);
        $mail = $n->toMail($client);
        $this->assertStringContainsString('견적', $mail->subject);
        $payload = $n->toArray($client);
        $this->assertSame('quote_issued', $payload['type']);
        $this->assertSame(2, $payload['params']['version']);
    }
}
