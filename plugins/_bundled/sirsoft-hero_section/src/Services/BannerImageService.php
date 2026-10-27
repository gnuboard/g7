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
use Plugins\Sirsoft\HeroSection\Enums\BannerDevice;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 배너 이미지 서비스 — 업로드·교체·파일 삭제·공개 서빙·고아 임시 이미지 정리.
 *
 * 업로드는 언제나 임시 묶음(temp_key)에 들어가고, 배너에는 등록·수정을 저장할 때만 붙는다 — 저장하지 않고
 * 나가면 사이트 화면은 그대로다. 파일은 플러그인 스토리지(카테고리 `images`)에 두고, 행보다 파일을 먼저 지운다 —
 * 행만 지우고 파일 삭제가 실패하면 아무도 찾지 못하는 고아 파일이 남기 때문이다.
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
     * 배너에서 떼어 낸 이미지를 돌려 두는 임시 묶음 키 접두 (+ 배너 ID)
     */
    private const RELEASED_KEY_PREFIX = 'released:';

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
     * 이미지를 임시 묶음에 올립니다 — 같은 묶음의 같은 기기 이미지가 있으면 교체합니다.
     *
     * @param  UploadedFile  $file  올린 파일 (FormRequest 가 형식·용량 검증)
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $tempKey  등록·수정 화면 임시 묶음 키
     * @param  int|null  $actorId  올린 회원 ID
     * @return BannerImage 올린 이미지
     */
    public function upload(UploadedFile $file, string $device, string $tempKey, ?int $actorId): BannerImage
    {
        $file = HookManager::applyFilters('sirsoft-hero_section.banner_image.filter_upload_file', $file);

        $mime = (string) $file->getMimeType();
        $hash = Str::random(64);
        $path = date('Y/m').'/'.$hash.'.'.(self::EXTENSIONS[$mime] ?? 'jpg');

        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
        [$width, $height] = $this->dimensions($file->getRealPath());

        $this->storage->put(self::CATEGORY, $path, file_get_contents($file->getRealPath()));

        $previous = $this->images->findByOwnerAndDevice(null, $tempKey, $device);

        $image = DB::transaction(function () use ($tempKey, $device, $hash, $path, $file, $mime, $width, $height, $actorId) {
            return $this->images->create([
                'banner_id' => null,
                'temp_key' => $tempKey,
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

        HookManager::doAction('sirsoft-hero_section.banner_image.after_upload', $image);

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
     * 등록 화면 임시 묶음에서 기기별로 붙일 이미지를 고릅니다 — 보낸 ID 가 있으면 그것(null 이면 없음), 없으면 묶음의 가장 최근 이미지.
     *
     * 기기마다 한 장만 고르므로, 교체 업로드 때 앞 이미지 삭제가 실패해 묶음에 같은 기기 이미지가 둘 남아도 하나만 붙는다.
     *
     * @param  string  $tempKey  임시 묶음 키
     * @param  array<string, int|null>  $selected  기기 => 보낸 이미지 ID (키가 없으면 묶음에서 고른다)
     * @return array<string, int|null> 기기 => 붙일 이미지 ID
     */
    public function resolveTempSelection(string $tempKey, array $selected): array
    {
        $selection = [];
        foreach (BannerDevice::cases() as $device) {
            $selection[$device->value] = array_key_exists($device->value, $selected)
                ? $selected[$device->value]
                : $this->images->findByOwnerAndDevice(null, $tempKey, $device->value)?->id;
        }

        return $selection;
    }

    /**
     * 등록·수정 화면이 고른 이미지로 배너의 기기별 이미지를 맞춥니다 — 호출한 쪽 트랜잭션 안에서 부른다.
     *
     * 새 이미지는 배너에 붙이고, 지금 붙어 있던 이미지는 떼어 임시 이미지로 돌려 둔다. 떼어 낸 이미지의 파일은
     * 트랜잭션이 끝난 뒤 `deleteImage()` 로 지우며, 그때 실패해도 임시 이미지로 남아 고아 임시 이미지 정리가 다시 지운다.
     *
     * @param  Banner  $banner  대상 배너
     * @param  array<string, int|null>  $selection  기기 => 남길 이미지 ID (null 이면 그 기기 이미지 없음, 키가 없으면 그대로)
     * @return array{changes: array<string, string>, released: array<int, BannerImage>} 바뀐 기기(replaced·removed)와 떼어 낸 이미지
     */
    public function applySelection(Banner $banner, array $selection): array
    {
        $changes = [];
        $released = [];

        foreach ($selection as $device => $imageId) {
            $current = $this->images->findByOwnerAndDevice($banner->id, null, $device);
            if ($current?->id === $imageId) {
                continue;
            }

            if ($current !== null) {
                $this->images->release($current, self::RELEASED_KEY_PREFIX.$banner->id);
                $released[] = $current;
            }

            $next = $imageId !== null ? $this->images->find($imageId) : null;
            if ($next !== null) {
                $this->images->attach($next, $banner->id);
            }

            $changes[$device] = $next !== null ? 'replaced' : 'removed';
        }

        return ['changes' => $changes, 'released' => $released];
    }

    /**
     * 지울 배너들의 이미지를 떼어 임시 이미지로 돌립니다 — 호출한 쪽 트랜잭션 안에서, 배너 행을 지우기 전에 부른다.
     *
     * 배너 행이 지워지면 이미지 행도 외래 키로 함께 지워지므로, 떼지 않으면 파일 삭제가 실패했을 때 아무도 찾지 못하는 파일이 남는다.
     * 돌려준 이미지는 트랜잭션이 끝난 뒤 `deleteImage()` 로 지우고, 실패분은 고아 임시 이미지 정리가 다시 지운다.
     *
     * @param  array<int, int>  $bannerIds  배너 ID 목록
     * @return array<int, BannerImage> 떼어 낸 이미지
     */
    public function releaseForBanners(array $bannerIds): array
    {
        $released = [];
        foreach ($this->images->forBanners($bannerIds) as $image) {
            $this->images->release($image, self::RELEASED_KEY_PREFIX.$image->banner_id);
            $released[] = $image;
        }

        return $released;
    }

    /**
     * 이미지를 공개 응답으로 내보냅니다 — 저장 전 미리보기를 위해 임시 업로드도 해시로 내보낸다(해시 64자는 추측할 수 없다).
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|null 이미지 응답 (없으면 null)
     */
    public function publicResponse(string $hash): ?StreamedResponse
    {
        $image = $this->images->findByHash($hash);
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
