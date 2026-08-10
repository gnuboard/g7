<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CKEditor5 이미지 서빙 서비스
 *
 * 업로드된 이미지를 hash로 조회하여 StreamedResponse를 반환합니다.
 */
class ImageServeService
{
    /**
     * @param  ImageUploadRepositoryInterface  $repository  이미지 업로드 리포지토리
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버
     */
    public function __construct(
        protected ImageUploadRepositoryInterface $repository,
        protected StorageInterface $storage
    ) {}

    /**
     * hash로 이미지 조회
     *
     * @param  string  $hash  이미지 해시 (12자)
     * @return Ckeditor5ImageUpload|null
     */
    public function findByHash(string $hash): ?Ckeditor5ImageUpload
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * 이미지 스트림 응답 생성
     *
     * @param  Ckeditor5ImageUpload  $image  이미지 업로드 모델
     * @return StreamedResponse|null 이미지 스트림 또는 스토리지 파일 없을 경우 null
     */
    public function serve(Ckeditor5ImageUpload $image): ?StreamedResponse
    {
        // file_path 형태: "images/2026/04/06/{uuid}.jpg"
        // PluginStorageDriver::response(category, path) 에서 category = 'images'
        $category = 'images';
        $relativePath = substr($image->file_path, strlen($category) + 1);

        $response = $this->storage->response(
            $category,
            $relativePath,
            $image->original_name,
            [
                'Content-Type'  => $image->mime_type,
                'Cache-Control' => 'public, max-age=31536000',
            ]
        );

        if (! $response) {
            Log::error('CKEditor5 이미지 스토리지에 없음', [
                'image_id'  => $image->id,
                'hash'      => $image->hash,
                'file_path' => $image->file_path,
                'disk'      => $image->storage_disk,
            ]);

            return null;
        }

        return $response;
    }
}
