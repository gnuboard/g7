<?php

namespace Modules\Sirsoft\Board\Services;

use App\Contracts\Extension\StorageInterface;
use App\Extension\Helpers\StorageTransaction;
use App\Extension\HookManager;
use App\Helpers\InlineMediaPolicy;
use App\Helpers\PermissionHelper;
use App\Support\ImageResizer;
use App\Support\UploadedFileName;
use App\Thumbnails\DTO\ThumbnailSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Exceptions\AttachmentLimitExceededException;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Services\Concerns\ResolvesRowStorage;
use Modules\Sirsoft\Board\Services\ContentMedia\BoardContentMediaService;
use Modules\Sirsoft\Board\Support\GuestUploadOwnerCookie;
use Modules\Sirsoft\Board\Support\SecretContentGate;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 게시판 첨부파일 서비스
 *
 * 첨부파일 업로드, 삭제 등의 비즈니스 로직을 처리합니다.
 */
class AttachmentService
{
    use ChecksBoardPermission;
    use ResolvesRowStorage;

    /**
     * AttachmentService 생성자
     *
     * @param  AttachmentRepositoryInterface  $repository  첨부파일 리포지토리
     * @param  BoardRepositoryInterface  $boardRepository  게시판 리포지토리
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
     * @return void 반환값 없음
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

        // 예약 컬렉션(본문 미디어)을 뺀 모든 컬렉션을 센다. 종전에는 `attachments` 만 세어, 업로더가 보내는
        // `post_attachments` 처럼 다른 이름으로 올리면 상한이 걸리지 않았다(#883). 요청 계층(ValidatesAttachmentCount)과 같은 기준이다.
        $existing = 0;
        if ($postId !== null) {
            $existing = $this->repository->countForLimitByPost($slug, $postId);
        } elseif ($tempKey !== null) {
            $existing = $this->repository->countForLimitByTempKey($slug, $tempKey);
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
     * @param  string|null  $guestOwnerHash  비회원 소유 쿠키 해시 (비회원 임시 업로드만, 연결 전 삭제·순서 판정용)
     * @return Attachment 생성된 첨부파일
     */
    public function upload(
        string $slug,
        UploadedFile $file,
        ?int $postId = null,
        string $collection = 'attachments',
        ?string $tempKey = null,
        ?string $guestOwnerHash = null
    ): Attachment {
        // 글도 임시 키도 없는 업로드(업로드 후 attachment_ids 로 잇는 흐름)는 서버가 키를 만든다 —
        // 비워 두면 임시 경로가 `{slug}/temp//파일` 이 되어 저장소 경로 가드가 거부한다
        if (! $postId && ! $tempKey) {
            $tempKey = Str::uuid()->toString();
        }

        // 개수 상한 최종 방어선 — 단일 업로드를 N 번 반복하는 경로도 여기서 막힌다
        $this->assertAttachmentCountWithin($slug, $postId, 1, $tempKey);

        // Before 훅
        HookManager::doAction('sirsoft-board.attachment.before_upload', $slug, $file, $postId);

        // 필터 훅 - 파일 데이터 변형 (압축, 리사이즈 등 확장 포인트)
        $file = HookManager::applyFilters('sirsoft-board.attachment.filter_upload_file', $file);

        // 저장 경로 생성
        // audit:allow stored-filename-client-extension 이름 확장자는 업로드 요청이 게시판 허용 목록(`extensions:`)과
        // 파일 내용(AttachmentFileContent)으로 교차 검증한 값이다 — 내용과 짝이 맞지 않는 이름은 여기 오기 전에 거부된다.
        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();

        if ($postId) {
            // 기존 게시글 수정: 최종 경로에 바로 저장
            // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
            $path = "{$slug}/".date('Y/m/d')."/{$storedFilename}";
        } else {
            // 신규 게시글: 임시 경로에 저장 (저장 시 최종 경로로 이동)
            $path = "{$slug}/temp/{$tempKey}/{$storedFilename}";
        }

        // 환경설정 > 업로드의 최대 가로/세로·품질을 적용한다 (코어 설정이 모든 업로드 경로에 동일 적용).
        // 임시 파일을 제자리에서 줄이므로 아래의 저장·크기 계산이 모두 축소본을 본다.
        app(ImageResizer::class)->resizeInPlace($file->getRealPath(), $file->getMimeType());

        // 축소는 **제자리 덮어쓰기**라 그 경로의 stat 이 이미 캐시돼 있을 수 있다.
        //
        // 아래에서 읽는 `getSize()` 는 PHP 의 공유 stat 캐시를 그대로 쓰는데, 그 값이
        // 축소 전 크기면 행의 `size` 가 실제 저장 바이트보다 커진다. 그 값은 서빙에서
        // `Content-Length` 로 **선언**되므로 어긋나는 순간 응답이 잘린다.
        // 종전에는 정합이 「캐시가 아직 안 찼다」는 호출 순서의 우연에 걸려 있었다.
        clearstatcache(true, $file->getRealPath());

        // 스토리지에 파일 저장 (category: 'attachments') — 글 저장 트랜잭션 안(파일 함께 보낸 업로드)이면
        // 저장이 롤백될 때 이 파일도 지운다 (StorageTransaction, #883)
        StorageTransaction::put($this->storage, 'attachments', $path, file_get_contents($file->getRealPath()));

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
            // 비회원 임시 업로드만 소유 증명 해시를 둔다 — 글에 바로 붙인 업로드·회원 업로드는 쓰지 않는다
            'guest_owner_hash' => ($postId === null && Auth::id() === null) ? $guestOwnerHash : null,
        ]);

        Log::info('게시판 첨부파일 업로드 완료', [
            'board_slug' => $slug,
            'attachment_id' => $attachment->id,
            'post_id' => $postId,
            'temp_key' => self::maskTempKey($tempKey),
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
     * temp_key 는 요청에서 온 값이다. 연결할 임시 첨부(현재 사용자가 올린 것)가 한 건도
     * 없으면 파일도 디렉토리도 건드리지 않고 0 을 반환한다 — 임시 디렉토리 정리는 실제로
     * 옮긴 파일이 있을 때만 한다(KVE-2026-2349: 첨부 없이 보낸 temp_key 가 정리 경로를
     * 모듈 저장소 루트로 끌어올려 전체가 삭제됐다).
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

        $tempAttachments = $this->ownedTempAttachments($this->repository->getByTempKey($slug, $tempKey));

        if ($tempAttachments->isEmpty()) {
            return 0;
        }

        // 개수 상한 최종 방어선 — FormRequest 는 files[] 만 보므로 temp_key 경로가 그대로 뚫린다.
        // 이미 연결된 첨부와 합산해 판정한다 (수정 시 기존 첨부 + 신규 임시첨부).
        $this->assertAttachmentCountWithin($slug, $postId, $tempAttachments->count());

        $linkedCount = 0;
        // 컬렉션별 다음 번호 — 이미 첨부가 있는 글(수정 화면)에서는 기존 첨부의 마지막 번호 뒤에 붙인다.
        // 임시 첨부는 임시 키 안에서 1 부터 번호를 받으므로 그대로 두면 기존 첨부와 같은 번호가 되어 목록 중간에 낀다.
        // 새 글은 기존 첨부가 없어 1..N 이 된다(getByTempKey 가 order 순으로 돌려준다).
        $nextOrders = [];

        foreach ($tempAttachments as $attachment) {
            $collection = (string) $attachment->collection;
            $nextOrders[$collection] ??= $this->repository->getMaxOrder($slug, $postId, $collection);
            $nextOrders[$collection]++;

            // 최종 경로 생성 (기존 경로 규칙 유지)
            // audit:allow datetime-display-user-timezone reason: 저장 경로(디렉터리 날짜) — 기계값
            $newPath = "{$slug}/".date('Y/m/d')."/{$attachment->stored_filename}";

            // 파일 이동 — 글 저장 트랜잭션 안에서는 복사해 두고 커밋되면 원본을 지운다. 롤백되면 사본을 지워 DB 와
            // 파일이 함께 임시 자리로 돌아간다. 종전 get+put+delete 는 뒤 단계가 실패하면 파일만 본경로에 남았다(#883)
            //
            // 디스크는 **행이 정한다.** 읽기 실패도 조용히 지나가지 않는다. 종전에는 `if ($content)` 라 ①읽기 실패
            // (`get()` 은 미존재 시 null)와 ②0 바이트 파일이 모두 falsy 로 접혀 이동을
            // 건너뛰면서 **DB `path` 는 아래에서 갱신**됐다 — 행은 최종 경로를 가리키는데
            // 파일은 임시 경로에 남거나 아예 없어, 첨부가 오류도 로그도 없이 영구 유실된다.
            // 디스크를 옮긴 사이트에서는 그 읽기가 **항상** 실패하므로 그 창의 모든 신규
            // 글 첨부가 전멸한다.
            $storage = $this->storageForRow($attachment->disk);

            if (! StorageTransaction::move($storage, 'attachments', $attachment->path, $newPath)) {
                Log::error('게시판 임시 첨부 파일을 읽지 못해 연결을 건너뜁니다 — 행을 그대로 둡니다', [
                    'board_slug' => $slug,
                    'attachment_id' => $attachment->id,
                    'disk' => $attachment->disk,
                    'path' => $attachment->path,
                    'post_id' => $postId,
                ]);

                continue;
            }

            // DB 업데이트: board_id 이동, post_id 설정, temp_key 제거, path 변경
            // 임시 첨부파일(board_id=0)을 직접 업데이트 (repository->update()는 board_id=$board->id로 조회하므로 사용 불가)
            $attachment->update([
                'board_id' => $board->id,
                'post_id' => $postId,
                'temp_key' => null,
                // 소유 증명은 연결 전에만 의미가 있다 — 연결되면 글의 수정 권한이 삭제·순서를 정한다
                'guest_owner_hash' => null,
                'path' => $newPath,
                'order' => $nextOrders[$collection],
            ]);
            $linkedCount++;
        }

        // 임시 디렉토리 정리 — 부수 작업이라 실패해도 연결을 되돌리지 않는다. 막 옮긴 대용량 파일을 운영체제가
        // 잠시 붙잡고 있으면(삭제 대기) 디렉토리 순회가 lstat 오류를 던졌고, 그 예외가 글 저장 전체를 500 으로
        // 되돌려 파일은 본경로, DB 는 임시 경로를 가리키는 어긋남이 남았다(#703 실측). 정리에 실패해 남는 것은 빈
        // 임시 디렉토리뿐이라 동작에는 영향이 없다(경고 로그로 남긴다). 원본 삭제가 커밋 뒤라 정리도 커밋 뒤에 한다.
        StorageTransaction::afterCommit(function () use ($slug, $tempKey) {
            try {
                $this->storage->deleteDirectory('attachments', "{$slug}/temp/{$tempKey}");
            } catch (\Throwable $e) {
                Log::warning('게시판 임시 첨부 디렉토리 정리 실패 — 연결은 완료, 빈 디렉토리가 남음', [
                    'board_slug' => $slug,
                    'temp_key' => self::maskTempKey($tempKey),
                    'error' => $e->getMessage(),
                ]);
            }
        });

        Log::info('게시판 임시 첨부파일 연결 완료', [
            'board_slug' => $slug,
            'temp_key' => self::maskTempKey($tempKey),
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
     * `attachment_ids` 로 잇는 첨부 번호 중 이 요청이 이을 수 있는 것만 남깁니다.
     *
     * 임시 키 경로(linkTempAttachmentsWithMove)는 소유자를 확인하는데, 번호로 잇는 경로는 확인하지
     * 않아 첨부 번호(순번)만 알면 남이 올려 둔 임시 첨부를 자기 글에 붙일 수 있었다(2026-10-07 실측,
     * KVE-2026-2349 후속). 요청 컨텍스트가 없는 콘솔 실행(시더·큐)은 종전처럼 판정하지 않는다.
     *
     * 게시판(`$slug`)을 주면 이 게시판의 임시 첨부 중 회원은 본인 업로드, 비회원은 비회원 업로드이면서 임시 키가 같은
     * 것만 남긴다 — 비회원끼리 번호만으로, 또는 다른 게시판 임시 첨부를 잇던 빈틈을 막는다(#883). 이미 글에 이어진
     * 첨부 번호(수정 화면이 함께 보낸다)는 잇는 대상이 아니므로 빠진다. 게시판을 주지 않는 옛 호출은 종전 판정이다.
     *
     * @param  array<int>  $ids  요청의 첨부파일 ID 배열
     * @param  string|null  $slug  게시판 슬러그 (주면 게시판·임시 키까지 본다)
     * @param  string|null  $tempKey  요청의 임시 업로드 키 (비회원 번호 연결의 소지 증명)
     * @return array<int, int> 이을 수 있는 첨부파일 ID 배열
     */
    public function filterLinkableAttachmentIds(array $ids, ?string $slug = null, ?string $tempKey = null): array
    {
        if (empty($ids)) {
            return [];
        }

        $userId = Auth::id();
        if ($userId === null && $this->runsWithoutRequest()) {
            return array_values(array_map('intval', $ids));
        }

        if ($slug !== null) {
            return $this->repository->findLinkableTempAttachmentIds(
                $slug,
                array_map('intval', $ids),
                $userId === null ? null : (int) $userId,
                $tempKey,
                $this->currentGuestOwnerHash(),
            );
        }

        $foreign = $this->repository->findForeignTempAttachmentIds($ids, $userId === null ? null : (int) $userId);

        return array_values(array_diff(array_map('intval', $ids), $foreign));
    }

    /**
     * 현재 사용자가 올린 임시 첨부만 남깁니다.
     *
     * 임시 키를 알아낸 다른 사용자가 남의 임시 첨부를 자기 글에 붙이거나 그 임시 디렉토리를
     * 지우지 못하게 한다. 인증 컨텍스트가 없으면(콘솔·큐) 판정하지 않는다.
     *
     * - 회원: 본인이 올린 것만(`created_by` = 본인). 종전에는 비회원 업로드(`created_by` 없음)도 통과해, 회원이 비회원의
     *   임시 키를 알면 그 첨부를 자기 글에 이을 수 있었다(#883).
     * - 비회원: 비회원이 올린 것만(`created_by` 없음) — 임시 키 소지로 판단한다. 회원이 올린 임시 첨부는 잇지 않는다.
     * - 회원이 로그인 전에 비회원으로 올린 것은 그때 받은 소유 쿠키가 맞으면 함께 잇는다(2단계 완화).
     *
     * @param  Collection<int, Attachment>  $attachments  임시 첨부 목록
     * @return Collection<int, Attachment> 현재 사용자 소유 임시 첨부
     */
    private function ownedTempAttachments(Collection $attachments): Collection
    {
        $userId = Auth::id();
        if ($userId === null && $this->runsWithoutRequest()) {
            return $attachments;
        }

        $guestToken = $userId === null ? null : $this->guestOwnerCookie()->resolveToken(request());

        return $attachments
            ->filter(fn (Attachment $attachment) => $userId === null
                ? $attachment->created_by === null
                : (($attachment->created_by !== null && (int) $attachment->created_by === (int) $userId)
                    || ($attachment->created_by === null
                        && $this->guestOwnerCookie()->matches($attachment->guest_owner_hash, $guestToken))))
            ->values();
    }

    /**
     * 현재 요청의 비회원 소유 쿠키 해시를 돌려줍니다(요청이 없거나 쿠키가 없으면 null).
     *
     * 본문 미디어 복제 게이트(다른 임시 키로 올린 비회원 임시 본문 미디어는 소유 쿠키가 맞을 때만 복제, #887)도 이 값을 쓴다.
     *
     * @return string|null 소유 쿠키 해시
     */
    public function currentGuestOwnerHash(): ?string
    {
        if ($this->runsWithoutRequest()) {
            return null;
        }

        $token = $this->guestOwnerCookie()->resolveToken(request());

        return $token === null ? null : $this->guestOwnerCookie()->hash($token);
    }

    /**
     * 비회원 소유 쿠키 도우미를 돌려줍니다.
     *
     * @return GuestUploadOwnerCookie 쿠키 도우미
     */
    private function guestOwnerCookie(): GuestUploadOwnerCookie
    {
        return app(GuestUploadOwnerCookie::class);
    }

    /**
     * 요청 없이 도는 콘솔 실행(시더·큐·명령)인지 판정합니다 — 소유 판정을 건너뛰는 유일한 경우다.
     *
     * `runningInConsole()` 만 보면 HTTP 요청을 처리하는 시험(PHPUnit 은 콘솔 프로세스다)에서도 비회원 판정이 건너뛰어져,
     * 비회원 소유 규칙을 시험으로 잠글 수 없다. 처리 중인 라우트가 있으면 요청으로 본다.
     *
     * @return bool 요청 없는 콘솔 실행이면 true
     */
    private function runsWithoutRequest(): bool
    {
        return app()->runningInConsole() && request()->route() === null;
    }

    /**
     * 로그에 남길 임시 키를 가립니다 — 임시 키는 그 임시 첨부를 잇는 권한이라 원문을 남기지 않는다(#883).
     *
     * 같은 키끼리 로그를 묶어 볼 수 있게 SHA-256 앞 12자를 쓴다.
     *
     * @param  string|null  $tempKey  임시 업로드 키
     * @return string|null 가린 값 (키가 없으면 null)
     */
    private static function maskTempKey(?string $tempKey): ?string
    {
        return $tempKey === null || $tempKey === '' ? $tempKey : substr(hash('sha256', $tempKey), 0, 12);
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
     * 첨부가 속한 글을 게이트용으로 읽습니다.
     *
     * 서빙 한 번의 게이트 셋과 캐시 판정이 이 값 하나를 함께 쓴다 — 저마다 읽으면 갤러리 한 화면의 이미지 수만큼 쿼리가 곱으로 는다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  첨부파일 모델
     * @return Post|null 글 (글에 이어지지 않은 임시 첨부이거나 글을 찾지 못하면 null)
     */
    private function gatePost(string $slug, Attachment $attachment): ?Post
    {
        return $attachment->post_id ? $this->repository->findPostForGate($slug, $attachment->post_id) : null;
    }

    /**
     * 글에 이어진 첨부의 콘텐츠 상태 게이트(삭제·비밀·블라인드)를 한 번에 겁니다 (#883).
     *
     * 본문 미디어 서빙(`PostContentMediaService::findServable`)이 첨부 미리보기와 같은 기준을 쓰게 하는 공개 래퍼다.
     * 글에 이어지지 않은 임시 행은 통과시킨다 — 임시 행의 서빙 조건(서명 필수)은 호출자가 정한다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  첨부(본문 미디어)
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 게이트를 통과하지 못한 경우
     */
    public function assertContentGates(string $slug, Attachment $attachment): void
    {
        $post = $this->gatePost($slug, $attachment);

        $this->assertDeletedPostAttachmentAccess($slug, $post);
        $this->assertSecretPostAttachmentAccess($attachment, $post);
        $this->assertBlindedPostAttachmentAccess($slug, $post);
    }

    /**
     * 임시 첨부 중 현재 요청자가 올린 것만 남깁니다 — 임시 키 연결과 같은 소유 기준의 공개 래퍼 (#883).
     *
     * 본문 미디어 연결(`PostContentMediaService::syncWithContent`)이 첨부와 같은 소유 규칙(회원은 본인 업로드, 비회원은 비회원
     * 업로드, 로그인 전 비회원 업로드는 소유 쿠키가 맞을 때)을 쓰게 한다.
     *
     * @param  Collection<int, Attachment>  $attachments  임시 첨부 목록
     * @return Collection<int, Attachment> 현재 요청자 소유 임시 첨부
     */
    public function filterOwnedTempAttachments(Collection $attachments): Collection
    {
        return $this->ownedTempAttachments($attachments);
    }

    /**
     * 삭제된 게시글의 첨부파일 접근 권한을 검증합니다.
     *
     * 첨부가 속한 게시글이 삭제 상태이면 관리 권한(manager/admin.manage)
     * 보유자만 접근을 허용합니다. 권한이 없으면 AccessDeniedHttpException 을 던집니다.
     * 정상 게시글(또는 게시글 미연결 임시 첨부)은 통과시킵니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Post|null  $post  gatePost() 로 읽은 글
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 삭제글 첨부에 권한 없이 접근한 경우
     */
    private function assertDeletedPostAttachmentAccess(string $slug, ?Post $post): void
    {
        if (! $post || ($post->deleted_at === null && $post->status !== PostStatus::Deleted)) {
            return;
        }

        // 게시판 관리 권한은 게시판 스코프까지 본다 — 보유만 보면 「본인이 만든 게시판만」 위임 관리자가
        // 남의 게시판 삭제·블라인드 글 첨부를 받는다.
        $canManage = $this->hasBoardManagePermission($slug);

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
     * @param  Attachment  $attachment  첨부파일 모델
     * @param  Post|null  $post  gatePost() 로 읽은 글
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 비밀글 첨부에 권한 없이 접근한 경우
     */
    private function assertSecretPostAttachmentAccess(Attachment $attachment, ?Post $post): void
    {
        if (! $attachment->post_id) {
            return;
        }

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
     * 블라인드된 게시글의 첨부파일 접근 권한을 검증합니다.
     *
     * 블라인드는 운영 제재라 본문은 작성자 본인과 게시판 관리자에게만 보인다(PostResource 의
     * canViewBlindedContent). 첨부 서빙은 상세와 분리된 요청이라 같은 기준을 여기서 다시 건다 —
     * 종전에는 화면만 첨부 영역을 숨기고 해시만으로 파일을 받을 수 있었다(2026-10-07 실측).
     * 서명된 preview(게이트를 통과한 직렬화가 발급)는 getFileInfo 가 이 검사를 건너뛴다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Post|null  $post  gatePost() 로 읽은 글
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 블라인드 글 첨부에 권한 없이 접근한 경우
     */
    private function assertBlindedPostAttachmentAccess(string $slug, ?Post $post): void
    {
        if (! $post || $post->status !== PostStatus::Blinded) {
            return;
        }

        $userId = Auth::id();
        if ($userId !== null && $post->user_id !== null && (int) $post->user_id === (int) $userId) {
            return;
        }

        // 게시판 관리 권한은 게시판 스코프까지 본다 — 보유만 보면 「본인이 만든 게시판만」 위임 관리자가
        // 남의 게시판 삭제·블라인드 글 첨부를 받는다.
        $canManage = $this->hasBoardManagePermission($slug);

        if (! $canManage) {
            throw new AccessDeniedHttpException(__('auth.scope_denied'));
        }
    }

    /**
     * ID로 첨부파일 조회
     *
     * 이 게시판 첨부가 없으면 이 게시판에 올린 임시 첨부(아직 글에 이어지지 않음, board_id=0)를 찾는다.
     * 글쓰기 화면은 [저장] 때 첨부를 먼저 올리므로, 글 저장이 실패하면 업로더 목록에 임시 첨부가 남는다 —
     * 그것을 지우는 요청이 「파일을 찾을 수 없습니다」(404)로 끝나지 않게 한다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    public function getById(string $slug, int $id): ?Attachment
    {
        return $this->findManageable($slug, $id);
    }

    /**
     * 삭제·순서 변경 대상 첨부를 찾습니다 — 이 게시판 첨부, 없으면 이 게시판의 임시 첨부.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @return Attachment|null 첨부파일 또는 null
     */
    private function findManageable(string $slug, int $id): ?Attachment
    {
        return $this->repository->findById($slug, $id) ?? $this->repository->findTempById($slug, $id);
    }

    /**
     * 사용자가 첨부파일을 삭제할 권한이 있는지 확인
     *
     * 비회원은 글에 연결되기 전의 자기 임시 첨부만 지운다 — 업로드 응답에서 받은 소유 쿠키가 첨부 행의 해시와 같아야 한다
     * (#883). 종전에는 비회원이 자기 임시 첨부를 지울 수 없어 업로더의 X 가 서버에 남았다.
     *
     * @param  Attachment  $attachment  첨부파일
     * @param  int|null  $userId  사용자 ID (null이면 비회원)
     * @param  string|null  $guestOwnerToken  요청의 비회원 소유 쿠키 값
     * @return bool 삭제 권한 여부
     */
    public function canDelete(Attachment $attachment, ?int $userId, ?string $guestOwnerToken = null): bool
    {
        if (! $userId) {
            return $this->isGuestOwnedTemp($attachment, $guestOwnerToken);
        }

        // 작성자 본인만 삭제 가능
        return $attachment->created_by !== null && (int) $attachment->created_by === (int) $userId;
    }

    /**
     * 비회원이 소유 쿠키로 증명한, 글에 연결되기 전의 자기 임시 첨부인지 판정합니다.
     *
     * @param  Attachment  $attachment  첨부파일
     * @param  string|null  $guestOwnerToken  요청의 비회원 소유 쿠키 값
     * @return bool 비회원 소유 임시 첨부면 true
     */
    private function isGuestOwnedTemp(Attachment $attachment, ?string $guestOwnerToken): bool
    {
        return $attachment->post_id === null
            && $attachment->created_by === null
            && $this->guestOwnerCookie()->matches($attachment->guest_owner_hash, $guestOwnerToken);
    }

    /**
     * 첨부파일 삭제
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @param  string  $context  호출 컨텍스트 (admin | user) — 스코프 권한 식별자 결정에 쓰인다
     * @param  string|null  $guestOwnerToken  요청의 비회원 소유 쿠키 값 (비회원 임시 첨부 삭제)
     * @return bool 삭제 성공 여부
     */
    public function delete(string $slug, int $id, string $context = 'user', ?string $guestOwnerToken = null): bool
    {
        $attachment = $this->findManageable($slug, $id);

        if (! $attachment) {
            return false;
        }

        $this->assertAttachmentWithinScope($slug, $attachment, $context, $guestOwnerToken);

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
     * @return void 반환값 없음
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
     * 게시글들에 딸린 첨부를 파일과 기록째 즉시 파기합니다 (개인정보 보유기간 삭제 모드, 이슈 #636).
     *
     * 게시글 삭제는 첨부를 소프트 삭제만 하므로 파일이 남는다. 첨부 정리 설정이 꺼져 있으면
     * 그 파일은 영원히 남아 「파기했다」가 거짓이 되므로, 보유기간 삭제는 첨부 정리 주기를
     * 기다리지 않고 바로 지운다. 파일 삭제에 실패한 첨부는 기록을 남겨 다음 실행이 다시 시도한다.
     *
     * @param  array<int, int>  $postIds  게시글 ID 목록
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function purgeByPostIds(array $postIds): array
    {
        return $this->purgeCollection(
            $this->repository->findWithTrashedByPostIds($postIds),
            false,
            '보유기간 파기 게시글 첨부',
        );
    }

    /**
     * 영구 삭제할 게시글들에 아직 남은 본문 미디어 행을 코어 본문 미디어 골격으로 지웁니다 (#887).
     *
     * `purgeByPostIds` 는 파일 삭제에 실패한 행을 다음 실행을 위해 남긴다. 글 자체를 영구 삭제하는 경로
     * (`PostRepository::forceDeleteWithRepliesByIds`)는 그 행을 일괄 삭제하므로, 그 전에 본문 미디어 행만 골격으로 지워
     * `core.content_media.before_delete` 를 거치게 한다. 일반 첨부는 건드리지 않는다.
     *
     * 골격 서비스를 생성자로 받지 않고 컨테이너에서 꺼내는 것은 의존 순환 때문이다 — 골격의 어댑터
     * (`BoardContentMediaStore`)가 이 서비스(임시 행 소유 판정·콘텐츠 게이트)를 주입받는다. 아래 `purgeCollection` 도 같다.
     *
     * @param  array<int, int>  $postIds  게시글 ID 목록
     * @return int 지운 본문 미디어 행 수
     */
    public function purgeContentMediaByPostIds(array $postIds): int
    {
        return $postIds === [] ? 0 : app(BoardContentMediaService::class)->purgeForPosts($postIds);
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

        // 본문 미디어 행은 코어 본문 미디어 골격 정리로 지운다 — 행마다 `core.content_media.before_delete` 를 발화하고,
        // 파일 → 행 순서·파일 삭제 실패 시 행 보존은 아래 일반 첨부와 같다. 일반 첨부의 삭제·훅은 그대로다 (#887)
        [$contentMedia, $attachments] = $attachments->partition(
            fn (Attachment $attachment) => $attachment->collection === Attachment::COLLECTION_CONTENT_MEDIA
        );
        if ($contentMedia->isNotEmpty()) {
            $mediaResult = app(BoardContentMediaService::class)->purgeSelectedRows(
                $this->repository->findContentMediaWithTrashedByIds($contentMedia->pluck('id')->map(fn ($id) => (int) $id)->all())
            );
            $result['deleted'] += $mediaResult['deleted'];
            $result['failed'] += $mediaResult['failed'];

            if ($mediaResult['failed'] > 0) {
                Log::warning("{$label} 본문 미디어 파일 삭제 실패 — 기록 보존", ['failed' => $mediaResult['failed']]);
            }
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
     * 순서 변경
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, int>  $orders  첨부파일 ID => order 매핑
     * @param  string  $context  호출 컨텍스트 (admin | user) — 스코프 권한 식별자 결정에 쓰인다
     * @param  string|null  $guestOwnerToken  요청의 비회원 소유 쿠키 값 (비회원 임시 첨부 순서)
     * @return bool 성공 여부
     */
    public function reorder(string $slug, array $orders, string $context = 'user', ?string $guestOwnerToken = null): bool
    {
        $this->assertReorderWithinScope($slug, $orders, $context, $guestOwnerToken);

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
     * 아직 글에 이어지지 않은 임시 첨부는 소유 글이 없으므로 **올린 사람**만 다룬다(관리자 경로 포함).
     * 남이 올린 임시 첨부는 잇지도 지우지도 못한다는 KVE-2026-2349 기준과 같다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  Attachment  $attachment  대상 첨부
     * @param  string  $context  호출 경로 (admin|user)
     * @param  string|null  $guestOwnerToken  요청의 비회원 소유 쿠키 값
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 스코프 밖 첨부인 경우
     */
    private function assertAttachmentWithinScope(string $slug, Attachment $attachment, string $context, ?string $guestOwnerToken = null): void
    {
        if ($attachment->post_id === null) {
            $userId = Auth::id();

            // 비회원은 소유 쿠키로 증명한 자기 임시 첨부만 (사용자 경로만 — 관리자 경로는 로그인 필수)
            if ($userId === null) {
                if ($context !== 'admin' && $this->isGuestOwnedTemp($attachment, $guestOwnerToken)) {
                    return;
                }

                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            if ($attachment->created_by === null || (int) $attachment->created_by !== (int) $userId) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            return;
        }

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
     * 컨트롤러는 `[첨부 ID => 순서]` 맵을 넘긴다. 종전에는 값 쪽에서 `['id']` 를 찾아 맵 형식에서는
     * 검사할 ID 가 하나도 남지 않았다(검사 전체가 건너뛰어짐 — 7.1.0 전수 실측 후속에서 발견).
     * 지금은 맵의 키를 ID 로 읽고, 목록 형식(`[{id, order}]`)도 그대로 받는다.
     *
     * 사용자 경로는 삭제(`canDelete`)와 같은 기준으로 자기가 올린 첨부만 순서를 바꾼다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  array<int, int|array{id: int, order: int}>  $orders  순서 데이터 (ID => 순서 맵 또는 목록)
     * @param  string  $context  호출 경로 (admin|user)
     * @param  string|null  $guestOwnerToken  요청의 비회원 소유 쿠키 값
     * @return void 반환값 없음
     *
     * @throws AccessDeniedHttpException 스코프 밖 첨부가 하나라도 포함된 경우
     */
    private function assertReorderWithinScope(string $slug, array $orders, string $context, ?string $guestOwnerToken = null): void
    {
        $ids = [];
        foreach ($orders as $key => $item) {
            $ids[] = is_array($item) ? (int) ($item['id'] ?? 0) : (int) $key;
        }
        $ids = array_values(array_unique(array_filter($ids)));

        foreach ($ids as $id) {
            $attachment = $this->findManageable($slug, $id);

            // 이 게시판 소속이 아닌 id 는 통과시키지 않는다.
            if (! $attachment) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }

            $this->assertAttachmentWithinScope($slug, $attachment, $context, $guestOwnerToken);

            if ($context !== 'admin' && ! $this->canDelete($attachment, Auth::id(), $guestOwnerToken)) {
                throw new AccessDeniedHttpException(__('auth.scope_denied'));
            }
        }
    }

    /**
     * 삭제 후 남은 파일들의 순서를 재정렬합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $postId  게시글 ID
     * @param  string  $collection  컬렉션명
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    public function rollbackUploadedFiles(string $slug, array $attachmentIds): void
    {
        // 저장 실패 뒤의 정리라 예외를 올리지 않는다 — 올리면 저장이 실패한 원래 이유를 덮는다. 비회원은 업로드 때 받은
        // 소유 쿠키로 자기 임시 첨부를 정리한다(#883).
        $guestToken = $this->runsWithoutRequest() ? null : $this->guestOwnerCookie()->resolveToken(request());

        foreach ($attachmentIds as $id) {
            try {
                $this->delete($slug, (int) $id, 'user', $guestToken);
            } catch (\Throwable $e) {
                Log::warning('게시판 첨부 롤백 정리 실패 — 임시 첨부가 남음', [
                    'board_slug' => $slug,
                    'attachment_id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 첨부파일 다운로드 응답 생성 (하위호환 표면)
     *
     * 캐시 계약(검증자·304)은 이 스트림에 실리지 않는다 — `getDownloadInfo()` 가 돌려주는
     * 메타를 컨트롤러가 코어 단일 지점(`streamedFileResponse()`)에 넘겨 입힌다. 이 메서드는
     * 그 정보의 `response` 만 돌려준다.
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @param  string  $context  권한 컨텍스트 (admin | user)
     * @return StreamedResponse|null 파일 스트림 또는 없을 경우 null
     */
    public function download(string $slug, int $id, string $context = 'admin'): ?StreamedResponse
    {
        return $this->getDownloadInfo($slug, $id, $context)['response'] ?? null;
    }

    /**
     * 다운로드(첨부 방식) 서빙 정보 조회 (스트림 + 검증자 메타)
     *
     * 미리보기 통로(`getFileInfo()`)와 **같은 서빙 메타**(ETag 소스·수정 시각)를 돌려준다.
     * 종전에는 다운로드가 `download()` 하나로 끝나 검증자가 하나도 없었다 — 같은 첨부가
     * 미리보기로는 304 로 재검증되고 내려받기로는 매번 전문을 받아 갔다. 오류도 로그도
     * 없이 대역폭으로만 나타나고, 정적 서빙(링크가 되는 서버)은 두 통로를 가리지 않는다.
     *권한·상태 게이트·훅 순서는 종전 `download()` 와 같다.
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @param  string  $context  권한 컨텍스트 (admin | user)
     * @return array{response: StreamedResponse, mime_type: string, filename: string, etag_source: string, last_modified: int|null, private: true}|null 서빙 정보 또는 null (`private` 는 항상 true)
     *
     * @throws AccessDeniedHttpException 스코프 밖 첨부이거나 삭제글·비밀글 게이트에 막힌 경우
     */
    public function getDownloadInfo(string $slug, int $id, string $context = 'admin'): ?array
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

        $post = $this->gatePost($slug, $attachment);

        // 삭제된 게시글의 첨부파일은 관리 권한자만 접근
        $this->assertDeletedPostAttachmentAccess($slug, $post);

        // 비밀글 첨부파일은 열람 권한자만 접근
        $this->assertSecretPostAttachmentAccess($attachment, $post);

        // 블라인드 글 첨부는 작성자·게시판 관리자만 (관리자 화면 내려받기는 관리자 권한 미들웨어가 이미 가른다)
        if ($context !== 'admin') {
            $this->assertBlindedPostAttachmentAccess($slug, $post);
        }

        // 다운로드 활동이력 기록 훅
        // 권한/삭제글 가드 통과 후 발화 → 차단된 시도는 기록하지 않음.
        // $context('user'|'admin')는 로그 부가정보용 (log_type 은 요청 경로로 자동 결정).
        HookManager::doAction('sirsoft-board.attachment.after_download', $attachment, $context);

        // `Content-Disposition` 을 직접 만들지 않는다.
        //
        // Flysystem 어댑터는 그 헤더가 이미 있으면 자기 `makeDisposition()` 을 통째로
        // 건너뛴다. 그래서 원문 파일명이 그대로 헤더에 실렸고, 이름에 따옴표가 들어간
        // 업로드(`getClientOriginalName()` 은 basename 만 하고 따옴표를 지우지 않는다)는
        // 헤더 구조를 깨뜨렸다. 형제 `preview()` 는 앞서 스토리지 단일 지점으로
        // 위임됐는데 이 메서드만 남아 있었다 — 코어 형제도 헤더를 넘기지 않고
        // `download()` 를 쓴다.
        //
        // 디스크도 행이 정한다.
        $response = $this->storageForRow($attachment->disk)->download(
            'attachments',
            $attachment->path,
            $attachment->original_filename,
            ['Content-Type' => $attachment->mime_type]
        );

        if (! $response) {
            return null;
        }

        // nosniff — 다운로드 응답도 브라우저가 내용을 추측해 렌더하지 않도록 (KVE-2026-2366)
        // thumbnail-exempt: 내려받기 전용 통로(Content-Disposition: attachment)라 이미지도 원본 파일로 낸다. 썸네일은 미리보기 통로가 낸다
        InlineMediaPolicy::harden($response, $attachment->mime_type, $attachment->original_filename);

        return [
            'response' => $response,
            'mime_type' => (string) $attachment->mime_type,
            'filename' => (string) $attachment->original_filename,
            ...$this->validatorMeta($attachment),
            // 내려받기는 글 상태와 무관하게 공유 캐시에 남기지 않는다. 다운로드 권한은 요청자마다
            // 갈리고(운영자가 비회원을 뺄 수 있다) 기록 훅도 이 메서드에서만 발화한다 — 공개 캐시로
            // 나가면 앞단 CDN 이 저장한 사본을 권한 없는 방문자가 받고 기록도 건너뛴다.
            // 미리보기(`getFileInfo()`)는 글 상태 판정(비밀글·삭제글·블라인드 글만 private)을 유지한다.
            'private' => true,
        ];
    }

    /**
     * 행 메타에서 서빙 검증자를 만듭니다 — 미리보기·다운로드 두 통로가 **같은 값**을 쓴다.
     *
     * ETag 는 파일 stat 이 아니라 행 메타에서 만든다 — 원격 디스크에서도 결정적이다.
     * 코어 형제(`App\Services\AttachmentService`)와 **같은 직렬화**(정수 타임스탬프)를 쓴다.
     * 종전에는 `(string) $attachment->updated_at`(Carbon 의 `__toString`,
     * `2026-08-28 12:54:00`)이라 같은 값이 두 통로에서 다른 문자열이 됐다. 무해하지만
     * 「코어 형제와 같은 형태다」가 사실이 아니었고, 형제 대조를 그 문장에 기대는 다음
     * 회차가 오판한다. 배포 시 게시판 첨부 ETag 가 1회 무효화된다
     * (내용은 그대로, 검증자만 바뀐다).
     *
     * @param  Attachment  $attachment  첨부 행
     * @return array{etag_source: string, last_modified: int|null}
     */
    private function validatorMeta(Attachment $attachment): array
    {
        return [
            'etag_source' => implode('|', [
                (string) $attachment->disk,
                (string) $attachment->path,
                (string) $attachment->updated_at?->getTimestamp(),
                (string) $attachment->size,
            ]),
            // 조건부 요청의 `Last-Modified`/`If-Modified-Since` 축 — ETag 를 쓰지 않고
            // 시각으로만 재검증하는 프록시는 이 값이 없으면 매번 전문을 받아 간다.
            // ETag 소스와 같은 행 메타라 파일 stat 없이 결정적이다.
            'last_modified' => $attachment->updated_at?->getTimestamp(),
        ];
    }

    /**
     * 첨부파일 URL 조회
     *
     * 게시판 첨부는 비밀글·삭제글 게이트가 걸린 서빙 경로를 통해서만 내보낸다.
     * 직접 URL(CDN)로 바꾸면 그 게이트가 통째로 우회되므로, 게이트가 살아 있는
     * 다운로드 서빙 URL 을 돌려준다.
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @return string|null 파일 URL 또는 없을 경우 null
     */
    public function getUrl(string $slug, int $id): ?string
    {
        $attachment = $this->repository->findById($slug, $id);

        return $attachment?->download_url;
    }

    /**
     * 이미지 미리보기 스트림 (기능 레벨 권한 없이, 콘텐츠 상태 게이트는 적용)
     *
     * 이미지 파일을 기능 레벨 권한 검사 없이 스트리밍합니다(비회원 썸네일 허용).
     * 삭제글·비밀글 게이트는 `getFileInfo()` 안에서 그대로 적용됩니다.
     *
     * 서빙은 `getFileInfo()` + `streamFile()` 단일 지점에 위임합니다 — 종전에는 이
     * 메서드만 존재 검사 없이 자체 응답을 만들고 원문 헤더 `Content-Disposition: inline`
     * 으로 스토리지의 파일 이름 선언을 덮어, 같은 파일이 통로에 따라 다른 이름·다른
     * 강도로 나갔습니다. 현재 호출부는 컨트롤러가 두 메서드를 직접 부르므로 없지만,
     * 서비스는 확장에 열려 있어 "지금 호출부가 없다" 는 방어가 아닙니다.
     *
     * 캐싱 헤더(ETag/Cache-Control/조건부 응답)는 형제 `streamFile()` 과 마찬가지로
     * 컨트롤러(`streamedFileResponse()`)가 입힙니다 — 서비스는 스트림까지만 만듭니다.
     *
     * @param  string  $slug  게시판 식별자
     * @param  int  $id  첨부파일 ID
     * @return StreamedResponse|null 이미지 스트림 또는 없을 경우 null
     */
    public function preview(string $slug, int $id): ?StreamedResponse
    {
        $attachment = $this->repository->findById($slug, $id);

        // 이미지가 아닌 경우
        if (! $attachment || ! $attachment->is_image) {
            return null;
        }

        // 콘텐츠 상태 게이트 + 존재 검사 + 서빙 메타는 getFileInfo() 가 소유한다
        $fileInfo = $this->getFileInfo($slug, $id);

        if (! $fileInfo) {
            return null;
        }

        // 블라인드 글 게이트는 getFileInfo() 가 함께 건다
        $response = $this->streamFile($fileInfo);

        // 인라인은 래스터 이미지만 — is_image 가 이미 거르지만 서빙 응답에도 nosniff 를 건다 (KVE-2026-2366)
        // thumbnail-exempt: 원본 스트림만 만드는 확장용 메서드다(호출부 없음). HTTP 미리보기 통로는 컨트롤러 preview() 가 getFileInfo() 뒤에서 썸네일 헬퍼를 부른다
        if ($response) {
            InlineMediaPolicy::harden($response, $attachment->mime_type, $attachment->original_filename);
        }

        return $response;
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
     * 썸네일 헬퍼가 쓸 원본 정보(`thumbnail_source`)와 원본 응답의 공유 캐시 금지 여부(`private`)도
     * 함께 싣는다. 둘 다 게이트를 지난 행에서만 만든다.
     *
     * @param  string  $slug  게시판 슬러그
     * @param  int  $id  첨부파일 ID
     * @param  bool  $signatureVerified  한시 서명 검증 통과 여부
     * @return array{path: string, disk: string|null, mime_type: string, filename: string, etag_source: string, last_modified: int|null, size: int, thumbnail_source: ThumbnailSource|null, private: bool}|null 파일 정보 또는 null
     */
    public function getFileInfo(string $slug, int $id, bool $signatureVerified = false): ?array
    {
        $attachment = $this->repository->findById($slug, $id);

        if (! $attachment) {
            return null;
        }

        $post = null;

        if (! $signatureVerified) {
            $post = $this->gatePost($slug, $attachment);

            // 삭제된 게시글의 첨부파일은 관리 권한자만 접근
            $this->assertDeletedPostAttachmentAccess($slug, $post);

            // 비밀글 첨부파일은 열람 권한자만 접근
            $this->assertSecretPostAttachmentAccess($attachment, $post);

            // 블라인드 글 첨부는 작성자·게시판 관리자만
            $this->assertBlindedPostAttachmentAccess($slug, $post);
        }

        // 파일 존재 확인 — **행의 disk** 를 따른다.
        //
        // 같은 클래스의 삭제·정리 경로는 이미 `storageForRow()` 를 쓰고 코어 형제도
        // `withDisk($attachment->disk)` 를 쓰는데, 서빙 경로만 카테고리 기본 디스크로
        // 갔다. 그러면서 바로 아래 `etag_source` 는 `disk` 를 **싣는다** — 검증자는
        // 디스크가 표현을 가른다고 선언하고 서빙은 그것을 부정하는 자기모순이었다.
        // 증상은 404 + 로그 한 줄이라, 디스크를 옮긴 사이트에서 썸네일이 조용히
        // 전멸한다.
        $storage = $this->storageForRow($attachment->disk);

        if (! $storage->exists('attachments', $attachment->path)) {
            Log::error('게시판 첨부파일 스토리지에 없음', [
                'board_slug' => $slug,
                'attachment_id' => $attachment->id,
                'disk' => $attachment->disk,
                'path' => $attachment->path,
            ]);

            return null;
        }

        // 로컬 절대 경로를 조립하지 않는다. 코어 형제(`BaseApiController::fileResponse`)는
        // `filemtime`/`filesize` 전제라 원격 디스크(S3 등) 행에서 그대로 죽고, 이 통로는
        // 그 실패를 404 로 접어 **게시판 썸네일이 오류 없이 전부 사라진다**. 코어는 같은
        // 사유로 이미 스트림 서빙(`StorageInterface::response()` + `streamedFileResponse()`)
        // 으로 옮겼는데 이 모듈만 남아 있었다.
        //
        // `path` 는 **디스크 상대 경로**다(종전에는 절대 경로였다). 서빙은 컨트롤러가
        // 스토리지에 위임하므로 이 자리에서 경로를 조립할 이유가 없다.
        return [
            'path' => $attachment->path,
            // 서빙도 이 값을 따라야 한다 — `streamFile()` 은 행을 보지 못하므로
            // 디스크를 여기서 실어 보낸다.
            'disk' => $attachment->disk,
            'mime_type' => $attachment->mime_type,
            'filename' => $attachment->original_filename,
            // 검증자(ETag 소스·수정 시각)는 다운로드 통로와 같은 자리가 만든다.
            ...$this->validatorMeta($attachment),
            // Content-Length 선지정용 — 넘기지 않으면 어댑터가 size() 로 원격 메타를
            // 한 번 더 조회한다. 업로드 시점의 저장 바이트 수가 그대로 기록된 값이다.
            'size' => (int) $attachment->size,
            // 썸네일 헬퍼가 쓸 원본 정보 — 위 게이트를 지난 행에서만 만든다(래스터가 아니면 null)
            'thumbnail_source' => $this->thumbnailSourceFor($attachment),
            // 원본 응답을 공유 캐시(CDN·프록시)에 남기지 않을지. 서명 URL 은 비밀글·삭제글·블라인드 글에만
            // 발급되고, 서명 없이 통과했어도 그 글이면 요청자마다 볼 수 있는지가 갈린다 — 공유 캐시에
            // 남으면 다음 방문자가 게이트 없이 받는다. 정상글은 종전처럼 public 이다.
            'private' => $signatureVerified || $this->isRestrictedPostAttachment($attachment, $post),
        ];
    }

    /**
     * 첨부 행의 썸네일 원본 정보를 만듭니다 (서빙 통로·썸네일 원본 제공자 공용).
     *
     * 게시판 첨부는 비밀글·삭제글·블라인드 글 게이트가 걸린 원본이라 항상 `gated` 다 — 파생본은
     * 비공개 썸네일 디스크에 두고 `private` 캐시로 낸다. 같은 원본의 파생본이 글 상태에 따라
     * 두 디스크로 갈리지 않게 글 상태와 무관하게 고정한다. 디스크는 행이 정한다.
     *
     * @param  Attachment  $attachment  첨부 행
     * @return ThumbnailSource|null 원본 정보 (래스터 이미지가 아니거나 드라이버가 썸네일을 지원하지 않으면 null)
     */
    public function thumbnailSourceFor(Attachment $attachment): ?ThumbnailSource
    {
        if (! $attachment->is_image) {
            return null;
        }

        $meta = is_array($attachment->meta) ? $attachment->meta : [];

        return ThumbnailSource::fromStorage(
            $this->storageForRow($attachment->disk),
            'attachments',
            (string) $attachment->path,
            $attachment->mime_type,
            gated: true,
            width: isset($meta['width']) ? (int) $meta['width'] : null,
            height: isset($meta['height']) ? (int) $meta['height'] : null,
        );
    }

    /**
     * 첨부가 속한 글이 열람이 제한된 상태인지 (비밀글·삭제글·블라인드 글)
     *
     * 게이트 판정이 아니다 — 통과한 요청의 원본 응답을 공유 캐시에 남겨도 되는지만 본다.
     * 글에 이어지지 않은 임시 첨부는 제한 대상이 아니다.
     *
     * @param  Attachment  $attachment  첨부 행
     * @param  Post|null  $post  gatePost() 로 읽은 글
     * @return bool 제한된 글의 첨부면 true
     */
    private function isRestrictedPostAttachment(Attachment $attachment, ?Post $post): bool
    {
        if (! $attachment->post_id) {
            return false;
        }

        // 게이트가 글 없음을 막으므로 보통 닿지 않는다 — 안전 측으로 제한 취급
        if (! $post) {
            return true;
        }

        return (bool) $post->is_secret
            || $post->deleted_at !== null
            || in_array($post->status, [PostStatus::Blinded, PostStatus::Deleted], true);
    }

    /**
     * 첨부 스트림 응답을 만듭니다 (원격·로컬 디스크 공통).
     *
     * Content-Type 과 Content-Length 를 행 메타로 선지정한다 — 넘기지 않으면
     * Flysystem 어댑터가 mimeType()/size() 로 원격 메타를 한 번 더 조회한다.
     * 크기는 업로드 시점의 저장 바이트 수(축소·이동 후에도 내용 보존)라 실제 전송량과 같다.
     * 코어 형제(`App\Services\AttachmentService::getFileInfo()`)와 같은 형태다.
     *
     * 디스크도 행이 정한다 — `getFileInfo()` 가 실어 보낸 `disk` 를 따른다. 이 값이
     * 없으면(구 호출부) 카테고리 기본 디스크로 내려간다.
     *
     * @param  array{path: string, mime_type: string, filename: string, size?: int, disk?: string|null}  $fileInfo
     * @return StreamedResponse|null
     */
    public function streamFile(array $fileInfo): ?StreamedResponse
    {
        $headers = ['Content-Type' => $fileInfo['mime_type']];

        if (isset($fileInfo['size'])) {
            $headers['Content-Length'] = (string) $fileInfo['size'];
        }

        return $this->storageForRow($fileInfo['disk'] ?? null)->response(
            'attachments',
            $fileInfo['path'],
            $fileInfo['filename'],
            $headers
        );
    }
}
