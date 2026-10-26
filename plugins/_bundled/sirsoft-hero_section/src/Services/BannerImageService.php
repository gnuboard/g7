<?php

namespace Plugins\Sirsoft\HeroSection\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Support\ImageResizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 배너 이미지 서비스 — 업로드·교체·파일 삭제·공개 서빙·고아 임시 이미지 정리.
 *
 * 파일은 플러그인 스토리지(카테고리 `images`)에 두고, 행보다 파일을 먼저 지운다 — 행만 지우고 파일 삭제가
 * 실패하면 아무도 찾지 못하는 고아 파일이 남기 때문이다.
 */
class BannerImageService
{
    /**
     * 스토리지 카테고리
     */
    private const CATEGORY = 'images';

    /**
     * 허용 MIME → 저장 확장자
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * BannerImageService 생성자
     *
     * @param  BannerImageRepositoryInterface  $images  이미지 Repository
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버
     */
    public function __construct(
        private BannerImageRepositoryInterface $images,
        private StorageInterface $storage,
    ) {}

    /**
     * 이미지를 올립니다 — 같은 배너(또는 임시 묶음)의 같은 기기 이미지가 있으면 교체합니다.
     *
     * @param  UploadedFile  $file  올린 파일 (FormRequest 가 형식·용량 검증)
     * @param  string  $device  기기 (pc, mobile)
     * @param  string|null  $tempKey  등록 화면 임시 묶음 키
     * @param  Banner|null  $banner  수정 화면의 대상 배너
     * @param  int|null  $actorId  올린 회원 ID
     * @return BannerImage 올린 이미지
     */
    public function upload(UploadedFile $file, string $device, ?string $tempKey, ?Banner $banner, ?int $actorId): BannerImage
    {
        $file = HookManager::applyFilters('sirsoft-hero_section.banner_image.filter_upload_file', $file);

        $mime = (string) $file->getMimeType();
        $hash = Str::random(64);
        $path = date('Y/m').'/'.$hash.'.'.(self::EXTENSIONS[$mime] ?? 'jpg');

        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
        [$width, $height] = $this->dimensions($file->getRealPath());

        $this->storage->put(self::CATEGORY, $path, file_get_contents($file->getRealPath()));

        $previous = $this->images->findByOwnerAndDevice($banner?->id, $banner ? null : $tempKey, $device);

        $image = DB::transaction(function () use ($banner, $tempKey, $device, $hash, $path, $file, $mime, $width, $height, $actorId) {
            return $this->images->create([
                'banner_id' => $banner?->id,
                'temp_key' => $banner ? null : $tempKey,
                'device' => $device,
                'hash' => $hash,
                'disk' => $this->storage->getDisk(),
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'width' => $width,
                'height' => $height,
                'created_by' => $actorId,
            ]);
        });

        if ($previous !== null) {
            $this->deleteImage($previous);
        }

        HookManager::doAction('sirsoft-hero_section.banner_image.after_upload', $image, $banner);

        return $image;
    }

    /**
     * 이미지 파일과 행을 지웁니다(파일 먼저).
     *
     * @param  BannerImage  $image  대상 이미지
     * @return bool 파일·행 모두 지웠으면 true (파일 삭제가 실패하면 행을 남기고 false)
     */
    public function deleteImage(BannerImage $image): bool
    {
        if (! $this->deleteFile($image)) {
            return false;
        }

        $snapshot = $image->toArray();
        $deleted = $this->images->delete($image);
        if ($deleted) {
            HookManager::doAction('sirsoft-hero_section.banner_image.after_delete', $snapshot);
        }

        return $deleted;
    }

    /**
     * 배너들의 이미지 파일을 모두 지웁니다(행은 배너 삭제 때 함께 지워진다).
     *
     * @param  array<int, int>  $bannerIds  배너 ID 목록
     * @return int 지운 파일 수
     */
    public function deleteFilesForBanners(array $bannerIds): int
    {
        $deleted = 0;
        foreach ($this->images->forBanners($bannerIds) as $image) {
            if ($this->deleteFile($image)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 배너에 연결된 이미지를 공개 응답으로 내보냅니다.
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|null 이미지 응답 (없으면 null)
     */
    public function publicResponse(string $hash): ?StreamedResponse
    {
        $image = $this->images->findPublicByHash($hash);
        if ($image === null) {
            return null;
        }

        $response = $this->storage->response(self::CATEGORY, $image->path, $image->original_name, [
            'Content-Type' => $image->mime,
            // 해시가 바뀌지 않는 한 내용도 바뀌지 않는다(교체하면 새 해시)
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        if ($response === null) {
            Log::warning('히어로섹션 배너 이미지 파일 없음', ['image_id' => $image->id, 'path' => $image->path]);

            return null;
        }

        // 인라인은 래스터 이미지만, 그 외는 다운로드 + nosniff
        InlineMediaPolicy::harden($response, $image->mime, $image->original_name);

        return $response;
    }

    /**
     * 끝내 배너에 연결되지 않은 임시 이미지를 정리합니다.
     *
     * @param  int  $days  보존 기간(일) — 이보다 오래된 임시 이미지만 대상
     * @param  int  $limit  한 번에 처리할 최대 건수
     * @param  bool  $dryRun  true 면 세기만 하고 지우지 않음
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function pruneTempUploads(int $days, int $limit, bool $dryRun): array
    {
        $targets = $this->images->staleTempImages(now()->subDays($days), $limit);
        $result = ['scanned' => $targets->count(), 'deleted' => 0, 'failed' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($targets as $image) {
            if ($this->deleteImage($image)) {
                $result['deleted']++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * 이 플러그인 이미지 폴더를 통째로 비웁니다(플러그인을 데이터까지 삭제할 때만).
     *
     * @return bool 비웠으면 true
     */
    public function purgeAll(): bool
    {
        try {
            return $this->storage->deleteAll(self::CATEGORY);
        } catch (\Throwable $e) {
            Log::warning('히어로섹션 이미지 폴더 정리 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 파일만 지웁니다 — 이미 없는 파일은 지운 것으로 봅니다.
     *
     * @param  BannerImage  $image  대상 이미지
     * @return bool 파일이 없거나 지웠으면 true
     */
    private function deleteFile(BannerImage $image): bool
    {
        try {
            if (! $this->storage->exists(self::CATEGORY, $image->path)) {
                return true;
            }

            return $this->storage->delete(self::CATEGORY, $image->path);
        } catch (\Throwable $e) {
            Log::warning('히어로섹션 배너 이미지 파일 삭제 실패', ['image_id' => $image->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 이미지 가로·세로 픽셀을 잽니다.
     *
     * @param  string  $path  파일 절대 경로
     * @return array{0: int|null, 1: int|null} [가로, 세로]
     */
    private function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        return $size ? [(int) $size[0], (int) $size[1]] : [null, null];
    }
}
