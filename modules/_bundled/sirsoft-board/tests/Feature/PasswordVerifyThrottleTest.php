<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 비밀번호 확인 엔드포인트 요청 제한 — 비회원 댓글·비회원 글 수정 확인도 비밀글 확인과 같은 제한을 받는다.
 *
 * 비밀글 비밀번호 확인만 IP 분당 10회로 묶여 있고, 비회원 댓글 비밀번호 확인은 제한이 없으며 비회원 글의
 * 수정·삭제용 확인은 그룹 제한(분당 600회)뿐이었다. 같은 비밀번호를 다른 문으로 대입할 수 있었다.
 * 세 엔드포인트가 같은 제한기를 써서 합산으로 센다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-board/tests/scenarios/password-verify-throttle.yaml
 */
#[Group('board')]
#[Group('password')]
class PasswordVerifyThrottleTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'pw-verify-throttle';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '비밀번호 확인 제한 테스트 게시판', 'en' => 'Password Verify Throttle Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 비회원 권한을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
    }

    /**
     * 게시판 API 주소를 만듭니다.
     *
     * @param  string  $suffix  `boards/{slug}/` 뒤의 경로
     * @return string 주소
     */
    private function boardUrl(string $suffix): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/{$suffix}";
    }

    /**
     * 비회원 댓글 비밀번호 확인은 같은 IP 에서 분당 10회까지만 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=comment_verify_password
     *
     * @effects eleventh_attempt_returns_429
     */
    public function test_comment_password_verify_is_rate_limited(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => null, 'password' => Hash::make('secret123')]);
        $url = $this->boardUrl("comments/{$commentId}/verify-password");

        for ($i = 1; $i <= 10; $i++) {
            $this->postJson($url, ['password' => 'wrong-'.$i])->assertStatus(401);
        }

        $blocked = $this->postJson($url, ['password' => 'secret123']);

        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertNotSame('Too Many Attempts.', $blocked->json('message'));
    }

    /**
     * 비회원 글의 수정·삭제용 비밀번호 확인도 같은 제한을 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=post_verify_password_for_modify
     *
     * @effects eleventh_attempt_returns_429
     */
    public function test_post_modify_password_verify_is_rate_limited(): void
    {
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('secret123')]);
        $url = $this->boardUrl("posts/{$postId}/verify-password-for-modify");

        for ($i = 1; $i <= 10; $i++) {
            $this->assertNotSame(429, $this->postJson($url, ['password' => 'wrong-'.$i])->status());
        }

        $this->postJson($url, ['password' => 'secret123'])->assertStatus(429);
    }

    /**
     * 세 확인 엔드포인트는 한 제한기로 합산한다 — 문을 바꿔 가며 대입해도 합쳐서 10회다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=shared_across_endpoints
     *
     * @effects verify_endpoints_share_one_counter
     */
    public function test_verify_endpoints_share_one_counter(): void
    {
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('secret123'), 'is_secret' => true]);
        $commentId = $this->createTestComment($postId, ['user_id' => null, 'password' => Hash::make('secret123')]);

        for ($i = 1; $i <= 4; $i++) {
            $this->postJson($this->boardUrl("posts/{$postId}/verify-password"), ['password' => 'wrong-'.$i]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson($this->boardUrl("posts/{$postId}/verify-password-for-modify"), ['password' => 'wrong-'.$i]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson($this->boardUrl("comments/{$commentId}/verify-password"), ['password' => 'wrong-'.$i]);
        }

        $this->postJson($this->boardUrl("comments/{$commentId}/verify-password"), ['password' => 'secret123'])
            ->assertStatus(429);
        $this->postJson($this->boardUrl("posts/{$postId}/verify-password-for-modify"), ['password' => 'secret123'])
            ->assertStatus(429);
        $this->postJson($this->boardUrl("posts/{$postId}/verify-password"), ['password' => 'secret123'])
            ->assertStatus(429);
    }

    /**
     * 비밀번호를 실어 보낸 수정·삭제 요청도 확인 요청과 같은 제한기로 센다.
     *
     * 수정·삭제 요청은 비밀번호를 본문으로 직접 받아 비교한다. 확인 엔드포인트만 묶으면 이 문으로 분당 600회까지
     * 비밀번호를 대입할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=modify_request_carrying_password
     *
     * @effects eleventh_attempt_returns_429
     * @effects verify_endpoints_share_one_counter
     */
    public function test_modify_requests_carrying_a_password_share_the_verify_counter(): void
    {
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('secret123')]);
        $commentId = $this->createTestComment($postId, ['user_id' => null, 'password' => Hash::make('secret123')]);

        $attempts = [
            ['putJson', "posts/{$postId}/comments/{$commentId}"],
            ['deleteJson', "posts/{$postId}/comments/{$commentId}"],
            ['putJson', "posts/{$postId}"],
            ['deleteJson', "posts/{$postId}"],
        ];

        for ($i = 0; $i < 10; $i++) {
            [$method, $suffix] = $attempts[$i % 4];
            $status = $this->{$method}($this->boardUrl($suffix), ['password' => 'wrong-'.$i, 'content' => '대입'])->status();

            $this->assertNotSame(429, $status, "{$i}번째 {$method} {$suffix}");
            $this->assertNotSame(200, $status, "{$i}번째 {$method} {$suffix}");
        }

        // 11번째부터는 문을 바꿔도, 맞는 비밀번호여도 막힌다.
        foreach ($attempts as [$method, $suffix]) {
            $this->{$method}($this->boardUrl($suffix), ['password' => 'secret123', 'content' => '대입'])->assertStatus(429);
        }
        $this->postJson($this->boardUrl("comments/{$commentId}/verify-password"), ['password' => 'secret123'])->assertStatus(429);
    }

    /**
     * 비밀번호를 싣지 않은 수정·삭제 요청은 이 제한기가 세지 않는다 — 회원의 정상 수정이 막히지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=modify_request_without_password
     *
     * @effects requests_without_password_are_not_counted
     */
    public function test_modify_requests_without_a_password_are_not_counted(): void
    {
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('secret123')]);
        $commentId = $this->createTestComment($postId, ['user_id' => null, 'password' => Hash::make('secret123')]);

        for ($i = 0; $i < 12; $i++) {
            $this->assertNotSame(429, $this->putJson($this->boardUrl("posts/{$postId}/comments/{$commentId}"), ['content' => '수정'])->status());
            $this->assertNotSame(429, $this->putJson($this->boardUrl("posts/{$postId}"), ['password' => '', 'content' => '수정'])->status());
        }

        // 세지 않았으므로 확인 요청은 처음부터 열 번을 다 쓸 수 있다.
        $this->postJson($this->boardUrl("comments/{$commentId}/verify-password"), ['password' => 'secret123'])->assertStatus(200);
    }

    /**
     * 글 번호와 비밀번호를 함께 받는 나머지 문(글쓰기 폼 데이터 조회·첨부 업로드·본문 미디어 업로드)도 같은 제한기로 센다.
     *
     * 세 요청 모두 비회원 글의 비밀번호를 직접 받아 비교한다. 수정·삭제 요청만 묶으면 이 문으로 계속 대입할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=modify_request_carrying_password
     *
     * @effects eleventh_attempt_returns_429
     * @effects verify_endpoints_share_one_counter
     */
    public function test_form_data_and_upload_requests_carrying_a_password_share_the_verify_counter(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write', 'attachments.upload']);
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('secret123')]);

        $attempt = function (int $door, string $password) use ($postId) {
            return match ($door) {
                0 => $this->getJson($this->boardUrl("posts/form-data?post_id={$postId}&password=".urlencode($password))),
                1 => $this->postJson($this->boardUrl('attachments'), ['post_id' => $postId, 'password' => $password]),
                default => $this->postJson($this->boardUrl('content-media'), ['post_id' => $postId, 'password' => $password]),
            };
        };

        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $attempt($i % 3, 'wrong-'.$i)->status(), "{$i}번째 시도");
        }

        // 11번째부터는 문을 바꿔도, 맞는 비밀번호여도 막힌다.
        foreach ([0, 1, 2] as $door) {
            $attempt($door, 'secret123')->assertStatus(429);
        }
        $this->postJson($this->boardUrl("posts/{$postId}/verify-password-for-modify"), ['password' => 'secret123'])->assertStatus(429);
    }

    /**
     * 비밀번호를 싣지 않은 폼 데이터 조회·업로드 요청은 이 제한기가 세지 않는다 — 정상 글쓰기가 막히지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=modify_request_without_password
     *
     * @effects requests_without_password_are_not_counted
     */
    public function test_form_data_and_upload_requests_without_a_password_are_not_counted(): void
    {
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write', 'attachments.upload']);
        $postId = $this->createTestPost(['user_id' => null, 'password' => Hash::make('secret123')]);

        for ($i = 0; $i < 12; $i++) {
            $this->assertNotSame(429, $this->getJson($this->boardUrl('posts/form-data'))->status());
            $this->assertNotSame(429, $this->postJson($this->boardUrl('attachments'), [])->status());
            $this->assertNotSame(429, $this->postJson($this->boardUrl('content-media'), ['password' => ''])->status());
        }

        $this->postJson($this->boardUrl("posts/{$postId}/verify-password-for-modify"), ['password' => 'secret123'])->assertStatus(200);
    }
}
