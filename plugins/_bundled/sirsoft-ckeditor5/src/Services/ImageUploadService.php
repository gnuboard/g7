<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Extension\PluginManager;
use App\Support\AssetUrl;
use App\Support\Files\StoredFileExtension;
use App\Support\ImageResizer;
use App\Support\PublicAssetDisk;
use App\Support\UploadedFileName;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailUrl;
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

        // 축소는 **같은 경로를 제자리에서 갈아 끼운다.** 그 과정이 `is_file()` 로 그 경로를
        // stat 하므로 PHP 공유 stat 캐시가 채워지고, 뒤따르는 `getSize()` 는 그 캐시를 그대로
        // 읽어 **축소 전 크기**를 돌려준다. `getimagesize()` 는 파일을 직접 읽으므로
        // width/height 만 축소본을 가리키고 크기만 어긋난다 — 한 행의 두 값이 서로 다른
        // 파일을 가리키는 상태다. 형제 넷(코어 2 · 게시판 · 페이지)은 같은 사유로 이미 이
        // 처리를 갖는다.
        clearstatcache(true, $file->getRealPath());

        // 공개 자산 디스크를 주입받았어도 「공개 디스크로 내보낼 확장자」 밖이면 플러그인 기본 디스크에
        // 저장한다 — 그 파일은 서버를 거쳐 내려간다.
        $storage = PublicAssetDisk::storageForExtension($this->storage, $extension, $this->defaultDisk());

        $storage->put('images', $path, file_get_contents($file->getRealPath()));

        $record = $this->repository->create([
            'original_name' => UploadedFileName::original($file),
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
     * 본문에 저장할 반응형 주소 묶음을 만듭니다 — CKEditor 업로드 어댑터의 `urls` 응답.
     *
     * 공개 자산 디스크(CDN)에 올라간 이미지는 본문 `<img src>` 에 직접 URL 이 박혀 썸네일 파이프를
     * 지나지 않는다. 그래서 업로드 때 숫자 규격 후보를 API 서빙 주소 + `?size=`(서버가 썸네일을 만들고
     * 공개 썸네일 디스크면 302) 로 함께 내려, 편집기가 `srcset`·`sizes` 로 본문에 저장하게 한다.
     * 원본(`default`)과 마지막 후보(원본 가로)는 직접 URL 이다 — 큰 화면이 서빙 경로를 거치지 않고 CDN 원본을 받는다.
     * 축소 후보는 사이트 절대 주소다 — 본문이 알림 메일처럼 사이트 밖에서 읽혀도 열린다.
     *
     * 빈 배열을 돌려주는 경우(응답에 `urls` 를 싣지 않음):
     * - 직접 URL 이 없는 행(`download_url === preview_url`) — 본문 렌더가 API 주소에 srcset 을 붙인다
     * - 썸네일 기능이 꺼짐(`core.thumbnail.enabled=false`)
     * - 숫자 요청 대상 규격이 하나도 없음(또는 모두 원본 가로보다 큼)
     *
     * @param  Ckeditor5ImageUpload  $image  업로드 기록
     * @param  int|null  $originalWidth  원본 가로(px). 모르면 null — 그때는 가로로 후보를 자르지 않는다
     * @return array<string, string> `[가로 문자열 => ?size= API 절대 주소, ..., 원본 가로 => 원본 주소, 'default' => 원본 주소]` 또는 빈 배열
     */
    public function responsiveUrls(Ckeditor5ImageUpload $image, ?int $originalWidth): array
    {
        $downloadUrl = $image->download_url;
        $previewUrl = $image->preview_url;

        if ($downloadUrl === $previewUrl || ! (bool) config('core.thumbnail.enabled', true)) {
            return [];
        }

        $rows = array_values(array_filter(
            app(ThumbnailPresetRegistry::class)->forFrontend(),
            fn (array $row) => ($row['numeric'] ?? false) === true
                && is_int($row['width'] ?? null)
                && $row['width'] > 0
                && ($originalWidth === null || $row['width'] <= $originalWidth)
        ));
        usort($rows, fn (array $a, array $b) => $a['width'] <=> $b['width']);

        $urls = [];

        foreach ($rows as $row) {
            $key = (string) $row['width'];

            // 같은 가로의 규격이 여럿이면 앞 것 하나만 — srcset 은 가로 기술자가 겹치면 무효가 된다
            if (isset($urls[$key])) {
                continue;
            }

            // 사이트 절대 주소로 싣는다 — 이 값은 본문 HTML 에 그대로 저장돼 알림 메일처럼 사이트 밖에서도 읽힌다
            $urls[$key] = AssetUrl::siteAbsolute(ThumbnailUrl::withSize($previewUrl, (string) $row['name']));
        }

        if ($urls === []) {
            return [];
        }

        // 마지막 후보는 원본 가로의 직접 주소다 — 원본이 필요한 큰 화면이 서빙 경로가 아니라 CDN 원본을 받게 한다.
        // 원본 가로와 같은 규격 후보가 있으면 원본으로 갈음한다(같은 가로 기술자가 겹치면 srcset 이 무효).
        // 가로를 모르면 기술자를 붙일 수 없어 축소 후보만 싣는다.
        if ($originalWidth !== null && $originalWidth > 0) {
            $urls[(string) $originalWidth] = AssetUrl::siteAbsolute($downloadUrl);
        }

        $urls['default'] = AssetUrl::siteAbsolute($downloadUrl);

        return $urls;
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
