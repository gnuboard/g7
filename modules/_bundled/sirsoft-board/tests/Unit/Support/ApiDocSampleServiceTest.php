<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Support;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Support\ApiDoc\ApiDocSampleService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * sirsoft-board API 문서 실측용 완전 샘플 시더 테스트.
 *
 * 게시판 도메인 대표 샘플(공개 게시글 + 댓글 + 첨부)이 생성되고, slug 라우팅
 * 실측을 위한 도메인별 path_params 맵이 반환되며, 재실행 시 멱등한지 검증한다.
 */
class ApiDocSampleServiceTest extends ModuleTestCase
{
    /**
     * @var string 샘플 게시판 슬러그 마커
     */
    private const SAMPLE_SLUG = 'apidoc-sample-board';

    #[Test]
    public function 시더는_계약을_구현한다(): void
    {
        $this->assertInstanceOf(ApiDocSampleSeeder::class, new ApiDocSampleService);
    }

    #[Test]
    public function 게시판_도메인_대표_샘플_맵을_반환한다(): void
    {
        $map = (new ApiDocSampleService)->seed();

        // 공개(boards)/관리자(board) 두 도메인 키가 모두 존재
        $this->assertArrayHasKey('boards', $map);
        $this->assertArrayHasKey('board', $map);
        $this->assertSame(Board::class, $map['boards']['model']);
        $this->assertNotEmpty($map['boards']['value']);
    }

    #[Test]
    public function slug_라우팅_실측용_path_params_맵을_제공한다(): void
    {
        $map = (new ApiDocSampleService)->seed();

        // boards 도메인은 slug/board/id/postId/commentId/hash 를 실제 값으로 제공
        $params = $map['boards']['path_params'];

        $this->assertSame(self::SAMPLE_SLUG, $params['slug']);
        $this->assertArrayHasKey('id', $params);
        $this->assertArrayHasKey('postId', $params);
        $this->assertArrayHasKey('commentId', $params);
        $this->assertArrayHasKey('hash', $params);

        // 각 값은 실제 시드된 레코드의 키와 일치
        $post = Post::query()->where('title', 'API 문서 샘플 게시글')->firstOrFail();
        $comment = Comment::query()->where('content', 'API 문서 샘플 댓글입니다.')->firstOrFail();
        $attachment = Attachment::query()->where('original_filename', 'apidoc-sample.png')->firstOrFail();

        $this->assertSame((string) $post->getKey(), $params['id']);
        $this->assertSame((string) $post->getKey(), $params['postId']);
        $this->assertSame((string) $comment->getKey(), $params['commentId']);
        $this->assertSame((string) $attachment->hash, $params['hash']);
    }

    #[Test]
    public function 대표_샘플_게시판은_공개_게시글과_댓글과_첨부를_갖는다(): void
    {
        (new ApiDocSampleService)->seed();

        $board = Board::query()->where('slug', self::SAMPLE_SLUG)->firstOrFail();
        $post = Post::query()->where('board_id', $board->id)->where('title', 'API 문서 샘플 게시글')->firstOrFail();

        $this->assertTrue((bool) $board->is_active);
        $this->assertFalse((bool) $post->is_secret);
        $this->assertSame('published', $post->status->value);
        $this->assertSame(1, Comment::query()->where('post_id', $post->id)->count());
        $this->assertSame(1, Attachment::query()->where('post_id', $post->id)->count());
    }

    #[Test]
    public function 게시판별_권한이_admin_역할에_등록되어_실측_사용자가_접근할_수_있다(): void
    {
        (new ApiDocSampleService)->seed();

        // ensureBoardPermissions 로 게시판별 권한이 생성됨 (posts.read 등)
        $this->assertDatabaseHas('permissions', [
            'identifier' => 'sirsoft-board.'.self::SAMPLE_SLUG.'.posts.read',
        ]);
    }

    #[Test]
    public function 재실행_시_샘플이_중복_생성되지_않는다(): void
    {
        $service = new ApiDocSampleService;

        $service->seed();
        $service->seed();

        $this->assertSame(1, Board::query()->where('slug', self::SAMPLE_SLUG)->count());
        $this->assertSame(1, Post::query()->where('title', 'API 문서 샘플 게시글')->count());
        $this->assertSame(1, Comment::query()->where('content', 'API 문서 샘플 댓글입니다.')->count());
        $this->assertSame(1, Attachment::query()->where('original_filename', 'apidoc-sample.png')->count());
    }
}
