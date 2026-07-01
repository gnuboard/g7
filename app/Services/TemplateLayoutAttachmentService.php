<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\TemplateLayoutAttachmentRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Models\TemplateLayoutAttachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 템플릿 레이아웃 첨부 파일 서비스
 *
 * 레이아웃 편집 중 업로드되는 파일(배경 이미지 등)의 업로드·조회·삭제를 처리한다.
 * 파일 저장은 코어 StorageInterface(CoreStorageDriver)를 통해서만 수행하고,
 * 저장 위치(disk/path)와 메타데이터를 template_layout_attachments 에 기록한다.
 * (Storage::disk() 직접 호출 금지 — CLAUDE.md 스토리지 규칙)
 */
class TemplateLayoutAttachmentService
{
    /** 스토리지 카테고리 — {category}/{path} 경로 패턴의 prefix */
    private const STORAGE_CATEGORY = 'template-layout-attachments';

    /**
     * @param  TemplateLayoutAttachmentRepositoryInterface  $repository  첨부 리포지토리
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 리포지토리
     * @param  StorageInterface  $storage  코어 스토리지 드라이버
     */
    public function __construct(
        private TemplateLayoutAttachmentRepositoryInterface $repository,
        private TemplateRepositoryInterface $templateRepository,
        private StorageInterface $storage,
    ) {}

    /**
     * 첨부 파일을 업로드하고 행을 생성합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자 (vendor-name 형식)
     * @param  UploadedFile  $file  업로드 파일
     * @param  string|null  $layoutName  사용 출처 레이아웃 이름
     * @return array{success: bool, attachment: TemplateLayoutAttachment|null, url: string|null, error: string|null}
     */
    public function upload(string $templateIdentifier, UploadedFile $file, ?string $layoutName = null): array
    {
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $template) {
            return ['success' => false, 'attachment' => null, 'url' => null, 'error' => 'template_not_found'];
        }

        // 저장 경로 — 템플릿 식별자/날짜별 디렉토리 + UUID 파일명 (충돌 회피)
        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $relativePath = "{$templateIdentifier}/".date('Y/m/d')."/{$storedFilename}";

        $disk = config('attachment.disk', 'attachments');
        $stored = $this->storage
            ->withDisk($disk)
            ->put(self::STORAGE_CATEGORY, $relativePath, file_get_contents($file->getRealPath()));

        if (! $stored) {
            return ['success' => false, 'attachment' => null, 'url' => null, 'error' => 'storage_failed'];
        }

        $attachment = $this->repository->create([
            'template_id' => $template->id,
            'layout_name' => $layoutName,
            'disk' => $disk,
            'path' => $relativePath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'created_by' => Auth::id(),
        ]);

        Log::info('레이아웃 첨부 파일 업로드 완료', [
            'attachment_id' => $attachment->id,
            'template_id' => $template->id,
            'path' => $relativePath,
        ]);

        return [
            'success' => true,
            'attachment' => $attachment,
            'url' => $this->resolveUrl($attachment),
            'error' => null,
        ];
    }

    /**
     * 템플릿(+선택적 레이아웃)별 첨부 파일 목록을 조회합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  string|null  $layoutName  레이아웃 이름 (null 이면 템플릿 전체)
     * @return array{success: bool, attachments: Collection<int, TemplateLayoutAttachment>|null, error: string|null}
     */
    public function list(string $templateIdentifier, ?string $layoutName = null): array
    {
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        if (! $template) {
            return ['success' => false, 'attachments' => null, 'error' => 'template_not_found'];
        }

        return [
            'success' => true,
            'attachments' => $this->repository->listForTemplate($template->id, $layoutName),
            'error' => null,
        ];
    }

    /**
     * 첨부 파일을 삭제합니다 — 스토리지 파일 실삭제 후 DB 행 삭제.
     *
     * DB CASCADE 에 의존하지 않고 스토리지 파일을 명시적으로 삭제한다
     * (CLAUDE.md "DB CASCADE 의존 삭제 금지" — 파일 정리 보장).
     *
     * @param  TemplateLayoutAttachment  $attachment  삭제할 첨부 파일
     * @return bool 삭제 성공 여부
     */
    public function delete(TemplateLayoutAttachment $attachment): bool
    {
        // 1. 스토리지 파일 실삭제 (명시적 — CASCADE 미의존)
        $this->storage
            ->withDisk($attachment->disk)
            ->delete(self::STORAGE_CATEGORY, $attachment->path);

        // 2. DB 행 삭제
        return $this->repository->delete($attachment);
    }

    /**
     * 첨부 파일의 공개 접근 URL을 생성합니다.
     *
     * 첨부 파일은 비공개 `attachments` 디스크에 저장되어 직접 공개 URL 이 없다
     * (`StorageInterface::url()` 은 public 디스크 전용). 발행된 배경 이미지는 일반
     * 사이트 방문자에게도 로드되어야 하므로, 인증 불필요한 공개 서빙 라우트
     * (`PublicTemplateController::serveFile`)의 URL 을 돌려준다. 라우트는 첨부 id 로
     * 키되며 서빙 시 첨부가 해당 템플릿 소속인지 검증한다.
     *
     * @param  TemplateLayoutAttachment  $attachment  첨부 파일
     * @return string 공개 서빙 URL
     */
    public function resolveUrl(TemplateLayoutAttachment $attachment): string
    {
        $template = $attachment->template;
        $identifier = $template?->identifier ?? (string) $attachment->template_id;

        return route('api.public.templates.layout-attachment-file', [
            'identifier' => $identifier,
            'attachment' => $attachment->id,
        ]);
    }

    /**
     * 서빙 가능한 첨부 파일의 절대 경로를 돌려줍니다.
     *
     * 첨부가 주어진 템플릿 소속인지 검증하고, 스토리지에 파일이 실제로 존재하면
     * 컨트롤러가 스트림할 수 있는 절대 파일시스템 경로를 반환한다. 소속 불일치 /
     * 파일 부재 시 null (컨트롤러가 404).
     *
     * @param  string  $templateIdentifier  요청 경로의 템플릿 식별자
     * @param  TemplateLayoutAttachment  $attachment  서빙 대상 첨부
     * @return string|null 절대 파일 경로 또는 null
     */
    public function getServableFilePath(string $templateIdentifier, TemplateLayoutAttachment $attachment): ?string
    {
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        // 경로 식별자와 첨부의 소속 템플릿이 일치해야 한다 (교차 템플릿 접근 차단).
        if (! $template || $attachment->template_id !== $template->id) {
            return null;
        }

        $driver = $this->storage->withDisk($attachment->disk);
        if (! $driver->exists(self::STORAGE_CATEGORY, $attachment->path)) {
            return null;
        }

        // 절대 경로 — {category base}/{relative path}. fileResponse 가 절대 경로를 요구.
        return rtrim($driver->getBasePath(self::STORAGE_CATEGORY), '/\\').'/'.$attachment->path;
    }
}
