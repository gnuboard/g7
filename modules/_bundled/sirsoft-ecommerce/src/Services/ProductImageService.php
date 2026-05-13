<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ProductImageCollection;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 상품 이미지 서비스
 *
 * 상품 이미지 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class ProductImageService
{
    /**
     * ProductImageService 생성자
     *
     * @param  ProductImageRepositoryInterface  $repository  상품 이미지 리포지토리
     * @param  StorageInterface  $storage  모듈 스토리지 드라이버
     * @param  ProductRepositoryInterface  $productRepository  상품 리포지토리
     */
    public function __construct(
        protected ProductImageRepositoryInterface $repository,
        protected StorageInterface $storage,
        protected ProductRepositoryInterface $productRepository
    ) {
        // StorageInterface는 EcommerceServiceProvider에서 자동 주입됨
    }

    /**
     * 단일 이미지 업로드
     *
     * product_id가 없는 경우 임시 업로드로 처리합니다.
     * 임시 업로드된 이미지는 temp_key로 식별되며, 상품 저장 시 연결됩니다.
     *
     * @param  UploadedFile  $file  업로드된 파일
     * @param  int|null  $productId  상품 ID (새 상품 생성 시 null)
     * @param  string  $collection  컬렉션명
     * @param  string|null  $tempKey  임시 업로드 키 (새 상품 생성 시 사용)
     * @param  array|null  $altText  대체 텍스트 (다국어 배열)
     * @return ProductImage 생성된 이미지
     */
    public function upload(
        UploadedFile $file,
        ?int $productId = null,
        string $collection = 'main',
        ?string $tempKey = null,
        ?array $altText = null
    ): ProductImage {
        // productId와 tempKey 모두 없으면 tempKey 자동 생성
        if (! $productId && ! $tempKey) {
            $tempKey = Str::uuid()->toString();
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-image.before_upload', $file, $productId);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('sirsoft-ecommerce.product-image.filter_upload_file', $file);

        // 저장 경로 결정
        $storedFilename = Str::uuid() . '.' . $file->getClientOriginalExtension();

        if ($productId) {
            $product = $this->productRepository->find($productId);
            $path = "products/{$product->product_code}/{$storedFilename}";
        } else {
            $path = "products/temp/{$tempKey}/{$storedFilename}";
        }

        // 스토리지에 파일 저장 (category: 'images')
        $this->storage->put('images', $path, file_get_contents($file->getRealPath()));

        // Disk 정보는 스토리지 드라이버에서 가져옴
        $disk = $this->storage->getDisk();

        // 현재 컬렉션의 최대 sort_order 조회
        $maxSortOrder = $productId
            ? $this->repository->getMaxSortOrder($productId, $collection)
            : $this->repository->getMaxSortOrderByTempKey($tempKey, $collection);

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

        // DB에 저장 (hash는 모델에서 자동 생성)
        $image = $this->repository->create([
            'product_id' => $productId,
            'temp_key' => $productId ? null : $tempKey,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'alt_text' => $altText,
            'collection' => $collection,
            'sort_order' => $maxSortOrder + 1,
            'is_thumbnail' => ($maxSortOrder === 0),
            'created_by' => Auth::id(),
        ]);

        Log::info('상품 이미지 업로드 완료', [
            'image_id' => $image->id,
            'product_id' => $productId,
            'temp_key' => $tempKey,
            'original_filename' => $image->original_filename,
            'file_size' => $image->file_size,
        ]);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-image.after_upload', $image);

        return $image;
    }

    /**
     * 임시 이미지를 상품에 연결합니다.
     *
     * 경로 패턴:
     * - 임시 경로: products/temp/{tempKey}/{filename}
     * - 정식 경로: products/{productCode}/{filename}
     *
     * StorageInterface에 move() 메서드가 없으므로 get+put+delete 조합 사용
     *
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $productId  상품 ID
     * @param  string  $productCode  상품 코드
     * @return int 연결된 이미지 수
     */
    public function linkTempImages(string $tempKey, int $productId, string $productCode): int
    {
        $tempImages = $this->repository->getByTempKey($tempKey);
        $linkedCount = 0;

        // 기존 이미지의 최대 sort_order 조회 (복사 이미지 뒤에 배치)
        $maxSortOrder = $this->repository->getMaxSortOrder($productId, 'main');

        foreach ($tempImages as $index => $image) {
            // 새 경로 생성: products/{productCode}/{filename}
            $newPath = "products/{$productCode}/{$image->stored_filename}";

            // 파일 이동 (get + put + delete)
            $content = $this->storage->get('images', $image->path);
            if ($content) {
                $this->storage->put('images', $newPath, $content);
                $this->storage->delete('images', $image->path);
            }

            // DB 업데이트: product_id 설정, temp_key 제거, path 변경, sort_order 재배치, is_thumbnail 해제
            $this->repository->update($image->id, [
                'product_id' => $productId,
                'temp_key' => null,
                'path' => $newPath,
                'sort_order' => $maxSortOrder + $index + 1,
                'is_thumbnail' => false,
            ]);

            $linkedCount++;
        }

        // 빈 임시 디렉토리 정리
        $this->storage->deleteDirectory('images', "products/temp/{$tempKey}");

        return $linkedCount;
    }

    /**
     * 임시 이미지 목록을 조회합니다.
     *
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $collection  컬렉션 필터
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTempImages(string $tempKey, ?string $collection = null)
    {
        return $this->repository->getByTempKey($tempKey, $collection);
    }

    /**
     * 해시로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return ProductImage|null
     */
    public function findByHash(string $hash): ?ProductImage
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * 이미지 삭제
     *
     * @param  int  $id  이미지 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        $image = $this->repository->findById($id);

        if (! $image) {
            return false;
        }

        // 삭제 후 재정렬을 위해 정보 저장
        $productId = $image->product_id;
        $collection = $image->collection;

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-image.before_delete', $image);

        // 스토리지에서 파일 삭제
        if ($this->storage->exists('images', $image->path)) {
            $this->storage->delete('images', $image->path);
        }

        // DB에서 삭제
        $result = $this->repository->delete($id);

        Log::info('상품 이미지 삭제 완료', [
            'image_id' => $id,
            'product_id' => $productId,
        ]);

        // 삭제 후 남은 이미지들의 순서 재정렬
        if ($result && $productId) {
            $this->reorderAfterDelete($productId, $collection);
        }

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-image.after_delete', $image);

        return $result;
    }

    /**
     * 순서 변경
     *
     * @param  array<int, int>  $orders  이미지 ID => sort_order 매핑
     * @return bool 성공 여부
     */
    public function reorder(array $orders): bool
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.product-image.before_reorder', $orders);

        $result = $this->repository->reorder($orders);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.product-image.after_reorder', $orders);

        return $result;
    }

    /**
     * 대표 이미지 설정
     *
     * @param  int  $productId  상품 ID
     * @param  int  $imageId  이미지 ID
     * @return bool 성공 여부
     */
    public function setThumbnail(int $productId, int $imageId): bool
    {
        // 기존 대표 이미지 해제
        $images = $this->repository->getByProductId($productId);
        foreach ($images as $image) {
            if ($image->is_thumbnail) {
                $this->repository->update($image->id, ['is_thumbnail' => false]);
            }
        }

        // 새 대표 이미지 설정
        $this->repository->update($imageId, ['is_thumbnail' => true]);

        return true;
    }

    /**
     * 이미지 다운로드 응답 생성
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return StreamedResponse|null 이미지 스트림 또는 없을 경우 null
     */
    public function download(string $hash): ?StreamedResponse
    {
        $image = $this->repository->findByHash($hash);

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
            Log::error('상품 이미지 스토리지에 없음', [
                'product_image_id' => $image->id,
                'path' => $image->path,
                'disk' => $this->storage->getDisk(),
            ]);

            return null;
        }

        return $response;
    }

    /**
     * 원본 이미지를 복사하여 새 상품에 연결합니다.
     *
     * 상품 복사 시 원본 이미지 파일을 새 경로로 복사하고
     * 전체 메타데이터를 포함한 새 레코드를 생성합니다.
     *
     * @param  string  $sourceHash  원본 이미지 해시
     * @param  int  $targetProductId  대상 상품 ID
     * @param  string  $targetProductCode  대상 상품 코드
     * @param  bool  $isThumbnail  대표 이미지 여부
     * @param  int  $sortOrder  정렬 순서
     * @return ProductImage|null 생성된 이미지 또는 null (원본 미존재 시)
     */
    public function copyFromSource(
        string $sourceHash,
        int $targetProductId,
        string $targetProductCode,
        bool $isThumbnail = false,
        int $sortOrder = 0
    ): ?ProductImage {
        $source = $this->repository->findByHash($sourceHash);

        if (! $source) {
            Log::warning('상품 이미지 복사 실패: 원본 이미지 없음', ['hash' => $sourceHash]);

            return null;
        }

        // 새 파일명 생성
        $extension = pathinfo($source->stored_filename, PATHINFO_EXTENSION);
        $newStoredFilename = Str::uuid().'.'.$extension;
        $newPath = "products/{$targetProductCode}/{$newStoredFilename}";

        // 파일 복사
        $content = $this->storage->get('images', $source->path);
        if ($content) {
            $this->storage->put('images', $newPath, $content);
        } else {
            Log::warning('상품 이미지 복사 실패: 원본 파일 없음', [
                'hash' => $sourceHash,
                'path' => $source->path,
            ]);

            return null;
        }

        // 새 레코드 생성 (전체 메타데이터 포함)
        return $this->repository->create([
            'product_id' => $targetProductId,
            'original_filename' => $source->original_filename,
            'stored_filename' => $newStoredFilename,
            'disk' => $source->disk,
            'path' => $newPath,
            'mime_type' => $source->mime_type,
            'file_size' => $source->file_size,
            'width' => $source->width,
            'height' => $source->height,
            'alt_text' => $source->alt_text,
            'collection' => $source->collection?->value ?? 'main',
            'is_thumbnail' => $isThumbnail,
            'sort_order' => $sortOrder,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * 상품의 모든 이미지 파일을 삭제합니다.
     *
     * 상품 이미지 폴더 전체를 삭제합니다.
     * 실제 경로: storage/app/modules/sirsoft-ecommerce/images/products/{productCode}/
     *
     * @param  int  $productId  상품 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteByProductId(int $productId): bool
    {
        $product = $this->productRepository->find($productId);

        if ($product) {
            return $this->storage->deleteDirectory('images', "products/{$product->product_code}");
        }

        // 폴백: product_id 기반 (기존 데이터 호환)
        return $this->storage->deleteDirectory('images', "products/{$productId}");
    }

    /**
     * 상품의 이미지 목록을 조회합니다.
     *
     * @param  int  $productId  상품 ID
     * @param  string|null  $collection  컬렉션 필터
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getImages(int $productId, ?string $collection = null)
    {
        return $this->repository->getByProductId($productId, $collection);
    }

    /**
     * 업로드된 이미지들을 롤백(삭제)합니다.
     *
     * 상품 저장 실패 시 업로드된 이미지들을 정리하기 위해 사용됩니다.
     *
     * @param  array<int>  $imageIds  이미지 ID 배열
     * @return void
     */
    public function rollbackUploadedImages(array $imageIds): void
    {
        foreach ($imageIds as $id) {
            $this->delete($id);
        }
    }

    /**
     * 삭제 후 남은 이미지들의 순서를 재정렬합니다.
     *
     * @param  int  $productId  상품 ID
     * @param  string  $collection  컬렉션명
     */
    protected function reorderAfterDelete(int $productId, string|ProductImageCollection $collection): void
    {
        $collectionValue = $collection instanceof ProductImageCollection ? $collection->value : $collection;
        $images = $this->repository->getByProductId($productId, $collectionValue);

        $orders = [];
        foreach ($images as $index => $image) {
            $orders[$image->id] = $index + 1;
        }

        if (! empty($orders)) {
            $this->repository->reorder($orders);
        }
    }
}
