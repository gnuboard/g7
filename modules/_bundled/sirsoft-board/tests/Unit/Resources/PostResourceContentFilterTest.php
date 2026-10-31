<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostResource getFilteredContent 메서드 테스트
 *
 * 비밀글 content 반환 조건:
 * - 일반글(is_secret=false): 비밀번호 유무와 관계없이 content 정상 반환
 * - 비밀글(is_secret=true): 권한 검증 후 content 반환
 */
class PostResourceContentFilterTest extends BoardTestCase
{
    /**
     * 비회원 일반글 (is_secret=false, password 있음): content 정상 반환
     *
     * 이 테스트는 수정된 로직의 핵심 케이스입니다.
     * 기존: 비회원 + 비밀번호 있으면 is_secret=false여도 content 숨김 (잘못됨)
     * 수정: is_secret=false면 비밀번호 유무와 관계없이 content 반환 (올바름)
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_general_post_with_password_returns_content(): void
    {
        // Given: 비회원이 비밀번호가 있는 일반글(is_secret=false) 작성
        $postId = $this->createTestPost([
            'user_id' => null, // 비회원
            'author_name' => '비회원',
            'is_secret' => false, // 일반글
            'password' => bcrypt('test1234'), // 비밀번호 있음
            'content' => '비회원 일반글 내용입니다.',
            'status' => 'published',
        ]);

        // When: 게시글 조회 및 PostResource로 변환
        $post = $this->getPostModel($postId);
        $request = Request::create('/');
        $resource = new PostResource($post);
        $response = $resource->toArray($request);

        // Then: content가 null이 아니라 정상 반환되어야 함
        $this->assertNotNull($response['content'], '비회원 일반글(is_secret=false)은 비밀번호가 있어도 content가 반환되어야 합니다');
        $this->assertEquals('비회원 일반글 내용입니다.', $response['content']);
    }

    /**
     * 비회원 일반글 (is_secret=false, password 없음): content 정상 반환
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_general_post_without_password_returns_content(): void
    {
        // Given: 비회원이 비밀번호 없이 일반글(is_secret=false) 작성
        $postId = $this->createTestPost([
            'user_id' => null, // 비회원
            'author_name' => '비회원',
            'is_secret' => false, // 일반글
            'password' => null, // 비밀번호 없음
            'content' => '비회원 일반글 (비밀번호 없음) 내용입니다.',
            'status' => 'published',
        ]);

        // When: PostResource로 변환
        $post = $this->getPostModel($postId);
        $request = Request::create('/');
        $resource = new PostResource($post);
        $response = $resource->toArray($request);

        // Then: content가 정상 반환되어야 함
        $this->assertNotNull($response['content']);
        $this->assertEquals('비회원 일반글 (비밀번호 없음) 내용입니다.', $response['content']);
    }

    /**
     * 비회원 비밀글 (is_secret=true): 비밀번호 미검증 시 content null 반환
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function guest_secret_post_returns_null_content_without_verification(): void
    {
        // Given: 비회원이 비밀글(is_secret=true) 작성
        $postId = $this->createTestPost([
            'user_id' => null, // 비회원
            'author_name' => '비회원',
            'is_secret' => true, // 비밀글
            'password' => bcrypt('test1234'),
            'content' => '비회원 비밀글 내용입니다.',
            'status' => 'published',
        ]);

        // When: 비밀번호 검증 없이 PostResource로 변환
        $post = $this->getPostModel($postId);
        $request = Request::create('/');
        $resource = new PostResource($post);
        $response = $resource->toArray($request);

        // Then: content가 null이어야 함 (권한 없음)
        $this->assertNull($response['content'], '비회원 비밀글(is_secret=true)은 검증 없이 content가 null이어야 합니다');
    }

    /**
     * 회원 일반글: content 정상 반환
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function member_general_post_returns_content(): void
    {
        // Given: 회원이 일반글(is_secret=false) 작성
        $user = User::factory()->create();
        $postId = $this->createTestPost([
            'user_id' => $user->id, // 회원
            'author_name' => $user->name,
            'is_secret' => false, // 일반글
            'content' => '회원 일반글 내용입니다.',
            'status' => 'published',
        ]);

        // When: PostResource로 변환
        $post = $this->getPostModel($postId);
        $request = Request::create('/');
        $resource = new PostResource($post);
        $response = $resource->toArray($request);

        // Then: content가 정상 반환되어야 함
        $this->assertNotNull($response['content']);
        $this->assertEquals('회원 일반글 내용입니다.', $response['content']);
    }

    /**
     * 회원 비밀글: 권한 없는 상태에서 content null 반환
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function member_secret_post_returns_null_content_for_unauthorized(): void
    {
        // Given: 회원이 비밀글(is_secret=true) 작성
        $author = User::factory()->create();
        $postId = $this->createTestPost([
            'user_id' => $author->id, // 회원
            'author_name' => $author->name,
            'is_secret' => true, // 비밀글
            'content' => '회원 비밀글 내용입니다.',
            'status' => 'published',
        ]);

        // When: 비로그인 상태로 PostResource 변환
        $post = $this->getPostModel($postId);
        $request = Request::create('/');
        $resource = new PostResource($post);
        $response = $resource->toArray($request);

        // Then: content가 null이어야 함 (권한 없음)
        $this->assertNull($response['content'], '회원 비밀글(is_secret=true)은 권한 없이 content가 null이어야 합니다');
    }

    /**
     * toListArray()에 content_preview 필드가 포함됩니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function to_list_array_includes_content_preview(): void
    {
        // Given: text 모드 게시글 (200자 본문)
        $content = str_repeat('가나다라마', 40); // 200자
        $postId = $this->createTestPost([
            'content' => $content,
            'content_mode' => 'text',
            'status' => 'published',
        ]);

        // When: toListArray() 호출
        $post = $this->getPostModel($postId);
        $resource = new PostResource($post);
        $result = $resource->toListArray();

        // Then: content_preview 키 존재, 150자 + '...'
        $this->assertArrayHasKey('content_preview', $result);
        $this->assertStringEndsWith('...', $result['content_preview']);
        $this->assertEquals(153, mb_strlen($result['content_preview'])); // 150 + 3('...')
    }

    /**
     * text 모드: 본문이 150자 이하면 '...' 없이 반환됩니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function content_preview_short_text_has_no_ellipsis(): void
    {
        // Given: 짧은 text 모드 게시글
        $postId = $this->createTestPost([
            'content' => '짧은 본문입니다.',
            'content_mode' => 'text',
            'status' => 'published',
        ]);

        $post = $this->getPostModel($postId);
        $resource = new PostResource($post);
        $result = $resource->toListArray();

        $this->assertEquals('짧은 본문입니다.', $result['content_preview']);
    }

    /**
     * html 모드: HTML 태그가 제거된 평문으로 반환됩니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function content_preview_strips_html_tags(): void
    {
        // Given: html 모드 게시글
        $postId = $this->createTestPost([
            'content' => '<p>안녕하세요</p><strong>반갑습니다</strong>',
            'content_mode' => 'html',
            'status' => 'published',
        ]);

        $post = $this->getPostModel($postId);
        $resource = new PostResource($post);
        $result = $resource->toListArray();

        // Then: 태그 제거 후 평문 반환 (태그 사이 공백은 strip_tags로 제거되지 않음)
        $this->assertEquals('안녕하세요반갑습니다', $result['content_preview']);
    }

    /**
     * 본문이 비어있으면 content_preview는 빈 문자열입니다.
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects resource_content_filter_masks_secret_and_keeps_public
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function content_preview_empty_when_no_content(): void
    {
        // Given: 본문이 빈 문자열인 게시글 (content 컬럼 NOT NULL)
        $postId = $this->createTestPost([
            'content' => '',
            'status' => 'published',
        ]);

        $post = $this->getPostModel($postId);
        $resource = new PostResource($post);
        $result = $resource->toListArray();

        $this->assertSame('', $result['content_preview']);
    }

    /**
     * Post 모델 인스턴스를 가져옵니다.
     *
     * @param  int  $postId  게시글 ID
     * @return Post 게시글 모델 (DB 행을 그대로 채운 인스턴스)
     */
    private function getPostModel(int $postId)
    {
        $postData = DB::table('board_posts')->find($postId);

        // Post 모델 인스턴스 생성 (단일 테이블)
        $post = new Post;
        $post->setRawAttributes((array) $postData, true);
        $post->exists = true;

        return $post;
    }

    /**
     * thread_password_available — 본문이 가려진 비밀 답변에 비회원·비밀번호 조상이 있을 때만 true (#789 §4.8).
     *
     * @scenario link=board-secret-thread-password
     *
     * @effects thread_password_available_flag_on_locked_reply
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function thread_password_available_flag_follows_guest_password_ancestor(): void
    {
        $member = User::factory()->create();
        $rootId = $this->createTestPost(['user_id' => null, 'password' => bcrypt('g-pass'), 'is_secret' => true, 'trigger_type' => 'user']);
        $replyId = $this->createTestPost(['user_id' => $member->id, 'is_secret' => true, 'parent_id' => $rootId, 'depth' => 1, 'trigger_type' => 'user']);
        $memberRootId = $this->createTestPost(['user_id' => $member->id, 'is_secret' => true, 'trigger_type' => 'user']);
        $memberReplyId = $this->createTestPost(['user_id' => $member->id, 'is_secret' => true, 'parent_id' => $memberRootId, 'depth' => 1, 'trigger_type' => 'user']);

        $flag = function (int $postId): bool {
            $post = $this->getPostModel($postId);
            $post->setRelation('board', $this->board);

            return (new PostResource($post))->toArray(Request::create('/'))['thread_password_available'];
        };

        $this->assertTrue($flag($replyId), '비회원 원글 아래 가려진 답변');
        $this->assertFalse($flag($rootId), '원글 자체는 글 비밀번호 폼을 쓴다');
        $this->assertFalse($flag($memberReplyId), '비회원 조상이 없는 답변');
    }

    /**
     * reply_locked_by_secret_ancestor — 하위 쓰기는 되지만 비밀 조상을 못 보면 true (#789 §3·§4.8).
     *
     * @scenario link=board-secret-thread-visibility
     *
     * @effects reply_locked_by_secret_ancestor_flag
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function reply_locked_flag_follows_secret_ancestor_visibility(): void
    {
        $author = User::factory()->create();
        $outsider = User::factory()->create();
        $rootId = $this->createTestPost(['user_id' => $author->id, 'is_secret' => true, 'trigger_type' => 'user']);
        $publicReplyId = $this->createTestPost(['user_id' => $author->id, 'is_secret' => false, 'parent_id' => $rootId, 'depth' => 1, 'trigger_type' => 'user']);

        $flag = function (User $viewer, int $postId): bool {
            $this->actingAs($viewer);
            $post = $this->getPostModel($postId);
            $post->setRelation('board', $this->board);

            return (new PostResource($post))->toArray(Request::create('/'))['reply_locked_by_secret_ancestor'];
        };

        $this->assertTrue($flag($outsider, $publicReplyId), '외부인 — 공개 답변 위 비밀 원글을 못 본다');
        $this->assertFalse($flag($author, $publicReplyId), '원글 작성자 — 경로 안');
        $this->assertFalse($flag($outsider, $rootId), '원글은 조상이 없다');
    }
}
