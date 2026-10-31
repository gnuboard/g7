<?php

namespace Tests\Feature\Api\Auth;

use App\Extension\HookManager;
use App\Models\Role;
use Database\Seeders\IdentityPolicySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 가입 완료 훅이 넘기는 가입 데이터에 비밀번호 원문이 실리지 않는다 (#138 후속 — 설계 결정)
 *
 * `core.auth.after_register` 의 `registration_data` 는 검증된 가입 요청 전체였다. 큐로 실행되는 구독자마다 작업이
 * 하나씩 쌓이므로, 데이터베이스 큐를 쓰는 사이트는 가입 1건마다 비밀번호 원문 사본이 작업 표(실패하면 실패 작업 표)에
 * 구독자 수만큼 남았다. 같은 가입 흐름의 동의 기록 훅(`core.auth.record_consents`)도 가입 데이터 전체를 넘겨 큐 구독자
 * (활동 로그)에 사본을 남겼다. 구독자 중 비밀번호를 읽는 곳은 없다 — 두 훅 모두 넘기기 전에 뺀다.
 */
class RegisterHookPayloadSecretTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 가입 API 가 도는 최소 환경(역할·권한·본인인증 정책)을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();

        $this->seed(RolePermissionSeeder::class);
        Role::firstOrCreate(['identifier' => 'user'], ['name' => ['ko' => '사용자', 'en' => 'User']]);
        $this->seed(IdentityPolicySeeder::class);
    }

    /**
     * 가입 완료 훅의 가입 데이터에서 비밀번호 두 칸이 빠지고, 큐에 쌓인 어떤 작업에도 비밀번호 원문이 없다.
     *
     * @scenario case=register_payload_secret
     *
     * @effects registration_data_excludes_password, queued_hook_jobs_hold_no_plaintext_password
     *
     * @return void 반환값 없음
     */
    public function test_after_register_payload_carries_no_plaintext_password(): void
    {
        $captured = null;
        HookManager::addAction('core.auth.after_register', function ($user, $context) use (&$captured) {
            $captured = $context;
        }, 1);

        $secret = 'Pw-secret-'.bin2hex(random_bytes(4));

        $this->postJson('/api/auth/register', [
            'name' => '테스트',
            'email' => 'secret@example.com',
            'password' => $secret,
            'password_confirmation' => $secret,
            'agree_terms' => true,
            'agree_privacy' => true,
        ])->assertStatus(201);

        $this->assertIsArray($captured, '가입 완료 훅이 발화하지 않았다');
        $data = $captured['registration_data'] ?? null;
        $this->assertIsArray($data);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_confirmation', $data);
        $this->assertSame('secret@example.com', $data['email'] ?? null, '비밀번호 외 가입 데이터는 그대로 넘긴다');

        $pushed = Queue::pushedJobs();
        $this->assertNotEmpty($pushed, '가입 완료 훅의 큐 구독자가 작업을 쌓지 않았다 — 이 검사가 아무것도 재지 않는다');
        foreach ($pushed as $jobs) {
            foreach ($jobs as $entry) {
                $this->assertStringNotContainsString($secret, serialize($entry['job']), '큐 작업에 비밀번호 원문이 실렸다');
            }
        }
    }
}
