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
        // file_path 형태: "images/2026/04/06/{uuid}.jpg" (외부 생성 행은 "ckeditor5/..." 등)
        // 첫 세그먼트 = 카테고리 일반형으로 분해 — PluginStorageDriver::response(category, path)
        [$category, $relativePath] = array_pad(explode('/', $image->file_path, 2), 2, '');

        if ($category === '' || $relativePath === '') {
            return null;
        }

        // 행에 기록된 disk 기준 서빙 — 디스크 전환 이전 행도 실제 저장 위치를 향한다.
        // 단 고아 disk(디스크를 제공하던 플러그인 비활성화로 config 에서 사라진 경우)는
        // 미등록 disk 로 withDisk 를 만들면 response 가 InvalidArgumentException 을 던져
        // 서빙이 500 이 되므로, 존재 검증 후 주입 스토리지로 폴백한다.
        $rowDisk = (string) ($image->storage_disk ?? '');
        $useRowDisk = $rowDisk !== ''
            && $rowDisk !== $this->storage->getDisk()
            && config("filesystems.disks.{$rowDisk}") !== null;

        $storage = $useRowDisk ? $this->storage->withDisk($rowDisk) : $this->storage;

        $response = $storage->response(
            $category,
            $relativePath,
            $image->original_name,
            [
                'Content-Type' => $image->mime_type,
                'Cache-Control' => 'public, max-age=31536000',
            ]
        );

        if (! $response) {
            Log::error('CKEditor5 이미지 스토리지에 없음', [
                'image_id' => $image->id,
                'hash' => $image->hash,
                'file_path' => $image->file_path,
                'disk' => $image->storage_disk,
            ]);

            return null;
        }

        return $response;
    }
}
