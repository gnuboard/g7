<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Support\ImageResizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Exceptions\ReviewImageUploadLimitException;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductReviewImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\ResolvesRowStorage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 상품 리뷰 이미지 서비스
 *
 * 리뷰 이미지 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class ProductReviewImageService
{
    use ResolvesRowStorage;

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

        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = "reviews/{$review->id}/{$storedFilename}";

        // 환경설정 > 업로드의 최대 가로/세로·품질 적용 (코어 설정이 모든 업로드 경로에 동일 적용)
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

        $this->storage->put('images', $path, file_get_contents($file->getRealPath()));

        $disk = $this->storage->getDisk();

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
            'original_filename' => $file->getClientOriginalName(),
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

        // 스토리지에서 파일 삭제 — 행 disk 기준
        $rowStorage = $this->storageForRow($image->disk);
        if ($rowStorage->exists('images', $image->path)) {
            $rowStorage->delete('images', $image->path);
        }

        $result = $this->repository->delete($image);

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
     * 이미지 다운로드 응답 생성
     *
     * $signatureVerified 는 요청 URL 의 한시 서명이 검증된 경우다 — 서명은
     * 숨김 리뷰 게이트(관리자 권한 미들웨어)를 통과한 응답 직렬화
     * (ProductReviewResource)만 발급하므로, 검증된 서명은 그 게이트 통과 자격의
     * 위임으로 보고 상태 게이트를 재적용하지 않는다 (<img> 는 인증 헤더를 실을 수
     * 없는 렌더 경로).
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @param  bool  $signatureVerified  한시 서명 검증 통과 여부
     * @return StreamedResponse|null 다운로드 응답 (없으면 null)
     */
    public function download(string $hash, bool $signatureVerified = false): ?StreamedResponse
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

        $response = $this->storageForRow($image->disk)->response(
            'images',
            $image->path,
            $image->original_filename,
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'public, max-age=31536000',
            ]
        );

        if (! $response) {
            Log::error('리뷰 이미지 스토리지에 없음', [
                'review_image_id' => $image->id,
                'path' => $image->path,
            ]);

            return null;
        }

        return $response;
    }
}
