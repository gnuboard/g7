<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Extension\StorageInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageUploadRepositoryInterface;

/**
 * CKEditor5 이미지 업로드 서비스
 *
 * 이미지를 plugins 디스크에 저장하고 업로드 기록을 생성합니다.
 */
class ImageUploadService
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
     * 이미지를 업로드하고 기록을 저장합니다.
     *
     * @param  UploadedFile  $file  업로드된 파일
     * @param  int|null  $uploadedBy  업로드 사용자 ID
     * @return Ckeditor5ImageUpload 생성된 업로드 기록
     */
    public function upload(UploadedFile $file, ?int $uploadedBy): Ckeditor5ImageUpload
    {
        // 훅: 이미지 업로드 전 (본인인증 등 확장 지점)
        \App\Extension\HookManager::doAction('sirsoft-ckeditor5.image.before_upload', $file, $uploadedBy);

        $extension = $file->guessExtension() ?? $file->getClientOriginalExtension();
        $storedFilename = Str::uuid().'.'.$extension;
        $path = date('Y/m/d').'/'.$storedFilename;

        $this->storage->put('images', $path, file_get_contents($file->getRealPath()));

        $record = $this->repository->create([
            'original_name' => $file->getClientOriginalName(),
            'file_path'     => "images/{$path}",
            'storage_disk'  => $this->storage->getDisk(),
            'file_size'     => $file->getSize(),
            'mime_type'     => $file->getMimeType(),
            'uploaded_by'   => $uploadedBy,
        ]);

        \App\Extension\HookManager::doAction('sirsoft-ckeditor5.image.after_upload', $record);

        return $record;
    }
}
