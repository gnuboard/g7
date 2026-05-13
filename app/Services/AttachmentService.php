<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Extension\HookManager;
use App\Models\Attachment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
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
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private StorageInterface $storage
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

        // 저장 경로 생성 (날짜별 디렉토리)
        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
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
     * 첨부파일 삭제
     *
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool
    {
        $attachment = $this->repository->findById($id);

        if (! $attachment) {
            return false;
        }

        // 삭제 후 재정렬을 위해 정보 저장
        $attachmentableType = $attachment->attachmentable_type;
        $attachmentableId = $attachment->attachmentable_id;
        $collection = $attachment->collection;

        // Before 훅
        HookManager::doAction('core.attachment.before_delete', $attachment);

        // 스토리지에서 파일 삭제
        $this->storage->withDisk($attachment->disk)->delete('', $attachment->path);

        // DB에서 영구 삭제
        $result = $this->repository->forceDelete($id);

        Log::info('첨부파일 삭제 완료', [
            'attachment_id' => $id,
            'hash' => $attachment->hash,
        ]);

        // 삭제 후 남은 파일들의 순서 재정렬
        if ($result && $attachmentableType && $attachmentableId) {
            $this->repository->reorderAfterDelete($attachmentableType, $attachmentableId, $collection);
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
        // Before 훅
        HookManager::doAction('core.attachment.before_reorder', $orderData);

        $this->repository->reorder($orderData);

        // After 훅
        HookManager::doAction('core.attachment.after_reorder', $orderData);
    }

    /**
     * 다운로드 응답 생성
     *
     * 기능 레벨 권한 체크 (permission_hooks) - 다운로드 기능 자체에 대한 접근 권한
     *
     * @param  string  $hash  첨부파일 해시
     * @param  \App\Models\User|null  $user  요청한 사용자 (null이면 비로그인)
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
     * 권한 체크 후 파일 경로와 메타 정보를 반환합니다.
     * 컨트롤러에서 fileResponse()로 캐싱 헤더와 함께 응답할 수 있습니다.
     *
     * @param  string  $hash  첨부파일 해시
     * @param  \App\Models\User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return array{path: string, mime_type: string, filename: string}|null 파일 정보 또는 null
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

        // 파일 존재 확인
        $diskStorage = $this->storage->withDisk($attachment->disk);
        if (! $diskStorage->exists('', $attachment->path)) {
            Log::error('첨부파일 스토리지에 없음', [
                'attachment_id' => $attachment->id,
                'path' => $attachment->path,
            ]);

            return null;
        }

        return [
            'path' => $diskStorage->getBasePath('').DIRECTORY_SEPARATOR.$attachment->path,
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

        // 해당 첨부파일들의 스토리지 파일 삭제
        $attachments = $this->repository->getBySourceIdentifier($identifier);
        $attachmentIds = $attachments->pluck('id')->toArray();
        $snapshots = $attachments->keyBy('id')->map(fn ($a) => $a->toArray())->toArray();

        foreach ($attachments as $attachment) {
            $this->storage->withDisk($attachment->disk)->delete('', $attachment->path);
        }

        // DB에서 삭제
        $count = $this->repository->deleteBySourceIdentifier($identifier);

        Log::info('소스 식별자 기준 첨부파일 일괄 삭제', [
            'source_identifier' => $identifier,
            'deleted_count' => $count,
        ]);

        // After 훅
        HookManager::doAction('core.attachment.after_bulk_delete', $identifier, $count, $attachmentIds, $snapshots);

        return $count;
    }
}
