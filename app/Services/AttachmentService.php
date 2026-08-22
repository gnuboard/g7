<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\Attachment;
use App\Models\User;
use App\Support\ImageResizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 첨부파일 서비스
 *
 * 첨부파일 업로드, 삭제, 다운로드 등의 비즈니스 로직을 처리합니다.
 */
class AttachmentService
{
    /**
     * AttachmentService 생성자
     *
     * @param  AttachmentRepositoryInterface  $repository  첨부파일 리포지토리
     * @param  StorageInterface  $storage  스토리지 드라이버
     * @param  ImageResizer  $imageResizer  업로드 이미지 축소기
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private StorageInterface $storage,
        private ImageResizer $imageResizer
    ) {}

    /**
     * 단일 파일 업로드
     *
     * @param  UploadedFile  $file  업로드된 파일
     * @param  string|null  $attachmentableType  첨부 대상 타입
     * @param  int|null  $attachmentableId  첨부 대상 ID
     * @param  string  $collection  컬렉션명
     * @param  AttachmentSourceType  $sourceType  소스 타입
     * @param  string|null  $sourceIdentifier  소스 식별자
     * @return Attachment 생성된 첨부파일
     */
    public function upload(
        UploadedFile $file,
        ?string $attachmentableType = null,
        ?int $attachmentableId = null,
        string $collection = 'default',
        AttachmentSourceType $sourceType = AttachmentSourceType::Core,
        ?string $sourceIdentifier = null,
    ): Attachment {
        // Before 훅
        HookManager::doAction('core.attachment.before_upload', $file, $attachmentableType, $attachmentableId);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('core.attachment.filter_upload_file', $file);

        // 환경설정 > 업로드의 최대 가로/세로·품질을 실제로 적용한다.
        // 임시 파일을 제자리에서 줄이므로 아래의 저장·크기·메타 계산이 모두 축소본을 본다.
        $this->imageResizer->resizeInPlace($file->getRealPath(), $file->getMimeType());

        // 저장 경로 생성 (날짜별 디렉토리).
        // 확장자는 클라이언트가 보낸 파일명이 아니라 MIME 추론값을 우선 사용한다 —
        // 파일명 확장자는 사용자가 임의로 바꿀 수 있어 저장 확장자의 근거가 될 수 없다.
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $storedFilename = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $datePath = date('Y/m/d');
        $path = "{$datePath}/{$storedFilename}";

        // 스토리지에 파일 저장
        $disk = config('attachment.disk');
        $this->storage->withDisk($disk)->put('', $path, file_get_contents($file->getRealPath()));

        // 현재 컬렉션의 최대 order 조회
        if ($attachmentableType && $attachmentableId) {
            $maxOrder = $this->repository->getMaxOrder($attachmentableType, $attachmentableId, $collection);
        } else {
            $maxOrder = $this->repository->getMaxOrderByCollection($collection);
        }

        // 메타데이터 준비 (이미지인 경우 크기 정보)
        $meta = [];
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageSize = @getimagesize($file->getRealPath());
            if ($imageSize) {
                $meta['width'] = $imageSize[0];
                $meta['height'] = $imageSize[1];
            }
        }

        // DB에 저장
        $attachment = $this->repository->create([
            'attachmentable_type' => $attachmentableType,
            'attachmentable_id' => $attachmentableId,
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'collection' => $collection,
            'order' => $maxOrder + 1,
            'meta' => ! empty($meta) ? $meta : null,
            'created_by' => Auth::id(),
        ]);

        Log::info('첨부파일 업로드 완료', [
            'attachment_id' => $attachment->id,
            'hash' => $attachment->hash,
            'original_filename' => $attachment->original_filename,
            'size' => $attachment->size,
        ]);

        // After 훅
        HookManager::doAction('core.attachment.after_upload', $attachment);

        return $attachment;
    }

    /**
     * 여러 파일 일괄 업로드
     *
     * @param  array<UploadedFile>  $files  업로드할 파일 배열
     * @param  string|null  $attachmentableType  첨부 대상 타입
     * @param  int|null  $attachmentableId  첨부 대상 ID
     * @param  string  $collection  컬렉션명
     * @param  AttachmentSourceType  $sourceType  소스 타입
     * @param  string|null  $sourceIdentifier  소스 식별자
     * @return Collection<int, Attachment>
     */
    public function uploadBatch(
        array $files,
        ?string $attachmentableType = null,
        ?int $attachmentableId = null,
        string $collection = 'default',
        AttachmentSourceType $sourceType = AttachmentSourceType::Core,
        ?string $sourceIdentifier = null,
    ): Collection {
        $attachments = collect();

        foreach ($files as $file) {
            $attachment = $this->upload(
                $file,
                $attachmentableType,
                $attachmentableId,
                $collection,
                $sourceType,
                $sourceIdentifier,
            );
            $attachments->push($attachment);
        }

        return $attachments;
    }

    /**
     * 소유자 없이 방치된 고아 첨부를 정리합니다.
     *
     * 폼 저장 전에 즉시 업로드되는 첨부(FileUploader 등)는 소유자 없이 먼저 만들어지므로,
     * 폼을 저장하지 않고 이탈하면 파일과 기록이 그대로 남습니다.
     *
     * 오탐을 막기 위해 아래 조건을 모두 만족하는 행만 대상으로 삼습니다.
     *
     *   1. 소유자(attachmentable_type/id)가 없다
     *   2. 업로드 후 보존기간이 지났다 (폼 작성 중 유예)
     *   3. 확장 소유가 아니다 (source_identifier 없음 — 확장 라이프사이클 소관)
     *   4. 현역으로 쓰이는 첨부가 아니다 (현재 사이트 로고 등)
     *
     * 4번 보호 조건은 이 메서드가 직접 해석합니다. 호출자가 넘기는 값으로 두면 그 인자를
     * 빠뜨린 호출 하나가 운영 중인 로고를 파기하는데, 나머지 세 조건은 조회의 불변식이라
     * 보호 조건만 규약으로 남는 비대칭이 생깁니다.
     *
     * 삭제는 단건 삭제 경로(delete)를 그대로 재사용하므로 파일 삭제·훅·로그가 동일합니다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  한 회차에 처리할 최대 건수
     * @param  bool  $dryRun  true 면 대상만 세고 삭제하지 않음
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function pruneOrphans(int $days, int $limit, bool $dryRun = false): array
    {
        $threshold = now()->subDays($days);
        $candidates = $this->repository->findOrphanCandidates(
            $threshold,
            $limit,
            $this->protectedAttachmentIds(),
        );

        $result = ['scanned' => $candidates->count(), 'deleted' => 0, 'failed' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($candidates as $candidate) {
            $this->delete($candidate->id) ? $result['deleted']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * 고아 정리에서 보호할 첨부 ID 목록을 반환합니다.
     *
     * 사이트 로고는 소유자(attachmentable) 없이 설정값이 직접 참조하는 첨부라 판정식만으로는
     * 고아와 구분되지 않습니다. 현역으로 설정에 실린 ID 를 명시적으로 제외합니다.
     *
     * @return array<int, int> 보호할 첨부 ID 목록
     */
    private function protectedAttachmentIds(): array
    {
        $siteLogo = g7_core_settings('general.site_logo', []);

        if (! is_array($siteLogo)) {
            return [];
        }

        $ids = [];

        foreach ($siteLogo as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;

            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 첨부파일 삭제 (파일 + 기록 영구 삭제)
     *
     * 대상 조회는 소프트삭제된 행까지 포함한다. 이 메서드가 수행하는 것은 forceDelete 이므로
     * 이미 소프트삭제된 행도 정당한 대상이고, 기본 조회로 찾으면 고아 정리가 그 행을 영원히
     * 회수하지 못한 채 실패만 반복한다.
     *
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        $attachment = $this->repository->findByIdWithTrashed($id);

        if (! $attachment) {
            return false;
        }

        // 삭제 후 재정렬을 위해 정보 저장
        $attachmentableType = $attachment->attachmentable_type;
        $attachmentableId = $attachment->attachmentable_id;
        $collection = $attachment->collection;

        // DB 를 먼저 지우고 파일은 커밋 후에 지운다.
        // 순서를 반대로 두면 DB 삭제가 실패했을 때 파일 없는 행이 남아 다운로드가 404 가 된다.
        // 반대로 이 순서에서는 최악이어도 참조되지 않는 고아 파일만 남고 데이터는 온전하다.
        $result = DB::transaction(function () use ($id, $attachment, $attachmentableType, $attachmentableId, $collection) {
            // Before 훅
            HookManager::doAction('core.attachment.before_delete', $attachment);

            // DB에서 영구 삭제
            $deleted = $this->repository->forceDelete($id);

            // 삭제 후 남은 파일들의 순서 재정렬
            if ($deleted && $attachmentableType && $attachmentableId) {
                $this->repository->reorderAfterDelete($attachmentableType, $attachmentableId, $collection);
            }

            return $deleted;
        });

        if ($result) {
            // 커밋 후 스토리지 파일 삭제 (실패해도 DB 는 되돌리지 않는다)
            try {
                $this->storage->withDisk($attachment->disk)->delete('', $attachment->path);
            } catch (\Throwable $e) {
                Log::warning('첨부파일 스토리지 삭제 실패 (고아 파일 잔존)', [
                    'attachment_id' => $id,
                    'path' => $attachment->path,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('첨부파일 삭제 완료', [
                'attachment_id' => $id,
                'hash' => $attachment->hash,
            ]);
        }

        // After 훅
        HookManager::doAction('core.attachment.after_delete', $attachment);

        return $result;
    }

    /**
     * 순서 변경
     *
     * @param  array<int, array{id: int, order: int}>  $orderData  순서 데이터
     */
    public function reorder(array $orderData): void
    {
        $this->assertReorderWithinScope($orderData);

        // Before 훅
        HookManager::doAction('core.attachment.before_reorder', $orderData);

        $this->repository->reorder($orderData);

        // After 훅
        HookManager::doAction('core.attachment.after_reorder', $orderData);
    }

    /**
     * 순서 변경 대상이 액터의 스코프 안에 있는지 검사합니다.
     *
     * `PATCH admin/attachments/reorder` 는 라우트 모델이 없는 정적 경로다. PermissionMiddleware
     * 는 `$request->route('attachment')` 가 Model 일 때만 스코프를 검사하고 없으면 목록
     * 엔드포인트로 보아 건너뛰므로(`PermissionMiddleware`), 상세 경로(`DELETE {attachment}`)가
     * 미들웨어로 강제하는 스코프 축이 이 경로에서만 비어 있었다. 배포 기본 역할 `manager` 가
     * `core.attachments.update` 를 `self` 스코프로 보유하므로 이론 구성이 아니라 기본값에서
     * 성립한다 — 타인 소유 첨부의 순서를 바꿀 수 있었다.
     *
     * 대상 일부만 걸러내지 않고 **전체를 거부**한다. 순서는 집합 전체에 대한 하나의 배열이라
     * 일부만 반영하면 나머지와 어긋난 순서가 저장되기 때문이다(사용자 일괄 상태변경이
     * "제외" 를 택한 것과 의미론이 다르다).
     *
     * @param  array<int, array{id: int, order: int}>  $orderData  순서 데이터
     *
     * @throws AuthorizationException 스코프 밖 첨부가 하나라도 포함된 경우
     */
    private function assertReorderWithinScope(array $orderData): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($item): int => (int) ($item['id'] ?? 0), $orderData)
        )));

        if (empty($ids)) {
            return;
        }

        $attachments = $this->repository->findByIds($ids);

        // 판정은 상세 경로와 같은 SSoT 에 위임한다 — 여기서 재구현하면 두 경로의 강도가 갈린다.
        $permitted = PermissionHelper::filterByScope($attachments, 'core.attachments.update');

        if (count($permitted) !== $attachments->count()) {
            throw new AuthorizationException(__('auth.scope_denied'));
        }
    }

    /**
     * 다운로드 응답 생성
     *
     * 기능 레벨 권한 체크 (permission_hooks) - 다운로드 기능 자체에 대한 접근 권한
     *
     * @param  string  $hash  첨부파일 해시
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return StreamedResponse|null 다운로드 응답 또는 null
     *
     * @throws AuthorizationException 기능 레벨 권한이 없는 경우
     */
    public function download(string $hash, mixed $user = null): ?StreamedResponse
    {
        $attachment = $this->repository->findByHash($hash);

        if (! $attachment) {
            return null;
        }

        // 기능 레벨 권한 체크 (permission_hooks)
        // → 'core.attachment.download' 훅에 권한이 매핑되어 있으면 체크
        // → 미매핑 시 모든 사용자 허용
        HookManager::checkHookPermission('core.attachment.download', $user);

        // 액션 훅 - IDV 정책 가드 지점 (filter 훅과 병행)
        HookManager::doAction('core.attachment.before_download_action', $attachment, $user);

        // 필터 훅 - 다운로드 전 처리 (다운로드 카운트 증가 등)
        $attachment = HookManager::applyFilters('core.attachment.before_download', $attachment, $user);

        // 파일 존재 확인
        $diskStorage = $this->storage->withDisk($attachment->disk);
        if (! $diskStorage->exists('', $attachment->path)) {
            Log::error('첨부파일 스토리지에 없음', [
                'attachment_id' => $attachment->id,
                'path' => $attachment->path,
            ]);

            return null;
        }

        // 다운로드 응답 생성 (원본 파일명으로)
        return $diskStorage->download('', $attachment->path, $attachment->original_filename);
    }

    /**
     * 이미지 파일 정보 조회 (캐싱 응답용)
     *
     * 권한 체크 후 인라인 스트림 응답과 캐싱용 메타를 반환합니다.
     * 로컬 절대 경로 조립(getBasePath) 방식은 S3 등 원격 디스크 행에서 성립하지
     * 않으므로(#99 — filemtime stat 실패 500), 행 disk 를 그대로 따르는
     * StorageInterface::response() 스트림으로 서빙합니다. 컨트롤러는
     * streamedFileResponse() 로 캐싱 헤더(ETag/304)를 입혀 응답합니다.
     *
     * @param  string  $hash  첨부파일 해시
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string}|null 서빙 정보 또는 null
     *
     * @throws AuthorizationException 기능 레벨 권한이 없는 경우
     */
    public function getFileInfo(string $hash, mixed $user = null): ?array
    {
        $attachment = $this->repository->findByHash($hash);

        if (! $attachment) {
            return null;
        }

        // 기능 레벨 권한 체크 (permission_hooks)
        HookManager::checkHookPermission('core.attachment.download', $user);

        // 필터 훅 - 다운로드 전 처리
        $attachment = HookManager::applyFilters('core.attachment.before_download', $attachment, $user);

        // 행 disk 기준 인라인 스트림 (존재 검사는 response() 내부에서 수행)
        // Content-Type/Length 를 행 메타로 선지정해 원격 디스크의 추가 메타 조회를 생략한다.
        $response = $this->storage->withDisk($attachment->disk)->response(
            '',
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Length' => (string) $attachment->size,
            ]
        );

        if (! $response) {
            Log::error('첨부파일 스토리지에 없음', [
                'attachment_id' => $attachment->id,
                'path' => $attachment->path,
            ]);

            return null;
        }

        return [
            'response' => $response,
            // 파일 stat 없이 결정적인 ETag 소스 (업로드 파일은 경로당 불변)
            'etag_source' => implode('|', [
                $attachment->disk,
                $attachment->path,
                (string) $attachment->updated_at?->getTimestamp(),
                (string) $attachment->size,
            ]),
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_filename,
        ];
    }

    /**
     * 해시로 첨부파일 조회
     *
     * @param  string  $hash  첨부파일 해시
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findByHash(string $hash): ?Attachment
    {
        return $this->repository->findByHash($hash);
    }

    /**
     * ID로 첨부파일 조회
     *
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function findById(int $id): ?Attachment
    {
        return $this->repository->findById($id);
    }

    /**
     * 첨부 대상의 첨부파일 목록 조회
     *
     * @param  string  $type  attachmentable_type
     * @param  int  $id  attachmentable_id
     * @param  string|null  $collection  컬렉션명 (null이면 전체)
     * @return Collection<int, Attachment>
     */
    public function getByAttachmentable(string $type, int $id, ?string $collection = null): Collection
    {
        return $this->repository->getByAttachmentable($type, $id, $collection);
    }

    /**
     * 특정 소스 식별자의 첨부파일 일괄 삭제
     * (모듈/플러그인 제거 시 사용)
     *
     * @param  string  $identifier  소스 식별자
     * @return int 삭제된 개수
     */
    public function deleteBySourceIdentifier(string $identifier): int
    {
        // Before 훅
        HookManager::doAction('core.attachment.before_bulk_delete', $identifier);

        // 삭제 대상 스냅샷 + 파일 경로를 먼저 수집한다 (DB 삭제 후에는 조회할 수 없다).
        $attachments = $this->repository->getBySourceIdentifier($identifier);
        $attachmentIds = $attachments->pluck('id')->toArray();
        $snapshots = $attachments->keyBy('id')->map(fn ($a) => $a->toArray())->toArray();
        $files = $attachments->map(fn ($a) => ['disk' => $a->disk, 'path' => $a->path])->all();

        // DB 일괄 삭제를 먼저 커밋한다 — 루프 중간에 실패하면 파일만 일부 사라지고
        // DB 행은 전부 남아 다운로드가 404 가 되기 때문이다.
        $count = DB::transaction(fn () => $this->repository->deleteBySourceIdentifier($identifier));

        // 커밋 후 파일 정리 (실패해도 DB 는 되돌리지 않는다 — 고아 파일은 비파괴)
        foreach ($files as $file) {
            try {
                $this->storage->withDisk($file['disk'])->delete('', $file['path']);
            } catch (\Throwable $e) {
                Log::warning('첨부파일 스토리지 일괄 삭제 실패 (고아 파일 잔존)', [
                    'source_identifier' => $identifier,
                    'path' => $file['path'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('소스 식별자 기준 첨부파일 일괄 삭제', [
            'source_identifier' => $identifier,
            'deleted_count' => $count,
        ]);

        // After 훅
        HookManager::doAction('core.attachment.after_bulk_delete', $identifier, $count, $attachmentIds, $snapshots);

        return $count;
    }
}
