<?php

namespace Modules\Sirsoft\Page\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 페이지 첨부파일 서비스
 *
 * 페이지 첨부파일 업로드/삭제/다운로드/순서변경 비즈니스 로직을 담당합니다.
 */
class PageAttachmentService
{
    public function __construct(
        private PageAttachmentRepositoryInterface $attachmentRepository,
        private StorageInterface $storage,
    ) {}

    /**
     * 파일을 업로드합니다.
     *
     * @param  UploadedFile  $file  업로드 파일
     * @param  int|null  $pageId  페이지 ID (null이면 임시 업로드)
     * @param  string  $collection  파일 컬렉션명
     * @param  string|null  $tempKey  임시 키 (신규 페이지 생성 시)
     * @return PageAttachment 생성된 첨부파일 모델
     */
    public function upload(
        UploadedFile $file,
        ?int $pageId = null,
        string $collection = 'attachments',
        ?string $tempKey = null
    ): PageAttachment {
        HookManager::doAction('sirsoft-page.attachment.before_upload', $file, $pageId);

        $file = HookManager::applyFilters('sirsoft-page.attachment.filter_upload_file', $file);

        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();

        // 경로 결정: 페이지가 있으면 최종 경로, 없으면 임시 경로
        if ($pageId) {
            $path = date('Y/m/d').'/'.$storedFilename;
        } else {
            $path = 'temp/'.$tempKey.'/'.$storedFilename;
        }

        // StorageInterface를 통한 파일 저장
        $this->storage->put('attachments', $path, file_get_contents($file->getRealPath()));

        // 이미지 메타데이터 추출
        $meta = null;
        if (str_starts_with($file->getMimeType(), 'image/')) {
            $imageSize = @getimagesize($file->getRealPath());
            if ($imageSize) {
                $meta = [
                    'width' => $imageSize[0],
                    'height' => $imageSize[1],
                ];
            }
        }

        $maxOrder = $this->attachmentRepository->getMaxOrder($pageId, $tempKey);

        $attachment = $this->attachmentRepository->create([
            'page_id' => $pageId,
            'temp_key' => $pageId ? null : $tempKey,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $this->storage->getDisk(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'collection' => $collection,
            'order' => $maxOrder + 1,
            'meta' => $meta,
            'created_by' => Auth::id(),
        ]);

        HookManager::doAction('sirsoft-page.attachment.after_upload', $attachment);

        return $attachment;
    }

    /**
     * 임시 첨부파일을 페이지에 연결하고 파일을 이동합니다.
     *
     * getByTempKey 가 order(업로드 시점 부여) 순으로 반환하므로, 그 순서대로
     * order 를 1..N 으로 재부여한다 (이커머스 ProductImageService::linkTempImages 와 동일).
     *
     * @param  string  $tempKey  임시 키
     * @param  int  $pageId  연결할 페이지 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkTempAttachmentsWithMove(string $tempKey, int $pageId): int
    {
        $tempAttachments = $this->attachmentRepository->getByTempKey($tempKey);
        $linkedCount = 0;

        foreach ($tempAttachments as $index => $attachment) {
            // 최종 경로 생성
            $newPath = date('Y/m/d').'/'.$attachment->stored_filename;

            // 파일 물리적 이동 (get + put + delete)
            $content = $this->storage->get('attachments', $attachment->path);
            if ($content) {
                $this->storage->put('attachments', $newPath, $content);
                $this->storage->delete('attachments', $attachment->path);
            }

            // DB 업데이트: page_id 설정, temp_key 제거, path 변경, order 재배치 (조회 순서 = 업로드 순서)
            $this->attachmentRepository->update($attachment, [
                'page_id' => $pageId,
                'temp_key' => null,
                'path' => $newPath,
                'order' => $index + 1,
            ]);

            $linkedCount++;
        }

        // 임시 디렉토리 정리
        $this->storage->deleteDirectory('attachments', 'temp/'.$tempKey);

        return $linkedCount;
    }

    /**
     * 첨부파일을 삭제합니다 (파일 + DB).
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @return bool 삭제 성공 여부
     */
    public function deleteAttachment(PageAttachment $attachment): bool
    {
        HookManager::doAction('sirsoft-page.attachment.before_delete', $attachment);

        // 물리 파일 삭제
        $this->storage->delete('attachments', $attachment->path);

        // DB 소프트 삭제
        $result = $this->attachmentRepository->delete($attachment);

        HookManager::doAction('sirsoft-page.attachment.after_delete', $attachment);

        return $result;
    }

    /**
     * 첨부파일 순서를 변경합니다.
     *
     * @param  array<int, int>  $orders  [첨부파일 ID => 순서] 매핑
     * @return bool 변경 성공 여부
     */
    public function reorder(array $orders): bool
    {
        HookManager::doAction('sirsoft-page.attachment.before_reorder', $orders);

        $result = $this->attachmentRepository->reorder($orders);

        HookManager::doAction('sirsoft-page.attachment.after_reorder', $orders);

        return $result;
    }

    /**
     * 해시로 첨부파일을 조회합니다.
     *
     * @param  string  $hash  12자리 해시
     * @return PageAttachment|null 첨부파일 모델 또는 null
     */
    public function getByHash(string $hash): ?PageAttachment
    {
        return $this->attachmentRepository->findByHash($hash);
    }

    /**
     * ID로 첨부파일을 조회합니다.
     *
     * @param  int  $id  첨부파일 ID
     * @return PageAttachment 첨부파일 모델
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function getById(int $id): PageAttachment
    {
        return $this->attachmentRepository->findOrFail($id);
    }

    /**
     * 페이지의 첨부파일 목록을 조회합니다.
     *
     * @param  int  $pageId  페이지 ID
     * @return Collection 첨부파일 목록
     */
    public function getByPageId(int $pageId): Collection
    {
        return $this->attachmentRepository->getByPageId($pageId);
    }

    /**
     * 첨부파일 다운로드 스트리밍 응답을 생성합니다.
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @return StreamedResponse|null 스트리밍 응답 또는 null (파일 없음)
     */
    public function download(PageAttachment $attachment): ?StreamedResponse
    {
        if (! $this->storage->exists('attachments', $attachment->path)) {
            return null;
        }

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
     * 이미지 미리보기 스트리밍 응답을 생성합니다 (Content-Disposition: inline).
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @return StreamedResponse|null 스트리밍 응답 또는 null
     */
    public function preview(PageAttachment $attachment): ?StreamedResponse
    {
        if (! $attachment->isImage()) {
            return null;
        }

        if (! $this->storage->exists('attachments', $attachment->path)) {
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
     * 파일 URL을 반환합니다.
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @return string|null 파일 URL 또는 null
     */
    public function getUrl(PageAttachment $attachment): ?string
    {
        return $this->storage->url('attachments', $attachment->path);
    }

    /**
     * 첨부파일 삭제 권한을 확인합니다.
     *
     * @param  PageAttachment  $attachment  첨부파일 모델
     * @param  int|null  $userId  사용자 ID
     * @return bool 삭제 가능 여부
     */
    public function canDelete(PageAttachment $attachment, ?int $userId): bool
    {
        return $attachment->created_by === $userId;
    }
}
