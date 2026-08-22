<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Support\ImageResizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Support\SecretContentGate;
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
     * 첨부 개수가 게시판 상한을 넘지 않는지 검증합니다 (도메인 불변조건).
     *
     * 총합은 **직접 업로드 + `attachment_ids` + `temp_key` 임시첨부 + 기존 첨부** 4소스이고
     * temp_key 쪽은 DB 조회가 필요하므로 FormRequest 만으로는 판정할 수 없습니다.
     * 모든 연결 경로가 반드시 지나는 이 지점이 상한의 SSoT 입니다.
     * (FormRequest 의 `files` max 규칙은 "글을 다 쓰고 저장 실패" 를 막는 UX 선차단이며
     *  역할이 다릅니다 — 둘 다 필요합니다.)
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int|null  $postId  게시글 ID (신규 작성 중이면 null)
     * @param  int  $additional  이번에 추가하려는 첨부 개수
     * @param  string|null  $tempKey  임시 업로드 키 (신규 작성 시 기존 임시첨부 합산용)
     *
     * @throws AttachmentLimitExceededException 상한 초과 시
     */
    public function assertAttachmentCountWithin(
        string $slug,
        ?int $postId,
        int $additional,
        ?string $tempKey = null
    ): void {
        if ($additional <= 0) {
            return;
        }

        $board = $this->boardRepository->findBySlug($slug);
        if (! $board) {
            throw new ModelNotFoundException(__('sirsoft-board::messages.errors.board_not_found'));
        }

        $limit = (int) ($board->max_file_count ?? 0);
        if ($limit <= 0) {
            // 상한 미설정 게시판은 제한하지 않는다 (기존 동작 유지)
            return;
        }

        $existing = 0;
        if ($postId !== null) {
            $existing = $this->repository->getByPost($slug, $postId, 'attachments')->count();
        } elseif ($tempKey !== null) {
            $existing = $this->repository->getByTempKey($slug, $tempKey, 'attachments')->count();
        }

        $total = $existing + $additional;
        if ($total > $limit) {
            throw new AttachmentLimitExceededException($limit, $total);
        }
    }

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
        // 개수 상한 최종 방어선 — 단일 업로드를 N 번 반복하는 경로도 여기서 막힌다
        $this->assertAttachmentCountWithin($slug, $postId, 1, $tempKey);

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

        // 환경설정 > 업로드의 최대 가로/세로·품질을 적용한다 (코어 설정이 모든 업로드 경로에 동일 적용).
        // 임시 파일을 제자리에서 줄이므로 아래의 저장·크기 계산이 모두 축소본을 본다.
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

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
        // (getByTempKey → linkTempAttachments → findById 순)
        $tempAttachments = $this->repository->getByTempKey($slug, $tempKey);

        $linkedCount = $this->repository->linkTempAttachments($slug, $tempKey, $postId);

        // 각 첨부에 대해 after_link 훅 발화 → 카운트 리스너가 post_id 기준으로 동기화 가능
        foreach ($tempAttachments as $tempAttachment) {
            $linked = $this->repository->findById($slug, $tempAttachment->id);
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

        // 개수 상한 최종 방어선 — FormRequest 는 files[] 만 보므로 temp_key 경로가 그대로 뚫린다.
        // 이미 연결된 첨부와 합산해 판정한다 (수정 시 기존 첨부 + 신규 임시첨부).
        $this->assertAttachmentCountWithin($slug, $postId, $tempAttachments->count());

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
     * @return Collection
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
     * 삭제된 게시글의 첨부파일 접근 권한을 검증합니다.
     *
     * 첨부가 속한 게시글이 삭제 상태이면 관리 권한(manager/admin.manage)
     * 보유자만 접근을 허용합니다. 권한이 없으면 AccessDeniedHttpException 을 던집니다.
     * 정상 게시글(또는 게시글 미연결 임시 첨부)은 통과시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  첨부파일 모델
     *
     * @throws AccessDeniedHttpException 삭제글 첨부에 권한 없이 접근한 경우
     */
    private function assertDeletedPostAttachmentAccess(string $slug, Attachment $attachment): void
    {
        if (! $attachment->post_id) {
            return;
        }

        if (! $this->repository->isPostDeleted($slug, $attachment->post_id)) {
            return;
        }

        $canManage = PermissionHelper::check("sirsoft-board.{$slug}.manager")
            || PermissionHelper::check("sirsoft-board.{$slug}.admin.manage");

        if (! $canManage) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * 비밀글 첨부파일 접근 권한을 검증합니다(KVE-2026-1914).
     *
     * 첨부가 속한 게시글이 비밀글이면 SecretContentGate(SSoT) 판정을 통과한
     * 요청(작성자 본인 또는 게시판 manager/posts.read-secret)에만 서빙합니다.
     * 첨부 서빙은 상세 요청과 분리된 별도 요청이라 password_verified 는 세팅되지
     * 않으므로, 비회원이 비밀번호로 검증한 경우는 이 경로에서 인정되지 않습니다
     * (안전 측 실패 — 해시/ID 만으로 비밀글 첨부를 가져가는 것을 차단).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  첨부파일 모델
     *
     * @throws AccessDeniedHttpException 비밀글 첨부에 권한 없이 접근한 경우
     */
    private function assertSecretPostAttachmentAccess(string $slug, Attachment $attachment): void
    {
        if (! $attachment->post_id) {
            return;
        }

        $post = $this->repository->findPostForGate($slug, $attachment->post_id);

        // 부모 글을 못 읽으면 막는다(fail-closed). 첨부에 post_id 가 있는데 그 글을 못 찾는
        // 것은 정상 상태가 아니다 — 슬러그가 다른 게시판이거나 글이 사라진 경우이며, 통과시키면
        // 게이트가 있어야 할 자리에서 무게이트가 된다. 조회는 withTrashed 라 소프트 삭제로는
        // null 이 되지 않는다.
        if (! $post) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }

        if (! $post->is_secret) {
            return;
        }

        if (! app(SecretContentGate::class)->canView($post)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
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
     * @param  string  $context  호출 컨텍스트 (admin | user) — 스코프 권한 식별자 결정에 쓰인다
     * @return bool 삭제 성공 여부
     */
    public function delete(string $slug, int $id, string $context = 'user'): bool
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return false;
        }

        $this->assertAttachmentWithinScope($slug, $attachment, $context);

        // 삭제 후 재정렬을 위해 정보 저장
        $postId = $attachment->post_id;
        $collection = $attachment->collection;

        // Before 훅
        HookManager::doAction('sirsoft-board.attachment.before_delete', $attachment);

        // 물리 파일은 삭제하지 않음 — 소프트 딜리트만 수행 (휴지통 복원 대비).
        // 보존기간이 지난 뒤의 파일·기록 파기는 `sirsoft-board:prune-attachments` 가
        // 담당한다 (purgeSoftDeleted). 운영자가 설정에서 켰을 때만 동작한다.

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
     * 게시글에 연결되지 않은 채 방치된 임시 첨부를 정리합니다.
     *
     * 글쓰기 폼에서 파일만 올리고 저장하지 않고 이탈하면 `temp_key` 만 남은 행과 그 파일이
     * 남습니다. 연결 시점에 본경로로 옮겨지므로 `temp_key` 가 남아 있다는 것은 "끝내 연결되지
     * 않았다" 는 뜻이고, 그래서 오탐 여지가 없습니다.
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
        $attachments = $this->repository->findStaleTempAttachments($threshold, $limit);

        $result = $this->purgeCollection($attachments, $dryRun, '임시 게시판 첨부');

        if (! $dryRun) {
            $this->removeEmptyTempDirectories($attachments);
        }

        return $result;
    }

    /**
     * 파일을 모두 지운 temp_key 디렉토리를 정리합니다.
     *
     * 파일만 지우고 디렉토리를 남기면 폼 세션마다 빈 디렉토리가 쌓여, 정리를 돌려도
     * 저장소에는 흔적이 계속 늘어납니다.
     *
     * 디렉토리에 파일이 남아 있으면(같은 temp_key 의 다른 첨부가 limit 에 걸려 이번 회차에서
     * 빠진 경우 등) 삭제하지 않습니다.
     *
     * @param  Collection  $attachments  이번 회차에 처리한 첨부 목록
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
     * 소프트 삭제된 지 보존기간이 지난 첨부를 파일까지 영구 파기합니다.
     *
     * 첨부 삭제는 소프트 삭제만 수행하므로 파일이 남아 있습니다. 게시글 복원
     * (restoreCascadedByPostId)은 첨부의 `deleted_at` 이 보존기간 안에 있을 때에만 첨부까지
     * 온전히 복원되므로, 보존기간은 휴지통 복원 가능 기간과 같게 유지해야 합니다.
     *
     * @param  int  $days  보존기간(일)
     * @param  int  $limit  한 회차에 처리할 최대 건수
     * @param  bool  $dryRun  true 면 대상만 세고 삭제하지 않음
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function purgeSoftDeleted(int $days, int $limit, bool $dryRun = false): array
    {
        $threshold = Carbon::now()->subDays($days);
        $attachments = $this->repository->findSoftDeletedOlderThan($threshold, $limit);

        return $this->purgeCollection($attachments, $dryRun, '소프트 삭제 게시판 첨부');
    }

    /**
     * 첨부 컬렉션을 파일 → 기록 순으로 영구 파기합니다.
     *
     * @param  Collection  $attachments  대상 첨부 목록
     * @param  bool  $dryRun  true 면 대상만 세고 삭제하지 않음
     * @param  string  $label  로그용 대상 설명
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    private function purgeCollection(Collection $attachments, bool $dryRun, string $label): array
    {
        $result = ['scanned' => $attachments->count(), 'deleted' => 0, 'failed' => 0];

        if ($dryRun) {
            return $result;
        }

        foreach ($attachments as $attachment) {
            $storage = $this->storageForRow($attachment->disk);

            if ($storage->exists('attachments', $attachment->path) && ! $storage->delete('attachments', $attachment->path)) {
                Log::warning("{$label} 파일 삭제 실패 — 기록 보존", [
                    'attachment_id' => $attachment->id,
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                ]);

                $result['failed']++;

                continue;
            }

            $this->repository->forceDelete($attachment);
            $result['deleted']++;
        }

        return $result;
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
     * 순서 변경
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, int>  $orders  첨부파일 ID => order 매핑
     * @param  string  $context  호출 컨텍스트 (admin | user) — 스코프 권한 식별자 결정에 쓰인다
     * @return bool 성공 여부
     */
    public function reorder(string $slug, array $orders, string $context = 'user'): bool
    {
        $this->assertReorderWithinScope($slug, $orders, $context);

        // Before 훅
        HookManager::doAction('sirsoft-board.attachment.before_reorder', $slug, $orders);

        $result = $this->repository->reorder($slug, $orders);

        // After 훅
        HookManager::doAction('sirsoft-board.attachment.after_reorder', $slug, $orders);

        return $result;
    }

    /**
     * 첨부가 액터의 스코프 안에 있는지 검사합니다.
     *
     * 첨부 관리 라우트는 `{id}`(정수)로 선언돼 라우트 모델 바인딩이 일어나지 않고, 순서
     * 변경은 아예 정적 경로다. 두 경우 모두 PermissionMiddleware 가 모델을 resolve 하지
     * 못해 스코프 검사를 건너뛰므로(목록 엔드포인트로 간주) 서비스가 재적용한다.
     * 사용자 경로는 컨트롤러가 `canDelete`(작성자 본인)로 막고 있었으나 관리자 경로는
     * 그 대응물이 없어 비어 있었다 — 두 경로 모두 여기서 같은 판정을 받는다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  대상 첨부
     *
     * @throws AccessDeniedHttpException 스코프 밖 첨부인 경우
     */
    private function assertAttachmentWithinScope(string $slug, Attachment $attachment, string $context): void
    {
        // 컨텍스트는 호출부가 명시한다 — PostService 가 쓰는 방식과 같다. 요청에서
        // 컨트롤러 네임스페이스를 스니핑하는 사설 복제본이 이미 4곳에 있는데 5번째를
        // 만들지 않는다.
        $scopePermission = $context === 'admin'
            ? "sirsoft-board.{$slug}.admin.attachments.upload"
            : "sirsoft-board.{$slug}.attachments.upload";

        if (! PermissionHelper::checkScopeAccess($attachment, $scopePermission)) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * 순서 변경 대상 첨부 전체가 액터의 스코프 안에 있는지 검사합니다.
     *
     * 순서는 집합 전체에 대한 하나의 배열이라 일부만 반영하면 나머지와 어긋난다 —
     * 걸러내지 않고 전량 거부한다(코어 첨부/메뉴 순서 변경과 같은 의미론).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, array{id: int, order: int}>  $orders  순서 데이터
     *
     * @throws AccessDeniedHttpException 스코프 밖 첨부가 하나라도 포함된 경우
     */
    private function assertReorderWithinScope(string $slug, array $orders, string $context): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($item): int => (int) ($item['id'] ?? 0), $orders)
        )));

        foreach ($ids as $id) {
            $attachment = $this->repository->findById($slug, $id);

            // 이 게시판 소속이 아닌 id 는 통과시키지 않는다.
            if (! $attachment) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            $this->assertAttachmentWithinScope($slug, $attachment, $context);
        }
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
     * @return Collection
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
     * @param  string  $context  권한 컨텍스트 (admin | user)
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

        // 삭제된 게시글의 첨부파일은 관리 권한자만 접근
        $this->assertDeletedPostAttachmentAccess($slug, $attachment);

        // 비밀글 첨부파일은 열람 권한자만 접근
        $this->assertSecretPostAttachmentAccess($slug, $attachment);

        // 다운로드 활동이력 기록 훅
        // 권한/삭제글 가드 통과 후 발화 → 차단된 시도는 기록하지 않음.
        // $context('user'|'admin')는 로그 부가정보용 (log_type 은 요청 경로로 자동 결정).
        HookManager::doAction('sirsoft-board.attachment.after_download', $attachment, $context);

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

        // 삭제된 게시글의 첨부파일은 관리 권한자만 접근
        $this->assertDeletedPostAttachmentAccess($slug, $attachment);

        // 비밀글 첨부파일은 열람 권한자만 접근
        $this->assertSecretPostAttachmentAccess($slug, $attachment);

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
     * $signatureVerified 는 요청 URL 의 한시 서명이 검증된 경우다 — 서명은
     * 비밀글·삭제글 게이트를 통과한 응답 직렬화(PostResource)만 발급하므로,
     * 검증된 서명은 그 게이트 통과 자격의 위임으로 보고 콘텐츠 상태 게이트를
     * 재적용하지 않는다 (<img> 는 인증 헤더를 실을 수 없는 렌더 경로).
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @param  bool  $signatureVerified  한시 서명 검증 통과 여부
     * @return array{path: string, mime_type: string, filename: string}|null 파일 정보 또는 null
     */
    public function getFileInfo(string $slug, int $id, bool $signatureVerified = false): ?array
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return null;
        }

        if (! $signatureVerified) {
            // 삭제된 게시글의 첨부파일은 관리 권한자만 접근
            $this->assertDeletedPostAttachmentAccess($slug, $attachment);

            // 비밀글 첨부파일은 열람 권한자만 접근
            $this->assertSecretPostAttachmentAccess($slug, $attachment);
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
