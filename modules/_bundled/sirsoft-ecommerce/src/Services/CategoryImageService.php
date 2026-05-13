<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Models\CategoryImage;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 카테고리 이미지 서비스
 *
 * 카테고리 이미지 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class CategoryImageService
{
    /**
     * CategoryImageService 생성자
     *
     * @param CategoryImageRepositoryInterface $repository 카테고리 이미지 리포지토리
     * @param StorageInterface $storage 모듈 스토리지 드라이버
     */
    public function __construct(
        private CategoryImageRepositoryInterface $repository,
        private StorageInterface $storage
    ) {}

    /**
     * 단일 이미지 업로드
     *
     * category_id가 없는 경우 임시 업로드로 처리합니다.
     * 임시 업로드된 이미지는 temp_key로 식별되며, 카테고리 저장 시 연결됩니다.
     *
     * @param UploadedFile $file 업로드된 파일
     * @param int|null $categoryId 카테고리 ID (새 카테고리 생성 시 null)
     * @param string $collection 컬렉션명
     * @param string|null $tempKey 임시 업로드 키 (새 카테고리 생성 시 사용)
     * @param array|null $altText 대체 텍스트 (다국어 배열)
     * @return CategoryImage 생성된 이미지
     */
    public function upload(
        UploadedFile $file,
        ?int $categoryId = null,
        string $collection = 'main',
        ?string $tempKey = null,
        ?array $altText = null
    ): CategoryImage {
        // categoryId와 tempKey 모두 없으면 tempKey 자동 생성
        if (!$categoryId && !$tempKey) {
            $tempKey = Str::uuid()->toString();
        }

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.before_upload', $file, $categoryId);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('sirsoft-ecommerce.category-image.filter_upload_file', $file);

        // 저장 경로 생성 (category + 날짜별 디렉토리)
        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $datePath = date('Y/m/d');
        $path = "category/{$datePath}/{$storedFilename}";

        // 스토리지에 파일 저장 (category: 'images')
        $this->storage->put('images', $path, file_get_contents($file->getRealPath()));

        // Disk 정보는 스토리지 드라이버에서 가져옴
        $disk = $this->storage->getDisk();

        // 현재 컬렉션의 최대 sort_order 조회
        $maxSortOrder = $categoryId
            ? $this->repository->getMaxSortOrder($categoryId, $collection)
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
            'category_id' => $categoryId,
            'temp_key' => $categoryId ? null : $tempKey,
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
            'created_by' => Auth::id(),
        ]);

        Log::info('카테고리 이미지 업로드 완료', [
            'image_id' => $image->id,
            'category_id' => $categoryId,
            'temp_key' => $tempKey,
            'original_filename' => $image->original_filename,
            'file_size' => $image->file_size,
        ]);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.after_upload', $image);

        return $image;
    }

    /**
     * 임시 이미지를 카테고리에 연결합니다.
     *
     * @param string $tempKey 임시 업로드 키
     * @param int $categoryId 카테고리 ID
     * @return int 연결된 이미지 수
     */
    public function linkTempImages(string $tempKey, int $categoryId): int
    {
        return $this->repository->linkTempImages($tempKey, $categoryId);
    }

    /**
     * 임시 이미지 목록을 조회합니다.
     *
     * @param string $tempKey 임시 업로드 키
     * @param string|null $collection 컬렉션 필터
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTempImages(string $tempKey, ?string $collection = null)
    {
        return $this->repository->getByTempKey($tempKey, $collection);
    }

    /**
     * 해시로 이미지 조회
     *
     * @param string $hash 이미지 해시
     * @return CategoryImage|null 이미지 또는 null
     */
    public function getByHash(string $hash): ?CategoryImage
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * 이미지 다운로드 응답 생성
     *
     * @param string $hash 이미지 해시 (12자)
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
            Log::error('카테고리 이미지 스토리지에 없음', [
                'category_image_id' => $image->id,
                'path' => $image->path,
                'disk' => $this->storage->getDisk(),
            ]);

            return null;
        }

        return $response;
    }

    /**
     * 이미지 삭제
     *
     * @param int $id 이미지 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        $image = $this->repository->findById($id);

        if (! $image) {
            return false;
        }

        // 삭제 후 재정렬을 위해 정보 저장
        $categoryId = $image->category_id;
        $collection = $image->collection;

        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.before_delete', $image);

        // 스토리지에서 파일 삭제
        // DB에 저장된 경로: category/{date}/{filename}
        // storage->exists/delete에 category를 전달하면 자동으로 images/ 추가됨
        if ($this->storage->exists('images', $image->path)) {
            $this->storage->delete('images', $image->path);
        }

        // DB에서 삭제
        $result = $this->repository->delete($id);

        Log::info('카테고리 이미지 삭제 완료', [
            'image_id' => $id,
            'category_id' => $categoryId,
        ]);

        // 삭제 후 남은 이미지들의 순서 재정렬
        if ($result && $categoryId) {
            $this->reorderAfterDelete($categoryId, $collection);
        }

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.after_delete', $image);

        return $result;
    }

    /**
     * 순서 변경
     *
     * @param array<int, int> $orders 이미지 ID => sort_order 매핑
     * @return bool 성공 여부
     */
    public function reorder(array $orders): bool
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.before_reorder', $orders);

        $result = $this->repository->reorder($orders);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.after_reorder', $orders);

        return $result;
    }

    /**
     * 삭제 후 남은 이미지들의 순서를 재정렬합니다.
     *
     * @param int $categoryId 카테고리 ID
     * @param string $collection 컬렉션명
     */
    protected function reorderAfterDelete(int $categoryId, string $collection): void
    {
        $images = $this->repository->getByCategoryId($categoryId, $collection);

        $orders = [];
        foreach ($images as $index => $image) {
            $orders[$image->id] = $index + 1;
        }

        if (! empty($orders)) {
            $this->repository->reorder($orders);
        }
    }

    /**
     * 카테고리의 이미지 목록을 조회합니다.
     *
     * @param int $categoryId 카테고리 ID
     * @param string|null $collection 컬렉션 필터
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getImages(int $categoryId, ?string $collection = null)
    {
        return $this->repository->getByCategoryId($categoryId, $collection);
    }

    /**
     * 업로드된 이미지들을 롤백(삭제)합니다.
     *
     * 카테고리 저장 실패 시 업로드된 이미지들을 정리하기 위해 사용됩니다.
     *
     * @param array<int> $imageIds 이미지 ID 배열
     * @return void
     */
    public function rollbackUploadedImages(array $imageIds): void
    {
        foreach ($imageIds as $id) {
            $this->delete($id);
        }
    }

    /**
     * 이미지 정보 업데이트 (대체 텍스트 등)
     *
     * @param int $id 이미지 ID
     * @param array $data 업데이트할 데이터
     * @return CategoryImage 업데이트된 이미지
     */
    public function update(int $id, array $data): CategoryImage
    {
        // Before 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.before_update', $id, $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('sirsoft-ecommerce.category-image.filter_update_data', $data);

        $image = $this->repository->update($id, $data);

        Log::info('카테고리 이미지 업데이트 완료', [
            'image_id' => $id,
            'category_id' => $image->category_id,
        ]);

        // After 훅
        HookManager::doAction('sirsoft-ecommerce.category-image.after_update', $image);

        return $image;
    }
}
