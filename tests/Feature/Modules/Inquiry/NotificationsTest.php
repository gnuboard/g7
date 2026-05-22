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

    public function test_payment_confirmed_renders(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'in_progress']);
        $n = new \Modules\Sirsoft\Inquiry\Notifications\PaymentConfirmed($inquiry, ['order' => 'order-uuid']);
        $this->assertSame('payment_confirmed', $n->toArray($client)['type']);
    }

    public function test_canceled_by_operator_uses_operator_line(): void
    {
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'canceled']);
        $n = new \Modules\Sirsoft\Inquiry\Notifications\InquiryCanceled($inquiry, ['actor' => 'operator']);
        $mail = $n->toMail($client);
        $this->assertStringContainsString('운영자', $mail->subject . ' ' . implode(' ', $mail->introLines ?? []));
    }

    public function test_quote_issued_notification_sent_on_transition(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $sm = app(\Modules\Sirsoft\Inquiry\Services\InquiryStateMachine::class);
        $inquiry->quotes()->create(['version' => 1, 'total_amount' => 1000000, 'currency' => 'KRW', 'status' => 'issued', 'issued_at' => now()]);
        $sm->transition(
            $inquiry,
            \Modules\Sirsoft\Inquiry\Enums\TransitionEvent::IssueQuote,
            actorUserId: $client->id,
            payload: ['quote_version' => 1, 'quote_total' => 1000000],
        );

        Notification::assertSentTo([$client], \Modules\Sirsoft\Inquiry\Notifications\QuoteIssued::class);
    }

    public function test_new_message_notification_sent_to_operators_on_client_message(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $operator = User::factory()->create();

        // 운영자에게 inquiry.notify 권한 부여 — PolicyTest 패턴 참고
        $perm = \App\Models\Permission::firstOrCreate(['identifier' => 'inquiry.notify'], ['name' => 'Inquiry Notify']);
        $role = \App\Models\Role::firstOrCreate(['identifier' => 'inquiry-notify-test'], ['name' => 'Inquiry Notify']);
        $role->permissions()->syncWithoutDetaching([$perm->id => ['granted_at' => now()]]);
        $operator->roles()->syncWithoutDetaching([$role->id => ['assigned_at' => now()]]);

        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);
        $msg = $inquiry->messages()->create(['sender_user_id' => $client->id, 'sender_role' => 'client', 'body' => '안녕하세요']);
        \Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted::dispatch($msg);

        Notification::assertSentTo([$operator], \Modules\Sirsoft\Inquiry\Notifications\NewMessageNotification::class);
    }

    public function test_canceled_by_operator_notification_to_client_only(): void
    {
        Notification::fake();
        $client = User::factory()->create();
        $operator = User::factory()->create();
        $inquiry = Inquiry::create(['uuid' => (string) Str::uuid(), 'user_id' => $client->id, 'title' => 'X', 'content' => 'Y', 'status' => 'received']);

        $sm = app(\Modules\Sirsoft\Inquiry\Services\InquiryStateMachine::class);
        $sm->transition(
            $inquiry,
            \Modules\Sirsoft\Inquiry\Enums\TransitionEvent::Cancel,
            actorUserId: $operator->id,
            payload: ['actor' => 'operator'],
        );

        Notification::assertSentTo([$client], \Modules\Sirsoft\Inquiry\Notifications\InquiryCanceled::class);
    }
}
