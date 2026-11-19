<?php

namespace App\Services;

use App\Contracts\Extension\StorageInterface;
use App\Contracts\Repositories\AttachmentRepositoryInterface;
use App\Contracts\Repositories\IdentityPolicyRepositoryInterface;
use App\Enums\AttachmentSourceType;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Helpers\InlineMediaPolicy;
use App\Helpers\PermissionHelper;
use App\Models\Attachment;
use App\Models\User;
use App\Services\Concerns\ResolvesRowStorage;
use App\Support\Favicon\FaviconPolicy;
use App\Support\ImageResizer;
use App\Support\PrivateAttachmentDisk;
use App\Support\SettingAttachmentReferences;
use App\Support\UploadedFileName;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\Providers\CoreAttachmentSourceProvider;
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
    use ResolvesRowStorage;

    /** 내려받기 전 액션 훅 — hook 범위 본인인증 정책의 대상 */
    public const DOWNLOAD_ACTION_HOOK = 'core.attachment.before_download_action';

    /** 첨부 서빙 라우트 이름 — route 범위 본인인증 정책의 대상 */
    public const DOWNLOAD_ROUTE = 'api.attachment.download';

    /**
     * 다운로드 가능 판정 필터 — `(null, Attachment $attachment, ?User $user)` → `null`(의견 없음) | `true`(허용) | `false`(거부, 403).
     *
     * 확장은 자기가 만든 행(`source_identifier`)에 대해서만, 그 행이 붙은 원 리소스의 열람 권한으로 답한다. 코어는 확장을 모른다.
     */
    public const CAN_DOWNLOAD_FILTER = 'core.attachment.filter_can_download';

    /**
     * AttachmentService 생성자
     *
     * 넷째 인자는 뒤에 더한 선택 인자다 — 앞 세 인자만 넘겨 만드는 기존 하위 클래스·호출이 깨지지 않게 한다.
     *
     * @param  AttachmentRepositoryInterface  $repository  첨부파일 리포지토리
     * @param  StorageInterface  $storage  스토리지 드라이버
     * @param  ImageResizer  $imageResizer  업로드 이미지 축소기
     * @param  IdentityPolicyRepositoryInterface|null  $identityPolicies  본인인증 정책 리포지토리 (서빙 캐시 판정, 없으면 쓸 때 컨테이너에서 꺼낸다)
     */
    public function __construct(
        private AttachmentRepositoryInterface $repository,
        private StorageInterface $storage,
        private ImageResizer $imageResizer,
        private ?IdentityPolicyRepositoryInterface $identityPolicies = null
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

        // 위 한 줄의 약속("모두 축소본을 본다")을 실제로 성립시킨다.
        //
        // 축소는 **제자리 덮어쓰기**이고, `ImageResizer::resizeInPlace()` 는 쓰기 **전에**
        // `is_file()` 로 그 경로를 stat 하므로 PHP 공유 stat 캐시가 반드시 채워진다.
        // 그 뒤의 `getSize()` 는 그 캐시를 그대로 읽어 **축소 전 크기**를 돌려주고, 그 값이
        // 행의 `size` → 서빙의 `Content-Length` 로 **선언**되어 응답이 잘린다. `getimagesize()`
        // 는 파일을 직접 읽으므로 메타(width/height)만 축소본을 보고 크기만 어긋난다 —
        // 두 값이 한 행에서 서로 다른 파일을 가리키는 상태다.
        // 형제 `sirsoft-board` 는 같은 사유로 이미 이 처리를 갖는다.
        clearstatcache(true, $file->getRealPath());

        // 저장 경로 생성 (날짜별 디렉토리).
        // 확장자는 클라이언트가 보낸 파일명이 아니라 MIME 추론값을 우선 사용한다 —
        // 파일명 확장자는 사용자가 임의로 바꿀 수 있어 저장 확장자의 근거가 될 수 없다.
        // audit:allow stored-filename-client-extension 내용 추론값(`extension()`)이 우선이고, 추론하지 못할 때만
        // 이름 확장자를 쓴다. 그 이름 확장자는 업로드 요청이 허용 목록(`extensions:`)·내용(AttachmentFileContent)으로
        // 교차 검증한 값이다.
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $storedFilename = Str::uuid().($extension !== '' ? '.'.$extension : '');
        // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
        $datePath = date('Y/m/d');
        $path = "{$datePath}/{$storedFilename}";

        // 스토리지에 파일 저장
        // 비공개 첨부 저장소 선택을 따른다(#916) — ATTACHMENT_DISK env 명시 > 선택 > 종전 규칙(s3 이면 s3, 아니면 attachments)
        $disk = PrivateAttachmentDisk::forCore();
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
            'original_filename' => UploadedFileName::original($file),
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
     * 로고·기본 공유 이미지·파비콘·템플릿 설정 첨부는 소유자(attachmentable) 없이 설정값이 직접 참조하는 첨부라
     * 판정식만으로는 고아와 구분되지 않습니다. 보호 목록은 설정 선언에서 파생합니다({@see SettingAttachmentReferences}) —
     * 종전에는 사이트 로고 한 줄을 손으로 적어 기본 공유 이미지가 빠졌습니다(#434).
     *
     * @return array<int, int> 보호할 첨부 ID 목록
     */
    private function protectedAttachmentIds(): array
    {
        return SettingAttachmentReferences::ids();
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
                $this->storageForRow($attachment->disk)->delete('', $attachment->path);
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 캐시 계약(검증자·304)은 이 스트림에 실리지 않는다 — `getDownloadInfo()` 가 돌려주는
     * 메타를 컨트롤러가 코어 단일 지점(`streamedFileResponse()`)에 넘겨 입힌다. 이 메서드는
     * 그 정보의 `response` 만 돌려주는 하위호환 표면이다.
     *
     * @param  string  $hash  첨부파일 해시
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return StreamedResponse|null 다운로드 응답 또는 null
     *
     * @throws AuthorizationException 기능 레벨 권한이 없는 경우
     */
    public function download(string $hash, mixed $user = null): ?StreamedResponse
    {
        return $this->getDownloadInfo($hash, $user)['response'] ?? null;
    }

    /**
     * 다운로드(첨부 방식) 서빙 정보 조회
     *
     * 이미지 인라인 통로(`getFileInfo()`)와 **같은 서빙 메타**(ETag 소스·수정 시각)를 돌려준다.
     * 종전에는 비이미지 다운로드가 `download()` 하나로 끝나 검증자가 하나도 없었다 —
     * 같은 사이트에서 같은 첨부가 이미지면 304 로 재검증되고 문서면 매번 전문을 받아 갔다.
     * 오류도 로그도 없이 대역폭으로만 나타나고, 정적 서빙(링크가 되는 서버)은 두 유형을
     * 가리지 않으므로 링크 유무로 동작이 갈리는 상태였다.
     *
     * 훅 순서는 종전 `download()` 와 같다 — 권한 훅 → 액션 훅(IDV 가드) → 필터 훅.
     *
     * @param  string  $hash  첨부파일 해시
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null, private: bool}|null 서빙 정보 또는 null
     *
     * @throws AuthorizationException 기능 레벨 권한이 없는 경우
     */
    public function getDownloadInfo(string $hash, mixed $user = null): ?array
    {
        $attachment = $this->repository->findByHash($hash);

        if (! $attachment) {
            return null;
        }

        $this->assertDownloadAllowed($attachment, $user);

        // 기능 레벨 권한 체크 (permission_hooks)
        // → 'core.attachment.download' 훅에 권한이 매핑되어 있으면 체크
        // → 미매핑 시 모든 사용자 허용
        HookManager::checkHookPermission('core.attachment.download', $user);

        return $this->downloadInfoFor($attachment, $user);
    }

    /**
     * 권한 검사를 지난 첨부 행으로 다운로드(첨부 방식) 서빙 정보를 만듭니다 — 액션 훅(IDV 가드) → 필터 훅 → 존재 확인 → 응답.
     *
     * `getDownloadInfo()` 와 `getFileInfo()`(래스터 이미지가 아닌 파일)가 함께 쓴다. 권한 검사는 부르는 쪽이 이미 했다.
     *
     * @param  Attachment  $attachment  권한 검사를 지난 첨부 행
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null, private: bool}|null 서빙 정보 또는 null (파일이 스토리지에 없음)
     */
    private function downloadInfoFor(Attachment $attachment, mixed $user): ?array
    {
        return $this->downloadServingFor($this->passDownloadHooks($attachment, $user));
    }

    /**
     * 내려받기 전 훅을 돌립니다 — 액션 훅(IDV 정책 가드 지점) → 필터 훅(다운로드 전 처리). 한 요청에 한 번만 부른다.
     *
     * @param  Attachment  $attachment  권한 검사를 지난 첨부 행
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return Attachment 필터를 지난 행 (서빙은 이 행의 disk·path·MIME 을 쓴다)
     */
    private function passDownloadHooks(Attachment $attachment, mixed $user): Attachment
    {
        // 액션 훅 - IDV 정책 가드 지점 (filter 훅과 병행)
        HookManager::doAction(self::DOWNLOAD_ACTION_HOOK, $attachment, $user);

        // 필터 훅 - 다운로드 전 처리 (다운로드 카운트 증가 등)
        return HookManager::applyFilters('core.attachment.before_download', $attachment, $user);
    }

    /**
     * 훅을 지난 첨부 행으로 다운로드(첨부 방식) 서빙 정보를 만듭니다 — 존재 확인 → 응답.
     *
     * @param  Attachment  $attachment  필터를 지난 첨부 행
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null, private: bool}|null 서빙 정보 또는 null (파일이 스토리지에 없음)
     */
    private function downloadServingFor(Attachment $attachment): ?array
    {
        // 파일 존재 확인
        $diskStorage = $this->storageForRow($attachment->disk);
        if (! $diskStorage->exists('', $attachment->path)) {
            Log::error('첨부파일 스토리지에 없음', [
                'attachment_id' => $attachment->id,
                'path' => $attachment->path,
            ]);

            return null;
        }

        // 다운로드 응답 생성 (원본 파일명으로)
        $response = $diskStorage->download('', $attachment->path, $attachment->original_filename, [
            'Content-Type' => $attachment->mime_type,
        ]);

        if (! $response) {
            return null;
        }

        return [
            'response' => $response,
            ...$this->serveMeta($attachment),
        ];
    }

    /**
     * 행 메타에서 서빙 검증자를 만듭니다 — 인라인·다운로드 두 통로가 **같은 값**을 쓴다.
     *
     * 파일 stat 없이 결정적이라 원격 디스크에서도 같고, 통로마다 사본으로 두면 같은 첨부가
     * 통로에 따라 다른 ETag 를 갖는다.
     *
     * @param  Attachment  $attachment  첨부 행
     * @return array{etag_source: string, mime_type: string, filename: string, last_modified: int|null, private: bool} 서빙 메타 (private 는 공유 캐시에 남기지 않을지 — 권한 훅이나 켜진 본인인증 정책이 걸려 있으면 true)
     */
    private function serveMeta(Attachment $attachment): array
    {
        return [
            // 파일 stat 없이 결정적인 ETag 소스 (업로드 파일은 경로당 불변)
            'etag_source' => implode('|', [
                $attachment->disk,
                $attachment->path,
                (string) $attachment->updated_at?->getTimestamp(),
                (string) $attachment->size,
            ]),
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_filename,
            // 조건부 요청의 `Last-Modified`/`If-Modified-Since` 축 — ETag 를 쓰지 않고
            // 시각으로만 재검증하는 프록시는 이 값이 없으면 매번 전문을 받아 간다.
            'last_modified' => $attachment->updated_at?->getTimestamp(),
            // 공유 캐시(CDN·프록시)에 남기지 않을지. 권한 훅이나 켜진 본인인증 정책이 걸려 있으면 요청자마다 받을 수
            // 있는지가 갈린다. 둘 다 없는 공개 첨부는 종전처럼 public 이다 — CDN 적중을 해치지 않는다.
            'private' => HookManager::hasPermissionGate('core.attachment.download') || $this->hasIdentityGate(),
        ];
    }

    /**
     * 파비콘 파일 정보 조회 — 파비콘 컬렉션 첨부만 (#434)
     *
     * 파비콘은 브라우저가 쿠키·토큰 없이 요청하므로 공개 경로다. 그래서 대상이 좁아야 한다 — 파비콘 컬렉션
     * (`site_favicon`, `template_settings.{템플릿}.favicon`)에 속하고 MIME 이 파비콘 목록 안인 첨부만 내보낸다.
     * 그 밖의 첨부는 해시를 알아도 이 경로로 받을 수 없다(일반 다운로드 경로의 권한 판정을 우회하지 않게).
     *
     * @param  string  $hash  첨부 해시
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null}|null 파일 정보 (대상이 아니면 null)
     */
    public function getFaviconFileInfo(string $hash): ?array
    {
        $attachment = $this->repository->findByHash($hash);

        if (! $attachment
            || ! FaviconPolicy::isFaviconCollection($attachment->collection)
            || FaviconPolicy::responseMime($attachment->mime_type) === null) {
            return null;
        }

        $response = $this->storageForRow($attachment->disk)->response(
            '',
            $attachment->path,
            $attachment->original_filename,
            [
                'Content-Type' => (string) FaviconPolicy::responseMime($attachment->mime_type),
                'Content-Length' => (string) $attachment->size,
            ]
        );

        if (! $response) {
            Log::error('파비콘 첨부가 스토리지에 없음', ['attachment_id' => $attachment->id, 'path' => $attachment->path]);

            return null;
        }

        return [
            'response' => $response,
            ...$this->serveMeta($attachment),
            'mime_type' => (string) FaviconPolicy::responseMime($attachment->mime_type),
        ];
    }

    /**
     * 첨부 서빙에 켜진 본인인증 정책이 걸려 있는지 — hook 범위(`core.attachment.before_download_action`) 또는
     * route 범위(`api.attachment.download`).
     *
     * 인증을 마친 사람에게 간 응답이 공유 캐시에 남으면 인증하지 않은 사람이 그 사본을 받는다. 두 색인 모두 캐시되어
     * (정책 저장·확장 활성화가 비운다) 요청마다 정책 표를 조회하지 않는다. 색인을 읽지 못하면 private 쪽으로 판정한다.
     *
     * @return bool 걸려 있으면 true
     */
    private function hasIdentityGate(): bool
    {
        try {
            $policies = $this->identityPolicies ??= app(IdentityPolicyRepositoryInterface::class);

            return isset($policies->getHookScopeIndex()[self::DOWNLOAD_ACTION_HOOK])
                || isset($policies->getRouteScopeIndex()[self::DOWNLOAD_ROUTE]);
        } catch (\Throwable $e) {
            Log::warning('첨부 서빙의 본인인증 정책 색인을 읽지 못해 private 으로 냅니다', ['error' => $e->getMessage()]);

            return true;
        }
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
     * 래스터 이미지가 아닌 파일(SVG 포함)은 인라인 스트림 대신 `getDownloadInfo()` 와 같은 다운로드(첨부 방식) 응답을
     * 돌려준다(`thumbnail_source` 는 null) — 권한을 지난 행을 그대로 써서 같은 요청에 행 조회·권한 확인을 다시 하지 않는다.
     * 갈래(`inline`)는 필터 훅 `core.attachment.before_download` 를 지난 행의 MIME 으로 한 번 정하고, 컨트롤러는 그 값을 따른다.
     *
     * @param  string  $hash  첨부파일 해시
     * @param  User|null  $user  요청한 사용자 (null이면 비로그인)
     * @return array{response: StreamedResponse, etag_source: string, mime_type: string, filename: string, last_modified: int|null, private: bool, inline: bool, thumbnail_source: ThumbnailSource|null}|null 서빙 정보 또는 null (inline 은 인라인 래스터 갈래인지, thumbnail_source 는 래스터 이미지일 때 썸네일 원본)
     *
     * @throws AuthorizationException 기능 레벨 권한이 없는 경우
     */
    public function getFileInfo(string $hash, mixed $user = null): ?array
    {
        $attachment = $this->repository->findByHash($hash);

        if (! $attachment) {
            return null;
        }

        $this->assertDownloadAllowed($attachment, $user);

        // 기능 레벨 권한 체크 (permission_hooks)
        HookManager::checkHookPermission('core.attachment.download', $user);

        // 액션 훅(IDV 정책 가드 지점)과 필터 훅(다운로드 전 처리)을 갈래를 고르기 전에 한 번 돌린다 — 두 갈래 모두 한 요청에 한 번이다.
        $attachment = $this->passDownloadHooks($attachment, $user);

        // 인라인·내려받기 갈래는 필터를 지난 행의 MIME 하나로 여기서만 정하고(`inline`), 컨트롤러는 그 값을 따른다.
        // 응답의 Content-Type 도 같은 행의 MIME 이다. 종전에는 필터 전 MIME 으로 갈래를 고르고 컨트롤러가 필터 뒤 MIME 으로
        // 다시 골라, 구독자가 MIME 을 바꾸면 내려받기 응답이 이미지 갈래로 나가는 식으로 두 판정이 갈렸다.
        // 래스터 이미지가 아닌 파일(SVG 포함)은 인라인으로 열지 않는다 — 권한을 지난 이 행으로 다운로드 서빙 정보를 바로 만든다.
        // 종전에는 여기서 인라인 스트림을 만든 뒤 컨트롤러가 getDownloadInfo() 를 이어 불러 같은 행을 다시 찾고 권한 확인과
        // 공유 캐시 판정을 한 번씩 더 했다(권한 매핑 조회는 캐시가 없어 DB 질의가 그만큼 늘었다).
        if (! InlineMediaPolicy::isInlineImage($attachment->mime_type)) {
            $download = $this->downloadServingFor($attachment);

            return $download === null ? null : [...$download, 'inline' => false, 'thumbnail_source' => null];
        }

        // 행 disk 기준 인라인 스트림 (존재 검사는 response() 내부에서 수행)
        // Content-Type/Length 를 행 메타로 선지정해 원격 디스크의 추가 메타 조회를 생략한다.
        $response = $this->storageForRow($attachment->disk)->response(
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
            ...$this->serveMeta($attachment),
            'inline' => true,
            // 썸네일 헬퍼가 쓸 원본 정보 — 권한 검사를 지난 행에서만 만든다(래스터가 아니면 null)
            'thumbnail_source' => CoreAttachmentSourceProvider::sourceFor($attachment),
        ];
    }

    /**
     * 코어 다운로드(`download`·`getFileInfo`)로 내보내도 되는 행인지 판정합니다 (#887).
     *
     * 1. 코어 일반 규칙: 행을 만든 확장(`source_type` 이 module·plugin 이고 `source_identifier`)이 비활성·미설치면 거부한다 —
     *    그 확장의 권한 판정(리스너)이 꺼져 있어 원 리소스 권한을 확인할 수 없다. 리스너가 이 규칙을 뒤집지는 못한다.
     * 2. 필터 `core.attachment.filter_can_download`: `false` 면 거부, `true`·`null`(의견 없음)이면 기존 흐름(기능 권한 →
     *    `before_download_action` → `before_download`)을 그대로 탄다. 리스너가 없는 행(회원 아바타·사이트 로고·OG 이미지·
     *    게시판 반응 아이콘 등)은 `null` 이라 종전처럼 서빙된다.
     *
     * @param  Attachment  $attachment  첨부파일
     * @param  mixed  $user  요청한 사용자 (null이면 비로그인)
     * @return void 반환값 없음
     *
     * @throws AuthorizationException 다운로드를 거부할 때 (403)
     */
    private function assertDownloadAllowed(Attachment $attachment, mixed $user): void
    {
        if ($this->isOwnedByInactiveExtension($attachment)) {
            throw new AuthorizationException(__('attachment.access_denied'));
        }

        $verdict = HookManager::applyFilters(self::CAN_DOWNLOAD_FILTER, null, $attachment, $user);

        if ($verdict === false) {
            throw new AuthorizationException(__('attachment.access_denied'));
        }
    }

    /**
     * 행을 만든 확장이 비활성(또는 미설치)인지 — 코어·출처 미기록 행은 해당 없음.
     *
     * 활성 여부는 확장 매니저의 활성 식별자 목록(요청 메모·상태 캐시)으로 본다.
     *
     * @param  Attachment  $attachment  첨부파일
     * @return bool 만든 확장이 꺼져 있으면 true
     */
    private function isOwnedByInactiveExtension(Attachment $attachment): bool
    {
        $identifier = (string) ($attachment->source_identifier ?? '');

        if ($identifier === '') {
            return false;
        }

        return match ($attachment->source_type) {
            AttachmentSourceType::Module => ! in_array($identifier, ModuleManager::getActiveModuleIdentifiers(), true),
            AttachmentSourceType::Plugin => ! in_array($identifier, PluginManager::getActivePluginIdentifiers(), true),
            default => false,
        };
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
                $this->storageForRow($file['disk'])->delete('', $file['path']);
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
