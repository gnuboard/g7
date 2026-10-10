<?php

namespace Modules\Sirsoft\Page\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Helpers\PermissionHelper;
use App\Support\ImageResizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Support\AttachmentLimits;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 페이지 첨부파일 서비스
 *
 * 페이지 첨부파일 업로드/삭제/다운로드/순서변경 비즈니스 로직을 담당합니다.
 */
class PageAttachmentService
{
    /**
     * PageAttachmentService 생성자
     *
     * @param  PageAttachmentRepositoryInterface  $attachmentRepository  첨부파일 저장소
     * @param  StorageInterface  $storage  스토리지 드라이버 (모듈 서비스 프로바이더가 주입 — attachments 카테고리 디스크)
     * @param  PageRepositoryInterface  $pageRepository  페이지 저장소 (업로드 대상 페이지의 스코프 판정용)
     */
    public function __construct(
        private PageAttachmentRepositoryInterface $attachmentRepository,
        private StorageInterface $storage,
        private PageRepositoryInterface $pageRepository,
    ) {}

    /**
     * 첨부 개수가 설정 상한을 넘지 않는지 검증합니다 (도메인 불변조건).
     *
     * 총합은 **직접 업로드 + `temp_key` 임시첨부 + 기존 첨부** 이고 임시첨부 쪽은 DB 조회가
     * 필요하므로 FormRequest 만으로는 판정할 수 없습니다. 모든 연결 경로가 반드시 지나는
     * 이 지점이 상한의 SSoT 입니다.
     *
     * @param  int|null  $pageId  페이지 ID (신규 작성 중이면 null)
     * @param  int  $additional  이번에 추가하려는 첨부 개수
     * @param  string|null  $tempKey  임시 업로드 키 (신규 작성 시 기존 임시첨부 합산용)
     * @return void
     *
     * @throws AttachmentLimitExceededException 상한 초과 시
     */
    public function assertAttachmentCountWithin(?int $pageId, int $additional, ?string $tempKey = null): void
    {
        if ($additional <= 0) {
            return;
        }

        $limit = AttachmentLimits::maxCount();
        if ($limit <= 0) {
            // 상한 미설정은 제한하지 않는다 (기존 동작 유지)
            return;
        }

        $existing = 0;
        if ($pageId !== null) {
            $existing = $this->attachmentRepository->getByPageId($pageId)->count();
        } elseif ($tempKey !== null) {
            $existing = $this->attachmentRepository->getByTempKey($tempKey)->count();
        }

        $total = $existing + $additional;
        if ($total > $limit) {
            throw new AttachmentLimitExceededException($limit, $total);
        }
    }

    /**
     * 화면이 상한 안내·입력 제약에 쓰는 공개 설정을 반환합니다.
     *
     * 상한·허용 형식을 화면이 리터럴로 적으면 운영자가 설정을 바꾼 순간 화면과 서버가
     * 갈린다 — 사용자에게는 "왜 갑자기 안 되는지" 만 남는다.
     *
     * MIME → 확장자 변환도 **서버에서 한 번만** 한다. 화면이 변환하면 같은 매핑이 화면마다
     * 생기고, 형식을 추가할 때 한쪽만 늘어난다.
     *
     * @return array<string, mixed> 공개 설정
     */
    public function publicLimits(): array
    {
        // 해석(기본값·폴백)은 업로드 검증과 같은 하위 지점을 쓴다 — Service 가 FormRequest 의
        // 상수를 읽으면 하위 계층이 HTTP 계층에 기대는 역전이 된다.
        $maxCount = AttachmentLimits::maxCount();
        $maxSizeMb = AttachmentLimits::maxSizeMb();
        $allowedTypes = AttachmentLimits::allowedTypes();

        return [
            // 0 이하는 "제한 없음" 이다(서버 판정과 같은 뜻) — 화면은 컴포넌트 기본값을 쓴다.
            'max_count' => $maxCount > 0 ? $maxCount : null,
            'max_size_mb' => $maxSizeMb,
            'allowed_types' => $allowedTypes,
            'accept' => $this->acceptFromMimeTypes($allowedTypes),
        ];
    }

    /**
     * MIME 목록을 파일 선택창 필터 문자열로 바꿉니다.
     *
     * 매핑에 없는 MIME 은 그대로 실어 보낸다 — 브라우저가 MIME 자체도 `accept` 값으로
     * 받아 주므로, 모르는 형식을 조용히 버려 고를 수 없게 만드는 것보다 낫다.
     *
     * @param  array<int, string>  $mimeTypes  허용 MIME 목록
     * @return string `accept` 문자열
     */
    private function acceptFromMimeTypes(array $mimeTypes): string
    {
        $map = [
            'image/jpeg' => ['.jpg', '.jpeg'],
            'image/png' => ['.png'],
            'image/gif' => ['.gif'],
            'image/webp' => ['.webp'],
            'application/pdf' => ['.pdf'],
            'application/zip' => ['.zip'],
            'application/x-zip-compressed' => ['.zip'],
        ];

        $extensions = [];
        foreach ($mimeTypes as $mimeType) {
            foreach ($map[$mimeType] ?? [$mimeType] as $extension) {
                $extensions[$extension] = true;
            }
        }

        return implode(',', array_keys($extensions));
    }

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
        // 페이지도 임시 키도 없는 업로드는 서버가 키를 만든다 (이커머스 이미지 업로드와 동일) —
        // 비워 두면 임시 경로가 `temp//파일` 이 되어 저장소 경로 가드가 거부한다
        if (! $pageId && ! $tempKey) {
            $tempKey = Str::uuid()->toString();
        }

        // 기존 페이지에 붙이는 업로드는 그 페이지의 수정 권한·스코프를 본다 — 업로드 라우트는 정적 경로라 미들웨어
        // 스코프 검사를 건너뛰고, 등록 권한만으로도 통과하므로 서비스가 다시 판정한다
        if ($pageId) {
            $this->assertUploadTargetPage($pageId);
        }

        // 개수 상한 최종 방어선 — 단일 업로드를 N 번 반복하는 경로도 여기서 막힌다
        $this->assertAttachmentCountWithin($pageId, 1, $tempKey);

        HookManager::doAction('sirsoft-page.attachment.before_upload', $file, $pageId);

        $file = HookManager::applyFilters('sirsoft-page.attachment.filter_upload_file', $file);

        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();

        // 경로 결정: 페이지가 있으면 최종 경로, 없으면 임시 경로
        if ($pageId) {
            $path = date('Y/m/d').'/'.$storedFilename;
        } else {
            $path = 'temp/'.$tempKey.'/'.$storedFilename;
        }

        // 환경설정 > 업로드의 최대 가로/세로·품질을 적용한다 (코어 설정이 모든 업로드 경로에 동일 적용).
        // 임시 파일을 제자리에서 줄이므로 아래의 저장·크기 계산이 모두 축소본을 본다.
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

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
     * getByTempKey 가 order(업로드 시점 부여) 순으로 반환하므로, 그 순서대로 order 를 다시 매긴다
     * (이커머스 ProductImageService::linkTempImages 와 동일). 이미 첨부가 있는 페이지(수정 화면)에서는
     * 기존 첨부의 마지막 번호 뒤에 붙인다 — 1 부터 다시 매기면 새 첨부가 기존 첨부와 같은 번호를 받아
     * 목록 중간에 낀다. 새 페이지는 기존 첨부가 없어 1..N 이 된다.
     *
     * @param  string  $tempKey  임시 키
     * @param  int  $pageId  연결할 페이지 ID
     * @return int 연결된 첨부파일 수
     */
    public function linkTempAttachmentsWithMove(string $tempKey, int $pageId): int
    {
        $tempAttachments = $this->attachmentRepository->getByTempKey($tempKey);

        // 연결할 임시 첨부가 없으면 디렉토리 정리도 하지 않는다 — temp_key 는 요청에서 온 값이라
        // 조회 결과 없이 정리 경로를 만들면 사용자 입력으로 삭제 대상을 고르게 된다 (KVE-2026-2349)
        if ($tempAttachments->isEmpty()) {
            return 0;
        }

        // 개수 상한 최종 방어선 — FormRequest 는 단건 업로드만 보므로 temp_key 연결 경로가 그대로 뚫린다
        $this->assertAttachmentCountWithin($pageId, $tempAttachments->count());
        $linkedCount = 0;
        $baseOrder = $this->attachmentRepository->getMaxOrder($pageId);

        foreach ($tempAttachments as $index => $attachment) {
            // 최종 경로 생성
            $newPath = date('Y/m/d').'/'.$attachment->stored_filename;

            // 파일 물리적 이동 (get + put + delete)
            $content = $this->storage->get('attachments', $attachment->path);
            if ($content) {
                $this->storage->put('attachments', $newPath, $content);
                $this->storage->delete('attachments', $attachment->path);
            }

            // DB 업데이트: page_id 설정, temp_key 제거, path 변경, order 재배치 (조회 순서 = 업로드 순서, 기존 첨부 뒤)
            $this->attachmentRepository->update($attachment, [
                'page_id' => $pageId,
                'temp_key' => null,
                'path' => $newPath,
                'order' => $baseOrder + $index + 1,
            ]);

            $linkedCount++;
        }

        // 임시 디렉토리 정리 — 부수 작업이라 실패해도 연결을 되돌리지 않는다. 막 옮긴 대용량 파일을 운영체제가
        // 잠시 붙잡고 있으면 디렉토리 순회가 lstat 오류를 던져 저장 전체가 500 이 되고 파일·DB 경로가 어긋났다(#703 게시판 실측).
        try {
            $this->storage->deleteDirectory('attachments', 'temp/'.$tempKey);
        } catch (\Throwable $e) {
            Log::warning('페이지 임시 첨부 디렉토리 정리 실패 — 연결은 완료, 빈 디렉토리가 남음', [
                'temp_key' => $tempKey,
                'error' => $e->getMessage(),
            ]);
        }

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
        $this->assertPageScope($attachment);

        HookManager::doAction('sirsoft-page.attachment.before_delete', $attachment);

        // 물리 파일 삭제
        $this->storage->delete('attachments', $attachment->path);

        // DB 소프트 삭제
        $result = $this->attachmentRepository->delete($attachment);

        HookManager::doAction('sirsoft-page.attachment.after_delete', $attachment);

        return $result;
    }

    /**
     * 페이지에 연결되지 않은 채 방치된 임시 첨부를 정리합니다.
     *
     * 페이지 작성 폼에서 첨부만 올리고 저장하지 않고 이탈하면 `temp_key` 만 남은 행과
     * 그 파일이 남습니다. 연결 시점에 본경로로 옮겨지므로 `temp_key` 가 남아 있다는 것은
     * "끝내 연결되지 않았다" 는 뜻이고, 그래서 오탐 여지가 없습니다.
     *
     * 파일 → 행 순서를 지켜, 행이 먼저 사라져 파일을 못 찾는 상태를 만들지 않습니다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  한 회차에 처리할 최대 건수
     * @param  bool  $dryRun  true 면 대상만 세고 삭제하지 않음
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function pruneTempUploads(int $days, int $limit, bool $dryRun = false): array
    {
        $threshold = Carbon::now()->subDays($days);
        $attachments = $this->attachmentRepository->findStaleTempAttachments($threshold, $limit);

        $result = ['scanned' => $attachments->count(), 'deleted' => 0, 'failed' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($attachments as $attachment) {
            $storage = $this->storageForRow($attachment->disk);

            if ($storage->exists('attachments', $attachment->path) && ! $storage->delete('attachments', $attachment->path)) {
                Log::warning('임시 페이지 첨부 파일 삭제 실패 — 기록 보존', [
                    'attachment_id' => $attachment->id,
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                ]);

                $result['failed']++;

                continue;
            }

            $this->attachmentRepository->delete($attachment);
            $result['deleted']++;
        }

        $this->removeEmptyTempDirectories($attachments);

        return $result;
    }

    /**
     * 파일을 모두 지운 temp_key 디렉토리를 정리합니다.
     *
     * 파일만 지우고 디렉토리를 남기면 폼 세션마다 빈 디렉토리가 쌓여, 정리를 돌려도
     * 저장소에는 흔적이 계속 늘어납니다.
     *
     * 디렉토리에 파일이 남아 있으면(같은 temp_key 의 다른 첨부가 limit 에 걸려 이번 회차에서
     * 빠졌거나 파일 삭제에 실패한 경우 등) 삭제하지 않습니다.
     *
     * @param  Collection  $attachments  이번 회차에 처리한 첨부 목록
     * @return void
     */
    private function removeEmptyTempDirectories(Collection $attachments): void
    {
        $directories = [];

        foreach ($attachments as $attachment) {
            $directory = dirname((string) $attachment->path);

            if ($directory === '' || $directory === '.') {
                continue;
            }

            $directories[$attachment->disk.'|'.$directory] = [$attachment->disk, $directory];
        }

        foreach ($directories as [$disk, $directory]) {
            $storage = $this->storageForRow($disk);

            if ($storage->files('attachments', $directory) !== []) {
                continue;
            }

            $storage->deleteDirectory('attachments', $directory);
        }
    }

    /**
     * 첨부 행에 기록된 disk 기준 스토리지를 반환합니다.
     *
     * 디스크를 전환한 뒤에도 전환 이전 행의 파일을 그 행의 실제 저장 위치에서 지우기 위한
     * 해석입니다. 미등록 disk(그 디스크를 제공하던 확장이 비활성화된 경우)는 주입 스토리지로
     * 폴백합니다 — withDisk 로 미등록 disk 인스턴스를 만들면 이후 호출이 예외가 됩니다.
     *
     * @param  string|null  $disk  행의 disk 컬럼 값
     * @return StorageInterface 행 disk 의 스토리지
     */
    private function storageForRow(?string $disk): StorageInterface
    {
        if ($disk === null || $disk === '' || $disk === $this->storage->getDisk()) {
            return $this->storage;
        }

        if (config("filesystems.disks.{$disk}") === null) {
            return $this->storage;
        }

        return $this->storage->withDisk($disk);
    }

    /**
     * 첨부파일 순서를 변경합니다.
     *
     * @param  array<int, int>  $orders  [첨부파일 ID => 순서] 매핑
     * @return bool 변경 성공 여부
     */
    public function reorder(array $orders): bool
    {
        $this->assertReorderScope($orders);

        HookManager::doAction('sirsoft-page.attachment.before_reorder', $orders);

        $result = $this->attachmentRepository->reorder($orders);

        HookManager::doAction('sirsoft-page.attachment.after_reorder', $orders);

        return $result;
    }

    /**
     * 첨부의 부모 페이지가 액터의 스코프 안에 있는지 검사합니다.
     *
     * 첨부 라우트는 `{id}`(정수)로 선언돼 있어 라우트 모델 바인딩이 일어나지 않고,
     * 순서 변경은 아예 정적 경로다. 두 경우 모두 PermissionMiddleware 의 스코프 검사가
     * 스킵되므로(모델이 resolve 되지 않으면 목록 엔드포인트로 간주) 서비스가 재적용한다.
     * 스코프 대상은 첨부가 아니라 **부모 페이지**다 — `sirsoft-page.pages.update` 의
     * owner_key 가 페이지의 `created_by` 이기 때문이다.
     *
     * 아직 페이지에 연결되지 않은 임시 첨부(`page_id` 없음)는 부모가 없으므로 **올린 사람**만
     * 다룰 수 있다. 작성 화면은 [저장] 때 첨부를 먼저 올리고 페이지를 만들므로, 페이지 저장이
     * 검증 오류로 실패하면 임시 첨부가 업로더 목록에 남는다 — 이것을 지우는 요청까지 부모 없음으로
     * 막으면 사용자는 방금 올린 파일을 뺄 수 없다.
     *
     * @param  PageAttachment  $attachment  대상 첨부
     * @return void
     *
     * @throws AccessDeniedHttpException 부모 페이지가 스코프 밖이거나, 남이 올린 임시 첨부인 경우
     */
    private function assertPageScope(PageAttachment $attachment): void
    {
        if ($attachment->page_id === null) {
            if ($attachment->created_by === null || (int) $attachment->created_by !== (int) Auth::id()) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            return;
        }

        $page = $attachment->page;

        // 부모를 못 읽으면 막는다(fail-closed) — 첨부에 page_id 가 있는데 페이지를 못 찾는
        // 것은 정상 상태가 아니고, 통과시키면 게이트가 있어야 할 자리가 비어 버린다.
        // 삭제 라우트가 등록·수정 어느 권한으로도 열리므로(임시 첨부 정리) 연결된 첨부는 수정 권한을 여기서 직접 본다.
        if (! $page
            || ! PermissionHelper::check('sirsoft-page.pages.update', Auth::user())
            || ! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * 기존 페이지에 첨부를 더하는 업로드가 그 페이지의 수정 권한·스코프 안인지 검사합니다.
     *
     * @param  int  $pageId  대상 페이지 ID
     * @return void
     *
     * @throws AccessDeniedHttpException 페이지가 없거나 수정 권한·스코프 밖인 경우
     */
    private function assertUploadTargetPage(int $pageId): void
    {
        $page = $this->pageRepository->findById($pageId);

        if (! $page
            || ! PermissionHelper::check('sirsoft-page.pages.update', Auth::user())
            || ! PermissionHelper::checkScopeAccess($page, 'sirsoft-page.pages.update')) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * 순서 변경 대상 첨부 전체가 액터의 스코프 안에 있는지 검사합니다.
     *
     * 순서는 집합 전체에 대한 하나의 배열이라 일부만 반영하면 나머지와 어긋난다 —
     * 걸러내지 않고 전량 거부한다(코어 첨부/메뉴 순서 변경과 같은 의미론).
     *
     * 대상 ID 는 `reorder()` 가 받는 `[첨부 ID => 순서]` 매핑의 **키**다. 값(순서)에서 `id` 를 찾으면
     * ID 를 하나도 읽지 못해 검사 없이 통과한다.
     *
     * @param  array<int, int>  $orders  [첨부 ID => 순서] 매핑
     * @return void
     *
     * @throws AccessDeniedHttpException 스코프 밖 첨부가 하나라도 포함된 경우
     */
    private function assertReorderScope(array $orders): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, array_keys($orders))
        )));

        foreach ($ids as $id) {
            // 존재하지 않는 id 는 findOrFail 이 404 로 끊는다 — 통과시키면 안 된다.
            $this->assertPageScope($this->attachmentRepository->findOrFail($id));
        }
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
     * @throws ModelNotFoundException
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

        $response = $this->storage->response(
            'attachments',
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => "attachment; filename=\"{$attachment->original_filename}\"; filename*=UTF-8''{$encodedFilename}",
            ]
        );

        // nosniff — 다운로드 응답도 브라우저가 내용을 추측해 렌더하지 않도록 (KVE-2026-2366)
        if ($response) {
            InlineMediaPolicy::harden($response, $attachment->mime_type, $attachment->original_filename);
        }

        return $response;
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

        $response = $this->storage->response(
            'attachments',
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline',
            ]
        );

        // 인라인은 래스터 이미지만 — isImage() 가 이미 거르지만 서빙 응답에도 nosniff 를 건다 (KVE-2026-2366)
        if ($response) {
            InlineMediaPolicy::harden($response, $attachment->mime_type, $attachment->original_filename);
        }

        return $response;
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
