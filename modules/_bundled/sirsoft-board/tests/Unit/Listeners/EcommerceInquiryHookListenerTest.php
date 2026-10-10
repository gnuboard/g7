<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Listeners\EcommerceInquiryHookListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * EcommerceInquiryHookListener 단위 테스트
 *
 * 검증 목적:
 * - getSubscribedHooks: 10개 훅 등록(운영 8 + 샘플 시더 2), 모두 filter 타입
 * - 실패 사유: 게시판 자기 문구(sirsoft-board::messages.inquiry.*)로 던짐
 * - seedSamplePost / purgeSamplePosts: 이커머스 샘플 시더 대신 글 생성·자기 타입만 정리
 * - createAndReturn: 성공(post_id/inquirable_type 반환), title 자동생성, parent_id Re: 처리, 예외 시 null,
 *   기존 살아있는 답변 존재 시 중복 생성 차단(duplicate 마커), 답변 soft delete 후 재등록 허용
 * - getByIds: 빈 배열 → carry 그대로, 유효 ID 목록 → 필드 포함 배열 반환
 * - getBoardSettings: 존재하지 않는 slug → carry 그대로, 유효 slug → 설정 배열 반환
 * - deletePost: 성공 → carry 반환, 답글 자식까지 cascade soft delete, 존재하지 않는 PostId → RuntimeException
 * - deleteReplyPost: reply 없음 → RuntimeException, 성공 → carry 반환
 * - countReplies: 살아있는 자식만 집계
 */
#[Group('board')]
#[Group('unit')]
#[Group('listener')]
class EcommerceInquiryHookListenerTest extends BoardTestCase
{
    private EcommerceInquiryHookListener $listener;

    /**
     * 이 테스트 전용 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'ecommerce-inquiry-hook';
    }

    /**
     * 테스트 게시판의 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '이커머스 문의 훅 테스트 게시판', 'en' => 'Ecommerce Inquiry Hook Test Board'],
            'is_active' => true,
            'secret_mode' => 'disabled',
            'use_file_upload' => true,
            'max_file_count' => 5,
            'max_file_size' => 10,
            'allowed_extensions' => [],
            'min_title_length' => 2,
            'max_title_length' => 200,
            'min_content_length' => 1,
            'max_content_length' => 10000,
            'blocked_keywords' => [],
        ];
    }

    /**
     * 리스너 인스턴스를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = app(EcommerceInquiryHookListener::class);
    }

    // ==========================================
    // getSubscribedHooks
    // ==========================================

    /**
     * 운영 연동 8개와 샘플 시더 2개, 모두 10개 훅이 등록되어 있고 모두 filter 타입이어야 합니다.
     *
     * @return void 반환값 없음
     *
     * @effects listener_subscribes_every_inquiry_filter_hook
     */
    public function test_subscribed_hooks_registers_all_eight_filter_hooks(): void
    {
        $hooks = EcommerceInquiryHookListener::getSubscribedHooks();

        $expectedHooks = [
            'sirsoft-ecommerce.inquiry.create',
            'sirsoft-ecommerce.inquiry.update',
            'sirsoft-ecommerce.inquiry.delete',
            'sirsoft-ecommerce.inquiry.update_reply',
            'sirsoft-ecommerce.inquiry.delete_reply',
            'sirsoft-ecommerce.inquiry.get_by_ids',
            'sirsoft-ecommerce.inquiry.get_settings',
            'sirsoft-ecommerce.inquiry.count_replies',
            'sirsoft-ecommerce.inquiry.seed_sample_post',
            'sirsoft-ecommerce.inquiry.purge_sample_posts',
        ];

        foreach ($expectedHooks as $hookName) {
            $this->assertArrayHasKey($hookName, $hooks, "훅 {$hookName}이 등록되어 있어야 합니다.");
            $this->assertSame('filter', $hooks[$hookName]['type'], "훅 {$hookName}은 filter 타입이어야 합니다.");
        }

        $this->assertCount(10, $hooks, '총 10개의 훅이 등록되어 있어야 합니다(운영 8 + 샘플 시더 2).');
    }

    // ==========================================
    // createAndReturn
    // ==========================================

    /**
     * createAndReturn: 정상 데이터로 게시글 생성 → post_id와 inquirable_type 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_creates_board_post_for_inquiry
     */
    public function test_create_and_return_returns_post_id_and_inquirable_type(): void
    {
        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'title' => '문의 테스트 제목',
            'content' => '문의 내용입니다.',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('post_id', $result);
        $this->assertArrayHasKey('inquirable_type', $result);
        $this->assertIsInt($result['post_id']);
        $this->assertSame(Post::class, $result['inquirable_type']);
    }

    /**
     * createAndReturn: title 없으면 content 앞부분으로 자동 생성
     *
     * @return void 반환값 없음
     *
     * @effects listener_creates_board_post_for_inquiry
     */
    public function test_create_and_return_auto_generates_title_from_content(): void
    {
        $content = '이것은 50자가 넘는 긴 내용입니다. 제목이 없을 때 content 앞부분으로 자동 생성됩니다.';

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'content' => $content,
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertNotNull($result);
        $post = Post::find($result['post_id']);
        $this->assertNotEmpty($post->title);
        $this->assertLessThanOrEqual(50, mb_strlen($post->title));
    }

    /**
     * createAndReturn: parent_id 있으면 부모 제목에 Re: 접두사 붙임
     *
     * @return void 반환값 없음
     *
     * @effects listener_creates_board_post_for_inquiry
     */
    public function test_create_and_return_prepends_re_prefix_for_reply(): void
    {
        $parentPostId = $this->createTestPost(['title' => '원본 문의 제목']);

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'content' => '답변 내용입니다.',
            'parent_id' => $parentPostId,
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertNotNull($result);
        $post = Post::find($result['post_id']);
        $this->assertStringStartsWith('Re:', $post->title);
        $this->assertStringContainsString('원본 문의 제목', $post->title);
    }

    /**
     * createAndReturn: 저장되는 ip_address 는 호출 서비스가 payload 로 넘긴 값이어야 한다.
     * 요청 경계인 ProductInquiryService 가 IP 를 주입하고 Listener 는 그것을 그대로 쓴다.
     *
     * 입력 경계는 viewer 축과 교차하지 않는 별개 관심사라 매니페스트 sub_flow
     * (`listener_ip_boundary`)로 두고 여기서는 효과만 마킹한다 — 매니페스트 axes 에 없는
     * 축을 @scenario 에 적으면 어떤 cross product 조합도 커버하지 못하는 죽은 마커가 된다.
     *
     * @return void 반환값 없음
     *
     * @effects listener_uses_service_provided_ip_not_request
     */
    public function test_create_and_return_persists_ip_from_payload(): void
    {
        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'title' => '문의',
            'content' => '내용',
            'ip_address' => '198.51.100.7',
        ]);

        $post = Post::find($result['post_id']);
        $this->assertSame('198.51.100.7', $post->ip_address, 'Listener 는 payload 의 ip_address 를 그대로 저장해야 합니다');
    }

    /**
     * createAndReturn: payload 에 ip_address 가 없으면 NULL 로 저장하고 request()->ip() 로
     * 되돌아가지 않는다(입력 우회 방지 경계 — 정정2 회귀 가드).
     *
     * request IP 를 실제 IP 로 세팅한 상태에서 NULL 이 나와야 한다. Listener 가 request()->ip() 를
     * 재도입하면 이 테스트가 request IP('203.0.113.99')를 잡아 fail 한다. 자리표시값 '0.0.0.0' 을
     * 되살려도 fail 한다 — board_posts.ip_address 가 nullable 이 된 뒤(이슈 #636) 그 값은 보유기간
     * 파기가 개인정보가 남은 행으로 세는 가짜 IP 다.
     *
     * 축이 아닌 sub_flow (`listener_ip_boundary`) 소속이라 효과만 마킹한다.
     *
     * @return void 반환값 없음
     *
     * @effects listener_does_not_reach_into_request_for_ip
     */
    public function test_create_and_return_does_not_fall_back_to_request_ip(): void
    {
        $request = Request::create('/'.$this->board->slug, 'POST', server: ['REMOTE_ADDR' => '203.0.113.99']);
        $this->app->instance('request', $request);
        $this->assertSame('203.0.113.99', $request->ip(), '테스트 전제: request IP 가 비-0.0.0.0 이어야 재도입을 잡는다');

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'title' => '문의',
            'content' => '내용',
            // ip_address 의도적 누락
        ]);

        $post = Post::find($result['post_id']);
        $this->assertNull(
            $post->ip_address,
            'ip_address 미제공 시 NULL 이어야 하며 request()->ip() 나 자리표시값(0.0.0.0)으로 채우면 안 됩니다'
        );
    }

    /**
     * createAndReturn: 존재하지 않는 slug → 예외 발생하지 않고 null 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_degrades_to_carry_when_board_missing
     */
    public function test_create_and_return_returns_null_on_exception(): void
    {
        $result = $this->listener->createAndReturn(null, 'nonexistent-slug-xyz', [
            'title' => '테스트',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertNull($result);
    }

    /**
     * createAndReturn: 부모에 이미 살아있는 답변이 있으면 중복 생성 차단 → null 반환
     *
     * 2차 방어(게시판 실데이터 기준): 피벗 is_answered 플래그가 어긋난 경우에도
     * 게시판에 중복 답변이 쌓이지 않아야 합니다.
     *
     * @return void 반환값 없음
     *
     * @effects listener_blocks_duplicate_child
     */
    public function test_create_and_return_returns_duplicate_marker_when_parent_already_has_reply(): void
    {
        $parentPostId = $this->createTestPost(['title' => '원본 문의']);
        $this->createTestPost([
            'title' => 'Re: 원본 문의',
            'parent_id' => $parentPostId,
            'depth' => 1,
        ]);

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'content' => '두 번째 답변 시도',
            'parent_id' => $parentPostId,
            'ip_address' => '127.0.0.1',
        ]);

        // null(무응답/실패)이 아닌 중복 마커 — 호출 서비스가 "이미 등록된 답변" 사유로
        // 변환한다. null 로 되돌리면 사유가 generic 실패로 위장된다 (운영 실측 제보 회귀).
        $this->assertSame(
            ['duplicate' => true],
            $result,
            '살아있는 답변이 이미 있으면 중복 마커로 차단되어야 합니다.'
        );
    }

    /**
     * createAndReturn: 기존 답변이 soft delete 된 뒤에는 답변 재등록이 허용된다.
     *
     * 중복 차단 판정은 살아있는 답변만 대상으로 해야 합니다 (SoftDeletes 기본 스코프).
     *
     * @return void 반환값 없음
     *
     * @effects listener_blocks_duplicate_child
     */
    public function test_create_and_return_allows_reply_after_previous_reply_soft_deleted(): void
    {
        $parentPostId = $this->createTestPost(['title' => '원본 문의']);
        $replyPostId = $this->createTestPost([
            'title' => 'Re: 원본 문의',
            'parent_id' => $parentPostId,
            'depth' => 1,
        ]);

        // 기존 답변 soft delete
        Post::find($replyPostId)->delete();

        $result = $this->listener->createAndReturn(null, $this->board->slug, [
            'content' => '삭제 후 재등록 답변',
            'parent_id' => $parentPostId,
            'ip_address' => '127.0.0.1',
        ]);

        $this->assertIsArray($result, '기존 답변이 삭제된 뒤에는 답변 재등록이 허용되어야 합니다.');
        $this->assertArrayHasKey('post_id', $result);
        $this->assertIsInt($result['post_id']);
        $this->assertNotSame($replyPostId, $result['post_id']);
    }

    // ==========================================
    // getByIds
    // ==========================================

    /**
     * getByIds: 빈 ids → carry 그대로 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_maps_posts_by_ids
     */
    public function test_get_by_ids_returns_carry_when_ids_empty(): void
    {
        $carry = ['existing' => 'data'];
        $result = $this->listener->getByIds($carry, ['ids' => [], 'slug' => $this->board->slug]);

        $this->assertSame($carry, $result);
    }

    /**
     * getByIds: 유효한 IDs → 필수 필드 포함 배열 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_maps_posts_by_ids
     */
    public function test_get_by_ids_returns_mapped_array_for_valid_ids(): void
    {
        $postId = $this->createTestPost(['title' => '문의글', 'content' => '문의 내용']);

        $result = $this->listener->getByIds([], ['ids' => [$postId], 'slug' => $this->board->slug]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $item = $result[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('board_id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('content', $item);
        $this->assertArrayHasKey('status', $item);
        $this->assertArrayHasKey('attachments', $item);
        $this->assertArrayHasKey('reply', $item);
        $this->assertSame($postId, $item['id']);
    }

    /**
     * getByIds: ids에 없는 ID 포함해도 예외 없이 조회된 것만 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_maps_posts_by_ids
     */
    public function test_get_by_ids_ignores_nonexistent_ids(): void
    {
        $postId = $this->createTestPost();

        $result = $this->listener->getByIds([], [
            'ids' => [$postId, 99999999],
            'slug' => $this->board->slug,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame($postId, $result[0]['id']);
    }

    /**
     * getByIds: 비밀글은 비열람자에게 content/title/reply/attachments 가 마스킹된다
     *
     * @return void 반환값 없음
     *
     * @scenario layer=hook, viewer=non_viewer
     *
     * @effects hook_masks_secret_post_for_non_viewer, hook_emits_authoritative_can_view_secret_flag
     */
    public function test_get_by_ids_masks_secret_post_for_non_viewer(): void
    {
        $owner = User::factory()->create();
        $postId = $this->createTestPost([
            'title' => '비밀 문의 제목',
            'content' => '비밀 문의 내용',
            'is_secret' => true,
            'user_id' => $owner->id,
            'author_name' => 'owner',
        ]);

        // 비인증(비열람자) 컨텍스트
        $result = $this->listener->getByIds([], ['ids' => [$postId], 'slug' => $this->board->slug]);

        $this->assertCount(1, $result);
        $item = $result[0];
        $this->assertNull($item['content'], '비밀글 content 는 비열람자에게 null 이어야 합니다');
        $this->assertNotSame('비밀 문의 제목', $item['title'], '비밀글 title 은 플레이스홀더로 대체되어야 합니다');
        $this->assertNull($item['reply'], '비밀글 reply 는 비열람자에게 null 이어야 합니다');
        $this->assertSame([], $item['attachments'], '비밀글 attachments 는 비열람자에게 빈 배열이어야 합니다');
        $this->assertTrue($item['is_secret']);
        // 소비 서비스의 이중 방어(A-2)를 위해 서버 열람 판정을 함께 실어 보낸다
        $this->assertFalse($item['can_view_secret'], '비열람자에게 can_view_secret 은 false 여야 합니다');
    }

    /**
     * getByIds: 작성자 본인에게는 비밀글 원문이 그대로 노출된다 (회귀 방지)
     *
     * @return void 반환값 없음
     *
     * @scenario layer=hook, viewer=owner
     *
     * @effects hook_exposes_secret_post_to_owner
     */
    public function test_get_by_ids_shows_secret_post_to_owner(): void
    {
        $owner = User::factory()->create();
        $postId = $this->createTestPost([
            'title' => '내 비밀 문의',
            'content' => '내 비밀 내용',
            'is_secret' => true,
            'user_id' => $owner->id,
            'author_name' => 'owner',
        ]);

        $this->actingAs($owner, 'sanctum');
        $result = $this->listener->getByIds([], ['ids' => [$postId], 'slug' => $this->board->slug]);

        $this->assertSame('내 비밀 내용', $result[0]['content']);
        $this->assertSame('내 비밀 문의', $result[0]['title']);
        $this->assertTrue($result[0]['can_view_secret'], '작성자 본인에게 can_view_secret 은 true 여야 합니다');
    }

    // 게시판 manager 의 비밀글 원문 열람은 HTTP 컨텍스트(라우트 slug)가 필요한
    // PermissionMiddleware 경로라, SecretPostAttachmentAccessTest·SecretPostCommentAccessTest
    // 에서 실제 요청으로 검증한다(여기서는 소유자/비열람자 마스킹만 단위 검증).

    // ==========================================
    // getBoardSettings
    // ==========================================

    /**
     * getBoardSettings: 존재하지 않는 slug → carry 그대로 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_degrades_to_carry_when_board_missing
     */
    public function test_get_board_settings_returns_carry_for_nonexistent_slug(): void
    {
        $carry = ['fallback' => true];
        $result = $this->listener->getBoardSettings($carry, 'nonexistent-slug-xyz');

        $this->assertSame($carry, $result);
    }

    /**
     * getBoardSettings: 유효한 slug → 게시판 설정 필드 포함 배열 반환
     *
     * @return void 반환값 없음
     *
     * @effects listener_reads_board_settings
     */
    public function test_get_board_settings_returns_config_for_valid_slug(): void
    {
        $result = $this->listener->getBoardSettings([], $this->board->slug);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('secret_mode', $result);
        $this->assertArrayHasKey('use_file_upload', $result);
        $this->assertArrayHasKey('max_file_count', $result);
        $this->assertArrayHasKey('max_file_size', $result);
        $this->assertArrayHasKey('min_title_length', $result);
        $this->assertArrayHasKey('max_title_length', $result);
        $this->assertArrayHasKey('attachment_upload_url', $result);
        $this->assertArrayHasKey('attachment_delete_url', $result);
        $this->assertStringContainsString($this->board->slug, $result['attachment_upload_url']);
    }

    // ==========================================
    // deletePost
    // ==========================================

    /**
     * deletePost: 성공 → carry 반환
     *
     * @return void 반환값 없음
     *
     * @effects inquiry_delete_removes_reply_posts
     */
    public function test_delete_post_returns_carry_on_success(): void
    {
        $postId = $this->createTestPost();
        $carry = ['some' => 'carry'];

        $result = $this->listener->deletePost($carry, $this->board->slug, $postId);

        $this->assertSame($carry, $result);
        $this->assertSoftDeleted('board_posts', ['id' => $postId]);
    }

    /**
     * deletePost: 부모 문의 삭제 시 답글 자식도 함께 cascade soft delete 된다.
     *
     * 훅 경유 삭제는 cascade_replies 옵션으로 게시판 답글 삭제 정책과 무관하게
     * 시스템 생성 답변을 함께 정리해야 합니다.
     *
     * @return void 반환값 없음
     *
     * @effects inquiry_delete_removes_reply_posts
     */
    public function test_delete_post_soft_deletes_reply_children_too(): void
    {
        $parentPostId = $this->createTestPost(['title' => '원본 문의']);
        $replyPostId = $this->createTestPost([
            'title' => 'Re: 원본 문의',
            'parent_id' => $parentPostId,
            'depth' => 1,
        ]);

        $this->listener->deletePost(null, $this->board->slug, $parentPostId);

        $this->assertSoftDeleted('board_posts', ['id' => $parentPostId]);
        $this->assertSoftDeleted('board_posts', ['id' => $replyPostId]);
    }

    /**
     * deletePost: 존재하지 않는 postId → RuntimeException
     *
     * @return void 반환값 없음
     *
     * @effects listener_delete_fails_loudly_for_missing_post
     */
    public function test_delete_post_throws_runtime_exception_for_nonexistent_post(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener->deletePost(null, $this->board->slug, 99999999);
    }

    /**
     * deletePost 실패 사유는 게시판 자기 문구로 던진다 — 이커머스 번역 키를 빌려 쓰지 않는다.
     *
     * 게시판과 이커머스는 서로를 의존성으로 선언하지 않으므로, 훅에 답하는 쪽도 자기 lang 만
     * 써야 이커머스가 없거나 대체된 설치에서 키 원문이 새지 않습니다. 문구 자체는 종전과 같습니다.
     *
     * @effects listener_failure_reason_uses_own_lang
     *
     * @return void 반환값 없음
     */
    public function test_delete_post_failure_reason_uses_board_lang(): void
    {
        try {
            $this->listener->deletePost(null, $this->board->slug, 99999999);
            $this->fail('존재하지 않는 글 삭제는 예외를 던져야 합니다.');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('sirsoft-board::messages.inquiry.board_changed'), $e->getMessage());
            $this->assertStringNotContainsString('::', $e->getMessage(), '번역 키 원문이 새면 안 됩니다.');
        }
    }

    // ==========================================
    // seedSamplePost / purgeSamplePosts (이커머스 샘플 시더 계약)
    // ==========================================

    /**
     * seedSamplePost: 문의 글과 답변 글을 만들고 inquiry.create 와 같은 모양의 참조를 돌려준다.
     *
     * 이커머스 샘플 시더가 게시판 모델을 직접 만들지 않도록 게시판이 대신 답하는 계약입니다.
     * 비회원 비밀번호는 평문으로 받아 게시판이 해시하고, 작성 시각은 요청대로 남깁니다.
     *
     * @effects listener_seeds_sample_post_with_reply
     *
     * @return void 반환값 없음
     */
    public function test_seed_sample_post_creates_question_and_reply(): void
    {
        $createdAt = now()->subDays(10)->startOfSecond();

        $result = $this->listener->seedSamplePost(null, $this->board->slug, [
            'title' => '샘플 문의 제목',
            'content' => '샘플 문의 내용',
            'author_name' => '김민준',
            'password' => '1234',
            'is_secret' => true,
            'created_at' => $createdAt,
        ], [
            'content' => '샘플 답변 내용',
            'created_at' => $createdAt->copy()->addDay(),
        ]);

        $this->assertIsArray($result);
        $this->assertSame(Post::class, $result['inquirable_type']);

        $question = Post::find($result['post_id']);
        $this->assertNotNull($question);
        $this->assertSame('샘플 문의 제목', $question->title);
        $this->assertTrue($question->is_secret);
        $this->assertNotSame('1234', $question->password, '비밀번호는 해시되어 저장되어야 합니다.');

        $reply = Post::where('parent_id', $question->id)->first();
        $this->assertNotNull($reply, '답변 글이 함께 만들어져야 합니다.');
        $this->assertSame('Re: 샘플 문의 제목', $reply->title);
    }

    /**
     * seedSamplePost: 게시판이 없으면 이전 결과를 그대로 돌려준다(시더가 건너뛸 수 있게).
     *
     * @effects listener_degrades_to_carry_when_board_missing
     *
     * @return void 반환값 없음
     */
    public function test_seed_sample_post_returns_carry_for_missing_board(): void
    {
        $this->assertNull($this->listener->seedSamplePost(null, 'no-such-board-slug', ['title' => 'x']));
    }

    /**
     * purgeSamplePosts: 자기 글 타입 묶음으로 넘겨받은 글과 그 글의 답변만 영구 삭제한다.
     *
     * 다른 타입 묶음은 게시판의 것이 아니므로 같은 ID 라도 건드리지 않습니다.
     *
     * @effects listener_purges_only_own_type_sample_posts
     *
     * @return void 반환값 없음
     */
    public function test_purge_sample_posts_force_deletes_own_type_only(): void
    {
        $questionId = $this->createTestPost(['title' => '샘플 문의']);
        $replyId = $this->createTestPost(['title' => 'Re: 샘플 문의', 'parent_id' => $questionId, 'depth' => 1]);
        $otherId = $this->createTestPost(['title' => '다른 타입과 ID 가 겹치는 글']);

        $deleted = $this->listener->purgeSamplePosts(0, $this->board->slug, [
            Post::class => [$questionId],
            'Vendor\\Other\\Model' => [$otherId],
        ]);

        $this->assertSame(2, $deleted);
        $this->assertDatabaseMissing('board_posts', ['id' => $questionId]);
        $this->assertDatabaseMissing('board_posts', ['id' => $replyId]);
        $this->assertDatabaseHas('board_posts', ['id' => $otherId]);
    }

    // ==========================================
    // deleteReplyPost
    // ==========================================

    /**
     * deleteReplyPost: 부모 게시글에 reply 없음 → RuntimeException
     *
     * @return void 반환값 없음
     *
     * @effects listener_delete_fails_loudly_for_missing_post
     */
    public function test_delete_reply_post_throws_runtime_exception_when_no_reply(): void
    {
        $parentPostId = $this->createTestPost();

        $this->expectException(\RuntimeException::class);

        $this->listener->deleteReplyPost(null, $this->board->slug, $parentPostId);
    }

    /**
     * deleteReplyPost: reply 있음 → 삭제 성공 + carry 반환
     *
     * @return void 반환값 없음
     *
     * @effects reply_delete_recomputes_answered
     */
    public function test_delete_reply_post_returns_carry_on_success(): void
    {
        $parentPostId = $this->createTestPost();
        $replyPostId = $this->createTestPost(['parent_id' => $parentPostId]);
        $carry = ['reply_carry' => true];

        $result = $this->listener->deleteReplyPost($carry, $this->board->slug, $parentPostId);

        $this->assertSame($carry, $result);
        $this->assertSoftDeleted('board_posts', ['id' => $replyPostId]);
    }

    // ==========================================
    // countReplies
    // ==========================================

    /**
     * countReplies: 살아있는 자식만 집계한다 (soft delete 된 답변 제외).
     *
     * @return void 반환값 없음
     *
     * @effects reply_delete_recomputes_answered
     */
    public function test_count_replies_counts_only_live_children(): void
    {
        $parentPostId = $this->createTestPost(['title' => '원본 문의']);
        $this->createTestPost([
            'title' => 'Re: 살아있는 답변',
            'parent_id' => $parentPostId,
            'depth' => 1,
        ]);
        $trashedReplyId = $this->createTestPost([
            'title' => 'Re: 삭제될 답변',
            'parent_id' => $parentPostId,
            'depth' => 1,
        ]);

        Post::find($trashedReplyId)->delete();

        $this->assertSame(
            1,
            $this->listener->countReplies(0, $parentPostId),
            'soft delete 된 답변은 집계에서 제외되어야 합니다.'
        );
    }
}
