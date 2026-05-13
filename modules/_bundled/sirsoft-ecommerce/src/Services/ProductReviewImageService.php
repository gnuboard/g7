<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;
use Modules\Sirsoft\Ecommerce\Models\ProductReviewImage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 상품 리뷰 이미지 서비스
 *
 * 리뷰 이미지 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class ProductReviewImageService
{
    /**
     * ProductReviewImageService 생성자
     *
     * @param  StorageInterface  $storage  모듈 스토리지 드라이버
     * @param  EcommerceSettingsService  $settingsService  이커머스 설정 서비스
     */
    public function __construct(
        protected StorageInterface $storage,
        protected EcommerceSettingsService $settingsService
    ) {}

    /**
     * 리뷰 이미지 업로드
     *
     * @param  UploadedFile  $file  업로드된 파일
     * @param  ProductReview  $review  리뷰 모델
     * @return ProductReviewImage 생성된 이미지
     *
     * @throws \RuntimeException 최대 업로드 수 초과 시
     */
    public function upload(UploadedFile $file, ProductReview $review): ProductReviewImage
    {
        $maxImages = (int) $this->settingsService->getSetting(
            'review.max_images',
            config('ecommerce.review.max_images', 5)
        );

        $currentCount = $review->images()->count();
        if ($currentCount >= $maxImages) {
            throw new \RuntimeException(
                __('sirsoft-ecommerce::review.image_upload_limit_exceeded', ['max' => $maxImages])
            );
        }

        HookManager::doAction('sirsoft-ecommerce.review-image.before_upload', $file, $review);

        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = "reviews/{$review->id}/{$storedFilename}";

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

        $image = ProductReviewImage::create([
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
     * @return bool
     */
    public function delete(ProductReviewImage $image): bool
    {
        HookManager::doAction('sirsoft-ecommerce.review-image.before_delete', $image);

        if ($this->storage->exists('images', $image->path)) {
            $this->storage->delete('images', $image->path);
        }

        $result = (bool) $image->delete();

        Log::info('리뷰 이미지 삭제 완료', ['image_id' => $image->id]);

        HookManager::doAction('sirsoft-ecommerce.review-image.after_delete', $image);

        return $result;
    }

    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return ProductReviewImage|null
     */
    public function findByHash(string $hash): ?ProductReviewImage
    {
        return ProductReviewImage::where('hash', $hash)->first();
    }

    /**
     * 이미지 다운로드 응답 생성
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|null
     */
    public function download(string $hash): ?StreamedResponse
    {
        $image = $this->findByHash($hash);

        if (! $image) {
            return null;
        }

        $response = $this->storage->response(
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
