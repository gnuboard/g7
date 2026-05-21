<?php

namespace Tests\Feature\Modules\Inquiry\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Sirsoft\Inquiry\Models\Inquiry;
use Modules\Sirsoft\Inquiry\Services\InquiryPaymentBridge;
use Tests\TestCase;

class PaymentBridgeTest extends TestCase
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

    public function test_handle_order_paid_transitions_to_in_progress(): void
    {
        $user = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'title' => 'X', 'content' => 'Y', 'status' => 'quoted', 'quoted_at' => now()]);
        $quote = $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);

        $bridge = app(InquiryPaymentBridge::class);

        $fakeOrder = (object) [
            'id' => 999,
            'uuid' => 'order-test-uuid',
            'status' => 'paid',
            'meta' => json_encode([
                'inquiry_id' => $inquiry->id,
                'quote_id' => $quote->id,
            ]),
        ];

        $bridge->handleOrderPaid($fakeOrder);

        $this->assertSame('in_progress', $inquiry->fresh()->status->value);
        $this->assertSame('accepted', $quote->fresh()->status->value);
        $this->assertSame('order-test-uuid', $inquiry->fresh()->payment_id);
    }

    public function test_handle_order_paid_ignores_unrelated_orders(): void
    {
        $bridge = app(InquiryPaymentBridge::class);
        $fakeOrder = (object) ['status' => 'paid', 'meta' => json_encode(['unrelated' => true])];
        $bridge->handleOrderPaid($fakeOrder); // no exception
        $this->assertTrue(true);
    }
}
