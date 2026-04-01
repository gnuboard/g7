<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\PostService;

/**
 * 이커머스 문의 연동 훅 리스너 (게시판 모듈)
 *
 * 이커머스 모듈이 게시판 기능을 사용할 수 있도록 Filter 훅을 제공합니다.
 * - sirsoft-ecommerce.inquiry.create       → Post 생성 후 post_id/inquirable_type 반환
 * - sirsoft-ecommerce.inquiry.get_by_ids   → ID 목록으로 Post 배열 반환
 * - sirsoft-ecommerce.inquiry.get_settings → 게시판 slug로 게시판 설정 전체 반환
 */
class EcommerceInquiryHookListener implements HookListenerInterface
{
    /**
     * EcommerceInquiryHookListener 생성자
     *
     * @param  PostService  $postService  게시글 서비스
     * @param  PostRepositoryInterface  $postRepository  게시글 저장소
     * @param  BoardRepositoryInterface  $boardRepository  게시판 저장소
     */
    public function __construct(
        protected PostService $postService,
        protected PostRepositoryInterface $postRepository,
        protected BoardRepositoryInterface $boardRepository,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 이커머스 → 게시판: Post 생성 요청 (Filter 훅)
            'sirsoft-ecommerce.inquiry.create' => [
                'method' => 'createAndReturn',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 이커머스 → 게시판: Post 수정 요청 (Filter 훅)
            'sirsoft-ecommerce.inquiry.update' => [
                'method' => 'updatePost',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 이커머스 → 게시판: Post 삭제 요청 (Filter 훅)
            'sirsoft-ecommerce.inquiry.delete' => [
                'method' => 'deletePost',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 이커머스 → 게시판: 답변(Reply) Post 수정 요청 (Filter 훅)
            'sirsoft-ecommerce.inquiry.update_reply' => [
                'method' => 'updateReplyPost',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 이커머스 → 게시판: 답변(Reply) Post 삭제 요청 (Filter 훅)
            'sirsoft-ecommerce.inquiry.delete_reply' => [
                'method' => 'deleteReplyPost',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 이커머스 → 게시판: ID 목록으로 Post 데이터 조회 (Filter 훅)
            'sirsoft-ecommerce.inquiry.get_by_ids' => [
                'method' => 'getByIds',
                'priority' => 10,
                'type' => 'filter',
            ],
            // 이커머스 → 게시판: 게시판 설정 전체 조회 (Filter 훅)
            'sirsoft-ecommerce.inquiry.get_settings' => [
                'method' => 'getBoardSettings',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // Filter 훅은 getSubscribedHooks에서 지정한 메서드를 직접 호출합니다.
    }

    /**
     * 게시글 생성 후 post_id와 inquirable_type 반환
     *
     * 이커머스 모듈이 문의/답변 게시글을 게시판에 생성할 때 사용합니다.
     *
     * @param  mixed  $carry  이전 필터 결과 (초기값: null)
     * @param  string  $slug  게시판 슬러그
     * @param  array  $data  게시글 생성 데이터
     * @return array|null 성공 시 ['post_id' => int, 'inquirable_type' => string], 실패 시 null
     */
    public function createAndReturn(mixed $carry, string $slug, array $data): ?array
    {
        try {
            // 비회원 문의 지원: user_id가 없으면 author_name 사용
            if (empty($data['user_id']) && ! Auth::check()) {
                $data['user_id'] = null;
            }

            // ip_address: board_posts.ip_address NOT NULL 제약 충족
            if (empty($data['ip_address'])) {
                $data['ip_address'] = request()->ip() ?? '0.0.0.0';
            }

            // parent_id 있으면 답변글 → 부모 Post 제목으로 Re: 원글제목 설정
            if (! empty($data['parent_id']) && empty($data['title'])) {
                $parentPost = $this->postRepository->findWithBoard($data['parent_id']);
                $parentTitle = $parentPost?->title ?? '';
                $data['title'] = $parentTitle ? 'Re: '.$parentTitle : 'Re:';
            }

            // title이 없으면 content 앞부분으로 자동 생성 (board_posts.title NOT NULL)
            if (empty($data['title'])) {
                $content = $data['content'] ?? '';
                $data['title'] = mb_substr(strip_tags($content), 0, 50) ?: __('sirsoft-board::messages.inquiry.default_title');
            }

            $post = $this->postService->createPost($slug, $data, options: ['skip_notification' => true]);

            return [
                'post_id' => $post->id,
                'inquirable_type' => Post::class,
            ];
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Post 생성 실패', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * ID 목록으로 Post 데이터 배열 반환
     *
     * 이커머스 모듈이 문의 목록을 구성할 때 게시글 데이터를 일괄 조회합니다.
     *
     * @param  array  $carry  이전 필터 결과 (초기값: [])
     * @param  array  $context  조회 컨텍스트 ['ids' => int[], 'slug' => string]
     * @return array Post 데이터 배열
     */
    public function getByIds(array $carry, array $context): array
    {
        $ids = $context['ids'] ?? [];

        if (empty($ids)) {
            return $carry;
        }

        try {
            $posts = $this->postRepository->findByIdsWithRelations($ids);

            return $posts->map(function (Post $post) {
                return [
                    'id' => $post->id,
                    'board_id' => $post->board_id,
                    'board_slug' => $post->board?->slug,
                    'parent_id' => $post->parent_id,
                    'user_id' => $post->user_id,
                    'author_name' => $post->author_name,
                    'title' => $post->title,
                    'content' => $post->content,
                    'category' => $post->category,
                    'is_secret' => (bool) $post->is_secret,
                    'status' => $post->status?->value,
                    'view_count' => $post->view_count,
                    'created_at' => $post->created_at?->toIso8601String(),
                    'updated_at' => $post->updated_at?->toIso8601String(),
                    // 첨부파일 목록
                    'attachments' => $post->attachments->map(fn ($a) => [
                        'id' => $a->id,
                        'original_filename' => $a->original_filename,
                        'size' => $a->size,
                        'size_formatted' => $a->size_formatted,
                        'is_image' => $a->is_image,
                        'preview_url' => $a->preview_url,
                        'download_url' => $a->download_url,
                    ])->values()->all(),
                    // 답변 게시글 (parent_id가 있는 자식 글)
                    'reply' => $this->getReplyForPost($post),
                ];
            })->all();
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Post 목록 조회 실패', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return $carry;
        }
    }

    /**
     * 게시판 slug로 게시판 설정 전체 반환
     *
     * 이커머스 문의 작성/목록 페이지에서 게시판 설정을 동적으로 반영할 때 사용합니다.
     *
     * @param  array  $carry  이전 필터 결과 (초기값: [])
     * @param  string  $slug  게시판 슬러그
     * @return array 게시판 설정 배열
     */
    public function getBoardSettings(array $carry, string $slug): array
    {
        try {
            $board = $this->boardRepository->findBySlug($slug);

            if (! $board) {
                return $carry;
            }

            return [
                'secret_mode'            => $board->secret_mode?->value ?? 'disabled',
                'categories'             => $board->categories ?? [],
                'use_file_upload'        => (bool) $board->use_file_upload,
                'max_file_count'         => $board->max_file_count ?? 5,
                'max_file_size'          => $board->max_file_size ?? 10485760,
                'allowed_extensions'     => $board->allowed_extensions ?? [],
                'min_title_length'       => $board->min_title_length ?? 2,
                'max_title_length'       => $board->max_title_length ?? 200,
                'min_content_length'     => $board->min_content_length ?? 10,
                'max_content_length'     => $board->max_content_length ?? 10000,
                'attachment_upload_url'  => '/api/modules/sirsoft-board/boards/' . $board->slug . '/attachments',
                'attachment_delete_url'  => '/api/modules/sirsoft-board/boards/' . $board->slug . '/attachments/:id',
            ];
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: 게시판 설정 조회 실패', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $carry;
        }
    }

    /**
     * 문의 게시글 수정
     *
     * Post가 속한 Board의 slug를 직접 조회하여 사용합니다.
     * inquiry.board_slug 설정이 변경되어도 기존 문의를 안전하게 수정할 수 있습니다.
     *
     * @param  mixed  $carry  이전 필터 결과
     * @param  string  $slug  게시판 슬러그 (무시됨 — Post 소속 Board slug 우선)
     * @param  int  $postId  수정할 Post ID
     * @param  array  $data  수정 데이터
     * @return mixed
     */
    public function updatePost(mixed $carry, string $slug, int $postId, array $data): mixed
    {
        try {
            $post = $this->postRepository->findWithBoard($postId);

            if (! $post || ! $post->board) {
                throw new ModelNotFoundException("Post {$postId} 또는 소속 Board를 찾을 수 없습니다.");
            }

            $attachmentIds = $data['attachment_ids'] ?? [];
            $this->postService->updatePost($post->board->slug, $postId, $data, $attachmentIds);
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Post 수정 실패 - 게시글 또는 게시판 없음', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Post 수정 실패', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.update_failed')
            );
        }

        return $carry;
    }

    /**
     * 문의 게시글 삭제
     *
     * Post가 속한 Board의 slug를 직접 조회하여 사용합니다.
     * inquiry.board_slug 설정이 변경되어도 기존 문의를 안전하게 삭제할 수 있습니다.
     *
     * @param  mixed  $carry  이전 필터 결과
     * @param  string  $slug  게시판 슬러그 (무시됨 — Post 소속 Board slug 우선)
     * @param  int  $postId  삭제할 Post ID
     * @return mixed
     */
    public function deletePost(mixed $carry, string $slug, int $postId): mixed
    {
        try {
            $post = $this->postRepository->findWithBoard($postId);

            if (! $post || ! $post->board) {
                throw new ModelNotFoundException("Post {$postId} 또는 소속 Board를 찾을 수 없습니다.");
            }

            // 이커머스 경로: 알림 발송 SKIP (createPost와 동일한 skip_notification 패턴)
            $this->postService->deletePost($post->board->slug, $postId, options: ['skip_notification' => true]);
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Post 삭제 실패 - 게시글 또는 게시판 없음', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Post 삭제 실패', [
                'post_id' => $postId,
                'error'   => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.delete_failed')
            );
        }

        return $carry;
    }

    /**
     * 답변(Reply) 게시글 수정
     *
     * 부모 Post의 첫 번째 Reply를 조회하고, Reply가 속한 Board의 slug를 직접 사용합니다.
     * inquiry.board_slug 설정이 변경되어도 기존 답변을 안전하게 수정할 수 있습니다.
     *
     * @param  mixed  $carry  이전 필터 결과
     * @param  string  $slug  게시판 슬러그 (무시됨 — Post 소속 Board slug 우선)
     * @param  int  $parentPostId  부모 문의 Post ID
     * @param  array  $data  수정 데이터 (content)
     * @return mixed
     */
    public function updateReplyPost(mixed $carry, string $slug, int $parentPostId, array $data): mixed
    {
        try {
            $reply = $this->postRepository->findFirstReplyWithBoard($parentPostId);

            if (! $reply) {
                throw new \RuntimeException(
                    __('sirsoft-ecommerce::messages.inquiries.reply_not_found')
                );
            }

            if (! $reply->board) {
                throw new ModelNotFoundException("Reply Post {$reply->id} 소속 Board를 찾을 수 없습니다.");
            }

            $this->postService->updatePost($reply->board->slug, $reply->id, $data);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Reply Post 수정 실패 - 게시글 또는 게시판 없음', [
                'parent_post_id' => $parentPostId,
                'error'          => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Reply Post 수정 실패', [
                'parent_post_id' => $parentPostId,
                'error'          => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.reply_update_failed')
            );
        }

        return $carry;
    }

    /**
     * 답변(Reply) 게시글 삭제
     *
     * 부모 Post의 첫 번째 Reply를 조회하고, Reply가 속한 Board의 slug를 직접 사용합니다.
     * inquiry.board_slug 설정이 변경되어도 기존 답변을 안전하게 삭제할 수 있습니다.
     *
     * @param  mixed  $carry  이전 필터 결과
     * @param  string  $slug  게시판 슬러그 (무시됨 — Post 소속 Board slug 우선)
     * @param  int  $parentPostId  부모 문의 Post ID
     * @return mixed
     */
    public function deleteReplyPost(mixed $carry, string $slug, int $parentPostId): mixed
    {
        try {
            $reply = $this->postRepository->findFirstReplyWithBoard($parentPostId);

            if (! $reply) {
                throw new \RuntimeException(
                    __('sirsoft-ecommerce::messages.inquiries.reply_not_found')
                );
            }

            if (! $reply->board) {
                throw new ModelNotFoundException("Reply Post {$reply->id} 소속 Board를 찾을 수 없습니다.");
            }

            // 이커머스 경로: 알림 발송 SKIP (createPost와 동일한 skip_notification 패턴)
            $this->postService->deletePost($reply->board->slug, $reply->id, options: ['skip_notification' => true]);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Reply Post 삭제 실패 - 게시글 또는 게시판 없음', [
                'parent_post_id' => $parentPostId,
                'error'          => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Reply Post 삭제 실패', [
                'parent_post_id' => $parentPostId,
                'error'          => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.reply_delete_failed')
            );
        }

        return $carry;
    }

    // ─── 내부 유틸리티 ────────────────────────────────────────

    /**
     * 게시글의 답변(자식 글) 조회
     *
     * @param  Post  $post  부모 게시글
     * @return array|null 답변 데이터 또는 null
     */
    private function getReplyForPost(Post $post): ?array
    {
        $reply = $post->replies
            ->sortBy('created_at')
            ->first();

        if (! $reply) {
            return null;
        }

        return [
            'id' => $reply->id,
            'content' => $reply->content,
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }
}
