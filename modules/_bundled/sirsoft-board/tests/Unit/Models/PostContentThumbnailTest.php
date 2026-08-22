<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Models;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Post 모델 content_thumbnail_url 캐시 계산 테스트 (공개 이슈 #22)
 *
 * saving 이벤트가 본문 첫 내부 이미지 URL 을 저장 시점에 추출·캐시하는 계약을
 * 고정합니다. 재계산 조건(생성 시 / content dirty 시에만)과 필터 훅
 * (sirsoft-board.post.filter_content_thumbnail)의 대체·차단 동작을 포함합니다.
 */
class PostContentThumbnailTest extends BoardTestCase
{
    private const FILTER_HOOK = 'sirsoft-board.post.filter_content_thumbnail';

    protected function getTestBoardSlug(): string
    {
        return 'post-content-thumbnail';
    }

    protected function tearDown(): void
    {
        HookManager::clearFilter(self::FILTER_HOOK);
        parent::tearDown();
    }

    /**
     * 모델 이벤트를 경유해 게시글을 생성합니다 (DB 직접 insert 아님).
     *
     * @param  array  $attributes  덮어쓸 속성
     * @return Post 생성된 게시글
     */
    private function createPostViaModel(array $attributes = []): Post
    {
        return Post::create(array_merge([
            'board_id' => $this->board->id,
            'title' => '본문 썸네일 테스트',
            'content' => '<p>본문</p>',
            'content_mode' => 'html',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
            'trigger_type' => 'admin',
        ], $attributes));
    }

    /**
     * @scenario image_source=content_internal_only, secrecy=normal
     *
     * @effects content_internal_image_fills_list_thumbnail
     */
    #[Test]
    public function saving_extracts_first_internal_image_on_create(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<p>글</p><img src="/storage/uploads/first.jpg"><img src="/storage/uploads/second.jpg">',
        ]);

        $this->assertSame('/storage/uploads/first.jpg', $post->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=content_external_only, secrecy=normal
     *
     * @effects external_only_content_yields_null
     */
    #[Test]
    public function external_only_content_yields_null(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="https://evil.example.org/x.jpg"><img src="//cdn.example.org/y.jpg">',
        ]);

        $this->assertNull($post->fresh()->content_thumbnail_url);
    }

    /**
     * @scenario image_source=none, secrecy=normal
     *
     * @effects external_only_content_yields_null
     */
    #[Test]
    public function content_without_image_yields_null(): void
    {
        $post = $this->createPostViaModel(['content' => '<p>이미지가 없는 본문</p>']);

        $this->assertNull($post->fresh()->content_thumbnail_url);
    }

    /**
     * @effects recompute_on_content_change
     */
    #[Test]
    public function content_change_recomputes_cache(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/old.jpg">',
        ]);

        $post->update(['content' => '<img src="/storage/uploads/new.jpg">']);

        $this->assertSame('/storage/uploads/new.jpg', $post->fresh()->content_thumbnail_url);
    }

    /**
     * @effects recompute_on_content_change
     */
    #[Test]
    public function removing_all_images_resets_cache_to_null(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/only.jpg">',
        ]);

        $post->update(['content' => '<p>이미지 전부 제거됨</p>']);

        $this->assertNull($post->fresh()->content_thumbnail_url);
    }

    /**
     * @effects no_recompute_on_unrelated_update
     */
    #[Test]
    public function unrelated_update_does_not_recompute(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/a.jpg">',
        ]);

        // 이벤트 우회로 캐시를 다른 값으로 바꿔 두면, content 무변경 저장이
        // 재계산을 하지 않는다는 사실이 값 보존으로 관측된다
        DB::table('board_posts')->where('id', $post->id)
            ->update(['content_thumbnail_url' => '/storage/uploads/manual.jpg']);

        $post->fresh()->update(['title' => '제목만 변경']);

        $this->assertSame(
            '/storage/uploads/manual.jpg',
            $post->fresh()->content_thumbnail_url,
            'content 가 dirty 가 아니면 캐시를 재계산하지 않아야 합니다.'
        );
    }

    /**
     * @effects filter_hook_can_override_or_block
     */
    #[Test]
    public function filter_hook_can_override_value(): void
    {
        HookManager::addFilter(self::FILTER_HOOK, function ($value, $post, $candidates) {
            $this->assertIsArray($candidates, '세 번째 인자로 전체 후보 목록이 전달되어야 합니다.');

            return 'https://cdn.example.net/promoted.jpg';
        });

        $post = $this->createPostViaModel([
            'content' => '<img src="https://cdn.example.net/promoted.jpg">',
        ]);

        $this->assertSame('https://cdn.example.net/promoted.jpg', $post->fresh()->content_thumbnail_url);
    }

    /**
     * @effects filter_hook_can_override_or_block
     */
    #[Test]
    public function filter_hook_can_block_fallback(): void
    {
        HookManager::addFilter(self::FILTER_HOOK, fn ($value) => null);

        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/internal.jpg">',
        ]);

        $this->assertNull($post->fresh()->content_thumbnail_url);
    }

    /**
     * text 모드 글은 리터럴 img 마크업이 있어도 캐시하지 않아야 합니다.
     *
     * text 모드 본문은 이스케이프되어 렌더되므로(상세에 이미지가 표시되지 않음)
     * 목록 썸네일만 뜨면 상세와 목록이 어긋난다 (Chrome MCP T10② 실측 회귀).
     *
     * @effects text_mode_content_never_caches
     */
    #[Test]
    public function text_mode_content_is_never_cached(): void
    {
        $post = $this->createPostViaModel([
            'content_mode' => 'text',
            'content' => '텍스트 본문에 쓴 <img src="/storage/uploads/literal.jpg"> 마크업',
        ]);

        $this->assertNull($post->fresh()->content_thumbnail_url);
    }

    /**
     * content_mode 를 html → text 로 바꾸면 캐시가 비워져야 합니다 (content 무변경이어도).
     *
     * @effects mode_switch_recomputes_cache
     */
    #[Test]
    public function switching_to_text_mode_clears_cache(): void
    {
        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/a.jpg">',
        ]);

        $this->assertSame('/storage/uploads/a.jpg', $post->fresh()->content_thumbnail_url);

        $post->fresh()->update(['content_mode' => 'text']);

        $this->assertNull($post->fresh()->content_thumbnail_url, 'text 모드 전환 시 캐시가 비워져야 합니다.');
    }

    /**
     * content_mode 를 text → html 로 바꾸면 캐시가 다시 계산되어야 합니다.
     *
     * @effects mode_switch_recomputes_cache
     */
    #[Test]
    public function switching_to_html_mode_recomputes_cache(): void
    {
        $post = $this->createPostViaModel([
            'content_mode' => 'text',
            'content' => '<img src="/storage/uploads/promoted.jpg">',
        ]);

        $this->assertNull($post->fresh()->content_thumbnail_url);

        $post->fresh()->update(['content_mode' => 'html']);

        $this->assertSame('/storage/uploads/promoted.jpg', $post->fresh()->content_thumbnail_url);
    }

    /**
     * 필터가 비정상 값(비문자열/상한 초과)을 돌려줘도 저장이 실패하지 않아야 합니다.
     */
    #[Test]
    public function filter_hook_invalid_return_degrades_to_null(): void
    {
        HookManager::addFilter(self::FILTER_HOOK, fn ($value) => str_repeat('a', 1500));

        $post = $this->createPostViaModel([
            'content' => '<img src="/storage/uploads/internal.jpg">',
        ]);

        $this->assertNull($post->fresh()->content_thumbnail_url);
    }
}
