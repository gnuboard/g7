<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\ProductInquiryOperationException;
use Modules\Sirsoft\Ecommerce\Models\ProductInquiry;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductInquiryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

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
     * @param  UserRepositoryInterface  $userRepository  사용자 리포지토리 (작성자 이름 배치 조회)
     */
    public function __construct(
        protected ProductInquiryRepositoryInterface $repository,
        protected ProductRepositoryInterface $productRepository,
        protected EcommerceSettingsService $settingsService,
        protected UserRepositoryInterface $userRepository
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
                'meta' => [
                    'board_settings' => $this->defaultBoardSettings(),
                    'inquiry_available' => false,
                    'total' => 0,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'last_page' => 1,
                ],
            ];
        }

        // 게시판 설정 조회
        $boardSettings = HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.get_settings',
            $this->defaultBoardSettings(),
            $boardSlug
        );

        $currentUserId = Auth::id();

        // 비밀글 원문 마스킹은 게시판 훅(getByIds)이 요청자 신원 기준으로 서버측에서
        // 이미 수행한다(KVE-2026-1914, SecretContentGate SSoT). 아래 exclude_secret 은
        // 보안 판정이 아니라 단순 "비밀글 행 숨김" 표시 필터일 뿐이며, 노출 여부는
        // 클라이언트 파라미터와 무관하게 서버가 결정한다.
        if ($excludeSecret) {
            // 비밀글 판정(is_secret)은 게시판 모듈의 게시글 데이터에만 있어 SQL 로 거를 수
            // 없다 — 이 경로만 전량 조회 후 PHP 필터가 구조적으로 필요하다. 기본 화면
            // 경로(exclude_secret=false)는 아래 else 의 쿼리 레벨 페이지네이션을 쓴다.
            $pivots = $this->repository->findByProductId($productId);
            $posts = $this->fetchPostsByIds($pivots->pluck('inquirable_id')->all(), $boardSlug);

            // 비밀글 제외 필터 적용 (Post의 is_secret 기준)
            $pivots = $pivots->filter(function ($pivot) use ($posts) {
                $post = $posts[$pivot->inquirable_id] ?? null;

                return empty($post['is_secret']);
            })->values();

            $total = $pivots->count();
            $pagePivots = $pivots->forPage($page, $perPage);
            $lastPage = (int) ceil($total / $perPage);
        } else {
            // 화면 목록은 쿼리 레벨 페이지네이션 — 전량 적재 후 PHP 잘라내기(#102 동형)를
            // 하지 않는다. 게시글 데이터도 이 페이지 분량만 일괄 조회한다.
            $paginator = $this->repository->paginateByProductId($productId, $perPage, $page);
            $pagePivots = collect($paginator->items());
            $posts = $this->fetchPostsByIds($pagePivots->pluck('inquirable_id')->all(), $boardSlug);

            $total = $paginator->total();
            $lastPage = $paginator->lastPage();
        }

        // user_id 일괄 조회 (N+1 방지)
        $userIds = $pagePivots->map(fn ($pivot) => $posts[$pivot->inquirable_id]['user_id'] ?? null)
            ->filter()->unique()->values()->all();
        $userMap = $this->userRepository->getNamesByIds($userIds);

        $items = $pagePivots->map(function ($pivot) use ($posts, $currentUserId, $userMap) {
            $post = $posts[$pivot->inquirable_id] ?? null;
            $isOwner = $currentUserId !== null && $pivot->user_id === $currentUserId;

            $userId = $post['user_id'] ?? null;
            $name = $userId ? ($userMap[$userId] ?? $post['author_name'] ?? null) : ($post['author_name'] ?? null);

            // 비밀글 이중 방어(KVE-2026-1914): 게시판 훅(getByIds)이 이미 요청자 신원으로
            // 원문을 마스킹하지만, 훅이 신원 판정만 하고 특정 필드 null 처리를 누락하는 회귀에
            // 대비해 훅이 실어 보낸 권위 플래그(can_view_secret)로 payload 를 재확정한다.
            // 자기 권한을 재계산하지 않으므로(플래그만 신뢰) 게이트 강도가 훅과 갈리지 않는다.
            //
            // fail-closed: 비밀글인데 권위 플래그가 없으면(훅 미치환·타 확장 치환으로 누락)
            // 열람 가능으로 가정하지 않고 마스킹한다. 플래그 부재 = 권위 미상 = 안전 측(감춤).
            // 비밀글이 아니면($isSecret=false) 어느 경우에도 마스킹되지 않으므로 영향 없다.
            $isSecret = $post['is_secret'] ?? false;
            $secretMasked = $isSecret && $post !== null && ($post['can_view_secret'] ?? false) === false;

            return [
                'id' => $pivot->id,
                'post_id' => $pivot->inquirable_id,
                'user_id' => $userId,
                'author_name' => $this->maskAuthorName($name),
                // 비밀글이면 title 도 fail-closed 로 재마스킹한다(KVE-2026-1914 A2b).
                // 훅이 신원 판정만 하고 title 치환을 누락하는 회귀에 대비 — 플레이스홀더 텍스트
                // 자체는 게시판 lang 키(post.secret_post_title)가 SSoT 로, 훅의 마스킹 값과 동일하다.
                'title' => $secretMasked
                    ? __('sirsoft-board::messages.post.secret_post_title')
                    : ($post['title'] ?? null),
                'category' => $post['category'] ?? null,
                'content' => $secretMasked ? null : ($post['content'] ?? null),
                'is_secret' => $isSecret,
                'is_owner' => $isOwner,
                'is_answered' => $pivot->is_answered ?? false,
                'answered_at' => $pivot->answered_at?->toIso8601String(),
                'created_at' => $pivot->created_at?->toIso8601String(),
                'reply' => $secretMasked ? null : ($post['reply'] ?? null),
                'attachments' => $secretMasked ? [] : ($post['attachments'] ?? []),
            ];
        })->values()->all();

        return [
            'items' => $items,
            'meta' => [
                'board_settings' => $boardSettings,
                'inquiry_available' => (bool) $boardSlug,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, $lastPage),
                'abilities' => [
                    'can_update' => PermissionHelper::check('sirsoft-ecommerce.inquiries.update', Auth::user()),
                    'can_delete' => PermissionHelper::check('sirsoft-ecommerce.inquiries.delete', Auth::user()),
                ],
            ],
        ];
    }

    /**
     * inquirable_id 목록으로 게시글 데이터를 일괄 조회합니다 (N+1 방지).
     *
     * @param  array<int, int>  $ids  게시글 ID 목록
     * @param  string  $boardSlug  문의 게시판 슬러그
     * @return array<int, array<string, mixed>> 게시글 ID => 게시글 데이터
     */
    private function fetchPostsByIds(array $ids, string $boardSlug): array
    {
        if (empty($ids)) {
            return [];
        }

        $rawPosts = HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.get_by_ids',
            [],
            ['ids' => $ids, 'slug' => $boardSlug]
        );

        $posts = [];
        foreach ($rawPosts as $post) {
            $postId = $post['id'] ?? null;
            if ($postId) {
                $posts[$postId] = $post;
            }
        }

        return $posts;
    }

    /**
     * 기본 게시판 설정값 반환
     *
     * @return array
     */
    private function defaultBoardSettings(): array
    {
        return [
            'secret_mode' => 'disabled',
            'categories' => [],
            'use_file_upload' => false,
            'max_file_count' => 5,
            'max_file_size' => 10485760,
            'allowed_extensions' => [],
            'min_title_length' => 2,
            'max_title_length' => 200,
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
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
        }

        $product = $this->productRepository->find($productId);

        if (! $product) {
            throw new ModelNotFoundException(
                __('sirsoft-ecommerce::messages.products.not_found')
            );
        }

        // 로그인 사용자의 user_id 주입 (board_posts.user_id)
        if (Auth::check() && empty($data['user_id'])) {
            $data['user_id'] = Auth::id();
        }

        // 클라이언트 IP 를 요청 경계(Service)에서 캡처해 게시판 훅 payload 로 전달한다.
        // 게시판 Listener 가 request() 를 직접 참조하지 않도록 소유 서비스가 주입한다.
        if (empty($data['ip_address'])) {
            $data['ip_address'] = request()->ip() ?? '0.0.0.0';
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
                throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_unavailable');
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
                'id' => $inquiry->id,
                'product_id' => $inquiry->product_id,
                'product' => $inquiry->product ? [
                    'id' => $inquiry->product->id,
                    'product_code' => $inquiry->product->product_code,
                    'name' => $inquiry->product->getLocalizedName(),
                    'thumbnail_url' => $inquiry->product->getThumbnailUrl(),
                    // 주소 없이 운영하는 상점(no_route)까지 반영해야 실제 상품 화면을 가리킨다 (공개 #85)
                    'url' => ShopPathResolver::path('products/'.$inquiry->product->product_code),
                ] : null,
                'product_name' => $this->localizeProductName($inquiry->product_name_snapshot),
                'is_answered' => $inquiry->is_answered,
                'answered_at' => $inquiry->answered_at?->toIso8601String(),
                'created_at' => $inquiry->created_at?->toIso8601String(),
                'updated_at' => $inquiry->updated_at?->toIso8601String(),
                // 게시판 Post 데이터 (게시판 미연동 시 null)
                'title' => $post['title'] ?? null,
                'category' => $post['category'] ?? null,
                'content' => $post['content'] ?? null,
                'is_secret' => $post['is_secret'] ?? false,
                'reply' => $post['reply'] ?? null,
                'attachments' => $post['attachments'] ?? [],
            ];
        })->values()->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'inquiry_available' => (bool) $boardSlug,
                'abilities' => [
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
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.not_found');
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
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
            'user_id' => Auth::id(),
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
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.not_found');
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
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
            'user_id' => Auth::id(),
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
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.not_found');
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
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
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.not_found');
        }

        $boardSlug = $this->getInquiryBoardSlug();

        if (! $boardSlug) {
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
        }

        // ① Reply Post 삭제 — after_delete Action 훅 포함, 트랜잭션 외부에서 실행
        HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.delete_reply',
            null,
            $boardSlug,
            $inquiry->inquirable_id
        );

        // ② 잔여 답변이 없을 때만 피벗 is_answered=false 업데이트.
        // 기설치본에는 과거 결함(#106)으로 답변이 여러 건 쌓인 문의가 있을 수 있다 —
        // 첫 답변만 지웠는데 무조건 해제하면 "답변이 남았는데 미답변" 표기가 된다.
        // 기본값 null = 판정 불가: 공급자(board 리스너) 부재 시 0 으로 접혀
        // 답변완료가 오해제되는 fail-open 을 막는다.
        $remaining = HookManager::applyFilters(
            'sirsoft-ecommerce.inquiry.count_replies',
            null,
            $inquiry->inquirable_id
        );

        if (is_numeric($remaining) && (int) $remaining === 0) {
            $this->repository->unmarkAnswered($inquiry);
        }

        Log::info('상품 문의 답변 삭제 완료', [
            'inquiry_id' => $inquiryId,
            'remaining_replies' => $remaining,
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
     * 상품 삭제 시 그 상품의 문의 스레드(질문+답변 Post)와 피벗을 정리합니다.
     *
     * 상품이 forceDelete 되면 피벗은 FK 캐스케이드로 하드 소멸하지만, 게시판의
     * 질문·답변 Post 는 published 로 잔존했다(#107 확대판). 애플리케이션이 먼저
     * 훅으로 Post 를 소프트 삭제(cascade_replies 로 답변 포함)한 뒤 피벗을
     * forceDelete 한다 — FK 캐스케이드는 백스톱으로만 유지.
     *
     * 문의 게시판이 미설정이면 Post 정리는 건너뛰고 피벗만 정리한다(안전 열화).
     * 개별 훅 실패는 warning 후 계속 진행한다 — 한 건의 실패가 상품 삭제 전체를
     * 막으면 안 되고, 남은 고아는 업그레이드 스텝 백필이 재정리한다.
     *
     * @param  int  $productId  상품 ID
     * @return int 정리된 피벗 수
     */
    public function deleteInquiriesForProduct(int $productId): int
    {
        $boardSlug = $this->getInquiryBoardSlug();

        if ($boardSlug) {
            // withTrashed 포함 — 소프트 삭제된 문의의 Post 잔존물도 함께 정리
            $inquiries = $this->repository->findByProductIdWithTrashed($productId);

            foreach ($inquiries as $inquiry) {
                try {
                    HookManager::applyFilters(
                        'sirsoft-ecommerce.inquiry.delete',
                        null,
                        $boardSlug,
                        $inquiry->inquirable_id
                    );
                } catch (\Exception $e) {
                    Log::warning('상품 삭제 시 문의 Post 정리 실패 — 계속 진행', [
                        'product_id' => $productId,
                        'inquiry_id' => $inquiry->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            Log::info('문의 게시판 미설정 — 상품 삭제 시 문의 Post 정리 생략', [
                'product_id' => $productId,
            ]);
        }

        return $this->repository->forceDeleteByProductId($productId);
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

        return $snapshot[$locale] ?? $snapshot[config('app.fallback_locale', 'ko')] ?? array_values($snapshot)[0] ?? '';
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
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.not_found');
        }

        $boardSlug = $this->getInquiryBoardSlug();

        // update/delete 경로와 동일한 미설정 가드 — 없으면 훅 무응답이 reply_failed 로 위장된다
        if (! $boardSlug) {
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.board_not_configured');
        }

        // 1차 방어(피벗 플래그): 이미 답변된 문의에는 재등록 거부 — 단일 답변 정책.
        // UI 는 `!item.reply` 게이팅으로 이미 차단하므로, 이 가드는 API 직접 호출/동시
        // 클릭 경로를 막는다. 게시판 실데이터 기준 2차 방어는 리스너(createAndReturn)가 담당.
        if ($inquiry->is_answered) {
            throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.reply_already_exists');
        }

        // 클라이언트 IP 를 요청 경계(Service)에서 캡처해 게시판 훅 payload 로 전달한다.
        if (empty($data['ip_address'])) {
            $data['ip_address'] = request()->ip() ?? '0.0.0.0';
        }

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

            // 2차 방어(리스너, 게시판 실데이터)가 중복을 감지하면 중복 마커를 돌려준다.
            // 경합 경로(피벗 is_answered 가 아직 false)에서도 사유를 보존해 422 로 안내
            // — null 과 합치면 "답변 등록에 실패했습니다" 로 위장된다 (운영 실측 제보).
            if (is_array($postResult) && ! empty($postResult['duplicate'])) {
                throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.reply_already_exists');
            }

            if (! $postResult || empty($postResult['post_id'])) {
                throw new ProductInquiryOperationException('sirsoft-ecommerce::messages.inquiries.reply_failed');
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
