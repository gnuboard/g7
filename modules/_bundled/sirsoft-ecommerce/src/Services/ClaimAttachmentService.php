<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Helpers\PermissionHelper;
use App\Support\ImageResizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ClaimImageUploaderTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimImage;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesRowStorage;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesStoredExtension;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * 클레임 첨부 이미지 서비스 (S5)
 *
 * 교환·반품·환불 신청서의 첨부 이미지를 저장·귀속·서빙한다. 리뷰 이미지 서비스와 같은 저장 경로
 * (`StorageInterface` + `ImageResizer`)를 쓰되, 서빙은 **권한 게이트**를 거친다 — 클레임 첨부는
 * 신청자 본인과 운영자만 볼 수 있어 직접(CDN) URL 을 노출하지 않는다(`ClaimImage` 모델 주석).
 *
 * ## 업로드 2단계 (신청서 작성 중 → 귀속)
 *
 * 신청 화면은 신청서가 만들어지기 전에 이미지를 올린다. 그 이미지는 `claimable_type=pending` ·
 * `claimable_id=주문 ID` 로 **주문에 묶어** 둔다 — 업로더 없는 비회원 업로드가 어느 주문의 것인지
 * 판정할 근거가 주문(토큰)뿐이기 때문이다. 신청 접수 시 `attachPending()` 이 같은 주문의 미귀속
 * 이미지 중 요청이 지목한 것만 실제 유형·신청서에 귀속한다. 다른 주문·다른 회원의 미귀속 이미지를
 * 지목하면 `claim_image_not_found` 로 거절한다.
 *
 * ## 서빙 게이트 (`download`)
 *
 *  1. 한시 서명 URL(`signedDownloadUrl`)이 검증되면 통과 — 신청자 화면의 `<img>` 는 인증 헤더를 실을 수
 *     없으므로 응답 직렬화(Resource)가 발급한 서명이 열람 자격의 위임이다. 비회원도 이 경로로 본다.
 *  2. 운영자(`sirsoft-ecommerce.claims.read`)는 통과.
 *  3. 회원은 그 첨부가 귀속된 신청서의 `requested_by`(미귀속이면 업로더)가 본인일 때 통과.
 *  그 외는 모두 404 — 존재 여부를 구분해 알리지 않는다.
 */
class ClaimAttachmentService
{
    use ResolvesRowStorage;
    use ResolvesStoredExtension;

    /**
     * 허용 확장자의 SSoT.
     *
     * 화면의 `accept` 와 두 FormRequest 의 `mimes:` 가 **이 하나를 읽는다.** 종전에는 화면이
     * `image/*` 라 bmp·svg·heic 를 고를 수 있었고, 고르면 서버가 무조건 422 로 거절했다 —
     * 사용자에게는 "왜 안 되는지" 가 아무 데도 보이지 않았다.
     *
     * @var array<int, string>
     */
    public const ALLOWED_EXTENSIONS = ['jpeg', 'jpg', 'png', 'gif', 'webp'];

    /**
     * @param  StorageInterface  $storage  스토리지 (images 카테고리 디스크 주입)
     * @param  EcommerceSettingsService  $settingsService  설정 서비스
     * @param  ClaimImageRepositoryInterface  $repository  첨부 Repository
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 신청서 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 신청서 Repository
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     */
    public function __construct(
        protected StorageInterface $storage,
        protected EcommerceSettingsService $settingsService,
        protected ClaimImageRepositoryInterface $repository,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderRefundRepositoryInterface $refundRepository,
    ) {}

    /**
     * 신청서 작성 중인 이미지를 주문에 묶어 업로드합니다. (미귀속)
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  Order  $order  대상 주문
     * @param  int|null  $userId  업로더 회원 ID (비회원이면 null)
     * @return ClaimImage 저장된 첨부
     *
     * @throws OrderClaimException 장수 초과
     */
    public function uploadPending(UploadedFile $file, Order $order, ?int $userId = null): ClaimImage
    {
        $pending = $this->repository->listPendingByOrder($order->id);

        if ($pending->count() >= $this->maxImages()) {
            throw OrderClaimException::imageLimitExceeded($this->maxImages());
        }

        // 미귀속 업로드 경로는 회원·비회원 신청 화면뿐이다 — 언제나 고객이 올린 것이다.
        return $this->store(
            $file,
            ClaimImage::PENDING_CLAIMABLE_TYPE,
            $order->id,
            $userId,
            (int) ($pending->max('sort_order') ?? 0) + 1,
            ClaimImageUploaderTypeEnum::CUSTOMER
        );
    }

    /**
     * 신청서에 이미지를 직접 업로드합니다. (수정 화면 — 이미 귀속된 신청서에 추가)
     *
     * **업로드 주체는 인자로 받는다(기본값 없음).** 기본값을 두면 나중에 회원 경로가 이
     * 메서드를 쓰게 될 때 조용히 운영자로 찍히고, 그 표기로 분쟁 판정이 뒤집힌다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 ID
     * @param  int|null  $userId  업로더 회원 ID
     * @param  ClaimImageUploaderTypeEnum  $uploaderType  업로드 주체
     * @return ClaimImage 저장된 첨부
     *
     * @throws OrderClaimException 장수 초과
     */
    public function uploadFor(
        UploadedFile $file,
        ClaimTypeEnum $type,
        int $claimId,
        ?int $userId,
        ClaimImageUploaderTypeEnum $uploaderType,
    ): ClaimImage {
        $current = $this->repository->countByClaimable($type->value, $claimId);

        if ($current >= $this->maxImages()) {
            throw OrderClaimException::imageLimitExceeded($this->maxImages());
        }

        $maxSort = (int) ($this->repository->listByClaimable($type->value, $claimId)->max('sort_order') ?? 0);

        return $this->store($file, $type->value, $claimId, $userId, $maxSort + 1, $uploaderType);
    }

    /**
     * 첨부가 그 신청서의 것인지 확인합니다.
     *
     * 권한 게이트는 "이 사람이 클레임을 다룰 수 있다" 만 말하지 "이 첨부가 이 신청서의
     * 것이다" 를 말하지 않는다. 이 검사가 없으면 **아무 신청서 상세에서나 남의 첨부 id 를
     * 넣어 지울 수 있다.**
     *
     * `ownedBy()` 는 재사용할 수 없다 — 그것은 "열람자가 신청자 본인인가" 라서 운영자에겐
     * 언제나 false 다. 묻는 질문 자체가 다르다.
     *
     * @param  ClaimImage  $image  첨부
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 ID
     * @return bool 그 신청서의 첨부면 true
     */
    public function belongsToClaim(ClaimImage $image, ClaimTypeEnum $type, int $claimId): bool
    {
        return (string) $image->claimable_type === $type->value
            && (int) $image->claimable_id === $claimId;
    }

    /**
     * 화면이 상한 안내·입력 제약에 쓰는 공개 설정을 반환합니다.
     *
     * 상한을 화면마다 리터럴로 적으면 운영자가 설정을 바꾼 순간 화면과 서버가 갈린다 —
     * 사용자에게는 "왜 갑자기 안 되는지" 만 남는다. **한 출처에서 내려 준다.**
     *
     * > ⚠️ 키 이름은 바꾸지 않는다. 사용자 신청 화면의 상한 가드가 이 키를 읽고, 값이 0 이면
     * > 가드가 꺼져 서버가 거절할 때까지 아무 안내도 없다.
     *
     * @return array<string, mixed> 공개 설정
     */
    public function publicLimits(): array
    {
        return [
            'max_images' => $this->maxImages(),
            'image_max_size_kb' => $this->maxSizeKb(),
            // 같은 상한의 MB 표기 — 첨부 컴포지트가 MB 로 상한을 받는다(T2-D2).
            //
            // 화면에서 `kb / 1024` 를 하지 않는 이유는 둘이다. ⓐ 상한은 설정 한 곳에서 나와야
            // 하는데 나눗셈이 화면에 흩어지면 반올림 규칙이 화면마다 갈린다. ⓑ 레이아웃 식에
            // 산술이 들어가면 바인딩 판정 경로가 달라져(엔진 `BindingShape` 라우팅) 같은 식이
            // prop 과 `if` 에서 다르게 읽히는 자리가 늘어난다.
            'image_max_size_mb' => round($this->maxSizeKb() / 1024, 3),
            'allowed_extensions' => self::ALLOWED_EXTENSIONS,
            // 화면 파일 선택창이 그대로 쓰는 문자열 — `image/*` 로 두면 서버가 받지 않는
            // 확장자를 고를 수 있고, 고르면 이유 없이 422 가 된다.
            'accept' => implode(',', array_map(
                static fn (string $extension): string => '.'.$extension,
                self::ALLOWED_EXTENSIONS
            )),
        ];
    }

    /**
     * 귀속 자격을 검사하고 대상 첨부를 반환합니다. (신청서를 만들기 전에 부를 수 있는 가드)
     *
     * 신청 경로는 신청서를 만든 **뒤** 귀속하는데, 그 사이에서 거절하면 신청서만 남아 수량 잠금까지
     * 걸린다 — 사용자는 422 를 보고도 같은 상품을 다시 신청할 수 없다. 그래서 신청서 ID 에 의존하지
     * 않는 검사(존재·소속·업로더·장수)를 이 메서드로 분리해 **쓰기 전에** 통과시킨다.
     * `attachPending()` 도 같은 검사를 다시 타므로(다른 호출 경로 보호) 이중 검사는 의도된 것이다.
     *
     * @param  Order  $order  주문
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID 목록
     * @param  int|null  $userId  신청자 회원 ID
     * @param  int  $alreadyAttached  이 신청서에 이미 귀속돼 남을 장수 (신규 신청은 0)
     * @return Collection<int, ClaimImage> 귀속 대상 첨부 목록 (빈 요청이면 빈 컬렉션)
     *
     * @throws OrderClaimException 첨부 부재·소속 불일치·장수 초과
     */
    public function resolveAttachable(Order $order, array $imageIds, ?int $userId = null, int $alreadyAttached = 0): Collection
    {
        $imageIds = array_values(array_unique(array_map('intval', $imageIds)));

        if ($imageIds === []) {
            return new Collection;
        }

        $images = $this->repository->listByIds($imageIds);

        if ($images->count() !== count($imageIds)) {
            throw OrderClaimException::imageNotFound();
        }

        foreach ($images as $image) {
            $belongs = $image->claimable_type === ClaimImage::PENDING_CLAIMABLE_TYPE
                && (int) $image->claimable_id === (int) $order->id
                && ($image->created_by === null || $userId === null || (int) $image->created_by === (int) $userId);

            if (! $belongs) {
                throw OrderClaimException::imageNotFound();
            }
        }

        // 덧붙이는 경로(수정)는 남을 기존 귀속분을 호출부가 넘겨 `attachPending()` 과 같은 등식을
        // 쓰기 전에 평가한다 — 넘기지 않으면 신규 신청(기존 0)과 같은 판정이 된다.
        if ($alreadyAttached + $images->count() > $this->maxImages()) {
            throw OrderClaimException::imageLimitExceeded($this->maxImages());
        }

        return $images;
    }

    /**
     * 주문의 미귀속 이미지를 신청서에 귀속합니다.
     *
     * 요청이 지목한 ID 가 이 주문의 미귀속 이미지가 아니면(다른 주문·이미 귀속됨) 거절한다. 회원 업로드는
     * 업로더가 본인이어야 한다 — 비회원 업로드(`created_by` NULL)는 주문 토큰이 곧 자격이다.
     *
     * @param  Order  $order  주문
     * @param  array<int, int>  $imageIds  귀속할 미귀속 첨부 ID 목록
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 ID
     * @param  int|null  $userId  신청자 회원 ID
     * @return Collection<int, ClaimImage> 귀속된 첨부 목록
     *
     * @throws OrderClaimException 첨부 부재·소속 불일치·장수 초과
     */
    public function attachPending(Order $order, array $imageIds, ClaimTypeEnum $type, int $claimId, ?int $userId = null): Collection
    {
        $images = $this->resolveAttachable($order, $imageIds, $userId);

        if ($images->isEmpty()) {
            return new Collection;
        }

        $existing = $this->repository->countByClaimable($type->value, $claimId);

        if ($existing + $images->count() > $this->maxImages()) {
            throw OrderClaimException::imageLimitExceeded($this->maxImages());
        }

        $sort = (int) ($this->repository->listByClaimable($type->value, $claimId)->max('sort_order') ?? 0);

        // 귀속은 전부-또는-전무여야 한다 — 중간에 실패하면 앞의 것만 신청서로 옮겨지고 나머지는
        // `pending` 에 남는데, 신청서는 이미 커밋돼(도메인 서비스 자체 트랜잭션) 수량까지 잠근 뒤라
        // 사용자가 스스로 되돌릴 수 없다.
        return DB::transaction(function () use ($images, $type, $claimId, $sort) {
            $attached = new Collection;

            foreach ($images as $image) {
                $attached->push($this->repository->update($image, [
                    'claimable_type' => $type->value,
                    'claimable_id' => $claimId,
                    'sort_order' => ++$sort,
                ]));
            }

            return $attached;
        });
    }

    /**
     * 신청서의 첨부 목록을 반환합니다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 ID
     * @return Collection<int, ClaimImage> 첨부 목록
     */
    public function listFor(ClaimTypeEnum $type, int $claimId): Collection
    {
        return $this->repository->listByClaimable($type->value, $claimId);
    }

    /**
     * 주문의 미귀속 첨부 목록을 반환합니다.
     *
     * @param  Order  $order  주문
     * @return Collection<int, ClaimImage> 미귀속 첨부 목록
     */
    public function listPendingFor(Order $order): Collection
    {
        return $this->repository->listPendingByOrder($order->id);
    }

    /**
     * 첨부를 삭제합니다. (파일 + 행)
     *
     * @param  ClaimImage  $image  첨부
     * @return bool 삭제 여부
     */
    public function delete(ClaimImage $image): bool
    {
        $rowStorage = $this->storageForRow($image->disk);

        if ($rowStorage->exists('images', $image->path)) {
            $rowStorage->delete('images', $image->path);
        }

        $result = $this->repository->delete($image);

        HookManager::doAction('sirsoft-ecommerce.claim-image.after_delete', $image);

        Log::info('[claim.image] 첨부 삭제', [
            'image_id' => $image->id,
            'claimable_type' => $image->claimable_type,
            'claimable_id' => $image->claimable_id,
        ]);

        return $result;
    }

    /**
     * 신청서의 첨부를 전부 삭제합니다. (파일 + 행) — 신청서 삭제 시 명시적으로 부른다.
     *
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @param  int  $claimId  신청서 ID
     * @return int 삭제 건수
     */
    public function deleteFor(ClaimTypeEnum $type, int $claimId): int
    {
        $count = 0;

        foreach ($this->repository->listByClaimable($type->value, $claimId) as $image) {
            if ($this->delete($image)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 보존 기간이 지난 미귀속(pending) 첨부를 정리합니다. (정리 배치 전용)
     *
     * 신청으로 이어지지 않은 임시 업로드만 대상이다 — 귀속된 첨부는 `claimable_type` 이
     * 클레임 유형 값으로 바뀌어 조회에 걸리지 않고, 보존 기간 이내의 미귀속 첨부는 아직
     * 작성 중인 신청서의 것일 수 있어 남긴다. 건별 실패는 나머지를 막지 않는다
     * (상품 임시 이미지 정리 `ProductImageService::pruneTempUploads()` 와 동형).
     *
     * @param  int  $days  보존 기간(일)
     * @param  int  $limit  한 번에 처리할 최대 건수
     * @param  bool  $dryRun  실제 삭제 없이 대상 건수만 셀지 여부
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function prunePendingUploads(int $days, int $limit, bool $dryRun = false): array
    {
        $targets = $this->repository->listPendingOlderThan(now()->subDays($days), $limit);

        $result = ['scanned' => $targets->count(), 'deleted' => 0, 'failed' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($targets as $image) {
            try {
                if ($this->delete($image)) {
                    $result['deleted']++;
                } else {
                    $result['failed']++;
                }
            } catch (Throwable $e) {
                $result['failed']++;

                Log::warning('[claim.image] 임시 첨부 정리 실패', [
                    'claim_image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * 주문에 딸린 모든 클레임 첨부 행을 모읍니다. (삭제하지 않는다)
     *
     * 주문 삭제 경로는 **DB 정리 전에** 이 목록을 잡아 두고, 정리가 끝난 뒤에 파일을 지운다 —
     * 신청서 행이 사라진 뒤에는 이 조회가 대상을 찾지 못하고, 파일 삭제는 되돌릴 수 없어
     * 정리 중간에 실패하면 살아 있는 주문의 첨부만 전멸한다.
     *
     * @param  Order  $order  주문
     * @return Collection<int, ClaimImage> 첨부 목록
     */
    public function imagesForOrder(Order $order): Collection
    {
        $images = new Collection;

        foreach ([
            ClaimTypeEnum::EXCHANGE->value => $order->exchanges()->pluck('id'),
            ClaimTypeEnum::RETURN->value => $order->returns()->pluck('id'),
            ClaimTypeEnum::REFUND->value => $order->refunds()->pluck('id'),
        ] as $type => $ids) {
            foreach ($ids as $id) {
                $images = $images->merge($this->repository->listByClaimable($type, (int) $id));
            }
        }

        return $images->merge($this->listPendingFor($order));
    }

    /**
     * 모아 둔 첨부의 저장 파일만 지웁니다. (행·훅·활동 로그 없음)
     *
     * 개인정보 보유기간 삭제 전용이다 — 첨부 행은 주문과 함께 보유기간 저장소가 한 트랜잭션에서 지우고,
     * 파일은 커밋 뒤 이 메서드가 지운다. {@see delete()} 를 쓰지 않는 이유: 삭제 훅이 활동 로그에 원본
     * 파일명을 새로 남겨, 파기하려던 개인정보 사본이 하나 더 생긴다. 건별 실패는 나머지를 막지 않고
     * 첨부 ID 만 남긴다.
     *
     * @param  Collection<int, ClaimImage>  $images  삭제 전에 모아 둔 첨부 목록
     * @return int 지운 파일 수
     */
    public function deleteStoredFiles(Collection $images): int
    {
        $count = 0;

        foreach ($images as $image) {
            try {
                $rowStorage = $this->storageForRow($image->disk);

                if ($rowStorage->exists('images', $image->path)) {
                    $rowStorage->delete('images', $image->path);
                    $count++;
                }
            } catch (Throwable $e) {
                Log::warning('[claim.image] 보유기간 삭제 — 첨부 파일 삭제 실패', [
                    'claim_image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    /**
     * 모아 둔 첨부 목록을 파일까지 삭제합니다.
     *
     * @param  Collection<int, ClaimImage>  $images  첨부 목록
     * @return int 삭제된 첨부 수
     */
    public function deleteImages(Collection $images): int
    {
        $count = 0;

        foreach ($images as $image) {
            if ($this->delete($image)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 첨부를 조회합니다.
     *
     * @param  int  $id  첨부 ID
     * @return ClaimImage|null 첨부
     */
    public function find(int $id): ?ClaimImage
    {
        return $this->repository->findById($id);
    }

    /**
     * 첨부가 이 열람자의 것인지 판정합니다. (삭제·열람 게이트)
     *
     * 미귀속 첨부는 주문 소속(+회원이면 업로더)으로, 귀속 첨부는 신청서의 신청자로 판정한다.
     *
     * @param  ClaimImage  $image  첨부
     * @param  int|null  $userId  열람 회원 ID (비회원이면 null)
     * @param  Order|null  $guestOrder  토큰으로 검증된 비회원 주문 (회원이면 null)
     * @return bool 본인 소유이면 true
     */
    public function ownedBy(ClaimImage $image, ?int $userId, ?Order $guestOrder = null): bool
    {
        if ($image->claimable_type === ClaimImage::PENDING_CLAIMABLE_TYPE) {
            if ($guestOrder !== null) {
                return (int) $image->claimable_id === (int) $guestOrder->id;
            }

            return $userId !== null && (int) $image->created_by === (int) $userId;
        }

        $claim = $this->resolveClaimable($image);

        if ($claim === null) {
            return false;
        }

        if ($guestOrder !== null) {
            return (int) $claim->order_id === (int) $guestOrder->id && $claim->requested_by === null;
        }

        return $userId !== null && (int) $claim->requested_by === (int) $userId;
    }

    /**
     * 고객이 미귀속 삭제 경로(DELETE)로 이 첨부를 지울 수 있는지 판정합니다.
     *
     * 그 경로는 작성 화면에서 **아직 신청서에 붙지 않은** 사진을 치우는 용도다. 신청서에 붙은
     * 첨부는 소유자라도 이 경로로 지울 수 없다 — 접수가 끝난 신청서의 증빙과 운영자가 올린
     * 사진까지 지워져 수정 창(승인 전만 허용)을 통째로 우회하기 때문이다. 붙은 첨부의 정리는
     * 수정 경로({@see UserClaimService::update()})가 신청서 상태와 업로더를 보고 판정한다.
     *
     * @param  ClaimImage  $image  첨부
     * @param  int|null  $userId  요청 회원 ID (비회원이면 null)
     * @param  Order|null  $guestOrder  토큰으로 검증된 비회원 주문 (회원이면 null)
     * @return bool 미귀속이면서 본인 소유이면 true
     */
    public function deletableBy(ClaimImage $image, ?int $userId, ?Order $guestOrder = null): bool
    {
        return $image->claimable_type === ClaimImage::PENDING_CLAIMABLE_TYPE
            && $this->ownedBy($image, $userId, $guestOrder);
    }

    /**
     * 첨부 서빙 응답을 만듭니다. (게이트 통과 시에만)
     *
     * @param  string  $hash  첨부 해시
     * @param  bool  $signatureVerified  한시 서명 검증 통과 여부
     * @param  int|null  $viewerUserId  열람 회원 ID
     * @return StreamedResponse|null 응답 (게이트 불통·부재 시 null)
     */
    public function download(string $hash, bool $signatureVerified = false, ?int $viewerUserId = null): ?StreamedResponse
    {
        $image = $this->repository->findByHash($hash);

        if (! $image) {
            return null;
        }

        $allowed = $signatureVerified
            || PermissionHelper::check('sirsoft-ecommerce.claims.read')
            || $this->ownedBy($image, $viewerUserId);

        if (! $allowed) {
            return null;
        }

        $response = $this->storageForRow($image->disk)->response(
            'images',
            $image->path,
            $image->original_name,
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'private, max-age=300',
            ]
        );

        if (! $response) {
            Log::error('클레임 첨부 스토리지에 없음', ['claim_image_id' => $image->id, 'path' => $image->path]);

            return null;
        }

        // 인라인 표시는 래스터 이미지만, 그 외는 다운로드로 — 모든 응답에 nosniff (KVE-2026-2366).
        // 업로드가 래스터만 받더라도 서빙 계층은 저장된 MIME 과 무관하게 같은 판정을 거친다(다른 이미지 서빙 지점과 동일).
        InlineMediaPolicy::harden($response, $image->mime_type, $image->original_name);

        return $response;
    }

    /**
     * 첨부 최대 장수 설정을 반환합니다.
     *
     * @return int 최대 장수
     */
    public function maxImages(): int
    {
        return max(1, (int) $this->settingsService->getSetting('claim.max_images', 5));
    }

    /**
     * 첨부 최대 용량(KB) 설정을 반환합니다.
     *
     * @return int 최대 용량 (KB)
     */
    public function maxSizeKb(): int
    {
        return max(1, (int) $this->settingsService->getSetting('claim.image_max_size_kb', 2048));
    }

    /**
     * 첨부가 귀속된 신청서를 반환합니다.
     *
     * @param  ClaimImage  $image  첨부
     * @return object|null 신청서 (교환/반품/환불)
     */
    public function resolveClaimable(ClaimImage $image): ?object
    {
        return match (ClaimTypeEnum::tryFrom((string) $image->claimable_type)) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->findById((int) $image->claimable_id),
            ClaimTypeEnum::RETURN => $this->returnRepository->findById((int) $image->claimable_id),
            ClaimTypeEnum::REFUND => $this->refundRepository->findById((int) $image->claimable_id),
            default => null,
        };
    }

    /**
     * 파일을 저장하고 첨부 행을 만듭니다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  string  $claimableType  귀속 유형 값
     * @param  int  $claimableId  귀속 ID
     * @param  int|null  $userId  업로더 회원 ID
     * @param  int  $sortOrder  정렬 순서
     * @param  ClaimImageUploaderTypeEnum  $uploaderType  업로드 주체
     * @return ClaimImage 저장된 첨부
     */
    protected function store(
        UploadedFile $file,
        string $claimableType,
        int $claimableId,
        ?int $userId,
        int $sortOrder,
        ClaimImageUploaderTypeEnum $uploaderType,
    ): ClaimImage {
        // 업로드 트라이어드의 첫 단계 — 다른 첨부 지점(상품·리뷰·게시판)과 같은 모양이다. 확장은
        // 여기서 예외를 던져 저장 전에 거절할 수 있다(파일도 행도 남지 않는다).
        HookManager::doAction(
            'sirsoft-ecommerce.claim-image.before_upload',
            $file,
            $claimableType,
            $claimableId,
            $uploaderType->value,
        );

        $file = HookManager::applyFilters('sirsoft-ecommerce.claim-image.filter_upload_file', $file);

        // 확장자는 클라이언트가 준 값이 아니라 검증이 실제로 본 값(내용)에서 정한다 — 상세는
        // `ResolvesStoredExtension` 주석 참조.
        $storedFilename = Str::uuid().'.'.$this->storedExtension($file);
        $path = "claims/{$claimableType}/{$claimableId}/{$storedFilename}";

        // 환경설정 > 업로드의 최대 가로/세로·품질 적용 (코어 설정이 모든 업로드 경로에 동일 적용)
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

        $this->storage->put('images', $path, $file->getContent());

        $width = null;
        $height = null;

        if (str_starts_with((string) $file->getMimeType(), 'image/')) {
            $imageSize = @getimagesize($file->getRealPath());

            if ($imageSize) {
                [$width, $height] = $imageSize;
            }
        }

        try {
            $image = $this->repository->create([
                'claimable_type' => $claimableType,
                'claimable_id' => $claimableId,
                'disk' => $this->storage->getDisk(),
                'path' => $path,
                // 컬럼 폭(255·100)을 넘는 값이 오면 저장이 통째로 실패해 방금 올린 파일이 고아가 된다.
                // 표시용 값이므로 잘라서 싣고, 파일 자체는 UUID 이름으로 저장돼 있어 영향이 없다.
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, '…'),
                'mime_type' => Str::limit((string) $file->getMimeType(), 97, '…'),
                'size' => $file->getSize(),
                'width' => $width,
                'height' => $height,
                'sort_order' => $sortOrder,
                'created_by' => $userId,
                'uploader_type' => $uploaderType->value,
            ]);
        } catch (Throwable $e) {
            // 행을 못 만들면 방금 올린 파일을 되돌린다 — 그러지 않으면 어떤 조회 경로에서도 닿을 수
            // 없는 파일이 디스크에 영구히 남는다(정리 커맨드도 DB 행을 순회한다).
            $this->storage->delete('images', $path);

            Log::error('[claim.image] 첨부 행 생성 실패로 업로드 파일을 되돌렸습니다', [
                'path' => $path,
                'claimable_type' => $claimableType,
                'claimable_id' => $claimableId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        HookManager::doAction('sirsoft-ecommerce.claim-image.after_upload', $image);

        Log::info('[claim.image] 첨부 업로드', [
            'image_id' => $image->id,
            'claimable_type' => $claimableType,
            'claimable_id' => $claimableId,
            'size' => $image->size,
        ]);

        return $image;
    }
}
