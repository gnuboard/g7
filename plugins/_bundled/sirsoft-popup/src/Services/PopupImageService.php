<?php

namespace Plugins\Sirsoft\Popup\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Extension\Storage\RowDiskStorage;
use App\Helpers\InlineMediaPolicy;
use App\Support\ImageResizer;
use App\Support\UploadedFileName;
use App\Thumbnails\DTO\ThumbnailSource;
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
 *
 * 주입 스토리지는 「지금 업로드가 갈 디스크」다(공개 자산 디스크 설정 반영, #878). 이미 올린 파일의 존재 확인·서빙·
 * 썸네일 원본·복제 원본 읽기·삭제는 **행에 기록된 disk** 로 한다(`storageFor()`).
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
     * @param  StorageInterface  $storage  플러그인 스토리지 드라이버 (카테고리 `images` 의 업로드 디스크)
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
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
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
            $cleaned = $tracked && $this->deletePath($path, ['hash' => $hash], $disk);
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
     * 원본은 원본 행의 disk 에서 읽고, 복제본은 지금 업로드 디스크(주입 스토리지)에 쓴다 — 공개 자산 디스크를 켠 뒤 복사하면
     * 복제본은 새 디스크 행이 된다(#878).
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
        try {
            $contents = $this->storageFor($source)->get(self::CATEGORY, $source->path);
        } catch (\Throwable) {
            $contents = null;
        }
        if ($contents === null || $contents === false) {
            throw PopupImageCopyException::sourceMissing($source->id);
        }

        $hash = Str::random(64);
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
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
            if ($tracked && $this->deletePath($path, ['source_image_id' => $source->id], $disk)) {
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
     * 공개 해시로 이미지를 찾습니다 — 공개 서빙의 게이트다(해시 64자는 추측할 수 없다).
     *
     * 저장 전 미리보기를 위해 임시 업로드도 찾는다(등록·수정 화면 업로더 칸과 미리보기가 저장 전에 그린다).
     *
     * @param  string  $hash  공개 해시
     * @return PopupImage|null 이미지 행 (없으면 null)
     */
    public function findPublic(string $hash): ?PopupImage
    {
        return $this->images->findByHash($hash);
    }

    /**
     * 행의 원본 파일이 저장소에 있는지 (원본 응답과 같은 스토리지로 확인한다).
     *
     * 썸네일 판정 앞에서 부른다 — 원격 저장소에서 원본만 지워져 파생본이 남은 경우, 확인 없이 썸네일로 가면
     * 무파라미터 요청은 404 인데 `?size=` 요청은 남은 파생본으로 200 이 된다.
     *
     * @param  PopupImage  $image  이미지 행
     * @return bool 있으면 true (저장소를 읽지 못해도 false)
     */
    public function originalExists(PopupImage $image): bool
    {
        try {
            return $this->storageFor($image)->exists(self::CATEGORY, (string) $image->path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 썸네일 원본 정보를 행에서 만듭니다 — 팝업 이미지는 해시만 알면 받는 공개 원본이다(`gated=false`).
     *
     * PC·모바일 이미지는 기기별로 따로 올린 각자의 원본이라 행마다 따로 만든다(파생본 키도 행 경로로 갈린다). 원본 응답과
     * **같은 스토리지**로 만들어야 파생본 키(`thumbnails/{disk}/{전체 경로}/…`)가 저장소 계층의 덮어쓰기·삭제 무효화와 같은
     * 경로를 가리킨다. 움직이는 GIF·WebP·APNG 는 코어가 판정해 원본을 낸다(`bypass:animated`, 코어 설정으로 첫 프레임 썸네일로 바꿀 수 있다).
     *
     * @param  PopupImage  $image  이미지 행
     * @return ThumbnailSource|null 원본 (드라이버가 원본 정보를 못 주면 null)
     */
    public function thumbnailSource(PopupImage $image): ?ThumbnailSource
    {
        return ThumbnailSource::fromStorage(
            $this->storageFor($image),
            self::CATEGORY,
            (string) $image->path,
            (string) $image->mime,
            gated: false,
            width: $image->width,
            height: $image->height,
        );
    }

    /**
     * 이미지의 공개 서빙 정보를 반환합니다 — 게이트(`findPublic()`)를 지난 행만 받는다.
     *
     * 응답 계약(ETag·304 재검증·`Cache-Control`·`nosniff`)은 컨트롤러가 코어 파일 서빙 헬퍼로 입힌다. 여기서는 스토리지
     * 스트림과 그 메타(`response`·`etag_source`·`mime_type`·`last_modified`)만 만든다. 인라인은 래스터 이미지만이고 그 밖은
     * 내려받기다(`InlineMediaPolicy`). `?size=`·`?w=` 썸네일은 컨트롤러가 이 메서드보다 먼저 `ThumbnailResponder` 로 판정한다 —
     * 원본 응답의 304 판정을 컨트롤러가 하므로, 썸네일이 원본 검증자로 다시 싸이지 않게 갈라 둔다.
     *
     * @param  PopupImage  $image  이미지 행 (`findPublic()` 결과)
     * @return array<string, mixed>|null 스트림 메타 (파일이 없으면 null)
     */
    public function publicServing(PopupImage $image): ?array
    {
        $response = $this->storageFor($image)->response(self::CATEGORY, $image->path, $image->original_name, [
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
                // 대장의 disk 는 고아일 수 있다(그 디스크를 주던 확장 비활성화) — 날 withDisk 는 이후 읽기·삭제가 예외를 던진다
                $disk = RowDiskStorage::resolve($this->storage, is_string($row['disk'] ?? null) ? $row['disk'] : null);
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
     * 행이 기록한 모든 disk 와 지금 업로드 디스크의 `images` 폴더를 각각 비운다 — 공개 자산 디스크를 켰다 끈 사이트는
     * 파일이 두 디스크 이상에 흩어져 있다(#878). 같은 디스크로 해석되는 값(고아 disk 는 주입 디스크로 폴백)은 한 번만
     * 비운다. 한 디스크가 실패해도 나머지는 계속 비우고 false 를 돌려준다.
     *
     * @return bool 모두 비웠으면 true
     */
    public function purgeAll(): bool
    {
        $storages = [$this->storage->getDisk() => $this->storage];
        try {
            foreach ($this->images->distinctDisks() as $disk) {
                $storage = RowDiskStorage::resolve($this->storage, $disk);
                $storages[$storage->getDisk()] ??= $storage;
            }
        } catch (\Throwable $e) {
            Log::error('팝업 이미지 디스크 목록 조회 실패', ['error' => $e->getMessage()]);
        }

        $ok = true;
        foreach ($storages as $disk => $storage) {
            try {
                if (! $storage->deleteAll(self::CATEGORY)) {
                    $ok = false;
                    Log::error('팝업 이미지 폴더 정리 실패', ['disk' => $disk]);
                }
            } catch (\Throwable $e) {
                $ok = false;
                Log::error('팝업 이미지 폴더 정리 실패', ['disk' => $disk, 'error' => $e->getMessage()]);
            }
        }

        return $ok;
    }

    /**
     * 파일만 지웁니다 — 이미 없는 파일은 지운 것으로 봅니다.
     *
     * @param  PopupImage  $image  대상 이미지
     * @return bool 파일이 없거나 지웠으면 true
     */
    private function deleteFile(PopupImage $image): bool
    {
        return $this->deletePath($image->path, ['image_id' => $image->id], $image->disk !== null ? (string) $image->disk : null);
    }

    /**
     * 경로로 파일을 지웁니다 — 이미 없는 파일은 지운 것으로 봅니다.
     *
     * 디스크는 행(또는 업로드·복제 실패 정리 때 그 파일을 쓴 디스크)의 것이다 — 비거나 고아면 주입 디스크(#878).
     *
     * @param  string  $path  카테고리 안 파일 경로
     * @param  array<string, mixed>  $context  실패 로그에 남길 값
     * @param  string|null  $disk  파일이 있는 disk (null 이면 주입 디스크)
     * @return bool 파일이 없거나 지웠으면 true
     */
    private function deletePath(string $path, array $context, ?string $disk = null): bool
    {
        try {
            $storage = RowDiskStorage::resolve($this->storage, $disk);
            if (! $storage->exists(self::CATEGORY, $path)) {
                return true;
            }

            return $storage->delete(self::CATEGORY, $path);
        } catch (\Throwable $e) {
            Log::error('팝업 이미지 파일 삭제 실패 — 행 또는 정리 대장을 유지합니다', $context + ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * 행에 기록된 disk 의 스토리지를 반환합니다 (#878).
     *
     * 빈 disk·주입 디스크와 같은 disk·설정에서 사라진 disk(고아)는 주입 스토리지로 물러난다 — 고아 disk 로 드라이버를 만들면
     * 이후 읽기·응답·삭제가 예외를 던져 무인증 공개 서빙이 500 이 된다(404 가 정상 저하). 판정은 코어 도우미가 소유한다.
     *
     * @param  PopupImage  $image  이미지 행
     * @return StorageInterface 그 행이 저장된 디스크의 스토리지
     */
    private function storageFor(PopupImage $image): StorageInterface
    {
        return RowDiskStorage::resolve($this->storage, $image->disk !== null ? (string) $image->disk : null);
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
