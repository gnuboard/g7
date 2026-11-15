<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Support\Files\StoredFileExtension;
use App\Support\ImageResizer;
use App\Support\PublicAssetDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
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
        HookManager::doAction('sirsoft-ckeditor5.image.before_upload', $file, $uploadedBy);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트).
        // 잘못 작성된 리스너가 UploadedFile 이 아닌 값을 반환하면 원본으로 폴백한다 —
        // 확장 결함이 코어 업로드 전면 장애(TypeError → 500)로 위장되면 안 된다.
        $filtered = HookManager::applyFilters('sirsoft-ckeditor5.image.filter_upload_file', $file);
        if ($filtered instanceof UploadedFile) {
            $file = $filtered;
        } else {
            Log::warning('sirsoft-ckeditor5.image.filter_upload_file 필터가 UploadedFile 이 아닌 값을 반환해 원본을 사용합니다.', [
                'returned_type' => get_debug_type($filtered),
            ]);
        }

        $extension = StoredFileExtension::resolve($file, 'jpg');
        $storedFilename = Str::uuid().'.'.$extension;
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
        $path = date('Y/m/d').'/'.$storedFilename;

        // 환경설정 > 업로드의 최대 가로/세로·품질 적용 (코어 설정이 모든 업로드 경로에 동일 적용)
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

        // 공개 자산 디스크를 주입받았어도 「공개 디스크로 내보낼 확장자」 밖이면 플러그인 기본 디스크에
        // 저장한다 — 그 파일은 서버를 거쳐 내려간다.
        $storage = PublicAssetDisk::storageForExtension($this->storage, $extension, $this->defaultDisk());

        $storage->put('images', $path, file_get_contents($file->getRealPath()));

        $record = $this->repository->create([
            'original_name' => $file->getClientOriginalName(),
            'file_path' => "images/{$path}",
            'storage_disk' => $storage->getDisk(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => $uploadedBy,
        ]);

        HookManager::doAction('sirsoft-ckeditor5.image.after_upload', $record);

        return $record;
    }

    /**
     * 플러그인 기본 디스크 이름을 반환합니다(공개 디스크로 내보낼 수 없는 파일의 저장 자리).
     *
     * @return string 디스크 이름
     */
    private function defaultDisk(): string
    {
        $plugin = app(PluginManager::class)->getPlugin('sirsoft-ckeditor5');

        return $plugin?->getStorageDisk() ?? 'plugins';
    }
}
