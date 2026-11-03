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
use Illuminate\Validation\ValidationException;
use Plugins\Sirsoft\HeroSection\Enums\MediaKind;
use Plugins\Sirsoft\HeroSection\Models\Banner;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Repositories\Contracts\BannerImageRepositoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 배너 파일(이미지·영상) 서비스 — 업로드·교체·파일 삭제·공개 서빙·고아 임시 파일 정리.
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
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];

    /**
     * 사이트에서 그대로 재생하는 영상 MIME — 업로드 검증과 같은 목록
     *
     * @var array<int, string>
     */
    private const INLINE_VIDEO_MIME_TYPES = ['video/mp4', 'video/webm'];

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
     * 이미지·영상을 임시 묶음에 올립니다 — 같은 묶음의 같은 기기·종류 파일이 있으면 교체합니다.
     *
     * 이미지는 긴 변을 줄이고 가로·세로를 잰다. 영상은 손대지 않고 스트림으로 옮긴다(가로·세로 null).
     *
     * @param  UploadedFile  $file  올린 파일 (FormRequest 가 형식·용량 검증)
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $tempKey  등록·수정 화면 임시 묶음 키
     * @param  int|null  $actorId  올린 회원 ID
     * @param  string  $kind  파일 종류 (image 기본, video)
     * @return BannerImage 올린 파일
     */
    public function upload(UploadedFile $file, string $device, string $tempKey, ?int $actorId, string $kind = 'image'): BannerImage
    {
        $file = HookManager::applyFilters('sirsoft-hero_section.banner_image.filter_upload_file', $file);

        $mime = (string) $file->getMimeType();
        $video = $kind === MediaKind::Video->value;
        $hash = Str::random(64);
        $path = date('Y/m').'/'.$hash.'.'.(self::EXTENSIONS[$mime] ?? ($video ? 'mp4' : 'jpg'));

        if ($video) {
            [$width, $height] = [null, null];
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                $this->storage->put(self::CATEGORY, $path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } else {
            app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
            [$width, $height] = $this->dimensions($file->getRealPath());

            $this->storage->put(self::CATEGORY, $path, file_get_contents($file->getRealPath()));
        }

        $previous = $this->images->findByOwnerAndDevice(null, $tempKey, $device, $kind);

        $image = DB::transaction(function () use ($tempKey, $device, $kind, $hash, $path, $file, $mime, $width, $height, $actorId) {
            return $this->images->create([
                'banner_id' => null,
                'temp_key' => $tempKey,
                'device' => $device,
                'kind' => $kind,
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
     * 등록 화면 임시 묶음에서 선택 키(기기·종류)별로 붙일 파일을 고릅니다 — 보낸 ID 가 있으면 그것(null 이면 없음), 없으면 묶음의 가장 최근 파일.
     *
     * 선택 키마다 하나만 고르므로, 교체 업로드 때 앞 파일 삭제가 실패해 묶음에 같은 파일이 둘 남아도 하나만 붙는다.
     *
     * @param  string  $tempKey  임시 묶음 키
     * @param  array<string, int|null>  $selected  선택 키(pc·mobile·pc_video·mobile_video) => 보낸 파일 ID (키가 없으면 묶음에서 고른다)
     * @return array<string, int|null> 선택 키 => 붙일 파일 ID
     */
    public function resolveTempSelection(string $tempKey, array $selected): array
    {
        $selection = [];
        foreach (MediaKind::slots() as $slot => [$device, $kind]) {
            $selection[$slot] = array_key_exists($slot, $selected)
                ? $selected[$slot]
                : $this->images->findByOwnerAndDevice(null, $tempKey, $device->value, $kind->value)?->id;
        }

        return $selection;
    }

    /**
     * 등록·수정 화면이 고른 이미지로 배너의 기기별 이미지를 맞춥니다 — 호출한 쪽 트랜잭션 안에서 부른다.
     *
     * 새 이미지는 배너에 붙이고, 지금 붙어 있던 이미지는 떼어 임시 이미지로 돌려 둔다. 떼어 낸 이미지의 파일은
     * 트랜잭션이 끝난 뒤 `deleteImage()` 로 지우며, 그때 실패해도 임시 이미지로 남아 고아 임시 이미지 정리가 다시 지운다.
     *
     * 붙일 파일은 행을 잠그고 다시 본다 — 검증은 요청 앞에서 끝나므로, 등록 저장을 연달아 누르면 먼저 커밋된 요청이 그 사이에
     * 같은 임시 파일을 가져갈 수 있다. 다른 배너에 붙었거나 사라졌으면 빼앗지 않고 저장 전체를 되돌린다(422).
     *
     * @param  Banner  $banner  대상 배너
     * @param  array<string, int|null>  $selection  선택 키(pc·mobile·pc_video·mobile_video) => 남길 파일 ID (null 이면 없음, 키가 없으면 그대로)
     * @return array{changes: array<string, string>, released: array<int, BannerImage>} 바뀐 선택 키(replaced·removed)와 떼어 낸 파일
     *
     * @throws ValidationException 고른 파일이 다른 배너에 붙었거나 없어졌을 때
     */
    public function applySelection(Banner $banner, array $selection): array
    {
        $changes = [];
        $released = [];
        $slots = MediaKind::slots();

        foreach ($selection as $slot => $imageId) {
            if (! isset($slots[$slot])) {
                continue;
            }

            [$device, $kind] = $slots[$slot];
            $current = $this->images->findByOwnerAndDevice($banner->id, null, $device->value, $kind->value);
            if ($current?->id === $imageId) {
                continue;
            }

            $next = $imageId !== null ? $this->images->findForUpdate($imageId) : null;
            if ($imageId !== null && $next === null) {
                throw ValidationException::withMessages([
                    self::selectionField($slot) => __('sirsoft-hero_section::validation.image_selection.invalid'),
                ]);
            }
            // 다른 배너에 붙었다 — 대개 저장 연타로 앞 요청이 먼저 등록한 것이라 「다시 올려 저장」 대신 이미 저장됐음을 알린다
            if ($next !== null && $next->banner_id !== null && $next->banner_id !== $banner->id) {
                throw ValidationException::withMessages([
                    self::selectionField($slot) => __('sirsoft-hero_section::validation.image_selection.taken'),
                ]);
            }

            if ($current !== null) {
                $this->images->release($current, self::RELEASED_KEY_PREFIX.$banner->id);
                $released[] = $current;
            }

            if ($next !== null) {
                $this->images->attach($next, $banner->id);
            }

            $changes[$slot] = $next !== null ? 'replaced' : 'removed';
        }

        return ['changes' => $changes, 'released' => $released];
    }

    /**
     * 선택 키의 요청 필드 이름을 반환합니다 — 검증 오류를 화면의 그 칸에 붙이려고 쓴다.
     *
     * @param  string  $slot  선택 키 (pc·mobile·pc_video·mobile_video)
     * @return string 요청 필드 (pc_image_id·mobile_image_id·pc_video_id·mobile_video_id)
     */
    private static function selectionField(string $slot): string
    {
        return in_array($slot, ['pc', 'mobile'], true) ? $slot.'_image_id' : $slot.'_id';
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
     * 이미지·영상을 공개 응답으로 내보냅니다 — 저장 전 미리보기를 위해 임시 업로드도 해시로 내보낸다(해시 64자는 추측할 수 없다).
     *
     * @param  string  $hash  공개 해시
     * @return StreamedResponse|BinaryFileResponse|null 파일 응답 (없으면 null)
     */
    public function publicResponse(string $hash): StreamedResponse|BinaryFileResponse|null
    {
        $image = $this->images->findByHash($hash);
        if ($image === null) {
            return null;
        }

        if ($image->kind === MediaKind::Video->value) {
            return $this->videoResponse($image);
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
     * 영상을 내보냅니다 — 로컬 디스크면 범위 요청(Range → 206)을 받는 파일 응답으로 낸다. iOS Safari 는 범위 요청을 받지 않는
     * 영상을 재생하지 않고, 다른 브라우저도 건너뛰기·이어 받기를 범위 요청으로 한다. 원격 디스크는 스트림으로 낸다.
     *
     * 형식은 업로드 때 내용으로 판정한 MP4·WEBM 뿐이라 인라인으로 재생하되, 내용 추측(nosniff)은 막는다.
     *
     * @param  BannerImage  $video  영상 행
     * @return StreamedResponse|BinaryFileResponse|null 영상 응답 (파일이 없으면 null)
     */
    private function videoResponse(BannerImage $video): StreamedResponse|BinaryFileResponse|null
    {
        $mime = in_array($video->mime, self::INLINE_VIDEO_MIME_TYPES, true) ? $video->mime : 'application/octet-stream';
        $cache = 'public, max-age=31536000, immutable';

        $local = $this->localPath($video);
        if ($local !== null) {
            $response = new BinaryFileResponse($local, 200, ['Content-Type' => $mime], true, null, false, true);
            $response->headers->set('Cache-Control', $cache);
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        }

        $response = $this->storage->response(self::CATEGORY, $video->path, $video->original_name, [
            'Content-Type' => $mime,
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($response === null) {
            Log::warning('히어로섹션 배너 영상 파일 없음', ['image_id' => $video->id, 'path' => $video->path]);
        }

        return $response;
    }

    /**
     * 로컬 디스크에 있는 파일의 절대 경로를 반환합니다 — 원격 디스크이거나 파일이 없으면 null.
     *
     * 경로는 업로드 때 서버가 만든 값(`Y/m/<무작위>.<확장자>`)이고 스토리지 드라이버의 기준 폴더 아래에만 붙인다.
     *
     * @param  BannerImage  $file  파일 행
     * @return string|null 절대 경로
     */
    private function localPath(BannerImage $file): ?string
    {
        try {
            $base = $this->storage->getBasePath(self::CATEGORY);
        } catch (\Throwable) {
            return null;
        }

        $path = rtrim($base, '/').'/'.$file->path;

        return str_starts_with($base, '/') && ! str_contains($file->path, '..') && is_file($path) ? $path : null;
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
