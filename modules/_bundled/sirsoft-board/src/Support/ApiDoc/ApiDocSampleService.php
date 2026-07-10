<?php

namespace Modules\Sirsoft\Board\Support\ApiDoc;

use App\Contracts\ApiDoc\ApiDocSampleSeeder;
use App\Models\User;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Enums\ReportReasonType;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Enums\ReportType;
use Modules\Sirsoft\Board\Enums\TriggerType;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardType;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardPermissionService;

/**
 * sirsoft-board API 문서 실측용 완전 샘플 시더
 *
 * 게시판 도메인은 라우트가 route-model binding 대신 게시판 slug·게시글 id 를
 * 문자열 path 파라미터로 받으므로(boards/{slug}/posts/{id}), docgen 의 route key
 * 자동 치환이 상세 GET 에서 동작하지 않습니다. 이 시더는 완전 샘플 게시판
 * (공개 게시글 + 댓글 + 첨부)을 멱등 생성하고, 각 도메인의 `path_params` 맵으로
 * slug/id/hash 를 실제 값으로 치환할 수 있게 하여 상세 조회를 실측 가능하게 합니다.
 *
 * `api:docgen --scope=module:sirsoft-board` 실행 시 커맨드가 규약 위치
 * (`Modules\Sirsoft\Board\Support\ApiDoc\ApiDocSampleService`)로 자동 발견합니다.
 */
class ApiDocSampleService implements ApiDocSampleSeeder
{
    /**
     * @var string 샘플 게시판 식별용 슬러그 마커
     */
    private const SAMPLE_SLUG = 'apidoc-sample-board';

    /**
     * 게시판 도메인 완전 샘플을 멱등 생성하고 도메인별 대표 route key + path_params 맵을 반환합니다.
     *
     * @return array<string, array{model: class-string, key: string, value: string, path_params?: array<string, string>}> 도메인 => 대표 레코드 정보
     */
    public function seed(): array
    {
        $board = $this->seedBoard();
        $post = $this->seedPost($board);
        $comment = $this->seedComment($board, $post);
        $attachment = $this->seedAttachment($board, $post);

        // 게시판 slug 라우팅(boards/{slug}/posts/{id}...)을 실측하기 위한 path 파라미터 맵.
        // route-model binding 이 없는 문자열 param 을 실제 값으로 정확 일치 치환한다.
        $boardParams = [
            'slug' => (string) $board->slug,
            'board' => (string) $board->getKey(),
            'id' => (string) $post->getKey(),
            'postId' => (string) $post->getKey(),
            'commentId' => (string) $comment->getKey(),
            'hash' => (string) $attachment->hash,
        ];

        $map = [];

        // boards: 공개(User) 게시판/게시글/댓글/첨부 라우트
        $map['boards'] = [
            'model' => Board::class,
            'key' => $board->getRouteKeyName(),
            'value' => (string) $board->getRouteKey(),
            'path_params' => $boardParams,
        ];

        // board: 관리자 게시글/첨부/댓글 라우트(admin/board/{slug}/posts/{id}...)
        $map['board'] = [
            'model' => Board::class,
            'key' => $board->getRouteKeyName(),
            'value' => (string) $board->getRouteKey(),
            'path_params' => $boardParams,
        ];

        // reports: 신고 상세(admin/reports/{report}) 실측용 대표 신고
        if ($report = $this->representativeReport($board, $post)) {
            $map['reports'] = [
                'model' => Report::class,
                'key' => $report->getRouteKeyName(),
                'value' => (string) $report->getRouteKey(),
                'path_params' => ['report' => (string) $report->getKey()],
            ];
        }

        // board-types: 게시판 유형(GET 상세는 없으나 대표 키 노출)
        if ($boardType = $this->representativeBoardType()) {
            $map['board-types'] = [
                'model' => BoardType::class,
                'key' => $boardType->getRouteKeyName(),
                'value' => (string) $boardType->getRouteKey(),
                'path_params' => ['id' => (string) $boardType->getKey()],
            ];
        }

        return $map;
    }

    /**
     * 완전 샘플 게시판을 멱등 생성합니다(신고/파일 업로드 활성).
     *
     * @return Board 대표 게시판 레코드
     */
    private function seedBoard(): Board
    {
        $board = Board::query()->where('slug', self::SAMPLE_SLUG)->first()
            ?? Board::factory()->create([
                'slug' => self::SAMPLE_SLUG,
                'name' => ['ko' => 'API 문서 샘플 게시판', 'en' => 'API Doc Sample Board'],
                'is_active' => true,
                'use_report' => true,
                'use_file_upload' => true,
                'show_view_count' => true,
            ]);

        // factory 직접 생성은 BoardService::create 의 권한 등록 훅을 우회하므로,
        // 게시판별 권한(sirsoft-board.{slug}.{action})을 멱등 등록한다. 기본 role
        // 매핑에 admin 이 포함(posts.read/comments.read 등)되어 실측 사용자(admin)가
        // slug 라우트(boards/{slug}/posts...)를 403 없이 실측할 수 있게 한다.
        (new BoardPermissionService)->ensureBoardPermissions($board);

        return $board;
    }

    /**
     * 완전 샘플 공개 게시글을 멱등 생성합니다.
     *
     * @param  Board  $board  대표 게시판
     * @return Post 대표 게시글 레코드
     */
    private function seedPost(Board $board): Post
    {
        $post = Post::query()
            ->where('board_id', $board->id)
            ->where('title', 'API 문서 샘플 게시글')
            ->first();

        if ($post) {
            return $post;
        }

        $actor = $this->sampleActor();

        return Post::query()->create([
            'board_id' => $board->id,
            'title' => 'API 문서 샘플 게시글',
            'content' => '<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>',
            'content_mode' => 'html',
            'user_id' => $actor?->id,
            'author_name' => $actor?->name ?? '관리자',
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => PostStatus::Published,
            'trigger_type' => TriggerType::User,
            'view_count' => 42,
        ]);
    }

    /**
     * 완전 샘플 댓글을 멱등 생성합니다.
     *
     * @param  Board  $board  대표 게시판
     * @param  Post  $post  대표 게시글
     * @return Comment 대표 댓글 레코드
     */
    private function seedComment(Board $board, Post $post): Comment
    {
        $comment = Comment::query()
            ->where('post_id', $post->id)
            ->where('content', 'API 문서 샘플 댓글입니다.')
            ->first();

        if ($comment) {
            return $comment;
        }

        $actor = $this->sampleActor();

        return Comment::query()->create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'user_id' => $actor?->id,
            'author_name' => $actor?->name ?? '관리자',
            'content' => 'API 문서 샘플 댓글입니다.',
            'is_secret' => false,
            'status' => PostStatus::Published,
            'trigger_type' => TriggerType::User,
            'depth' => 0,
        ]);
    }

    /**
     * 완전 샘플 첨부파일 레코드를 멱등 생성합니다(실파일 없이 메타만).
     *
     * @param  Board  $board  대표 게시판
     * @param  Post  $post  대표 게시글
     * @return Attachment 대표 첨부 레코드
     */
    private function seedAttachment(Board $board, Post $post): Attachment
    {
        $attachment = Attachment::query()
            ->where('post_id', $post->id)
            ->where('original_filename', 'apidoc-sample.png')
            ->first();

        if ($attachment) {
            return $attachment;
        }

        $actor = $this->sampleActor();

        return Attachment::query()->create([
            'board_id' => $board->id,
            'post_id' => $post->id,
            'hash' => 'apidocsmpl1',
            'original_filename' => 'apidoc-sample.png',
            'stored_filename' => 'apidoc-sample.png',
            'disk' => 'public',
            'path' => 'board/apidoc-sample.png',
            'mime_type' => 'image/png',
            'size' => 2048,
            'collection' => 'default',
            'order' => 0,
            'created_by' => $actor?->id,
            'trigger_type' => TriggerType::User,
        ]);
    }

    /**
     * 신고 상세 실측용 대표 신고를 반환합니다(기존 우선, 없으면 멱등 생성).
     *
     * @param  Board  $board  대표 게시판
     * @param  Post  $post  대표 게시글
     * @return Report|null 대표 신고 (생성 실패 시 null)
     */
    private function representativeReport(Board $board, Post $post): ?Report
    {
        if ($report = Report::query()->orderBy('id')->first()) {
            return $report;
        }

        $actor = $this->sampleActor();

        return Report::query()->create([
            'board_id' => $board->id,
            'target_type' => ReportType::Post,
            'target_id' => $post->id,
            'author_id' => $actor?->id,
            'status' => ReportStatus::Pending,
            'process_histories' => [],
            'metadata' => ['reason' => ReportReasonType::Spam->value],
            'last_reported_at' => now(),
        ]);
    }

    /**
     * 대표 게시판 유형을 반환합니다(기존 우선, 없으면 멱등 생성).
     *
     * @return BoardType|null 대표 게시판 유형 (없으면 null)
     */
    private function representativeBoardType(): ?BoardType
    {
        if ($boardType = BoardType::query()->orderBy('id')->first()) {
            return $boardType;
        }

        return BoardType::query()->create([
            'slug' => 'apidoc-sample-type',
            'name' => ['ko' => 'API 문서 샘플 유형', 'en' => 'API Doc Sample Type'],
        ]);
    }

    /**
     * 샘플 작성자로 쓸 사용자를 반환합니다.
     *
     * 코어 완전 샘플 사용자(먼저 시드됨)를 우선하고, 없으면 첫 사용자로 폴백합니다.
     *
     * @return User|null 샘플 사용자 (없으면 null)
     */
    private function sampleActor(): ?User
    {
        return User::query()->where('email', 'apidoc-sample-user@example.com')->first()
            ?? User::query()->orderBy('id')->first();
    }
}
