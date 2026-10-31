<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../Concerns/BuildsSecretThread.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Tests\Concerns\BuildsSecretThread;

/**
 * 답변 작성 가드 — 경로 규칙이 여는 권한 상승 구멍 차단 (#789 §3)
 *
 * 경로 규칙은 답변 작성자를 앵커로 삼아 조상을 열어 준다. 그래서 남의 비밀글 H 아래 공개 답변 P 에
 * 외부인이 답변을 달 수 있으면 그 외부인이 H 를 보게 된다. 답변은 부모의 모든 비밀 조상을 지금
 * 열 수 있을 때만 허용한다(canWriteReply). 댓글은 앵커를 만들지 않으므로 기존대로다.
 *
 * @scenario link=board-secret-thread-visibility
 */
class SecretThreadWriteGuardTest extends BoardTestCase
{
    use BuildsSecretThread;

    /**
     * 시험 게시판 슬러그
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-thread-write-guard';
    }

    /**
     * 기본 게시판 속성 (비밀글·답변·댓글 사용)
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '답변 작성 가드 시험', 'en' => 'Secret Thread Write Guard'],
            'is_active' => true,
            'use_reply' => true,
            'use_comment' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 비밀 원글 H(회원 a) → 공개 답변 P(회원 admin) 스레드를 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function buildPublicReplyHole(): void
    {
        $this->grantThreadMemberPermissions();
        foreach (['a', 'admin', 'outsider'] as $key) {
            $this->threadUsers[$key] = $this->createThreadMember();
        }
        $this->thread['H'] = $this->createThreadPost(null, $this->threadUsers['a'], 0, null, true, '비밀 원글 H');
        $this->thread['P'] = $this->createThreadPost($this->thread['H'], $this->threadUsers['admin'], 1, null, false, '공개 답변 P');
    }

    /**
     * 회원으로 답변을 제출합니다.
     *
     * @param  User  $user  작성자
     * @param  string  $parentKey  부모 글 키
     * @return TestResponse 응답
     */
    private function submitReply(User $user, string $parentKey)
    {
        $this->resetPermissionMiddlewareCache();

        return $this->actingAs($user)->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
            'title' => 'Re: 답변',
            'content' => '공개 답변 아래에 다는 답변 본문입니다.',
            'parent_id' => $this->thread[$parentKey],
        ]);
    }

    /**
     * 외부인은 비밀글 아래 공개 답변에 답변을 달 수 없습니다 (422 parent_id).
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects outsider_reply_under_secret_ancestor_rejected
     *
     * @return void 반환값 없음
     */
    public function test_outsider_cannot_reply_under_public_reply_of_secret_post(): void
    {
        $this->buildPublicReplyHole();
        $before = DB::table('board_posts')->where('board_id', $this->board->id)->count();

        $this->submitReply($this->threadUsers['outsider'], 'P')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);

        $this->assertSame($before, DB::table('board_posts')->where('board_id', $this->board->id)->count());
        // 구멍이 막혀 H 는 여전히 닫혀 있다
        $this->assertNull($this->threadContentFor('H', $this->threadUsers['outsider']));
    }

    /**
     * 외부인은 그 공개 답변의 답변 폼(form-meta·form-data)도 열 수 없습니다 (404).
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects outsider_reply_form_under_secret_ancestor_404
     *
     * @return void 반환값 없음
     */
    public function test_outsider_reply_form_is_404(): void
    {
        $this->buildPublicReplyHole();
        $this->actingAs($this->threadUsers['outsider']);
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";

        $this->getJson("{$base}/form-meta?parent_id={$this->thread['P']}")->assertNotFound();
        $this->getJson("{$base}/form-data?parent_id={$this->thread['P']}")->assertNotFound();
    }

    /**
     * 원글을 열 수 있는 사람(앵커)은 자기 경로 안에서 답변을 달 수 있습니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects anchor_can_reply_in_own_path
     *
     * @return void 반환값 없음
     */
    public function test_anchor_can_reply_in_own_path(): void
    {
        $this->buildPublicReplyHole();

        $this->submitReply($this->threadUsers['a'], 'P')->assertCreated();
    }

    /**
     * 공개 답변에 다는 댓글은 앵커를 만들지 않으므로 그대로 허용됩니다 (무회귀).
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects comment_on_public_reply_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_comment_on_public_reply_is_unchanged(): void
    {
        $this->buildPublicReplyHole();
        $this->resetPermissionMiddlewareCache();

        $this->actingAs($this->threadUsers['outsider'])
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->thread['P']}/comments", [
                'content' => '공개 답변에 남기는 댓글입니다.',
            ])
            ->assertCreated();
    }

    /**
     * 상세 응답의 reply_locked_by_secret_ancestor 가 답변 버튼을 미리 숨기게 합니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects reply_locked_by_secret_ancestor_flag
     *
     * @return void 반환값 없음
     */
    public function test_reply_locked_flag(): void
    {
        $this->buildPublicReplyHole();

        $this->assertTrue($this->actingAs($this->threadUsers['outsider'])->getJson($this->threadPostUrl('P'))->assertOk()->json('data.reply_locked_by_secret_ancestor'));
        $this->assertFalse($this->actingAs($this->threadUsers['a'])->getJson($this->threadPostUrl('P'))->assertOk()->json('data.reply_locked_by_secret_ancestor'));
        // 원글은 조상이 없으므로 항상 false
        $this->assertFalse($this->actingAs($this->threadUsers['outsider'])->getJson($this->threadPostUrl('H'))->assertOk()->json('data.reply_locked_by_secret_ancestor'));
    }

    /**
     * 답변마다 따로(per_post) 게시판은 경로가 열리지 않으므로 기존 판정(canWriteChild)과 같습니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects per_post_reply_guard_equals_child_write
     *
     * @return void 반환값 없음
     */
    public function test_per_post_board_keeps_child_write_rule(): void
    {
        $this->buildPublicReplyHole();
        $this->updateBoardSettings(['secret_reply_visibility' => 'per_post']);

        $this->submitReply($this->threadUsers['outsider'], 'P')->assertCreated();
        // 답변을 달았어도 per_post 라 H 는 열리지 않는다
        $this->assertNull($this->threadContentFor('H', $this->threadUsers['outsider']));
    }
}
