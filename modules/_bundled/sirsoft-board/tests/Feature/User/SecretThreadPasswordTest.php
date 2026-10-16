<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../Concerns/BuildsSecretThread.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Tests\Concerns\BuildsSecretThread;

/**
 * 스레드 비밀번호 — 답변 화면에서 원글(비회원 조상) 비밀번호로 답변을 연다 (#789 제보 A·D5·D16)
 *
 * 답변 주소로 바로 들어온 비회원은 열 원글 비밀번호 폼이 없어 「로그인 후 확인」만 봤다.
 * `verify-thread-password` 는 가까운 조상부터 비회원·비밀번호 있음·살아 있는 글을 후보로 비밀번호를
 * 맞추고, 맞은 조상에 grant 를 더해 경로 규칙으로 답변을 연다. 응답에는 어느 조상이 맞았는지 싣지 않는다.
 *
 * @scenario link=board-secret-thread-password
 */
class SecretThreadPasswordTest extends BoardTestCase
{
    use BuildsSecretThread;

    /**
     * 시험 게시판 슬러그
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-thread-password';
    }

    /**
     * 기본 게시판 속성 (비밀글·답변 사용)
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '스레드 비밀번호 시험', 'en' => 'Secret Thread Password'],
            'is_active' => true,
            'use_reply' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 시험마다 비밀번호 확인 제한 카운터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear(md5('sirsoft-board.secret-password-verify'.'127.0.0.1'));
    }

    /**
     * 스레드 비밀번호 확인 주소를 반환합니다.
     *
     * @param  string  $key  스레드 글 키
     * @return string 주소
     */
    private function threadPasswordUrl(string $key): string
    {
        return $this->threadPostUrl($key).'/verify-thread-password';
    }

    /**
     * 비회원 원글(G) → 관리자 비밀 답변(R) 스레드를 만듭니다.
     *
     * @param  bool  $rootSecret  원글을 비밀글로 만들지 여부 (false 면 공개 질문 — D16)
     * @return void 반환값 없음
     */
    private function buildGuestRootThread(bool $rootSecret = true): void
    {
        $this->grantThreadMemberPermissions();
        $admin = $this->createThreadMember();
        $this->threadUsers['admin'] = $admin;
        $this->thread['G'] = $this->createThreadPost(null, null, 0, 'g-pass', $rootSecret, '비회원 질문 G');
        $this->thread['R'] = $this->createThreadPost($this->thread['G'], $admin, 1, null, true, '관리자 답변 R');
    }

    /**
     * 답변 주소로 바로 들어온 비회원이 원글 비밀번호로 답변 본문을 받습니다.
     *
     * @scenario case=guest_root_password_on_reply
     *
     * @effects thread_password_correct_returns_200_and_shows_body, thread_password_issues_view_token
     *
     * @return void 반환값 없음
     */
    public function test_guest_opens_reply_with_root_password(): void
    {
        $this->buildGuestRootThread();

        $this->assertNull($this->threadContentFor('R', null));

        $response = $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass']);

        $response->assertOk();
        $this->assertSame('관리자 답변 R 본문', $response->json('data.content'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $response->json('secret_view_token'));
        $this->assertNotNull($response->json('secret_view_expires_at'));

        // 받은 토큰으로 다음 요청에서도 열린다 (경로 규칙 — G 의 grant 가 R 을 연다)
        $this->assertSame('관리자 답변 R 본문', $this->threadContentFor('R', null, $response->json('secret_view_token')));
        $this->assertSame('비회원 질문 G 본문', $this->threadContentFor('G', null, $response->json('secret_view_token')));
    }

    /**
     * 깊은 답변에서 가운데 비회원 조상의 비밀번호로도 열 수 있습니다.
     *
     * @scenario case=middle_guest_ancestor_password
     *
     * @effects thread_password_matches_nearest_guest_ancestor
     *
     * @return void 반환값 없음
     */
    public function test_middle_guest_ancestor_password_opens_descendant(): void
    {
        $this->buildSecretThread();
        $member = $this->threadUsers['d'];
        // E(비회원 e-pass) 아래 회원 비밀 답변 F
        $this->thread['F'] = $this->createThreadPost($this->thread['E'], $member, 3, null, true, '답변 F');

        $response = $this->postJson($this->threadPasswordUrl('F'), ['password' => 'e-pass']);

        $response->assertOk();
        $this->assertSame('답변 F 본문', $response->json('data.content'));
        // E 의 grant 는 형제 가지 B·D 를 열지 않는다
        $token = $response->json('secret_view_token');
        $this->assertNull($this->threadContentFor('B', null, $token));
        $this->assertNotNull($this->threadContentFor('E', null, $token));
    }

    /**
     * 비회원이 쓴 공개 질문도 비밀번호가 있으면 비밀 답변을 엽니다 (D16).
     *
     * @scenario case=guest_public_question_password
     *
     * @effects public_guest_question_password_opens_secret_answer
     *
     * @return void 반환값 없음
     */
    public function test_public_guest_question_password_opens_secret_answer(): void
    {
        $this->buildGuestRootThread(rootSecret: false);

        $detail = $this->getJson($this->threadPostUrl('R'))->assertOk();
        $this->assertNull($detail->json('data.content'));
        $this->assertTrue($detail->json('data.thread_password_available'));

        $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])
            ->assertOk()
            ->assertJsonPath('data.content', '관리자 답변 R 본문');
    }

    /**
     * 오답은 403 이고 토큰을 주지 않습니다.
     *
     * @scenario case=wrong_password
     *
     * @effects thread_password_wrong_returns_403_and_clears_input
     *
     * @return void 반환값 없음
     */
    public function test_wrong_password_is_rejected_without_token(): void
    {
        $this->buildGuestRootThread();

        $response = $this->postJson($this->threadPasswordUrl('R'), ['password' => 'nope']);

        $response->assertStatus(403);
        $this->assertSame(__('sirsoft-board::messages.posts.password_incorrect'), $response->json('message'));
        $this->assertNull($response->json('secret_view_token'));
    }

    /**
     * 원글(루트) 자체에는 쓸 수 없습니다 — 원글은 글 비밀번호 확인으로 엽니다.
     *
     * @scenario case=root_target
     *
     * @effects thread_password_not_applicable_on_root
     *
     * @return void 반환값 없음
     */
    public function test_root_target_is_not_applicable(): void
    {
        $this->buildGuestRootThread();

        $this->postJson($this->threadPasswordUrl('G'), ['password' => 'g-pass'])
            ->assertStatus(400)
            ->assertJsonPath('message', __('sirsoft-board::messages.posts.thread_password_not_applicable'));
    }

    /**
     * 비회원 조상이 없는 답변에는 쓸 수 없습니다.
     *
     * @scenario case=no_guest_ancestor
     *
     * @effects thread_password_not_applicable_without_guest_ancestor
     *
     * @return void 반환값 없음
     */
    public function test_reply_without_guest_ancestor_is_not_applicable(): void
    {
        $this->buildSecretThread();

        $this->postJson($this->threadPasswordUrl('B'), ['password' => 'anything'])->assertStatus(400);
        $this->assertFalse($this->getJson($this->threadPostUrl('B'))->assertOk()->json('data.thread_password_available'));
    }

    /**
     * 삭제된 비회원 조상은 후보가 아닙니다.
     *
     * @scenario case=trashed_guest_ancestor
     *
     * @effects trashed_guest_ancestor_not_candidate
     *
     * @return void 반환값 없음
     */
    public function test_trashed_guest_ancestor_is_ignored(): void
    {
        $this->buildGuestRootThread();
        $this->trashThreadPost('G');

        $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])->assertStatus(400);
    }

    /**
     * 이미 가진 토큰을 보내면 새 토큰을 만들지 않고 같은 토큰에 grant 를 더합니다 (D4).
     *
     * @scenario case=token_reuse
     *
     * @effects same_token_accumulates_grants
     *
     * @return void 반환값 없음
     */
    public function test_existing_token_is_reused(): void
    {
        $this->buildGuestRootThread();
        $this->thread['Q'] = $this->createThreadPost(null, null, 0, 'q-pass', true, '다른 비회원 글 Q');

        $first = $this->verifyThreadGuestPassword('Q', 'q-pass');
        $second = $this->withHeaders(['X-Board-Secret-View-Token' => $first])
            ->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])
            ->assertOk()
            ->json('secret_view_token');

        $this->assertSame($first, $second);
        $this->assertNotNull($this->threadContentFor('Q', null, $second));
        $this->assertNotNull($this->threadContentFor('R', null, $second));
    }

    /**
     * 다른 신원이 발급받은 토큰에는 grant 를 더하지 않고 새로 발급합니다 (토큰 고정 방지).
     *
     * @scenario case=token_other_identity
     *
     * @effects other_identity_token_not_extended
     *
     * @return void 반환값 없음
     */
    public function test_token_of_other_identity_is_not_extended(): void
    {
        $this->buildGuestRootThread();
        $guestToken = $this->verifyThreadGuestPassword('G', 'g-pass');
        $member = $this->createThreadMember();

        $issued = $this->actingAs($member)
            ->withHeaders(['X-Board-Secret-View-Token' => $guestToken])
            ->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])
            ->assertOk()
            ->json('secret_view_token');

        $this->assertNotSame($guestToken, $issued);
    }

    /**
     * 글 비밀번호·스레드 비밀번호 확인은 합쳐서 IP 당 분당 10회로 제한됩니다.
     *
     * @scenario case=rate_limit
     *
     * @effects password_attempts_limited_per_minute
     *
     * @return void 반환값 없음
     */
    public function test_password_attempts_are_rate_limited(): void
    {
        $this->buildGuestRootThread();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->threadPostUrl('G').'/verify-password', ['password' => 'wrong'])->assertStatus(403);
            $this->postJson($this->threadPasswordUrl('R'), ['password' => 'wrong'])->assertStatus(403);
        }

        $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])->assertStatus(429);
        $this->postJson($this->threadPostUrl('G').'/verify-password', ['password' => 'g-pass'])->assertStatus(429);
    }

    /**
     * 응답에 어느 조상이 맞았는지(조상 ID)를 싣지 않습니다.
     *
     * @scenario case=no_ancestor_id_leak
     *
     * @effects response_omits_ancestor_ids
     *
     * @return void 반환값 없음
     */
    public function test_response_does_not_expose_ancestor_ids(): void
    {
        $this->buildGuestRootThread();

        $response = $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])->assertOk();
        $extra = array_diff(array_keys($response->json()), ['success', 'message', 'data', 'secret_view_token', 'secret_view_expires_at']);

        $this->assertSame([], array_values($extra));
        $this->assertArrayNotHasKey('anchor_id', $response->json('data'));
    }

    /**
     * thread_password_available 은 본문이 가려진 답변에 비회원 비밀번호 조상이 있을 때만 true 입니다.
     *
     * @scenario case=available_flag
     *
     * @effects thread_password_available_flag_on_locked_reply
     *
     * @return void 반환값 없음
     */
    public function test_thread_password_available_flag_conditions(): void
    {
        $this->buildGuestRootThread();

        // 잠긴 답변 + 비회원 조상 → true
        $this->assertTrue($this->getJson($this->threadPostUrl('R'))->assertOk()->json('data.thread_password_available'));
        // 원글 자체 → false (원글 비밀번호 폼이 따로 있다)
        $this->assertFalse($this->getJson($this->threadPostUrl('G'))->assertOk()->json('data.thread_password_available'));
        // 이미 열린 답변 → false
        $this->assertFalse($this->actingAs($this->threadUsers['admin'])->getJson($this->threadPostUrl('R'))->assertOk()->json('data.thread_password_available'));

        // 답변마다 따로(per_post) 게시판 → false, 엔드포인트도 400
        $this->updateBoardSettings(['secret_reply_visibility' => 'per_post']);
        $this->app['auth']->forgetGuards();
        $this->assertFalse($this->getJson($this->threadPostUrl('R'))->assertOk()->json('data.thread_password_available'));
        $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])->assertStatus(400);
    }

    /**
     * 공개 답변에는 쓸 수 없습니다 (이 폼이 뜰 일이 없다).
     *
     * @scenario case=public_reply_target
     *
     * @effects thread_password_not_applicable_on_public_reply
     *
     * @return void 반환값 없음
     */
    public function test_public_reply_target_is_not_applicable(): void
    {
        $this->buildGuestRootThread();
        DB::table('board_posts')->where('id', $this->thread['R'])->update(['is_secret' => false]);

        $this->postJson($this->threadPasswordUrl('R'), ['password' => 'g-pass'])->assertStatus(400);
    }
}
