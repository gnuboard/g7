<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\TemplateLayoutAttachmentRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\HookManager;
use App\Models\TemplateLayoutAttachment;
use App\Support\ImageResizer;
use App\Support\PublicAssetDisk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 템플릿 레이아웃 첨부 파일 서비스
 *
 * 레이아웃 편집 중 업로드되는 파일(배경 이미지 등)의 업로드·조회·삭제를 처리한다.
 * 파일 저장은 코어 StorageInterface(CoreStorageDriver)를 통해서만 수행하고,
 * 저장 위치(disk/path)와 메타데이터를 template_layout_attachments 에 기록한다.
 * (Storage::disk() 직접 호출 금지 — 코어 스토리지 규칙)
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

        // 훅: 업로드 전 (확장 지점)
        HookManager::doAction('core.template_layout_attachment.before_upload', $file, $templateIdentifier, $layoutName);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('core.template_layout_attachment.filter_upload_file', $file);

        // 저장 경로 — 템플릿 식별자/날짜별 디렉토리 + UUID 파일명 (충돌 회피)
        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $relativePath = "{$templateIdentifier}/".date('Y/m/d')."/{$storedFilename}";

        // 운영자가 공개 자산 디스크를 선언했으면 그쪽에 저장한다 (방문자 요청이 CDN 을 탄다).
        // 미선언이면 기존 첨부 디스크 그대로. 행이 자기 disk 를 기록하므로 나중에 설정이
        // 바뀌어도 각 행은 자기 저장 위치를 기억한다.
        $disk = PublicAssetDisk::resolve() ?? config('attachment.disk', 'attachments');

        // 환경설정 > 업로드의 최대 가로/세로·품질 적용 (코어 설정이 모든 업로드 경로에 동일 적용).
        // 임시 파일을 제자리에서 줄이므로 아래의 저장·크기 기록이 모두 축소본을 본다.
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

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

        HookManager::doAction('core.template_layout_attachment.after_upload', $attachment);

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
     * (코어 규정: DB CASCADE 의존 삭제 금지 — 파일 정리 보장).
     *
     * @param  TemplateLayoutAttachment  $attachment  삭제할 첨부 파일
     * @return bool 삭제 성공 여부
     */
    public function delete(TemplateLayoutAttachment $attachment): bool
    {
        // 1. 스토리지 파일 실삭제 (명시적 — CASCADE 미의존)
        $this->storageForRow($attachment->disk)
            ->delete(self::STORAGE_CATEGORY, $attachment->path);

        // 2. DB 행 삭제
        return $this->repository->delete($attachment);
    }

    /**
     * 첨부 파일의 공개 접근 URL을 생성합니다.
     *
     * 운영자가 공개 자산 디스크(`core.storage.public_asset_disk`)를 선언했고 행 disk 가
     * 그 값과 일치할 때만 직접 URL(CDN)을 돌려준다. 그 외에는 인증 불필요한 공개 서빙
     * 라우트(`PublicTemplateController::serveFile`)의 URL 을 돌려주며, 라우트는 첨부 id 로
     * 키되고 서빙 시 첨부가 해당 템플릿 소속인지 검증한다.
     *
     * 디스크의 `url` 설정 유무로 판정하지 않는다 — 그 설정은 "URL 문자열을 만들 수
     * 있는가" 일 뿐 "익명 읽기가 되는가" 가 아니어서, 비공개 버킷에 공개 URL 이
     * 설정된 조합에서는 발급된 직접 URL 이 403 이 된다.
     *
     * @param  TemplateLayoutAttachment  $attachment  첨부 파일
     * @return string 직접 URL 또는 공개 서빙 URL
     */
    public function resolveUrl(TemplateLayoutAttachment $attachment): string
    {
        return $this->resolveDirectUrl($attachment) ?? $this->proxyUrl($attachment);
    }

    /**
     * 행 disk 가 공개 자산 디스크일 때만 직접 URL 해석을 시도합니다.
     *
     * @param  TemplateLayoutAttachment  $attachment  첨부 파일
     * @return string|null 직접 URL (대상이 아니거나 훅이 차단하면 null)
     */
    private function resolveDirectUrl(TemplateLayoutAttachment $attachment): ?string
    {
        if (! PublicAssetDisk::isCurrent($attachment->disk)) {
            return null;
        }

        // 게이트를 통과한 disk 는 PublicAssetDisk::resolve() 가 존재를 이미 확인했다.
        return $this->storage
            ->withDisk($attachment->disk)
            ->url(self::STORAGE_CATEGORY, $attachment->path);
    }

    /**
     * 공개 서빙 라우트(프록시) URL 을 생성합니다.
     *
     * @param  TemplateLayoutAttachment  $attachment  첨부 파일
     * @return string 공개 서빙 URL
     */
    private function proxyUrl(TemplateLayoutAttachment $attachment): string
    {
        $template = $attachment->template;
        $identifier = $template?->identifier ?? (string) $attachment->template_id;

        return route('api.public.templates.layout-attachment-file', [
            'identifier' => $identifier,
            'attachment' => $attachment->id,
        ]);
    }

    /**
     * 행에 기록된 disk 기준 스토리지를 반환합니다 (고아 disk 방어).
     *
     * 공개 자산 디스크는 플러그인이 등록한 디스크일 수 있고, 그 플러그인이 비활성화되면
     * 해당 disk 가 config 에서 사라진다. 미등록 disk 로 withDisk 를 만들면 이후
     * response/delete 가 InvalidArgumentException 을 던져 **무인증 공개 서빙 라우트가
     * 500** 이 되므로, 주입 스토리지로 폴백해 404 로 끝나게 한다.
     *
     * @param  string|null  $disk  행의 disk 컬럼 값
     * @return StorageInterface 행 disk 의 스토리지 (고아면 주입 스토리지)
     */
    private function storageForRow(?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || config("filesystems.disks.{$disk}") === null) {
            return $this->storage;
        }

        return $this->storage->withDisk($disk);
    }

    /**
     * 서빙 가능한 첨부의 인라인 스트림 응답과 캐싱 메타를 돌려줍니다.
     *
     * 첨부가 주어진 템플릿 소속인지 검증하고, 행 disk 를 따르는 스토리지 스트림
     * 응답을 반환한다 (S3 등 원격 디스크 행 포함 — 로컬 절대 경로 방식은 #99 에서
     * filemtime 500). 소속 불일치 / 파일 부재 시 null (컨트롤러가 404).
     *
     * @param  string  $templateIdentifier  요청 경로의 템플릿 식별자
     * @param  TemplateLayoutAttachment  $attachment  서빙 대상 첨부
     * @return array{response: StreamedResponse, etag_source: string}|null 서빙 정보 또는 null
     */
    public function getServableResponse(string $templateIdentifier, TemplateLayoutAttachment $attachment): ?array
    {
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);
        // 경로 식별자와 첨부의 소속 템플릿이 일치해야 한다 (교차 템플릿 접근 차단).
        if (! $template || $attachment->template_id !== $template->id) {
            return null;
        }

        // 행 disk 기준 인라인 스트림 (존재 검사는 response() 내부에서 수행).
        // 로컬 절대 경로 조립(getBasePath)은 S3 등 원격 디스크 행에서 성립하지 않는다 (#99).
        $response = $this->storageForRow($attachment->disk)->response(
            self::STORAGE_CATEGORY,
            $attachment->path,
            $attachment->original_name ?? basename($attachment->path),
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Length' => (string) $attachment->size,
            ]
        );

        if (! $response) {
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
        ];
    }
}
