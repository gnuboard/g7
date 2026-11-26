<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Helpers\StorageTransaction;
use App\Extension\HookManager;
use App\Helpers\TimezoneHelper;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewNotWritableException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\PurifiesHtml;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesRowStorage;
use Modules\Sirsoft\Ecommerce\Support\ReviewWritePolicy;
use Modules\Sirsoft\Ecommerce\Traits\ReappliesPermissionScope;

/**
 * 상품 리뷰 서비스
 *
 * 상품 리뷰 생성, 수정, 삭제, 답변 등의 비즈니스 로직을 처리합니다.
 */
class ProductReviewService
{
    use PurifiesHtml;
    use ReappliesPermissionScope;
    use ResolvesRowStorage;

    /** 회원 없이 등록하는 후기의 작성자 표시 이름 최대 길이 (컬럼 VARCHAR(50)) */
    private const IMPORTED_AUTHOR_NAME_MAX = 50;

    /** @var array<int, string> 후기 본문·답변의 콘텐츠 모드 */
    private const IMPORTED_CONTENT_MODES = ['text', 'html'];

    /**
     * ProductReviewService 생성자
     *
     * @param  ProductReviewRepositoryInterface  $repository  리뷰 리포지토리
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 리포지토리
     * @param  StorageInterface  $storage  모듈 스토리지 드라이버
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  ProductReviewImageRepositoryInterface  $imageRepository  리뷰 이미지 리포지토리
     * @param  ProductRepositoryInterface  $productRepository  상품 리포지토리 (이관 후기의 상품 존재 확인)
     */
    public function __construct(
        protected ProductReviewRepositoryInterface $repository,
        protected OrderOptionRepositoryInterface $orderOptionRepository,
        protected StorageInterface $storage,
        protected EcommerceSettingsService $settingsService,
        protected ProductReviewImageRepositoryInterface $imageRepository,
        protected ProductRepositoryInterface $productRepository
    ) {}

    /**
     * 관리자용 리뷰 목록 조회 (통계 포함)
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 개수
     * @return LengthAwarePaginator 페이지네이션된 리뷰 목록
     */
    public function getAdminList(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 상품 공개 리뷰 목록 조회 (별점 통계 포함)
     *
     * @param  int  $productId  상품 ID
     * @param  array  $filters  필터 조건 (sort, photo_only)
     * @param  int  $perPage  페이지당 개수
     * @return array{reviews: LengthAwarePaginator, rating_stats: array}
     */
    public function getProductReviews(int $productId, array $filters = [], int $perPage = 10): array
    {
        return [
            'reviews' => $this->repository->findByProduct($productId, $filters, $perPage),
            'rating_stats' => $this->repository->getRatingStats($productId),
            'option_filters' => $this->repository->getOptionFilters($productId),
            'total_count' => $this->repository->getTotalCount($productId),
        ];
    }

    /**
     * 전체 공개 후기 목록(리뷰 모아보기)을 조회합니다.
     *
     * @param  array  $filters  필터 조건 (sort, photo_only, rating, per_page)
     * @return LengthAwarePaginator 페이지네이션된 후기 목록
     */
    public function getPublicFeed(array $filters = []): LengthAwarePaginator
    {
        return $this->repository->paginatePublicFeed($filters);
    }

    /**
     * 리뷰 작성 가능 여부 확인
     *
     * @param  int  $userId  사용자 ID
     * @param  int  $orderOptionId  주문 옵션 ID
     * @return array{can_write: bool, reason: string|null}
     */
    public function canWrite(int $userId, int $orderOptionId): array
    {
        try {
            $orderOption = $this->orderOptionRepository->findOrFail($orderOptionId);
            $orderOption->load('order');
        } catch (ModelNotFoundException $e) {
            return ['can_write' => false, 'reason' => 'order_option_not_found'];
        }

        // 본인 주문 여부 확인
        if ($orderOption->order->user_id !== $userId) {
            return ['can_write' => false, 'reason' => 'not_own_order'];
        }

        // 구매확정 여부 확인
        if ($orderOption->option_status !== OrderStatusEnum::CONFIRMED) {
            return ['can_write' => false, 'reason' => 'not_confirmed'];
        }

        // 작성 기간 확인 (판정 규칙은 ReviewWritePolicy 단일 SSoT)
        if (ReviewWritePolicy::isDeadlinePassed($orderOption->confirmed_at)) {
            return ['can_write' => false, 'reason' => 'deadline_passed'];
        }

        // 중복 작성 확인
        $existing = $this->repository->findByOrderOptionId($orderOptionId);
        if ($existing) {
            return ['can_write' => false, 'reason' => 'already_written'];
        }

        return ['can_write' => true, 'reason' => null];
    }

    /**
     * 리뷰 생성
     *
     * @param  int  $userId  작성자 ID
     * @param  array  $data  리뷰 데이터
     * @return ProductReview 생성된 리뷰 모델
     *
     * @throws ReviewNotWritableException 작성 자격이 없거나, 같은 주문옵션의 활성 후기가 먼저 저장된 경우(already_written)
     */
    public function createReview(int $userId, array $data): ProductReview
    {
        // canWrite 사전 검증
        $eligibility = $this->canWrite($userId, $data['order_option_id']);
        if (! $eligibility['can_write']) {
            throw new ReviewNotWritableException((string) $eligibility['reason']);
        }

        // option_snapshot: 주문 옵션에서 복사
        $orderOption = $this->orderOptionRepository->findOrFail($data['order_option_id']);
        $optionSnapshot = $orderOption->option_snapshot ?? [];

        // product_id 는 클라이언트 payload 가 아니라 확정된 주문 옵션에서 도출한다(SSoT).
        // canWrite 가 옵션 소유권/확정만 검증하므로, payload 의 product_id 를 그대로 신뢰하면
        // 소유하지 않은 상품에 리뷰를 붙일 수 있다. 도출값이 유일한 진실이므로 payload 값은
        // 옵션과 일치하든 아니든 무시한다.
        $resolvedProductId = (int) $orderOption->product_id;

        HookManager::doAction('sirsoft-ecommerce.product-review.before_create', $data);

        try {
            $review = $this->repository->create([
                'product_id' => $resolvedProductId,
                'order_option_id' => $data['order_option_id'],
                'user_id' => $userId,
                'rating' => $data['rating'],
                'content' => $data['content'],
                'content_mode' => $data['content_mode'] ?? 'text',
                'option_snapshot' => $optionSnapshot,
                'status' => ReviewStatus::VISIBLE->value,
            ]);
        } catch (UniqueConstraintViolationException) {
            // canWrite 는 조회로만 중복을 확인하므로, 같은 주문옵션의 작성이 동시에 겹치거나 작성 훅 안에서
            // 다시 작성되면 둘 다 검사를 통과한다. 주문옵션당 활성 후기 1건 유니크(active_order_option_key)가
            // 뒤에 온 쪽을 막으므로, 사전 검사와 같은 사유(already_written)로 돌려준다.
            throw new ReviewNotWritableException('already_written');
        }

        Log::info('상품 리뷰 작성 완료', [
            'review_id' => $review->id,
            'user_id' => $userId,
            'product_id' => $review->product_id,
        ]);

        HookManager::doAction('sirsoft-ecommerce.product-review.after_create', $review);

        return $review;
    }

    /**
     * 회원·주문 없이 후기를 등록합니다 (다른 쇼핑몰에서 이관한 후기 등).
     *
     * 회원 후기 작성(`createReview`)과 달리 작성 자격(구매확정·작성 기간·중복)을 묻지 않고, 회원 후기 작성 훅
     * (`before_create`/`after_create` — 관리자 활동 로그가 구독)을 발행하지 않는다. 작성자에게 가는 알림·적립도
     * 없다. 대신 `sirsoft-ecommerce.product-review.after_import` 를 생성·갱신마다 한 번 발행한다.
     *
     * 같은 상품·같은 작성자 이름·같은 작성일의 후기가 이미 있으면(지운 것 포함) 새로 만들지 않고 그 행을
     * 갱신해 돌려준다 — 재이관이 같은 후기를 두 번 넣지 않게 한다. 반환 모델의 `wasRecentlyCreated` 로
     * 새로 만들었는지(사진을 붙일지) 가를 수 있다. 갱신 때 관리자가 바꿨을 수 있는 값은 보존한다:
     * `status` 와 답변은 입력에 그 키가 있을 때만 덮어쓰고, 지운 후기는 되살리지 않는다(`trashed()` 가 참).
     *
     * 이 메서드는 HTTP 요청이 아니라 이관 도구가 부르는 프로그램 진입점이라, 저장 전 형식 확인을
     * FormRequest 대신 여기서 한다(필수값·범위만 — 어긋나면 저장하지 않고 예외).
     *
     * @param  int  $productId  상품 ID
     * @param  array{
     *     rating: int,
     *     content: string,
     *     content_mode?: string,
     *     author_name: string,
     *     created_at: string|\DateTimeInterface,
     *     status?: string,
     *     reply_content?: string|null,
     *     reply_content_mode?: string,
     *     replied_at?: string|\DateTimeInterface|null
     * }  $data  후기 데이터 — 작성일은 오프셋이 없으면 사이트 기본 시간대로 읽는다
     * @return ProductReview 생성·갱신된 후기
     *
     * @throws \InvalidArgumentException 필수값이 없거나 범위를 벗어난 경우
     * @throws ModelNotFoundException 상품이 없는 경우
     */
    public function createImported(int $productId, array $data): ProductReview
    {
        $product = $this->productRepository->find($productId);
        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        $attributes = $this->normalizeImportedReview($data);

        $review = DB::transaction(function () use ($productId, $attributes) {
            $existing = $this->repository->findImported(
                $productId,
                $attributes['author_name'],
                $attributes['created_at']
            );

            if ($existing === null) {
                return $this->repository->saveImported(new ProductReview, [
                    'product_id' => $productId,
                    'order_option_id' => null,
                    'user_id' => null,
                    'option_snapshot' => null,
                    'status' => $attributes['status'] ?? ReviewStatus::VISIBLE->value,
                    'updated_at' => $attributes['created_at'],
                    ...$attributes,
                ]);
            }

            return $this->repository->saveImported($existing, $attributes);
        });

        Log::info('상품 후기 이관 완료', [
            'review_id' => $review->id,
            'product_id' => $productId,
            'created' => $review->wasRecentlyCreated,
        ]);

        HookManager::doAction('sirsoft-ecommerce.product-review.after_import', $review, $review->wasRecentlyCreated);

        return $review;
    }

    /**
     * 이관 후기 입력을 저장할 컬럼 값으로 바꿉니다.
     *
     * `status` 와 답변 키는 입력에 있을 때만 결과에 넣는다 — 재이관 갱신이 관리자가 바꾼 값을 지우지 않게 한다.
     *
     * @param  array<string, mixed>  $data  이관 후기 입력
     * @return array<string, mixed> 저장할 컬럼 값
     *
     * @throws \InvalidArgumentException 필수값이 없거나 범위를 벗어난 경우
     */
    private function normalizeImportedReview(array $data): array
    {
        $rating = $data['rating'] ?? null;
        if (! is_numeric($rating) || (int) $rating != $rating || (int) $rating < 1 || (int) $rating > 5) {
            throw $this->invalidImportedField('rating');
        }

        $content = $data['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw $this->invalidImportedField('content');
        }

        $authorName = $data['author_name'] ?? null;
        if (! is_string($authorName) || trim($authorName) === '' || mb_strlen(trim($authorName)) > self::IMPORTED_AUTHOR_NAME_MAX) {
            throw $this->invalidImportedField('author_name');
        }

        $contentMode = $data['content_mode'] ?? 'text';
        if (! in_array($contentMode, self::IMPORTED_CONTENT_MODES, true)) {
            throw $this->invalidImportedField('content_mode');
        }

        $createdAt = $this->parseImportedDate($data['created_at'] ?? null, 'created_at');

        $attributes = [
            'author_name' => trim($authorName),
            'rating' => (int) $rating,
            'content' => $contentMode === 'html' ? $this->purifyHtml($content) : $content,
            'content_mode' => $contentMode,
            'created_at' => $createdAt,
        ];

        if (array_key_exists('status', $data)) {
            if (ReviewStatus::tryFrom((string) $data['status']) === null) {
                throw $this->invalidImportedField('status');
            }
            $attributes['status'] = (string) $data['status'];
        }

        $replyContent = $data['reply_content'] ?? null;
        if ($replyContent !== null && $replyContent !== '') {
            if (! is_string($replyContent)) {
                throw $this->invalidImportedField('reply_content');
            }

            $replyMode = $data['reply_content_mode'] ?? 'text';
            if (! in_array($replyMode, self::IMPORTED_CONTENT_MODES, true)) {
                throw $this->invalidImportedField('reply_content_mode');
            }

            $repliedAt = ($data['replied_at'] ?? null) !== null && $data['replied_at'] !== ''
                ? $this->parseImportedDate($data['replied_at'], 'replied_at')
                : $createdAt;

            $attributes += [
                'reply_content' => $replyMode === 'html' ? $this->purifyHtml($replyContent) : $replyContent,
                'reply_content_mode' => $replyMode,
                'reply_admin_id' => null,
                // 답변 필터(답변완료/미답변)는 답변일을 본다 — 답변이 있으면 답변일도 반드시 채운다
                'replied_at' => $repliedAt,
                'reply_updated_at' => null,
            ];
        }

        return $attributes;
    }

    /**
     * 이관 후기의 날짜 입력을 앱 시간대의 Carbon 으로 읽습니다.
     *
     * 오프셋이 없는 문자열은 사이트 기본 시간대의 벽시계로 읽는다 — 원 쇼핑몰 화면에 보이던 날짜를
     * 그대로 넘기면 G7 화면에도 같은 날짜로 보인다.
     *
     * @param  mixed  $value  날짜 문자열 또는 DateTimeInterface
     * @param  string  $field  입력 키 (오류 메시지용)
     * @return Carbon 앱 시간대의 날짜
     *
     * @throws \InvalidArgumentException 날짜로 읽을 수 없는 경우
     */
    private function parseImportedDate(mixed $value, string $field): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone(config('app.timezone'));
        }

        if (! is_string($value) || trim($value) === '') {
            throw $this->invalidImportedField($field);
        }

        try {
            return Carbon::parse(trim($value), TimezoneHelper::getSiteTimezone())
                ->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            throw $this->invalidImportedField($field);
        }
    }

    /**
     * 이관 후기 입력 오류 예외를 만듭니다.
     *
     * @param  string  $field  잘못된 입력 키
     * @return \InvalidArgumentException 예외
     */
    private function invalidImportedField(string $field): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            __('sirsoft-ecommerce::exceptions.imported_review_invalid_field', ['field' => $field])
        );
    }

    /**
     * 리뷰 상태 변경
     *
     * @param  ProductReview  $review  리뷰 모델
     * @param  string  $status  변경할 상태값
     * @return ProductReview 변경된 리뷰 모델
     */
    public function updateStatus(ProductReview $review, string $status): ProductReview
    {
        $this->assertWithinScope($review, 'sirsoft-ecommerce.reviews.update');

        return $this->repository->update($review, ['status' => $status]);
    }

    /**
     * 회원이 쓴 리뷰 목록을 조회합니다. (마이페이지 내 리뷰)
     *
     * @param  int  $userId  회원 ID
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 리뷰 페이지네이터
     */
    public function getUserReviews(int $userId, int $perPage = 10): LengthAwarePaginator
    {
        HookManager::doAction('sirsoft-ecommerce.product-review.before_user_list', $userId, $perPage);

        $reviews = $this->repository->paginateByUser($userId, $perPage);

        $reviews = HookManager::applyFilters('sirsoft-ecommerce.product-review.filter_user_list_result', $reviews, $userId);

        HookManager::doAction('sirsoft-ecommerce.product-review.after_user_list', $reviews, $userId);

        return $reviews;
    }

    /**
     * 판매자 답변 저장 (등록/수정)
     *
     * @param  ProductReview  $review  리뷰 모델
     * @param  int  $adminId  답변 관리자 ID
     * @param  array  $data  답변 데이터
     * @return ProductReview 답변이 반영된 리뷰 모델
     */
    public function saveReply(ProductReview $review, int $adminId, array $data): ProductReview
    {
        // 같은 서비스의 상태 변경·답변 삭제와 같은 권한 범위 — 범위 밖 운영자가 답변을 다는 것을 막는다
        $this->assertWithinScope($review, 'sirsoft-ecommerce.reviews.update');

        $isUpdate = ! is_null($review->replied_at);

        $saved = $this->repository->update($review, [
            'reply_content' => $data['reply_content'],
            'reply_content_mode' => $data['reply_content_mode'] ?? 'text',
            'reply_admin_id' => $adminId,
            'replied_at' => $isUpdate ? $review->replied_at : now(),
            'reply_updated_at' => $isUpdate ? now() : null,
        ]);

        // 답변 알림 — 두 번째 인자가 첫 등록 여부다. 알림은 첫 등록이고 작성자가 회원일 때만 나간다
        HookManager::doAction('sirsoft-ecommerce.product-review.after_reply', $saved, ! $isUpdate);

        return $saved;
    }

    /**
     * 판매자 답변 삭제
     *
     * @param  ProductReview  $review  리뷰 모델
     * @return ProductReview 답변이 삭제된 리뷰 모델
     */
    public function deleteReply(ProductReview $review): ProductReview
    {
        $this->assertWithinScope($review, 'sirsoft-ecommerce.reviews.update');

        return $this->repository->update($review, [
            'reply_content' => null,
            'reply_content_mode' => 'text',
            'reply_admin_id' => null,
            'replied_at' => null,
            'reply_updated_at' => null,
        ]);
    }

    /**
     * 리뷰 삭제 (이미지 파일 포함)
     *
     * @param  ProductReview  $review  리뷰 모델 (images 관계 로드됨)
     * @return bool 삭제 성공 여부
     */
    public function deleteReview(ProductReview $review): bool
    {
        $this->assertWithinScope($review, 'sirsoft-ecommerce.reviews.delete');

        HookManager::doAction('sirsoft-ecommerce.product-review.before_delete', $review);

        return DB::transaction(function () use ($review) {
            // 이미지 파일은 커밋된 뒤에 지운다 — 뒤 단계가 실패해 레코드가 되돌아가면 파일만 사라져 깨진 이미지가 남았다
            // (StorageTransaction). 행 disk 기준
            foreach ($review->images as $image) {
                $rowStorage = $this->storageForRow($image->disk);
                if ($rowStorage->exists('images', $image->path)) {
                    StorageTransaction::deleteAfterCommit($rowStorage, 'images', $image->path);
                }
            }

            // 이미지 레코드 삭제 (명시적, CASCADE 의존 금지)
            $review->images()->delete();

            // 리뷰 삭제
            $result = $this->repository->delete($review);

            Log::info('상품 리뷰 삭제 완료', ['review_id' => $review->id]);

            HookManager::doAction('sirsoft-ecommerce.product-review.after_delete', $review);

            return $result;
        });
    }

    /**
     * 주문 옵션들에 연결된 리뷰를 이미지 파일째 영구 삭제합니다 (개인정보 보유기간 삭제 모드·관리자 주문 삭제).
     *
     * 회원 후기는 주문 옵션에 묶여 있어 주문을 삭제하면 남을 수 없다. DB CASCADE 에
     * 기대지 않고 명시 삭제하며, 이미지 파일도 함께 지운다(행만 지우면 파일이 고아로 남는다).
     * 주문 없이 등록한 후기(`order_option_id` NULL — 다른 쇼핑몰에서 이관)는 대상이 아니다.
     * 무인 배치·주문 삭제가 부르는 시스템 삭제라 관리자 스코프 판정을 두지 않는다.
     *
     * @param  array<int, int>  $orderOptionIds  주문 옵션 ID 목록
     * @return int 삭제한 리뷰 수
     */
    public function purgeForOrderOptions(array $orderOptionIds): int
    {
        return $this->purgeReviews($this->repository->getWithTrashedByOrderOptionIds($orderOptionIds));
    }

    /**
     * 회원이 쓴 리뷰를 이미지 파일째 영구 삭제합니다 (회원 삭제 정리).
     *
     * 회원 행을 지우기 전에 부른다 — 리뷰는 작성 회원에 묶여 있어 회원이 사라지면 남을 수 없다.
     * 휴지통에 든 리뷰도 함께 지운다. 시스템 삭제라 관리자 스코프 판정을 두지 않는다.
     *
     * @param  int  $userId  회원 ID
     * @return int 삭제한 리뷰 수
     */
    public function purgeForUser(int $userId): int
    {
        return $this->purgeReviews($this->repository->getWithTrashedByUserId($userId));
    }

    /**
     * 상품에 달린 리뷰를 이미지 파일째 영구 삭제합니다 (상품 삭제 정리).
     *
     * 주문 이력이 있는 상품은 삭제가 막히므로 실제 대상은 주문 없이 등록한 후기(이관)다.
     * 휴지통에 든 리뷰도 함께 지운다. 상품 삭제 권한 판정은 호출부(상품 서비스)가 이미 했다.
     *
     * @param  int  $productId  상품 ID
     * @return int 삭제한 리뷰 수
     */
    public function purgeForProduct(int $productId): int
    {
        return $this->purgeReviews($this->repository->getWithTrashedByProductId($productId));
    }

    /**
     * 리뷰 묶음을 이미지 행·파일과 함께 영구 삭제합니다.
     *
     * 이미지 파일은 커밋된 뒤에 지운다(StorageTransaction) — 호출부 트랜잭션이 되돌아가면 행과 파일이 함께 남는다.
     * 트랜잭션 밖이면 바로 지운다. 파일은 행의 disk 기준으로 찾는다.
     *
     * @param  iterable<ProductReview>  $reviews  삭제할 리뷰 (images 관계 로드됨, 휴지통 포함)
     * @return int 삭제한 리뷰 수
     */
    private function purgeReviews(iterable $reviews): int
    {
        $deleted = 0;

        foreach ($reviews as $review) {
            foreach ($review->images as $image) {
                $rowStorage = $this->storageForRow($image->disk);
                if ($rowStorage->exists('images', $image->path)) {
                    StorageTransaction::deleteAfterCommit($rowStorage, 'images', $image->path);
                }
            }

            if ($this->repository->forceDeleteWithImages($review)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('상품 리뷰 영구 삭제 (상위 데이터 삭제 정리)', ['count' => $deleted]);
        }

        return $deleted;
    }

    /**
     * 리뷰 일괄 상태 변경
     *
     * @param  array  $ids  리뷰 ID 배열
     * @param  string  $status  변경할 상태값
     * @return int 변경된 건수
     */
    public function bulkUpdateStatus(array $ids, string $status): int
    {
        $this->assertAllWithinScope($this->repository->getByIdsWithImages($ids), 'sirsoft-ecommerce.reviews.update');

        return $this->repository->bulkUpdateStatus($ids, $status);
    }

    /**
     * 리뷰 일괄 삭제 (이미지 파일 포함, N+1 방지)
     *
     * @param  array  $ids  리뷰 ID 배열
     * @return int 삭제된 건수
     */
    public function bulkDelete(array $ids): int
    {
        $reviews = $this->repository->getByIdsWithImages($ids);

        $this->assertAllWithinScope($reviews, 'sirsoft-ecommerce.reviews.delete');

        // 삭제 전 스냅샷 캡처 (after_bulk_delete 훅에 전달)
        $snapshots = $reviews->keyBy('id')->map->toArray()->all();

        HookManager::doAction('sirsoft-ecommerce.product-review.before_bulk_delete', $ids, $reviews);

        return DB::transaction(function () use ($reviews, $ids, $snapshots) {
            // 이미지 파일 일괄 삭제 — 커밋된 뒤에 지운다 (StorageTransaction). 행 disk 기준
            foreach ($reviews as $review) {
                foreach ($review->images as $image) {
                    $rowStorage = $this->storageForRow($image->disk);
                    if ($rowStorage->exists('images', $image->path)) {
                        StorageTransaction::deleteAfterCommit($rowStorage, 'images', $image->path);
                    }
                }
            }

            // 이미지 레코드 일괄 삭제 (명시적, CASCADE 의존 금지)
            $reviewImageIds = $reviews->flatMap(fn ($r) => $r->images->pluck('id'))->all();
            if (! empty($reviewImageIds)) {
                $this->imageRepository->deleteByIds($reviewImageIds);
            }

            // 리뷰 일괄 소프트 삭제
            $count = $this->repository->bulkSoftDeleteByIds($ids);

            HookManager::doAction('sirsoft-ecommerce.product-review.after_bulk_delete', $ids, $snapshots);

            return $count;
        });
    }
}
