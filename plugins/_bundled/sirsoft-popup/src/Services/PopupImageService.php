<?php

namespace Plugins\Sirsoft\Popup\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Support\ImageResizer;
use App\Support\UploadedFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Enums\ImageDevice;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageAttachedException;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageCopyException;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageNotOwnedException;
use Plugins\Sirsoft\Popup\Exceptions\PopupImageUploadException;
use Plugins\Sirsoft\Popup\Models\Popup;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Repositories\Contracts\PopupImageRepositoryInterface;

/**
 * 팝업 이미지 서비스 — 업로드·교체·파일 삭제·공개 서빙·고아 임시 이미지 정리.
 *
 * 업로드는 언제나 임시 묶음(temp_key)에 들어가고, 팝업에는 등록·수정을 저장할 때만 붙는다 — 저장하지 않고
 * 나가면 사이트 화면은 그대로다. 파일은 플러그인 스토리지(카테고리 `images`)에 두고, 행보다 파일을 먼저 지운다 —
 * 행만 지우고 파일 삭제가 실패하면 아무도 찾지 못하는 고아 파일이 남기 때문이다.
 */
class PopupImageService
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
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /**
     * 팝업에서 떼어 낸 이미지를 돌려 두는 임시 묶음 키 접두 (+ 팝업 ID)
     */
    private const RELEASED_KEY_PREFIX = 'released:';

    /**
     * PopupImageService 생성자
     *
     * @param  PopupImageRepositoryInterface  $images  이미지 Repository
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버
     */
    public function __construct(
        private PopupImageRepositoryInterface $images,
        private StorageInterface $storage,
    ) {}

    /**
     * 이미지를 임시 묶음에 올립니다 — 같은 묶음의 같은 기기 이미지가 있으면 교체합니다.
     *
     * @param  UploadedFile  $file  올린 파일 (FormRequest 가 형식·용량 검증)
     * @param  string  $device  기기 (pc, mobile)
     * @param  string  $tempKey  등록·수정 화면 임시 묶음 키
     * @param  int|null  $actorId  올린 회원 ID
     * @return PopupImage 올린 이미지
     *
     * @throws PopupImageUploadException 파일·조회·행 저장 실패 시(정리 실패분은 대장에 남겨 재시도)
     */
    public function upload(UploadedFile $file, string $device, string $tempKey, ?int $actorId): PopupImage
    {
        $file = HookManager::applyFilters('sirsoft-popup.popup_image.filter_upload_file', $file);

        $mime = (string) $file->getMimeType();
        $hash = Str::random(64);
        $path = date('Y/m').'/'.$hash.'.'.(self::EXTENSIONS[$mime] ?? 'jpg');

        // GIF 는 다시 저장하면 움직임이 사라지므로 줄이지 않는다(크기 상한은 업로드 검증이 막는다)
        if ($mime !== 'image/gif') {
            app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $mime);
        }
        [$width, $height] = $this->dimensions($file->getRealPath());

        $pending = app(PendingImageFiles::class);
        $tracked = false;
        $disk = null;
        try {
            $disk = $this->storage->getDisk();
            $pending->remember($hash, $path, $disk);
            $tracked = true;
            if (! $this->storage->put(self::CATEGORY, $path, file_get_contents($file->getRealPath()))) {
                throw new PopupImageUploadException;
            }
            $previous = $this->images->findByOwnerAndDevice(null, $tempKey, $device);
            $image = DB::transaction(fn () => $this->images->create([
                'popup_id' => null,
                'temp_key' => $tempKey,
                'device' => $device,
                'hash' => $hash,
                'disk' => $this->storage->getDisk(),
                'path' => $path,
                'original_name' => mb_substr(UploadedFileName::original($file), 0, 255),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'width' => $width,
                'height' => $height,
                'created_by' => $actorId,
            ]));
        } catch (\Throwable $e) {
            $cleaned = $tracked && $this->deletePath($path, ['hash' => $hash]);
            if ($cleaned) {
                $pending->forget($hash);
            }
            Log::error('팝업 이미지 업로드 실패', [
                'hash' => $hash, 'path' => $path, 'disk' => $disk,
                'cleanup' => $cleaned ? 'removed_or_absent' : ($tracked ? 'pending_retry' : 'write_not_started'),
                'exception' => $e::class,
            ]);

            throw new PopupImageUploadException($e);
        }
        $pending->forget($hash);

        if ($previous !== null) {
            $this->deleteImage($previous);
        }

        HookManager::doAction('sirsoft-popup.popup_image.after_upload', $image);

        return $image;
    }

    /**
     * 이미지 파일과 행을 지웁니다(파일 먼저).
     *
     * @param  PopupImage  $image  대상 이미지
     * @return bool 파일·행 모두 지웠으면 true (파일 삭제가 실패하면 행을 남기고 false)
     */
    public function deleteImage(PopupImage $image): bool
    {
        if (! $this->deleteFile($image)) {
            return false;
        }

        $snapshot = $image->toArray();
        $deleted = $this->images->delete($image);
        if ($deleted) {
            HookManager::doAction('sirsoft-popup.popup_image.after_delete', $snapshot);
        }

        return $deleted;
    }

    /**
     * 아직 팝업에 붙지 않은 임시 이미지를 지웁니다 — 자기가 올린 것만(관리 화면의 임시 이미지 삭제).
     *
     * @param  PopupImage  $image  대상 이미지
     * @param  int|null  $actorId  요청한 회원 ID
     * @return bool 파일·행 모두 지웠으면 true
     *
     * @throws PopupImageAttachedException 팝업에 붙은 이미지일 때 — 붙은 이미지는 팝업 저장만 바꾼다
     * @throws PopupImageNotOwnedException 다른 관리자가 올린 이미지일 때 — 그 관리자의 저장 전 화면을 깨지 않게
     */
    public function deleteTempImage(PopupImage $image, ?int $actorId): bool
    {
        if ($image->popup_id !== null) {
            throw new PopupImageAttachedException($image->id);
        }
        if ($actorId === null || $image->created_by !== $actorId) {
            throw new PopupImageNotOwnedException($image->id);
        }

        return $this->deleteImage($image);
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
        foreach (ImageDevice::cases() as $device) {
            $selection[$device->value] = array_key_exists($device->value, $selected)
                ? $selected[$device->value]
                : $this->images->findByOwnerAndDevice(null, $tempKey, $device->value)?->id;
        }

        return $selection;
    }

    /**
     * 등록·수정 화면이 고른 이미지로 팝업의 기기별 이미지를 맞춥니다 — 호출한 쪽 트랜잭션 안에서 부른다.
     *
     * 새 이미지는 팝업에 붙이고, 지금 붙어 있던 이미지는 떼어 임시 이미지로 돌려 둔다. 떼어 낸 이미지의 파일은
     * 트랜잭션이 끝난 뒤 `deleteImage()` 로 지우며, 그때 실패해도 임시 이미지로 남아 고아 임시 이미지 정리가 다시 지운다.
     *
     * @param  Popup  $popup  대상 팝업
     * @param  array<string, int|null>  $selection  기기 => 남길 이미지 ID (null 이면 그 기기 이미지 없음, 키가 없으면 그대로)
     * @return array{changes: array<string, string>, released: array<int, PopupImage>} 바뀐 기기(replaced·removed)와 떼어 낸 이미지
     */
    public function applySelection(Popup $popup, array $selection): array
    {
        $changes = [];
        $released = [];

        foreach ($selection as $device => $imageId) {
            $current = $this->images->findByOwnerAndDevice($popup->id, null, $device);
            if ($current?->id === $imageId) {
                continue;
            }

            if ($current !== null) {
                $this->images->release($current, self::RELEASED_KEY_PREFIX.$popup->id);
                $released[] = $current;
            }

            $next = $imageId !== null ? $this->images->find($imageId) : null;
            if ($next !== null) {
                $this->images->attach($next, $popup->id);
            }

            $changes[$device] = $next !== null ? 'replaced' : 'removed';
        }

        return ['changes' => $changes, 'released' => $released];
    }

    /**
     * 지울 팝업들의 이미지를 떼어 임시 이미지로 돌립니다 — 호출한 쪽 트랜잭션 안에서, 팝업 행을 지우기 전에 부른다.
     *
     * 팝업 행이 지워지면 이미지 행도 외래 키로 함께 지워지므로, 떼지 않으면 파일 삭제가 실패했을 때 아무도 찾지 못하는 파일이 남는다.
     * 돌려준 이미지는 트랜잭션이 끝난 뒤 `deleteImage()` 로 지우고, 실패분은 고아 임시 이미지 정리가 다시 지운다.
     *
     * @param  array<int, int>  $popupIds  팝업 ID 목록
     * @return array<int, PopupImage> 떼어 낸 이미지
     */
    public function releaseForPopups(array $popupIds): array
    {
        $released = [];
        foreach ($this->images->forPopups($popupIds) as $image) {
            $this->images->release($image, self::RELEASED_KEY_PREFIX.$image->popup_id);
            $released[] = $image;
        }

        return $released;
    }

    /**
     * 이미지 파일을 새 해시로 복제해 다른 팝업에 붙입니다(복사) — 원본을 지워도 복사본이 깨지지 않게 파일까지 따로 둔다.
     *
     * 호출한 쪽 트랜잭션 안에서 부른다. 파일을 읽거나 쓰지 못하면 예외를 던져 그 팝업 복사를 되돌리게 하고,
     * 이미 쓴 파일은 호출한 쪽이 `discardFile()` 로 지운다(행은 트랜잭션과 함께 되돌려진다).
     *
     * @param  PopupImage  $source  원본 이미지
     * @param  int  $popupId  복사본 팝업 ID
     * @param  int|null  $actorId  복사한 회원 ID
     * @return PopupImage 복제한 이미지
     *
     * @throws PopupImageCopyException 원본 파일을 읽거나 새 파일을 쓰지 못했을 때
     */
    public function duplicate(PopupImage $source, int $popupId, ?int $actorId): PopupImage
    {
        $contents = $this->storage->get(self::CATEGORY, $source->path);
        if ($contents === null || $contents === false) {
            throw PopupImageCopyException::sourceMissing($source->id);
        }

        $hash = Str::random(64);
        $path = date('Y/m').'/'.$hash.'.'.(self::EXTENSIONS[$source->mime] ?? 'jpg');
        $pending = app(PendingImageFiles::class);
        $tracked = false;
        $disk = null;
        try {
            $disk = $this->storage->getDisk();
            $pending->remember($hash, $path, $disk);
            $tracked = true;
            if (! $this->storage->put(self::CATEGORY, $path, $contents)) {
                throw PopupImageCopyException::writeFailed($source->id);
            }
            $image = $this->images->create([
                'popup_id' => $popupId,
                'temp_key' => null,
                'device' => $source->device,
                'hash' => $hash,
                'disk' => $this->storage->getDisk(),
                'path' => $path,
                'original_name' => $source->original_name,
                'mime' => $source->mime,
                'size' => $source->size,
                'width' => $source->width,
                'height' => $source->height,
                'created_by' => $actorId,
            ]);
        } catch (\Throwable $e) {
            if ($tracked && $this->deletePath($path, ['source_image_id' => $source->id])) {
                $pending->forget($hash);
            }

            throw $e;
        }
        // 호출자 트랜잭션이 되돌려지면 대장을 남긴다 — 이후 정리에서 행 부재를 다시 확인한다.
        DB::afterCommit(fn () => $pending->forget($hash));

        return $image;

    }

    /**
     * 행 없이 파일만 지웁니다 — 복사가 되돌려져 행은 사라졌지만 이미 쓴 복제 파일을 거둘 때.
     *
     * @param  PopupImage  $image  파일을 지울 이미지(행은 이미 없다)
     * @return bool 파일이 없거나 지웠으면 true
     */
    public function discardFile(PopupImage $image): bool
    {
        if (! $this->deleteFile($image)) {
            return false;
        }
        app(PendingImageFiles::class)->forget($image->hash);

        return true;
    }

    /**
     * 이미지의 공개 서빙 정보를 반환합니다 — 저장 전 미리보기를 위해 임시 업로드도 해시로 내보낸다(해시 64자는 추측할 수 없다).
     *
     * 응답 계약(ETag·304 재검증·`Cache-Control`·`nosniff`)은 컨트롤러가 코어 파일 서빙 헬퍼로 입힌다. 여기서는 스토리지
     * 스트림과 그 메타(`response`·`etag_source`·`mime_type`·`last_modified`)만 만든다. 인라인은 래스터 이미지만이고 그 밖은
     * 내려받기다(`InlineMediaPolicy`).
     *
     * @param  string  $hash  공개 해시
     * @return array<string, mixed>|null 스트림 메타 (없으면 null)
     */
    public function publicServing(string $hash): ?array
    {
        $image = $this->images->findByHash($hash);
        if ($image === null) {
            return null;
        }

        $response = $this->storage->response(self::CATEGORY, $image->path, $image->original_name, [
            'Content-Type' => $image->mime,
        ]);

        if ($response === null) {
            Log::error('팝업 이미지 파일 없음 — 사용자 화면에 이미지가 깨져 보입니다', ['image_id' => $image->id, 'path' => $image->path]);

            return null;
        }

        // 인라인은 래스터 이미지만, 그 외는 다운로드 + nosniff
        InlineMediaPolicy::harden($response, $image->mime, $image->original_name);

        // 파일 내용은 올린 때 정해진다 — 팝업에 붙이거나 떼어도(updated_at) 바뀌지 않는다
        $modifiedAt = $image->created_at?->getTimestamp();

        return [
            'response' => $response,
            // 해시가 바뀌지 않는 한 내용도 바뀌지 않는다(교체하면 새 해시) — 행 메타로 검증자를 만든다
            'etag_source' => implode('|', [$image->hash, $image->path, (string) $image->size, (string) $modifiedAt]),
            'mime_type' => (string) $image->mime,
            'last_modified' => $modifiedAt,
        ];
    }

    /**
     * 끝내 팝업에 연결되지 않은 임시 이미지를 정리합니다.
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
        $pending = app(PendingImageFiles::class);
        $orphans = $limit > $targets->count() ? $pending->stale(now()->subDays($days)->getTimestamp(), $limit - $targets->count()) : [];
        $result['scanned'] += count($orphans);

        if ($dryRun) {
            return $result;
        }

        foreach ($orphans as $row) {
            try {
                // 대장이 남았어도 행이 있으면 절대 지우지 않는다(다른 요청·연결된 파일 보호).
                if ($this->images->findByHash($row['hash']) !== null) {
                    $pending->forget($row['hash']);

                    continue;
                }
                $disk = $this->storage->withDisk($row['disk']);
                if (! $disk->exists(self::CATEGORY, $row['path']) || $disk->delete(self::CATEGORY, $row['path'])) {
                    $pending->forget($row['hash']);
                    $result['deleted']++;
                } else {
                    $result['failed']++;
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                Log::error('팝업 미완료 이미지 재정리 실패 — 대장을 유지합니다', ['hash' => $row['hash'], 'disk' => $row['disk'], 'exception' => $e::class]);
            }
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
            Log::error('팝업 이미지 폴더 정리 실패', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 파일만 지웁니다 — 이미 없는 파일은 지운 것으로 봅니다.
     *
     * @param  PopupImage  $image  대상 이미지
     * @return bool 파일이 없거나 지웠으면 true
     */
    private function deleteFile(PopupImage $image): bool
    {
        return $this->deletePath($image->path, ['image_id' => $image->id]);
    }

    /**
     * 경로로 파일을 지웁니다 — 이미 없는 파일은 지운 것으로 봅니다.
     *
     * @param  string  $path  카테고리 안 파일 경로
     * @param  array<string, mixed>  $context  실패 로그에 남길 값
     * @return bool 파일이 없거나 지웠으면 true
     */
    private function deletePath(string $path, array $context): bool
    {
        try {
            if (! $this->storage->exists(self::CATEGORY, $path)) {
                return true;
            }

            return $this->storage->delete(self::CATEGORY, $path);
        } catch (\Throwable $e) {
            Log::error('팝업 이미지 파일 삭제 실패 — 행 또는 정리 대장을 유지합니다', $context + ['exception' => $e::class]);

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
