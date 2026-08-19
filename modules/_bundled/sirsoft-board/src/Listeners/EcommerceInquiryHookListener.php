<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Http\Resources\PostResource;
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
            // 이커머스 → 게시판: 살아있는 답변 수 조회 (Filter 훅)
            'sirsoft-ecommerce.inquiry.count_replies' => [
                'method' => 'countReplies',
                'priority' => 10,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
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
     * @return array|null 성공 시 ['post_id' => int, 'inquirable_type' => string],
     *                    중복 답변 차단 시 ['duplicate' => true], 실패 시 null
     */
    public function createAndReturn(mixed $carry, string $slug, array $data): ?array
    {
        try {
            // 비회원 문의 지원: user_id가 없으면 author_name 사용
            if (empty($data['user_id']) && ! Auth::check()) {
                $data['user_id'] = null;
            }

            // ip_address: board_posts.ip_address NOT NULL 제약 충족.
            // 클라이언트 IP 는 요청 경계(호출 서비스 ProductInquiryService)가 payload 로
            // 주입한다 — Listener 는 request() 를 직접 참조하지 않는다(입력 우회 방지).
            if (empty($data['ip_address'])) {
                $data['ip_address'] = '0.0.0.0';
            }

            // 2차 방어(게시판 실데이터): 이미 살아있는 답변이 있는 부모에는 자식 Post 생성 거부.
            // 1차 방어(피벗 is_answered)는 서비스가 담당하지만, API 동시 호출/직접 호출로
            // 플래그가 어긋난 경우에도 게시판에 중복 답변이 쌓이지 않도록 여기서 최종 차단한다.
            if (! empty($data['parent_id']) && $this->postRepository->findFirstReplyWithBoard((int) $data['parent_id']) !== null) {
                Log::warning('EcommerceInquiryHookListener: 기존 답변이 있는 문의에 중복 답변 생성 시도 차단', [
                    'slug' => $slug,
                    'parent_id' => $data['parent_id'],
                ]);

                // null(훅 무응답/실패)과 구분되는 중복 마커 — 호출 서비스가 이 마커로
                // "이미 등록된 답변" 사유를 사용자에게 그대로 안내한다.
                return ['duplicate' => true];
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
     * ID 목록으로 Post 데이터 배열 반환 (`sirsoft-ecommerce.inquiry.get_by_ids` 필터 훅)
     *
     * 이커머스 모듈이 문의 목록을 구성할 때 게시글 데이터를 일괄 조회합니다.
     *
     * 반환 payload 계약(KVE-2026-1914): 각 항목은 비밀글 열람 권위 플래그
     * `can_view_secret`(bool)을 반드시 포함해야 한다. 소비자(`ProductInquiryService`)는
     * 이 플래그로 title/content/reply/attachments 마스킹을 최종 확정하며, **플래그가
     * 없으면 fail-closed 로 전부 마스킹**한다. 3자 확장이 이 훅을 대체 구현할 때
     * `can_view_secret` 를 누락하면 비밀 아닌 문의까지 조용히 마스킹되는 기능 회귀가
     * 발생하므로, 대체 리스너도 요청자 신원으로 이 플래그를 채워야 한다.
     *
     * @param  array  $carry  이전 필터 결과 (초기값: [])
     * @param  array  $context  조회 컨텍스트 ['ids' => int[], 'slug' => string]
     * @return array Post 데이터 배열 (각 항목에 `can_view_secret` bool 필수)
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
                // 비밀글 서버측 게이팅(KVE-2026-1914): 열람 권한이 없으면 원문을 마스킹한다.
                // 규칙은 PostResource 와 동일한 SecretContentGate(SSoT)를 공유한다.
                // 리스트 컨텍스트라 password_verified 는 적용되지 않는다(작성자/관리 권한만).
                $isSecret = (bool) $post->is_secret;
                $canViewSecret = ! $isSecret || PostResource::canViewSecretForPost($post);

                return [
                    'id' => $post->id,
                    'board_id' => $post->board_id,
                    'board_slug' => $post->board?->slug,
                    'parent_id' => $post->parent_id,
                    'user_id' => $post->user_id,
                    'author_name' => $post->author_name,
                    'title' => $canViewSecret
                        ? $post->title
                        : __('sirsoft-board::messages.post.secret_post_title'),
                    'content' => $canViewSecret ? $post->content : null,
                    'category' => $post->category,
                    'is_secret' => $isSecret,
                    // 서버가 요청자 신원으로 내린 열람 판정(SSoT). 소비 서비스가 이 값으로
                    // 마스킹을 재확인(이중 방어)할 수 있도록 함께 실어 보낸다. 소비측은 자기
                    // 권한을 재계산하지 말고 이 값만 신뢰해야 게이트 강도가 갈리지 않는다.
                    'can_view_secret' => $canViewSecret,
                    'status' => $post->status?->value,
                    'view_count' => $post->view_count,
                    'created_at' => $post->created_at?->toIso8601String(),
                    'updated_at' => $post->updated_at?->toIso8601String(),
                    // 첨부파일 목록 (비밀글 비열람자는 빈 배열)
                    'attachments' => $canViewSecret
                        ? $post->attachments->map(fn ($a) => [
                            'id' => $a->id,
                            'original_filename' => $a->original_filename,
                            'size' => $a->size,
                            'size_formatted' => $a->size_formatted,
                            'is_image' => $a->is_image,
                            'preview_url' => $a->preview_url,
                            'download_url' => $a->download_url,
                        ])->values()->all()
                        : [],
                    // 답변 게시글 (parent_id가 있는 자식 글, 비밀글 비열람자는 null)
                    'reply' => $canViewSecret ? $this->getReplyForPost($post) : null,
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
                'secret_mode' => $board->secret_mode?->value ?? 'disabled',
                'categories' => $board->categories ?? [],
                'use_file_upload' => (bool) $board->use_file_upload,
                'max_file_count' => $board->max_file_count ?? 5,
                'max_file_size' => $board->max_file_size ?? 10,
                'allowed_extensions' => $board->allowed_extensions ?? [],
                'min_title_length' => $board->min_title_length ?? 2,
                'max_title_length' => $board->max_title_length ?? 200,
                'min_content_length' => $board->min_content_length ?? 10,
                'max_content_length' => $board->max_content_length ?? 10000,
                'attachment_upload_url' => '/api/modules/sirsoft-board/boards/'.$board->slug.'/attachments',
                'attachment_delete_url' => '/api/modules/sirsoft-board/boards/'.$board->slug.'/attachments/:id',
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
                throw new ModelNotFoundException("Post {$postId} or its board could not be found.");
            }

            $attachmentIds = $data['attachment_ids'] ?? [];
            $this->postService->updatePost($post->board->slug, $postId, $data, $attachmentIds);
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Post 수정 실패 - 게시글 또는 게시판 없음', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Post 수정 실패', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
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
                throw new ModelNotFoundException("Post {$postId} or its board could not be found.");
            }

            // 이커머스 경로: 알림 발송 SKIP (createPost와 동일한 skip_notification 패턴).
            // cascade_replies: 훅 경유 삭제는 게시판 답글 삭제 정책(block)과 무관하게 답변을
            // 함께 정리한다 — 문의 답변은 시스템 생성이므로 정책에 막히면 안 되고,
            // 기설치본의 다건 고아 답변도 cascade 스윕이 함께 정리한다.
            $this->postService->deletePost($post->board->slug, $postId, options: [
                'skip_notification' => true,
                'cascade_replies' => true,
            ]);
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Post 삭제 실패 - 게시글 또는 게시판 없음', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Post 삭제 실패', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
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
                throw new ModelNotFoundException("Reply Post {$reply->id}'s board could not be found.");
            }

            $this->postService->updatePost($reply->board->slug, $reply->id, $data);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Reply Post 수정 실패 - 게시글 또는 게시판 없음', [
                'parent_post_id' => $parentPostId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Reply Post 수정 실패', [
                'parent_post_id' => $parentPostId,
                'error' => $e->getMessage(),
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
                throw new ModelNotFoundException("Reply Post {$reply->id}'s board could not be found.");
            }

            // 이커머스 경로: 알림 발송 SKIP (createPost와 동일한 skip_notification 패턴).
            // cascade_replies: 답변 밑에 달린 자식 글까지 함께 정리 (정책 무관 — 시스템 생성 답변)
            $this->postService->deletePost($reply->board->slug, $reply->id, options: [
                'skip_notification' => true,
                'cascade_replies' => true,
            ]);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (ModelNotFoundException $e) {
            Log::warning('EcommerceInquiryHookListener: Reply Post 삭제 실패 - 게시글 또는 게시판 없음', [
                'parent_post_id' => $parentPostId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_changed')
            );
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: Reply Post 삭제 실패', [
                'parent_post_id' => $parentPostId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.reply_delete_failed')
            );
        }

        return $carry;
    }

    /**
     * 부모 문의 Post 의 살아있는 답변 수 반환 (`sirsoft-ecommerce.inquiry.count_replies` 필터 훅)
     *
     * 이커머스 모듈이 답변 삭제 후 `is_answered` 재계산, 게시판 직권 삭제 시 답변완료
     * 해제 판정에 사용합니다. SoftDeletes 전역 스코프가 삭제된 답변을 제외하므로
     * "살아있는 답변" 만 집계됩니다.
     *
     * @param  mixed  $carry  이전 필터 결과 (초기값: null = 판정 불가)
     * @param  int  $parentPostId  부모 문의 Post ID
     * @return int|null 살아있는 답변 수 (조회 실패 시 이전 필터 결과 유지 — null 이면 소비측이 판정 불가로 no-op)
     */
    public function countReplies(mixed $carry, int $parentPostId): ?int
    {
        try {
            return $this->postRepository->countRepliesByParentId($parentPostId);
        } catch (\Exception $e) {
            Log::error('EcommerceInquiryHookListener: 답변 수 조회 실패', [
                'parent_post_id' => $parentPostId,
                'error' => $e->getMessage(),
            ]);

            // 실패를 0(답변 없음)으로 접으면 소비측이 답변완료를 오해제한다 —
            // 판정 불가(null)를 그대로 흘려보낸다.
            return is_numeric($carry) ? (int) $carry : null;
        }
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
