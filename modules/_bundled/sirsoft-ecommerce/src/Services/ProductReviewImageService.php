<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Helpers\StorageTransaction;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Support\Files\StoredFileExtension;
use App\Support\ImageResizer;
use App\Support\UploadedFileName;
use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewImageUploadLimitException;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\BuildsRowThumbnailSource;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesRowStorage;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ServesImageWithRowValidators;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 상품 리뷰 이미지 서비스
 *
 * 리뷰 이미지 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class ProductReviewImageService
{
    use BuildsRowThumbnailSource;
    use ResolvesRowStorage;
    use ServesImageWithRowValidators;

    /**
     * ProductReviewImageService 생성자
     *
     * @param  StorageInterface  $storage  모듈 스토리지 드라이버
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     * @param  ProductReviewImageRepositoryInterface  $repository  리뷰 이미지 리포지토리
     */
    public function __construct(
        protected StorageInterface $storage,
        protected EcommerceSettingsService $settingsService,
        protected ProductReviewImageRepositoryInterface $repository
    ) {}

    /**
     * 리뷰 이미지 업로드
     *
     * @param  UploadedFile  $file  업로드된 파일
     * @param  ProductReview  $review  리뷰 모델
     * @return ProductReviewImage 생성된 이미지
     *
     * @throws ReviewImageUploadLimitException 최대 업로드 수 초과 시
     */
    public function upload(UploadedFile $file, ProductReview $review): ProductReviewImage
    {
        $maxImages = (int) $this->settingsService->getSetting(
            'review_settings.max_images',
            // 모듈 config 는 'sirsoft-ecommerce' 네임스페이스로 로드된다 (ModuleManager::loadModuleConfig)
            config('sirsoft-ecommerce.review.max_images', 5)
        );

        $currentCount = $review->images()->count();
        if ($currentCount >= $maxImages) {
            throw new ReviewImageUploadLimitException($maxImages);
        }

        HookManager::doAction('sirsoft-ecommerce.review-image.before_upload', $file, $review);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('sirsoft-ecommerce.review-image.filter_upload_file', $file);

        $extension = StoredFileExtension::resolve($file, 'jpg');
        $storedFilename = Str::uuid().'.'.$extension;
        $path = "reviews/{$review->id}/{$storedFilename}";

        // 환경설정 > 업로드의 최대 가로/세로·품질 적용 (코어 설정이 모든 업로드 경로에 동일 적용)
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

        // 축소는 **같은 경로를 제자리에서 갈아 끼운다.** 그 과정이 `is_file()` 로 그 경로를
        // stat 하므로 PHP 공유 stat 캐시가 채워지고, 뒤따르는 `getSize()` 는 그 캐시를 그대로
        // 읽어 **축소 전 크기**를 돌려준다. `getimagesize()` 는 파일을 직접 읽으므로
        // width/height 만 축소본을 가리키고 크기만 어긋난다 — 한 행의 두 값이 서로 다른
        // 파일을 가리키는 상태다. 형제 넷(코어 2 · 게시판 · 페이지)은 같은 사유로 이미 이
        // 처리를 갖는다 (종전 수정은 형제 절반에만 적용됐다).
        clearstatcache(true, $file->getRealPath());

        // 공개 자산 디스크를 주입받았어도 「공개 디스크로 내보낼 확장자」 밖이면 모듈 기본 디스크에 저장한다.
        $storage = $this->storageForUpload($extension);

        $storage->put('images', $path, file_get_contents($file->getRealPath()));

        $disk = $storage->getDisk();

        // 이미지 크기 정보 추출
        $width = null;
        $height = null;
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageSize = @getimagesize($file->getRealPath());
            if ($imageSize) {
                $width = $imageSize[0];
                $height = $imageSize[1];
            }
        }

        $maxSortOrder = $review->images()->max('sort_order') ?? 0;

        $image = $this->repository->create([
            'review_id' => $review->id,
            'original_filename' => UploadedFileName::original($file),
            'stored_filename' => $storedFilename,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'collection' => 'review',
            'sort_order' => $maxSortOrder + 1,
            'is_thumbnail' => ($maxSortOrder === 0),
            'created_by' => Auth::id(),
        ]);

        Log::info('리뷰 이미지 업로드 완료', [
            'image_id' => $image->id,
            'review_id' => $review->id,
            'original_filename' => $image->original_filename,
        ]);

        HookManager::doAction('sirsoft-ecommerce.review-image.after_upload', $image);

        return $image;
    }

    /**
     * 리뷰 이미지 삭제
     *
     * @param  ProductReviewImage  $image  이미지 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ProductReviewImage $image): bool
    {
        HookManager::doAction('sirsoft-ecommerce.review-image.before_delete', $image);

        // 레코드 삭제와 파일 삭제를 한 트랜잭션으로 묶고 파일은 커밋 뒤에 지운다 — 종전에는 파일을 먼저 지우고 레코드를
        // 지워, 레코드 삭제가 실패하면 깨진 이미지가 남았다 (StorageTransaction, #883). 행 disk 기준
        $result = DB::transaction(function () use ($image) {
            $rowStorage = $this->storageForRow($image->disk);
            if ($rowStorage->exists('images', $image->path)) {
                StorageTransaction::deleteAfterCommit($rowStorage, 'images', $image->path);
            }

            return $this->repository->delete($image);
        });

        Log::info('리뷰 이미지 삭제 완료', ['image_id' => $image->id]);

        HookManager::doAction('sirsoft-ecommerce.review-image.after_delete', $image);

        return $result;
    }

    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return ProductReviewImage|null 이미지 모델 (없으면 null)
     */
    public function findByHash(string $hash): ?ProductReviewImage
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * 이미지 다운로드 응답 생성 (하위호환 표면)
     *
     * 캐시 계약(검증자·304)은 이 스트림에 실리지 않는다 — `getFileInfo()` 가 돌려주는 메타를
     * 컨트롤러가 코어 단일 지점(`streamedFileResponse()`)에 넘겨 입힌다. 이 메서드는 그
     * 정보의 `response` 만 돌려준다.
     *
     * @param  string  $hash  이미지 해시
     * @param  bool  $signatureVerified  유효 서명 URL 로 접근했는가 (숨김 리뷰 이미지의 한시 허용)
     * @return StreamedResponse|null 이미지 스트림 또는 null
     */
    public function download(string $hash, bool $signatureVerified = false): ?StreamedResponse
    {
        return $this->getFileInfo($hash, $signatureVerified)['response'] ?? null;
    }

    /**
     * 이미지 서빙 정보 조회 (스트림 + 검증자 메타)
     *
     * 캐싱 헤더·검증자 비교·304 는 코어 단일 지점이 만든다 — 이 서비스는 코어 형제
     * (`App\Services\AttachmentService::getFileInfo()`)와 같은 형태의 서빙 정보만 돌려준다.
     *
     * @param  string  $hash  이미지 해시
     * @param  bool  $signatureVerified  유효 서명 URL 로 접근했는가 (숨김 리뷰 이미지의 한시 허용)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null, max_age: int, thumbnail_source: ThumbnailSource|null, private: bool}|null 서빙 정보 또는 null
     */
    public function getFileInfo(string $hash, bool $signatureVerified = false): ?array
    {
        $image = $this->findByHash($hash);

        if (! $image) {
            return null;
        }

        // 숨김(HIDDEN) 리뷰의 이미지는 서빙하지 않는다(KVE-2026-1914 S-2).
        // 관리자가 숨긴 리뷰의 이미지가 해시만으로 공개 서빙되던 불일치를 정합화한다.
        //
        // 부모 리뷰를 못 읽으면 **가린다**(fail-closed). ProductReview 는 소프트 삭제되므로
        // 관계 조회가 null 을 돌려줄 수 있는데, 그때 게이트가 성립하지 않아 통과하면
        // "부모가 사라진 이미지는 무조건 공개" 가 된다 — 첨부 게이트가 채택한 방향과 반대다.
        // 현재는 리뷰 삭제 시 이미지도 함께 삭제돼 여기까지 오지 않지만, 그 두 서비스가
        // 계속 동기화된다는 암묵 불변식에 보안을 걸지 않는다.
        if (! $signatureVerified && (! $image->review || $image->review->status !== ReviewStatus::VISIBLE)) {
            return null;
        }

        // 서빙 정보(스트림 + 검증자 메타)는 `ServesImageWithRowValidators` 단일 지점이 만든다
        // (형제 두 이미지 서비스와 같은 계약 — 코어 지점 경유).
        $info = $this->imageServeInfo($image);

        if (! $info) {
            Log::error('리뷰 이미지 스토리지에 없음', [
                'review_image_id' => $image->id,
                'path' => $image->path,
            ]);

            return null;
        }

        // 인라인 표시는 래스터 이미지만, 그 외(SVG 등)는 다운로드로 — 모든 응답에 nosniff (KVE-2026-2366).
        // 서빙 정보의 스트림에 굳혀 두면 코어 지점(`streamedFileResponseFromMeta()`)이 같은 응답 객체에
        // 캐시 계약을 더해 내보낸다.
        InlineMediaPolicy::harden($info['response'], $image->mime_type, $image->original_filename);

        // 썸네일 헬퍼가 쓸 원본 — 위 상태 게이트를 지난 행에서만 만든다. 컨트롤러가 원본 응답 바로 앞에서
        // `ThumbnailResponder::respond()` 에 넘긴다.
        $info['thumbnail_source'] = $this->thumbnailSourceFor($image);

        // 서명으로 연 응답과 노출 상태가 아닌 리뷰의 원본은 공유 캐시(CDN·프록시)에 남기지 않는다 — 다음 방문자가
        // 게이트 없이 받는다. 노출 리뷰 원본은 종전처럼 public 이다.
        $info['private'] = $signatureVerified || $image->review?->status !== ReviewStatus::VISIBLE;

        return $info;
    }

    /**
     * 리뷰 이미지 행의 썸네일 원본 (게이트)
     *
     * 리뷰 이미지는 숨김(HIDDEN) 상태 게이트와 한시 서명이 걸린 원본이라 **원본이 공개 자산 디스크(CDN)에
     * 있어도** 파생본은 비공개 썸네일 디스크에 두고 `private` 캐시로 낸다(설계 결정 D25). 공개 위치에 두면
     * 리뷰를 숨긴 뒤에도 파생본 주소만으로 계속 열리고, 공유 캐시가 숨김 리뷰의 서명 응답을 남긴다.
     *
     * @param  ProductReviewImage  $image  리뷰 이미지 행
     * @return ThumbnailSource|null 원본 (래스터가 아니면 null)
     */
    public function thumbnailSourceFor(ProductReviewImage $image): ?ThumbnailSource
    {
        return $this->rowThumbnailSource($image, gated: true);
    }
}
