<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';
require_once __DIR__.'/../../Concerns/BuildsSecretThread.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\SecretThreadAccess;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use Modules\Sirsoft\Board\Tests\Concerns\BuildsSecretThread;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 비밀 답변 열람 경로 규칙 매트릭스 (#789 제보 A·D1·D15·D17·D19)
 *
 * 스레드 A ─┬─ B ── D / └─ C ── E(비회원) 에서 열람자마다 어떤 글의 본문을 받는지를
 * 게시판 설정 `secret_reply_visibility` 세 값 모두에 대해 고정한다. 기대값은 계획서 §3 의 두 표다.
 */
class SecretThreadVisibilityTest extends BoardTestCase
{
    use BuildsSecretThread;

    /**
     * 시험 게시판 슬러그
     *
     * @return string 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'secret-thread-visibility';
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
            'name' => ['ko' => '비밀 답변 경로 시험', 'en' => 'Secret Thread Visibility'],
            'is_active' => true,
            'use_reply' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 설정 값 × 열람자 ⇒ 본문을 받는 글 목록 (계획서 §3 두 표)
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, string>}> 케이스 이름 ⇒ [설정 값, 열람자, 본문을 받는 글]
     */
    public static function threadMatrixProvider(): array
    {
        $all = ['A', 'B', 'C', 'D', 'E'];
        $memberPosts = ['A', 'B', 'C', 'D'];

        $expected = [
            'thread_path' => [
                'root_member_author' => $all,
                'root_guest_token' => $all,
                'b_author' => ['A', 'B', 'D'],
                'c_author' => ['A', 'C', 'E'],
                'd_author' => ['A', 'B', 'D'],
                'e_guest_token' => ['A', 'C', 'E'],
            ],
            'descendants_only' => [
                'root_member_author' => $all,
                'root_guest_token' => $all,
                'b_author' => ['B', 'D'],
                'c_author' => ['C', 'E'],
                'd_author' => ['D'],
                'e_guest_token' => ['E'],
            ],
            'per_post' => [
                'root_member_author' => ['A'],
                'root_guest_token' => ['A'],
                'b_author' => ['B'],
                'c_author' => ['C'],
                'd_author' => ['D'],
                'e_guest_token' => ['E'],
            ],
        ];

        $cases = [];
        foreach ($expected as $mode => $rows) {
            $rows += [
                'outsider' => [],
                'guest_no_token' => [],
                'read_secret_all' => $all,
                'read_secret_self' => [],
                // 소유역할(D18): 기본 user 역할을 공유하는 회원 글 전부, 비회원 글(E)은 닫힘
                'read_secret_role' => $memberPosts,
                'manager' => $all,
            ];

            foreach ($rows as $viewer => $visible) {
                $cases["{$mode} / {$viewer}"] = [$mode, $viewer, $visible];
            }
        }

        return $cases;
    }

    /**
     * 설정 값 × 열람자 × 대상 매트릭스에서 상세 본문 노출이 표와 같습니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects thread_matrix_matches_plan_tables
     *
     * @param  string  $mode  secret_reply_visibility 값
     * @param  string  $viewer  열람자 축 이름
     * @param  array<int, string>  $visible  본문을 받아야 하는 글 키
     * @return void 반환값 없음
     */
    #[DataProvider('threadMatrixProvider')]
    public function test_thread_matrix_show_content(string $mode, string $viewer, array $visible): void
    {
        $this->buildSecretThread(guestRoot: $viewer === 'root_guest_token');
        $this->applyMode($mode);

        [$user, $token] = $this->resolveViewer($viewer);

        $actual = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
            if ($this->threadContentFor($key, $user, $token) !== null) {
                $actual[] = $key;
            }
        }

        $this->assertSame($visible, $actual, "{$mode} / {$viewer} 가 본문을 받는 글");
    }

    /**
     * 답변마다 따로(per_post) 설정이면 경로 판정이 쿼리를 하나도 돌리지 않습니다.
     *
     * @scenario mode=per_post, viewer=b_author, target=A
     *
     * @effects per_post_mode_runs_no_thread_queries
     *
     * @return void 반환값 없음
     */
    public function test_per_post_mode_runs_no_thread_queries(): void
    {
        $this->buildSecretThread();
        $this->applyMode('per_post');
        $this->actingAs($this->threadUsers['b']);

        $post = Post::query()->with('board')->findOrFail($this->thread['A']);
        $request = request();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $reaches = app(SecretThreadAccess::class)->reachesThroughThread($post, $request, $this->board->slug);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertFalse($reaches);
        $this->assertSame([], $queries);
    }

    /**
     * 하위만(descendants_only) 설정이면 답변 작성자가 조상 글을 보지 못합니다.
     *
     * @scenario mode=descendants_only, viewer=d_author, target=A
     *
     * @effects descendants_only_hides_ancestors_from_reply_author
     *
     * @return void 반환값 없음
     */
    public function test_descendants_only_hides_ancestors_from_reply_author(): void
    {
        $this->buildSecretThread();
        $this->applyMode('descendants_only');

        $this->assertNull($this->threadContentFor('A', $this->threadUsers['d']));
        $this->assertNull($this->threadContentFor('B', $this->threadUsers['d']));
        $this->assertNotNull($this->threadContentFor('D', $this->threadUsers['d']));
        // 원글 작성자는 하위를 계속 본다
        $this->assertNotNull($this->threadContentFor('D', $this->threadUsers['a']));
    }

    /**
     * 게시판 설정을 바꾸면 다음 요청부터 바로 적용됩니다.
     *
     * @scenario mode=per_post, viewer=b_author, target=A
     *
     * @effects setting_change_applies_immediately
     *
     * @return void 반환값 없음
     */
    public function test_setting_change_applies_immediately(): void
    {
        $this->buildSecretThread();

        $this->assertNotNull($this->threadContentFor('A', $this->threadUsers['b']));

        $this->applyMode('per_post');

        $this->assertNull($this->threadContentFor('A', $this->threadUsers['b']));
    }

    /**
     * 원글 작성자가 원글 상세를 열면 답변 트리 안의 본문이 모두 실립니다.
     *
     * @scenario mode=thread_path, viewer=root_member_author, target=A
     *
     * @effects root_viewer_sees_all_replies_inline
     *
     * @return void 반환값 없음
     */
    public function test_root_viewer_sees_all_replies_inline_in_root_detail(): void
    {
        $this->buildSecretThread();
        $this->actingAs($this->threadUsers['a']);

        $response = $this->getJson($this->threadPostUrl('A'))->assertOk();
        $contents = $this->collectReplyContents($response->json('data.replies') ?? []);

        $this->assertSame(['B', 'C', 'D', 'E'], array_keys(array_filter($contents, fn ($c) => $c !== null)));
        $this->assertCount(4, $contents);
    }

    /**
     * 답변 작성자는 자기 답변 상세의 원글 상자에서 원글 본문을 받습니다.
     *
     * @scenario mode=thread_path, viewer=b_author, target=B
     *
     * @effects reply_author_sees_parent_box_content
     *
     * @return void 반환값 없음
     */
    public function test_reply_author_sees_parent_box_content(): void
    {
        $this->buildSecretThread();
        $this->actingAs($this->threadUsers['b']);

        $response = $this->getJson($this->threadPostUrl('B'))->assertOk();

        $this->assertSame('답변 B 본문', $response->json('data.content'));
        $this->assertSame('원글 A 본문', $response->json('data.parent.content'));
    }

    /**
     * B 작성자는 형제 가지 C 와 그 아래 E 를 보지 못합니다.
     *
     * @scenario mode=thread_path, viewer=b_author, target=C
     *
     * @effects sibling_branch_stays_masked
     *
     * @return void 반환값 없음
     */
    public function test_b_author_cannot_see_sibling_c_or_its_descendant_e(): void
    {
        $this->buildSecretThread();

        $this->assertNull($this->threadContentFor('C', $this->threadUsers['b']));
        $this->assertNull($this->threadContentFor('E', $this->threadUsers['b']));
    }

    /**
     * E 비밀번호를 맞힌 비회원은 A·C·E 만 보고 B·D 는 보지 못합니다.
     *
     * @scenario mode=thread_path, viewer=e_guest_token, target=A
     *
     * @effects e_guest_token_sees_ancestor_path_only
     *
     * @return void 반환값 없음
     */
    public function test_e_guest_token_sees_a_and_c_not_b_d(): void
    {
        $this->buildSecretThread();
        $token = $this->verifyThreadGuestPassword('E', 'e-pass');

        $this->assertNotNull($this->threadContentFor('A', null, $token));
        $this->assertNotNull($this->threadContentFor('C', null, $token));
        $this->assertNull($this->threadContentFor('B', null, $token));
        $this->assertNull($this->threadContentFor('D', null, $token));
    }

    /**
     * 삭제한 답변은 앵커가 아닙니다 — 살아 있는 다른 내 답변을 통한 경로만 남습니다 (D15).
     *
     * @scenario mode=thread_path, viewer=b_author, target=A
     *
     * @effects trashed_reply_does_not_anchor
     *
     * @return void 반환값 없음
     */
    public function test_trashed_reply_does_not_anchor_author(): void
    {
        $this->buildSecretThread();
        $this->trashThreadPost('B');

        $this->assertNull($this->threadContentFor('A', $this->threadUsers['b']));
        // D 는 삭제된 B 를 지나 A 까지 경로가 이어진다 (구조 순회는 삭제 노드도 지난다)
        $this->assertNotNull($this->threadContentFor('A', $this->threadUsers['d']));
    }

    /**
     * 공개로 쓴 원글이 나중에 비밀글이 되어도 기존 답변 작성자는 계속 봅니다 (D17).
     *
     * @scenario mode=thread_path, viewer=b_author, target=A
     *
     * @effects public_root_made_secret_stays_visible_to_reply_author
     *
     * @return void 반환값 없음
     */
    public function test_public_root_later_made_secret_stays_visible_to_reply_author(): void
    {
        $this->buildSecretThread(secret: false);
        DB::table('board_posts')->where('id', $this->thread['A'])->update(['is_secret' => true]);

        $this->assertNotNull($this->threadContentFor('A', $this->threadUsers['b']));
        $this->assertNull($this->threadContentFor('A', $this->threadUsers['outsider']));
    }

    /**
     * 블라인드된 조상 글은 앵커에게도 본문이 가려집니다.
     *
     * @scenario mode=thread_path, viewer=b_author, target=A
     *
     * @effects blinded_ancestor_body_stays_masked
     *
     * @return void 반환값 없음
     */
    public function test_blinded_ancestor_body_stays_masked_for_anchor(): void
    {
        $this->buildSecretThread();
        DB::table('board_posts')->where('id', $this->thread['A'])->update(['status' => 'blinded']);

        $this->assertNull($this->threadContentFor('A', $this->threadUsers['b']));
    }

    /**
     * 다른 게시판 글로 받은 grant 는 이 게시판 스레드를 열지 않습니다.
     *
     * @scenario mode=thread_path, viewer=guest_no_token, target=A
     *
     * @effects grant_from_other_board_ignored
     *
     * @return void 반환값 없음
     */
    public function test_grant_in_other_board_slug_is_ignored(): void
    {
        $this->buildSecretThread();

        $other = Board::updateOrCreate(
            ['slug' => 'secret-thread-visibility-other'],
            [...$this->getDefaultBoardAttributes('secret-thread-visibility-other'), 'name' => ['ko' => '다른 게시판', 'en' => 'Other']]
        );
        $this->grantGuestPermissionsOn($other);
        $otherPostId = DB::table('board_posts')->insertGetId([
            'board_id' => $other->id,
            'title' => '다른 게시판 비회원 글',
            'content' => '다른 게시판 본문',
            'author_name' => '비회원',
            'password' => Hash::make('o-pass'),
            'is_secret' => true,
            'status' => 'published',
            'trigger_type' => 'user',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = $this->postJson("/api/modules/sirsoft-board/boards/{$other->slug}/posts/{$otherPostId}/verify-password", ['password' => 'o-pass'])
            ->assertOk()
            ->json('secret_view_token');

        foreach (['A', 'B', 'C', 'D', 'E'] as $key) {
            $this->assertNull($this->threadContentFor($key, null, $token), "{$key} 는 다른 게시판 grant 로 열리지 않는다");
        }
    }

    /**
     * 게시판의 비밀 답변 열람 범위를 바꿉니다 (기본값 thread_path 는 바꾸지 않는다).
     *
     * @param  string  $mode  secret_reply_visibility 값
     * @return void 반환값 없음
     */
    private function applyMode(string $mode): void
    {
        if ($mode !== 'thread_path') {
            $this->updateBoardSettings(['secret_reply_visibility' => $mode]);
        }
    }

    /**
     * 열람자 축 이름을 회원·토큰으로 풉니다.
     *
     * @param  string  $viewer  열람자 축 이름
     * @return array{0: User|null, 1: string|null} [회원, 토큰]
     */
    private function resolveViewer(string $viewer): array
    {
        return match ($viewer) {
            'root_member_author' => [$this->threadUsers['a'], null],
            'root_guest_token' => [null, $this->verifyThreadGuestPassword('A', 'a-pass')],
            'b_author' => [$this->threadUsers['b'], null],
            'c_author' => [$this->threadUsers['c'], null],
            'd_author' => [$this->threadUsers['d'], null],
            'e_guest_token' => [null, $this->verifyThreadGuestPassword('E', 'e-pass')],
            'outsider' => [$this->threadUsers['outsider'], null],
            'guest_no_token' => [null, null],
            'read_secret_all' => [$this->createScopedBoardUser('posts.read-secret', null), null],
            'read_secret_self' => [$this->createScopedBoardUser('posts.read-secret', 'self'), null],
            'read_secret_role' => [$this->createScopedBoardUser('posts.read-secret', 'role'), null],
            'manager' => [$this->createScopedBoardUser('manager', null), null],
        };
    }

    /**
     * 답변 트리 응답에서 제목 끝 글자(B~E) ⇒ 본문 맵을 만듭니다.
     *
     * @param  array<int, array<string, mixed>>  $replies  replies 응답
     * @return array<string, string|null> 글 키 ⇒ 본문
     */
    private function collectReplyContents(array $replies): array
    {
        $contents = [];
        foreach ($replies as $reply) {
            $key = substr((string) $reply['title'], -1);
            $contents[$key] = $reply['content'];
            $contents += $this->collectReplyContents($reply['replies'] ?? []);
        }
        ksort($contents);

        return $contents;
    }

    /**
     * 다른 게시판에도 비회원 열람 권한을 줍니다.
     *
     * @param  Board  $board  대상 게시판
     * @return void 반환값 없음
     */
    private function grantGuestPermissionsOn(Board $board): void
    {
        $original = $this->board;
        $this->board = $board;
        $this->setGuestPermissions(['posts.read']);
        $this->board = $original;
    }
}
