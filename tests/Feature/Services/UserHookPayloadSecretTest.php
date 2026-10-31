<?php

namespace Tests\Feature\Services;

use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 관리자 회원 추가·수정 훅이 넘기는 원본 데이터에 비밀번호 원문이 실리지 않는다 (#138 후속 — 설계 결정)
 *
 * `core.user.after_create`·`after_update` 는 요청 원본 데이터(`$originalData`)를 넘긴다. 관리자가 회원을 추가하거나
 * 비밀번호를 바꾸면 그 안에 비밀번호 원문이 있고, 큐로 실행되는 구독자(활동 로그·게시판 알림 설정·마케팅 동의 등)마다
 * 작업 표에 사본이 남았다. 구독자 중 비밀번호를 읽는 곳은 없다 — 회원가입 훅과 같은 방식으로 넘기기 전에 뺀다.
 */
class UserHookPayloadSecretTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 큐와 알림을 가짜로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
    }

    /**
     * 관리자 회원 추가·비밀번호 변경 뒤 큐에 쌓인 어떤 작업에도 비밀번호 원문이 없다.
     *
     * @scenario case=admin_create_and_update
     *
     * @effects queued_user_hook_jobs_hold_no_plaintext_password
     *
     * @return void 반환값 없음
     */
    public function test_user_create_and_update_hooks_carry_no_plaintext_password(): void
    {
        $service = app(UserService::class);
        $created = 'Pw-create-'.bin2hex(random_bytes(4));
        $updated = 'Pw-update-'.bin2hex(random_bytes(4));

        $user = $service->createUser([
            'name' => 'secret-create',
            'email' => 'secret-create@example.test',
            'password' => $created,
            'password_confirmation' => $created,
        ]);
        $service->updateUser($user, [
            'password' => $updated,
            'password_confirmation' => $updated,
        ]);

        $pushed = Queue::pushedJobs();
        $this->assertNotEmpty($pushed, '회원 추가·수정 훅의 큐 구독자가 작업을 쌓지 않았다 — 이 검사가 아무것도 재지 않는다');
        foreach ($pushed as $jobs) {
            foreach ($jobs as $entry) {
                $payload = serialize($entry['job']);
                $this->assertStringNotContainsString($created, $payload, '회원 추가 훅 작업에 비밀번호 원문이 실렸다');
                $this->assertStringNotContainsString($updated, $payload, '회원 수정 훅 작업에 비밀번호 원문이 실렸다');
            }
        }
    }
}
