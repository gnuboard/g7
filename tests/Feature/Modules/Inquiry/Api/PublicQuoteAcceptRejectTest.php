<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Tests\TestCase;

class PublicQuoteAcceptRejectTest extends TestCase
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

    private function quotedInquiry(): array
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted', 'quoted_at' => now()]);
        $quote = $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);
        return [$user, $inquiry, $quote];
    }

    public function test_reject_transitions_to_received(): void
    {
        [$user, $inquiry, $quote] = $this->quotedInquiry();
        Sanctum::actingAs($user);

        $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/quotes/{$quote->id}/reject")
            ->assertOk();

        $this->assertSame('received', $inquiry->fresh()->status->value);
        $this->assertSame('rejected', $quote->fresh()->status->value);
    }

    public function test_accept_returns_payment_url_or_pending(): void
    {
        [$user, $inquiry, $quote] = $this->quotedInquiry();
        Sanctum::actingAs($user);

        $res = $this->postJson("/api/modules/sirsoft-inquiry/inquiries/{$inquiry->uuid}/quotes/{$quote->id}/accept");
        $res->assertOk();

        // ecommerce 가 없으면 422 또는 pending message
        // ecommerce 있으면 redirect_url 반환
        $body = $res->json();
        $this->assertTrue(
            isset($body['data']['redirect_url']) || isset($body['data']['message']),
            'Response should contain redirect_url or pending message'
        );
    }
}
