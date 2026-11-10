<?php

namespace Modules\Sirsoft\Board\Services\ContentMedia;

use App\Contracts\Extension\StorageInterface;
use App\Support\ContentMedia\AbstractContentMediaService;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\AttachmentRepositoryInterface;

/**
 * 게시판 본문 미디어 골격 서비스 — 코어 골격에 게시판 어댑터와 모듈 저장소를 넘긴 얇은 클래스 (#887)
 *
 * 업로드·저장 동기화(연결 → 복제 → 제거)·서명·서빙·정리 흐름과 공용 훅 `core.content_media.*` 발화는 코어
 * `AbstractContentMediaService` 가 한다. 게시판 호출자(서비스·리소스·컨트롤러)는 종전 시그니처를 지키는
 * `PostContentMediaService` 를 거쳐 이 클래스를 쓴다.
 */
class BoardContentMediaService extends AbstractContentMediaService
{
    /** 게시판 영구 삭제 때 한 번에 읽는 본문 미디어 행 수 */
    private const PURGE_CHUNK = 200;

    /**
     * @param  BoardContentMediaStore  $boardStore  게시판 어댑터
     * @param  StorageInterface  $storage  모듈 저장소
     * @param  AttachmentRepositoryInterface  $repository  첨부 리포지토리 (문서 영구 삭제 대상 조회)
     */
    public function __construct(
        private BoardContentMediaStore $boardStore,
        StorageInterface $storage,
        private AttachmentRepositoryInterface $repository,
    ) {
        parent::__construct($boardStore, $storage);
    }

    /**
     * 게시판 어댑터.
     *
     * @return BoardContentMediaStore 어댑터
     */
    public function boardStore(): BoardContentMediaStore
    {
        return $this->boardStore;
    }

    /**
     * 첨부 정리가 고른 본문 미디어 행을 골격 정리로 영구 삭제합니다 — 행마다 `core.content_media.before_delete` 발화.
     *
     * 파일 → 행 순서이고, 파일을 지우지 못하면 행을 남겨 다음 실행이 다시 시도한다(첨부 정리와 같은 계약).
     *
     * @param  Collection<int, Attachment>  $rows  본문 미디어 행 (전체 열)
     * @return array{scanned: int, deleted: int, failed: int} 처리 결과
     */
    public function purgeSelectedRows(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['scanned' => 0, 'deleted' => 0, 'failed' => 0];
        }

        return $this->boardStore->withSelectedStaleRows($rows, fn () => $this->pruneStale(0, $rows->count()));
    }

    /**
     * 게시판을 영구 삭제할 때 그 게시판의 본문 미디어 행(소프트 삭제분 포함)을 골격으로 영구 삭제합니다 (#887).
     *
     * 행마다 `core.content_media.before_delete`(문맥 = 그 행이 속했던 글) → 파일 삭제(트랜잭션 안이면 커밋 뒤) → 행 삭제.
     * 게시판 삭제는 첨부 디렉터리를 통째로 지우므로 파일이 먼저 사라져 있어도 무해하다. 일반 첨부는 건드리지 않는다.
     *
     * @param  Board  $board  삭제할 게시판
     * @return int 지운 행 수
     */
    public function purgeForBoard(Board $board): int
    {
        $count = 0;
        $this->repository->chunkContentMediaWithTrashedByBoardId((int) $board->id, self::PURGE_CHUNK, function ($rows) use ($board, &$count) {
            foreach ($rows as $row) {
                $postId = $row->post_id !== null ? (int) $row->post_id : null;
                $count += $this->purgeRows($this->boardStore->context((string) $board->slug, $board, $postId), [$row]);
            }
        });

        return $count;
    }

    /**
     * 게시글을 영구 삭제할 때 그 글들의 본문 미디어 행(소프트 삭제분 포함)을 골격으로 영구 삭제합니다 (#887).
     *
     * 행마다 `core.content_media.before_delete` → 파일 삭제(트랜잭션 안이면 커밋 뒤) → 행 삭제. 일반 첨부는 건드리지 않는다.
     *
     * @param  array<int, int>  $postIds  게시글 ID 목록
     * @return int 지운 행 수
     */
    public function purgeForPosts(array $postIds): int
    {
        $count = 0;
        foreach ($this->repository->findContentMediaWithTrashedByPostIds($postIds) as $row) {
            $count += $this->purgeRows($this->boardStore->contextForRow($row), [$row]);
        }

        return $count;
    }
}
