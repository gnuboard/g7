<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Helpers\PermissionHelper;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시판 첨부파일 서비스
 *
 * 첨부파일 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class AttachmentService
{
    /**
     * AttachmentService 생성자
     *
     * @param  AttachmentRepositoryInterface  $repository  첨부파일 리포지토리
     * @param  StorageInterface  $storage  모듈 스토리지 드라이버
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private BoardRepositoryInterface $boardRepository,
        private StorageInterface $storage
    ) {}

    /**
     * 단일 파일 업로드
     *
     * post_id가 없는 경우 임시 업로드로 처리합니다.
     * 임시 업로드된 파일은 temp_key로 식별되며, 게시글 저장 시 연결됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  UploadedFile  $file  업로드된 파일
     * @param  int|null  $postId  게시글 ID (새 글 작성 시 null)
     * @param  string  $collection  컬렉션명
     * @param  string|null  $tempKey  임시 업로드 키 (새 글 작성 시 사용)
     * @return Attachment 생성된 첨부파일
     */
    public function upload(
        string $slug,
        UploadedFile $file,
        ?int $postId = null,
        string $collection = 'attachments',
        ?string $tempKey = null
    ): Attachment {
        // Before 훅
        HookManager::doAction('sirsoft-board.attachment.before_upload', $slug, $file, $postId);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('sirsoft-board.attachment.filter_upload_file', $file);

        // 저장 경로 생성
        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();

        if ($postId) {
            // 기존 게시글 수정: 최종 경로에 바로 저장
            $path = "{$slug}/".date('Y/m/d')."/{$storedFilename}";
        } else {
            // 신규 게시글: 임시 경로에 저장 (저장 시 최종 경로로 이동)
            $path = "{$slug}/temp/{$tempKey}/{$storedFilename}";
        }

        // 스토리지에 파일 저장 (category: 'attachments')
        $this->storage->put('attachments', $path, file_get_contents($file->getRealPath()));

        // Disk 정보는 스토리지 드라이버에서 가져옴
        $disk = $this->storage->getDisk();

        // 현재 컬렉션의 최대 order 조회
        $maxOrder = $postId
            ? $this->repository->getMaxOrder($slug, $postId, $collection)
            : $this->repository->getMaxOrderByTempKey($slug, $tempKey, $collection);

        // 메타데이터 준비 (이미지인 경우 크기 정보)
        $meta = [];
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageSize = @getimagesize($file->getRealPath());
            if ($imageSize) {
                $meta['width'] = $imageSize[0];
                $meta['height'] = $imageSize[1];
            }
        }

        // board_id 설정: postId가 있으면 실제 board_id, 없으면 0(임시 업로드)
        $boardId = 0;
        if ($postId) {
            $board = $this->boardRepository->findBySlug($slug);
            $boardId = $board?->id ?? 0;
        }

        // DB에 저장 (hash는 모델에서 자동 생성)
        $attachment = $this->repository->create($slug, [
            'board_id' => $boardId,
            'post_id' => $postId,
            'temp_key' => $postId ? null : $tempKey,
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

        Log::info('게시판 첨부파일 업로드 완료', [
            'board_slug' => $slug,
            'attachment_id' => $attachment->id,
            'post_id' => $postId,
            'temp_key' => $tempKey,
            'original_filename' => $attachment->original_filename,
            'size' => $attachment->size,
        ]);

        // After 훅
        HookManager::doAction('sirsoft-board.attachment.after_upload', $attachment);

        return $attachment;
    }

    /**
     * 임시 첨부파일을 게시글에 연결합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $postId  게시글 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkTempAttachments(string $slug, string $tempKey, int $postId): int
    {
        // 연결 대상 후보를 먼저 조회하여 링크 후 재조회 → 훅 발화를 위한 식별자 확보
        $tempAttachments = $this->repository->getByTempKey($slug, $tempKey);

        $linkedCount = $this->repository->linkTempAttachments($slug, $tempKey, $postId);

        // 각 첨부에 대해 after_link 훅 발화 → 카운트 리스너가 post_id 기준으로 동기화 가능
        foreach ($tempAttachments as $tempAttachment) {
            $linked = $this->repository->getById($slug, $tempAttachment->id);
            if ($linked && $linked->post_id === $postId) {
                HookManager::doAction('sirsoft-board.attachment.after_link', $linked);
            }
        }

        return $linkedCount;
    }

    /**
     * 임시 첨부파일을 게시글에 연결하고 최종 경로로 파일을 이동합니다.
     *
     * 이커머스 ProductImageService::linkTempImages() 패턴 참고.
     * StorageInterface에 move()가 없으므로 get+put+delete 조합 사용.
     *
     * 경로 패턴:
     * - 임시 경로: {slug}/temp/{tempKey}/{filename}
     * - 최종 경로: {slug}/{Y/m/d}/{filename}
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  int  $postId  게시글 ID
     * @return int 연결된 파일 수
     */
    public function linkTempAttachmentsWithMove(string $slug, string $tempKey, int $postId): int
    {
        // 게시판 조회 (board_id 설정용)
        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }

        $tempAttachments = $this->repository->getByTempKey($slug, $tempKey);
        $linkedCount = 0;

        foreach ($tempAttachments as $attachment) {
            // 최종 경로 생성 (기존 경로 규칙 유지)
            $newPath = "{$slug}/".date('Y/m/d')."/{$attachment->stored_filename}";

            // 파일 물리적 이동 (StorageInterface: get + put + delete)
            $content = $this->storage->get('attachments', $attachment->path);
            if ($content) {
                $this->storage->put('attachments', $newPath, $content);
                $this->storage->delete('attachments', $attachment->path);
            }

            // DB 업데이트: board_id 이동, post_id 설정, temp_key 제거, path 변경
            // 임시 첨부파일(board_id=0)을 직접 업데이트 (repository->update()는 board_id=$board->id로 조회하므로 사용 불가)
            $attachment->update([
                'board_id' => $board->id,
                'post_id' => $postId,
                'temp_key' => null,
                'path' => $newPath,
            ]);
            $linkedCount++;
        }

        // 임시 디렉토리 정리
        $this->storage->deleteDirectory('attachments', "{$slug}/temp/{$tempKey}");

        Log::info('게시판 임시 첨부파일 연결 완료', [
            'board_slug' => $slug,
            'temp_key' => $tempKey,
            'post_id' => $postId,
            'linked_count' => $linkedCount,
        ]);

        // 각 첨부에 대해 after_link 훅 발화 → 카운트 리스너가 post_id 기준으로 동기화 가능
        foreach ($tempAttachments as $attachment) {
            HookManager::doAction('sirsoft-board.attachment.after_link', $attachment);
        }

        return $linkedCount;
    }

    /**
     * 임시 첨부파일 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $tempKey  임시 업로드 키
     * @param  string|null  $collection  컬렉션 필터
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getTempAttachments(string $slug, string $tempKey, ?string $collection = null)
    {
        return $this->repository->getByTempKey($slug, $tempKey, $collection);
    }

    /**
     * 해시로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  string  $hash  첨부파일 해시
     * @return Attachment|null 첨부파일 또는 null
     */
    public function getByHash(string $slug, string $hash): ?Attachment
    {
        return $this->repository->findByHash($slug, $hash);
    }

    /**
     * ID로 첨부파일 조회
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function getById(string $slug, int $id): ?Attachment
    {
        return $this->repository->findById($slug, $id);
    }

    /**
     * 사용자가 첨부파일을 삭제할 권한이 있는지 확인
     *
     * @param  Attachment  $attachment  첨부파일
     * @param  int|null  $userId  사용자 ID (null이면 비회원)
     * @return bool 삭제 권한 여부
     */
    public function canDelete(Attachment $attachment, ?int $userId): bool
    {
        // 비회원은 삭제 불가
        if (! $userId) {
            return false;
        }

        // 작성자 본인만 삭제 가능
        return $attachment->created_by === $userId;
    }

    /**
     * 첨부파일 삭제
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(string $slug, int $id): bool
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return false;
        }

        // 삭제 후 재정렬을 위해 정보 저장
        $postId = $attachment->post_id;
        $collection = $attachment->collection;

        // Before 훅
        HookManager::doAction('sirsoft-board.attachment.before_delete', $attachment);

        // 물리 파일은 삭제하지 않음 — 소프트 딜리트만 수행
        // 추후 배치 작업(Artisan Command + Scheduler)으로 보존 기간 경과 후 정리 예정

        // DB에서 소프트 삭제
        $result = $this->repository->delete($slug, $id);

        Log::info('게시판 첨부파일 삭제 완료', [
            'board_slug' => $slug,
            'attachment_id' => $id,
            'post_id' => $postId,
        ]);

        // 삭제 후 남은 파일들의 순서 재정렬
        if ($result && $postId) {
            $this->reorderAfterDelete($slug, $postId, $collection);
        }

        // After 훅
        HookManager::doAction('sirsoft-board.attachment.after_delete', $attachment);

        return $result;
    }

    /**
     * 순서 변경
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, int>  $orders  첨부파일 ID => order 매핑
     * @return bool 성공 여부
     */
    public function reorder(string $slug, array $orders): bool
    {
        // Before 훅
        HookManager::doAction('sirsoft-board.attachment.before_reorder', $slug, $orders);

        $result = $this->repository->reorder($slug, $orders);

        // After 훅
        HookManager::doAction('sirsoft-board.attachment.after_reorder', $slug, $orders);

        return $result;
    }

    /**
     * 삭제 후 남은 파일들의 순서를 재정렬합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $collection  컬렉션명
     */
    protected function reorderAfterDelete(string $slug, int $postId, string $collection): void
    {
        $attachments = $this->repository->getByPost($slug, $postId, $collection);

        $orders = [];
        foreach ($attachments as $index => $attachment) {
            $orders[$attachment->id] = $index + 1;
        }

        if (! empty($orders)) {
            $this->repository->reorder($slug, $orders);
        }
    }

    /**
     * 게시글의 첨부파일 목록을 조회합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string|null  $collection  컬렉션 필터
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAttachments(string $slug, int $postId, ?string $collection = null)
    {
        return $this->repository->getByPost($slug, $postId, $collection);
    }

    /**
     * 업로드된 첨부파일들을 롤백(삭제)합니다.
     *
     * 게시글 저장 실패 시 업로드된 파일들을 정리하기 위해 사용됩니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int>  $attachmentIds  첨부파일 ID 배열
     */
    public function rollbackUploadedFiles(string $slug, array $attachmentIds): void
    {
        foreach ($attachmentIds as $id) {
            $this->delete($slug, $id);
        }
    }

    /**
     * 첨부파일 다운로드 응답 생성
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @return StreamedResponse|null 파일 스트림 또는 없을 경우 null
     */
    public function download(string $slug, int $id, string $context = 'admin'): ?StreamedResponse
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return null;
        }

        // 컨텍스트 기반 스코프 접근 검사
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.attachments.download"
            : "sirsoft-board.{$slug}.attachments.download";

        if (! PermissionHelper::checkScopeAccess($attachment, $scopePermission)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        // RFC 5987에 따라 UTF-8 파일명 인코딩
        $encodedFilename = rawurlencode($attachment->original_filename);

        return $this->storage->response(
            'attachments',
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => "attachment; filename=\"{$attachment->original_filename}\"; filename*=UTF-8''{$encodedFilename}",
            ]
        );
    }

    /**
     * 첨부파일 URL 조회
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @return string|null 파일 URL 또는 없을 경우 null
     */
    public function getUrl(string $slug, int $id): ?string
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return null;
        }

        return $this->storage->url('attachments', $attachment->path);
    }

    /**
     * 이미지 미리보기 (권한 체크 없이)
     *
     * 이미지 파일을 권한 체크 없이 스트리밍합니다.
     * 비회원도 이미지를 볼 수 있습니다.
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @return StreamedResponse|null 이미지 스트림 또는 없을 경우 null
     */
    public function preview(string $slug, int $id): ?StreamedResponse
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return null;
        }

        // 이미지가 아닌 경우
        if (! $attachment->is_image) {
            return null;
        }

        return $this->storage->response(
            'attachments',
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline',
            ]
        );
    }

    /**
     * 이미지 파일 정보 조회 (캐싱 응답용)
     *
     * 컨트롤러에서 fileResponse()로 캐싱 헤더와 함께 응답할 수 있도록
     * 파일 경로와 메타 정보를 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return array{path: string, mime_type: string, filename: string}|null 파일 정보 또는 null
     */
    public function getFileInfo(string $slug, int $id): ?array
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return null;
        }

        // 파일 존재 확인
        if (! $this->storage->exists('attachments', $attachment->path)) {
            Log::error('게시판 첨부파일 스토리지에 없음', [
                'board_slug' => $slug,
                'attachment_id' => $attachment->id,
                'path' => $attachment->path,
            ]);

            return null;
        }

        // 전체 파일 경로 생성
        $basePath = $this->storage->getBasePath('attachments');
        $fullPath = $basePath.DIRECTORY_SEPARATOR.$attachment->path;

        return [
            'path' => $fullPath,
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_filename,
        ];
    }
}
