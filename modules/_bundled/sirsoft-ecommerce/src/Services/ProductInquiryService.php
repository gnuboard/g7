<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 상품 1:1 문의 서비스
 *
 * 상품 문의 목록 조회, 작성, 관리자 답변 등의 비즈니스 로직을 처리합니다.
 * 게시판 모듈과의 연동은 HookManager::applyFilters를 통해서만 수행합니다.
 */
class ProductInquiryService
{
    /**
     * ProductInquiryService 생성자
     *
     * @param  ProductInquiryRepositoryInterface  $repository  문의 리포지토리
     * @param  ProductRepositoryInterface  $productRepository  상품 리포지토리
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     */
    public function __construct(
        protected ProductInquiryRepositoryInterface $repository,
        protected ProductRepositoryInterface $productRepository,
        protected EcommerceSettingsService $settingsService
    ) {}

    /**
     * 설정된 문의 게시판 slug 조회
     *
     * @return string|null
     */
    public function getInquiryBoardSlug(): ?string
    {
        $inquirySettings = $this->settingsService->getSettings('inquiry');

        return $inquirySettings['board_slug'] ?? null;
    }

    /**
     * 상품 문의 목록 조회 (사용자)
     *
     * 피벗 조회 → 게시판 훅으로 Post 데이터 가져옴 → board_settings 메타 포함 반환
     *
     * @param  int  $productId  상품 ID
     * @param  int  $perPage  페이지당 개수
     * @param  int  $page  페이지 번호
     * @param  bool  $excludeSecret  비밀글 제외 여부
     * @return array{items: array, meta: array}
     */
    public function getProductInquiries(int $productId, int $perPage = 10, int $page = 1, bool $excludeSecret = false): array
    {
        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            return [
                'items' => [],
                'meta'  => [
                    'board_settings'    => $this->defaultBoardSettings(),
                    'inquiry_available' => false,
                    'total'             => 0,
                    'current_page'      => $page,
                    'per_page'          => $perPage,
                    'last_page'         => 1,
                ],
            ];
        }

        // 게시판 설정 조회
        $boardSettings = HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.get_settings',
            $this->defaultBoardSettings(),
            $boardSlug
        );

        // 피벗 기준 전체 목록 조회 (페이지네이션 전 — 비밀글 필터 적용 위해)
        $pivots = $this->repository->findByProductId($productId);

        $currentUserId = Auth::id();

        // inquirable_id 목록으로 Post 데이터 일괄 조회
        $ids = $pivots->pluck('inquirable_id')->all();
        $posts = [];
        if (! empty($ids)) {
            $rawPosts = HookManager::applyFilters(
                'sirsoft-ecommerce.inquiry.get_by_ids',
                [],
                ['ids' => $ids, 'slug' => $boardSlug]
            );
            foreach ($rawPosts as $post) {
                $postId = $post['id'] ?? null;
                if ($postId) {
                    $posts[$postId] = $post;
                }
            }
        }

        // 비밀글 제외 필터 적용 (Post의 is_secret 기준)
        if ($excludeSecret) {
            $pivots = $pivots->filter(function ($pivot) use ($posts) {
                $post = $posts[$pivot->inquirable_id] ?? null;

                return empty($post['is_secret']);
            })->values();
        }

        $total = $pivots->count();
        $pagePivots = $pivots->forPage($page, $perPage);

        // user_id 일괄 조회 (N+1 방지)
        $userIds = $pagePivots->map(fn ($pivot) => $posts[$pivot->inquirable_id]['user_id'] ?? null)
            ->filter()->unique()->values()->all();
        $userMap = User::whereIn('id', $userIds)->pluck('name', 'id');

        $items = $pagePivots->map(function ($pivot) use ($posts, $currentUserId, $userMap) {
            $post = $posts[$pivot->inquirable_id] ?? null;
            $isOwner = $currentUserId !== null && $pivot->user_id === $currentUserId;

            $userId = $post['user_id'] ?? null;
            $name = $userId ? ($userMap[$userId] ?? $post['author_name'] ?? null) : ($post['author_name'] ?? null);

            return [
                'id'          => $pivot->id,
                'post_id'     => $pivot->inquirable_id,
                'user_id'     => $userId,
                'author_name' => $this->maskAuthorName($name),
                'title'       => $post['title'] ?? null,
                'category'    => $post['category'] ?? null,
                'content'     => $post['content'] ?? null,
                'is_secret'   => $post['is_secret'] ?? false,
                'is_owner'    => $isOwner,
                'is_answered' => $pivot->is_answered ?? false,
                'answered_at' => $pivot->answered_at?->toIso8601String(),
                'created_at'  => $pivot->created_at?->toIso8601String(),
                'reply'       => $post['reply'] ?? null,
                'attachments' => $post['attachments'] ?? [],
            ];
        })->values()->all();

        $lastPage = (int) ceil($total / $perPage);

        return [
            'items' => $items,
            'meta'  => [
                'board_settings'    => $boardSettings,
                'inquiry_available' => (bool) $boardSlug,
                'current_page'      => $page,
                'per_page'          => $perPage,
                'total'             => $total,
                'last_page'         => max(1, $lastPage),
                'abilities'         => [
                    'can_update' => PermissionHelper::check('sirsoft-ecommerce.inquiries.update', Auth::user()),
                    'can_delete' => PermissionHelper::check('sirsoft-ecommerce.inquiries.delete', Auth::user()),
                ],
            ],
        ];
    }

    /**
     * 기본 게시판 설정값 반환
     *
     * @return array
     */
    private function defaultBoardSettings(): array
    {
        return [
            'secret_mode'        => 'disabled',
            'categories'         => [],
            'use_file_upload'    => false,
            'max_file_count'     => 5,
            'max_file_size'      => 10485760,
            'allowed_extensions' => [],
            'min_title_length'   => 2,
            'max_title_length'   => 200,
            'min_content_length' => 10,
            'max_content_length' => 10000,
        ];
    }

    /**
     * 상품 문의 작성
     *
     * 게시판 훅으로 Post 생성 → 피벗 생성 (DB::transaction 보장)
     *
     * @param  int  $productId  상품 ID
     * @param  array  $data  문의 데이터 (content, is_secret, author_name, secret_password)
     * @return ProductInquiry
     *
     * @throws \RuntimeException 게시판 미설치 또는 설정 오류 시
     */
    public function createInquiry(int $productId, array $data): ProductInquiry
    {
        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_not_configured')
            );
        }

        $product = $this->productRepository->find($productId);

        if (! $product) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                __('sirsoft-ecommerce::messages.products.not_found')
            );
        }

        // 로그인 사용자의 user_id 주입 (board_posts.user_id)
        if (Auth::check() && empty($data['user_id'])) {
            $data['user_id'] = Auth::id();
        }

        $inquiry = DB::transaction(function () use ($productId, $product, $boardSlug, $data) {
            // 게시판 훅으로 Post 생성
            $postResult = HookManager::applyFilters(
                'sirsoft-ecommerce.inquiry.create',
                null,
                $boardSlug,
                $data
            );

            if (! $postResult || empty($postResult['post_id'])) {
                throw new \RuntimeException(
                    __('sirsoft-ecommerce::messages.inquiries.board_unavailable')
                );
            }

            // 상품명 스냅샷 (다국어) — name은 array cast
            $nameRaw = $product->name ?? [];
            $nameSnapshot = is_array($nameRaw) ? $nameRaw : [];

            // 피벗 생성
            $inquiry = $this->repository->create([
                'product_id' => $productId,
                'inquirable_type' => $postResult['inquirable_type'],
                'inquirable_id' => $postResult['post_id'],
                'user_id' => Auth::id(),
                'is_answered' => false,
                'product_name_snapshot' => $nameSnapshot,
            ]);

            Log::info('상품 문의 작성 완료', [
                'inquiry_id' => $inquiry->id,
                'product_id' => $productId,
                'user_id' => Auth::id(),
                'post_id' => $postResult['post_id'],
            ]);

            return $inquiry;
        });

        // Action 훅은 트랜잭션 외부에서 실행 (롤백 시 부작용 방지)
        HookManager::doAction('sirsoft-ecommerce.product_inquiry.after_create', $inquiry);

        return $inquiry;
    }

    /**
     * 마이페이지 문의 목록 조회
     *
     * 피벗 페이지네이션 조회 → 게시판 훅으로 Post 데이터 조합 반환
     * 비밀글 여부(is_secret), 문의 내용(content), 답변(reply) 포함
     *
     * @param  int  $userId  사용자 ID
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return array{items: array, meta: array}
     */
    public function getUserInquiries(int $userId, array $filters = [], int $perPage = 10): array
    {
        $paginator = $this->repository->findByUserId($userId, $filters, $perPage);
        $boardSlug = $this->getInquiryBoardSlug();

        // 게시판 설정 조회 (비밀글/유형 등 모달 표시에 필요)
        $boardSettings = $boardSlug
            ? HookManager::applyFilters(
                'sirsoft-ecommerce.inquiry.get_settings',
                $this->defaultBoardSettings(),
                $boardSlug
            )
            : $this->defaultBoardSettings();

        // 피벗의 inquirable_id 목록으로 Post 데이터 일괄 조회
        $ids = collect($paginator->items())->pluck('inquirable_id')->filter()->values()->all();
        $posts = [];

        if (! empty($ids) && $boardSlug) {
            $rawPosts = HookManager::applyFilters(
                'sirsoft-ecommerce.inquiry.get_by_ids',
                [],
                ['ids' => $ids, 'slug' => $boardSlug]
            );
            foreach ($rawPosts as $post) {
                $postId = $post['id'] ?? null;
                if ($postId) {
                    $posts[$postId] = $post;
                }
            }
        }

        // 피벗 + Post 조합 → 명시적 배열로 직렬화
        $items = collect($paginator->items())->map(function ($inquiry) use ($posts) {
            $post = $posts[$inquiry->inquirable_id] ?? null;

            return [
                'id'          => $inquiry->id,
                'product_id'  => $inquiry->product_id,
                'product'     => $inquiry->product ? [
                    'id'            => $inquiry->product->id,
                    'name'          => $inquiry->product->getLocalizedName(),
                    'thumbnail_url' => $inquiry->product->getThumbnailUrl(),
                    'url'           => '/' . ltrim($this->settingsService->getSetting('basic_info.route_path', 'shop'), '/') . '/products/' . $inquiry->product->id,
                ] : null,
                'product_name'  => $this->localizeProductName($inquiry->product_name_snapshot),
                'is_answered'   => $inquiry->is_answered,
                'answered_at'   => $inquiry->answered_at?->toIso8601String(),
                'created_at'    => $inquiry->created_at?->toIso8601String(),
                'updated_at'    => $inquiry->updated_at?->toIso8601String(),
                // 게시판 Post 데이터 (게시판 미연동 시 null)
                'title'         => $post['title'] ?? null,
                'category'      => $post['category'] ?? null,
                'content'       => $post['content'] ?? null,
                'is_secret'     => $post['is_secret'] ?? false,
                'reply'         => $post['reply'] ?? null,
                'attachments'   => $post['attachments'] ?? [],
            ];
        })->values()->all();

        return [
            'items' => $items,
            'meta'  => [
                'current_page'     => $paginator->currentPage(),
                'per_page'         => $paginator->perPage(),
                'total'            => $paginator->total(),
                'last_page'        => $paginator->lastPage(),
                'from'             => $paginator->firstItem(),
                'to'               => $paginator->lastItem(),
                'inquiry_available' => (bool) $boardSlug,
                'abilities'        => [
                    'can_update' => PermissionHelper::check('sirsoft-ecommerce.inquiries.update', Auth::user()),
                    'can_delete' => PermissionHelper::check('sirsoft-ecommerce.inquiries.delete', Auth::user()),
                ],
                'board_settings' => $boardSettings,
            ],
        ];
    }

    /**
     * 문의 수정 (사용자)
     *
     * 게시판 훅으로 Post 업데이트 → 피벗은 변경 없음
     *
     * @param  int  $inquiryId  문의 ID
     * @param  array  $data  수정 데이터 (title, content, is_secret, category, attachment_ids)
     * @return void
     *
     * @throws \RuntimeException 문의 없거나 게시판 훅 실패 시
     */
    public function updateInquiry(int $inquiryId, array $data): void
    {
        $inquiry = $this->repository->findById($inquiryId);

        if (! $inquiry) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.not_found')
            );
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_not_configured')
            );
        }

        HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.update',
            null,
            $boardSlug,
            $inquiry->inquirable_id,
            $data
        );

        Log::info('상품 문의 수정 완료', [
            'inquiry_id' => $inquiryId,
            'user_id'    => Auth::id(),
        ]);
    }

    /**
     * 문의 삭제 (사용자/관리자)
     *
     * ① 게시판 훅으로 Post 삭제 (트랜잭션 외부 — after_delete Action 훅이 내부에서 발행되므로)
     * ② 피벗 삭제
     *
     * Post 삭제 실패 시 예외가 던져지므로 피벗 삭제는 실행되지 않습니다.
     *
     * @param  int  $inquiryId  문의 ID
     * @return void
     *
     * @throws \RuntimeException 문의 없거나 게시판 훅 실패 시
     */
    public function deleteInquiry(int $inquiryId): void
    {
        $inquiry = $this->repository->findById($inquiryId);

        if (! $inquiry) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.not_found')
            );
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_not_configured')
            );
        }

        // ① Post 삭제 — after_delete Action 훅 포함, 트랜잭션 외부에서 실행
        HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.delete',
            null,
            $boardSlug,
            $inquiry->inquirable_id
        );

        // ② 피벗 삭제
        $this->repository->deleteById($inquiryId);

        Log::info('상품 문의 삭제 완료', [
            'inquiry_id' => $inquiryId,
            'user_id'    => Auth::id(),
        ]);
    }

    /**
     * 답변 수정 (관리자/권한 보유자)
     *
     * 게시판 훅으로 Reply Post 업데이트
     *
     * @param  int  $inquiryId  문의 ID
     * @param  array  $data  수정 데이터 (content)
     * @return void
     *
     * @throws \RuntimeException 문의/답변 없거나 게시판 훅 실패 시
     */
    public function updateReply(int $inquiryId, array $data): void
    {
        $inquiry = $this->repository->findById($inquiryId);

        if (! $inquiry) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.not_found')
            );
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_not_configured')
            );
        }

        HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.update_reply',
            null,
            $boardSlug,
            $inquiry->inquirable_id,
            $data
        );

        Log::info('상품 문의 답변 수정 완료', [
            'inquiry_id' => $inquiryId,
        ]);
    }

    /**
     * 답변 삭제 (관리자/권한 보유자)
     *
     * ① 게시판 훅으로 Reply Post 삭제 (트랜잭션 외부 — after_delete Action 훅이 내부에서 발행되므로)
     * ② 피벗 is_answered=false 업데이트
     *
     * Reply Post 삭제 실패 시 예외가 던져지므로 피벗 업데이트는 실행되지 않습니다.
     *
     * @param  int  $inquiryId  문의 ID
     * @return void
     *
     * @throws \RuntimeException 문의 없거나 게시판 훅 실패 시
     */
    public function deleteReply(int $inquiryId): void
    {
        $inquiry = $this->repository->findById($inquiryId);

        if (! $inquiry) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.not_found')
            );
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.board_not_configured')
            );
        }

        // ① Reply Post 삭제 — after_delete Action 훅 포함, 트랜잭션 외부에서 실행
        HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.delete_reply',
            null,
            $boardSlug,
            $inquiry->inquirable_id
        );

        // ② 피벗 is_answered=false 업데이트
        $this->repository->unmarkAnswered($inquiry);

        Log::info('상품 문의 답변 삭제 완료', [
            'inquiry_id' => $inquiryId,
        ]);
    }

    /**
     * ID로 문의 조회
     *
     * @param  int  $inquiryId  문의 ID
     * @return ProductInquiry|null
     */
    public function findById(int $inquiryId): ?ProductInquiry
    {
        return $this->repository->findById($inquiryId);
    }

    /**
     * 작성자 이름 마스킹 처리
     *
     * 2자: 첫 글자 + * (예: 김동 → 김*)
     * 3자 이상: 첫 글자 + 중간 마스킹 + 마지막 글자 (예: 홍길동 → 홍*동, 김민준호 → 김**호)
     *
     * @param  string|null  $name  원본 이름 (호출 전 user_id 기반 조회 완료된 값)
     * @return string|null
     */
    private function maskAuthorName(?string $name): ?string
    {
        if (empty($name)) {
            return $name;
        }

        $chars = mb_str_split($name);
        $len = count($chars);

        if ($len === 1) {
            return $name;
        }

        if ($len === 2) {
            return $chars[0].'*';
        }

        // 3자 이상: 첫 글자 + 중간 전체 마스킹 + 마지막 글자
        return $chars[0].str_repeat('*', $len - 2).$chars[$len - 1];
    }

    /**
     * 상품명 스냅샷에서 현재 로케일에 맞는 상품명 반환
     *
     * @param  array|null  $snapshot  다국어 상품명 스냅샷
     * @return string
     */
    private function localizeProductName(?array $snapshot): string
    {
        if (empty($snapshot)) {
            return '';
        }

        $locale = app()->getLocale();

        return $snapshot[$locale] ?? $snapshot['ko'] ?? array_values($snapshot)[0] ?? '';
    }

    /**
     * 관리자 답변 작성
     *
     * 게시판 훅으로 Reply Post 생성 → 피벗 is_answered 업데이트 (DB::transaction 보장)
     *
     * @param  int  $inquiryId  문의 ID
     * @param  array  $data  답변 데이터 (content)
     * @return ProductInquiry
     *
     * @throws \RuntimeException 문의 없거나 게시판 훅 실패 시
     */
    public function createReply(int $inquiryId, array $data): ProductInquiry
    {
        $inquiry = $this->repository->findById($inquiryId);

        if (! $inquiry) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::messages.inquiries.not_found')
            );
        }

        $boardSlug = $this->getInquiryBoardSlug();

        $updated = DB::transaction(function () use ($inquiry, $boardSlug, $data) {
            // 게시판 훅으로 Reply Post 생성 (title은 리스너에서 Re: 부모글제목 형식으로 설정)
            $replyData = array_merge($data, [
                'parent_id' => $inquiry->inquirable_id,
            ]);
            $postResult = HookManager::applyFilters(
                'sirsoft-ecommerce.inquiry.create',
                null,
                $boardSlug,
                $replyData
            );

            if (! $postResult || empty($postResult['post_id'])) {
                throw new \RuntimeException(
                    __('sirsoft-ecommerce::messages.inquiries.reply_failed')
                );
            }

            // 피벗 is_answered 업데이트
            $updated = $this->repository->markAsAnswered($inquiry);

            Log::info('상품 문의 답변 완료', [
                'inquiry_id' => $inquiry->id,
                'reply_post_id' => $postResult['post_id'],
            ]);

            return $updated;
        });

        // Action 훅은 트랜잭션 외부에서 실행 (롤백 시 부작용 방지)
        HookManager::doAction('sirsoft-ecommerce.product_inquiry.after_reply', $updated);

        return $updated;
    }
}
