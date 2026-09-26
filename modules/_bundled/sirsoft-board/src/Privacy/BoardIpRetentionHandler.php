<?php

namespace Modules\Sirsoft\Board\Privacy;

use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Privacy\Concerns\PaginatesBoardTargets;

/**
 * 게시판 작성 IP 보유기간 핸들러 (이슈 #636)
 *
 * 게시글·댓글의 작성 IP(`ip_address`)를 작성 후 N일이 지나면 NULL 로 지웁니다. 회원 글과
 * 비회원 글을 가리지 않습니다 — IP 는 접속기록이고, 안전성 확보조치 기준이 요구하는 보관
 * 기간(1년, 대규모 2년)이 지나면 남겨 둘 근거가 없기 때문입니다. 그래서 비회원 작성자 정보
 * 축({@see BoardGuestRetentionHandler})과 **기간을 따로** 둡니다.
 *
 * 삭제된(휴지통) 글·댓글도 대상입니다 — 목록에서 사라졌을 뿐 행에는 IP 가 그대로 남습니다.
 *
 * 처리는 값만 지우며 수정 시각을 바꾸지 않습니다. IP 파기는 글의 수정이 아니므로 목록의
 * 「수정됨」 표시나 정렬이 흔들리면 안 됩니다.
 */
class BoardIpRetentionHandler implements PrivacyRetentionHandlerInterface
{
    use PaginatesBoardTargets;

    /** 한 번에 읽어 처리할 행 수 — OFFSET 은 처리 중 행이 빠져 밀리므로 쓰지 않는다 */
    private const CHUNK_SIZE = 500;

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'sirsoft-board.ip';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('sirsoft-board::messages.privacy_retention.handlers.ip');
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return (bool) module_setting('sirsoft-board', 'privacy_retention.enabled', false);
    }

    /**
     * 보존기간(일)을 설정에서 읽습니다.
     *
     * 바닥값 1 — 저장 검증(`min:1`)을 거치지 않은 쓰기(설정 파일 직접 편집 등)가 0·음수를
     * 남기면 임계가 `now()` 로 올라붙어 **방금 쓴 글의 IP 까지** 지워진다. 판독부에서 막는다
     * (코어 탈퇴 회원 핸들러와 같은 어휘).
     *
     * @return int 보존기간(일)
     */
    public function retentionDays(): int
    {
        return max(1, (int) module_setting('sirsoft-board', 'privacy_retention.ip_retention_days', 365));
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return $this->postQuery()->count() + $this->commentQuery()->count();
    }

    /**
     * {@inheritDoc}
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        return $this->paginateBoardTargets($this->postQuery(), $this->commentQuery(), $page, $perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function targetColumns(): array
    {
        return $this->boardTargetColumns();
    }

    /**
     * {@inheritDoc}
     */
    public function targetPermissions(): array
    {
        return $this->boardTargetPermissions();
    }

    /**
     * {@inheritDoc}
     */
    public function runPermissions(): array
    {
        return $this->boardRunPermissions();
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun, bool $force = false): array
    {
        // 토글을 스스로 다시 본다 — 수집기가 걸러 주지만 되돌릴 수 없는 파기라 호출자를 믿지 않는다.
        // 관리자 직권 실행(`$force`)만 이 가드를 넘는다 — 권한은 코어 직권 실행 API 가 검사했다.
        if (! $force && ! $this->isEnabled()) {
            return ['count' => 0, 'mode' => 'disabled'];
        }

        if ($dryRun) {
            return ['count' => $this->preview(), 'mode' => 'erase_ip'];
        }

        $days = $this->retentionDays();

        // 훅 이름을 리터럴로 적는다 — 조립하면 문서 생성기와 이름 검색이 찾지 못한다.
        HookManager::doAction('sirsoft-board.author_ip.before_prune', $days);

        $processed = $this->erase($this->postQuery(), Post::class)
            + $this->erase($this->commentQuery(), Comment::class);

        HookManager::doAction('sirsoft-board.author_ip.after_prune', $days, $processed);

        if ($processed > 0) {
            Log::info('게시판 작성 IP 보유기간 파기 완료', ['days' => $days, 'count' => $processed]);
        }

        return ['count' => $processed, 'mode' => 'erase_ip'];
    }

    /**
     * 보존기간이 지나 IP 가 남은 게시글 쿼리 — 미리보기와 실행이 같은 이 쿼리를 쓴다.
     *
     * @return Builder<Post> 대상 쿼리
     */
    public function postQuery(): Builder
    {
        return Post::withTrashed()
            ->whereNotNull('ip_address')
            ->where('created_at', '<=', now()->subDays($this->retentionDays()));
    }

    /**
     * 보존기간이 지나 IP 가 남은 댓글 쿼리 — 미리보기와 실행이 같은 이 쿼리를 쓴다.
     *
     * @return Builder<Comment> 대상 쿼리
     */
    public function commentQuery(): Builder
    {
        return Comment::withTrashed()
            ->whereNotNull('ip_address')
            ->where('created_at', '<=', now()->subDays($this->retentionDays()));
    }

    /**
     * 대상 행의 IP 를 지웁니다.
     *
     * @param  Builder  $query  대상 쿼리
     * @param  class-string  $model  모델 클래스
     * @return int 지운 행 수
     */
    private function erase(Builder $query, string $model): int
    {
        $processed = 0;

        // chunkById — 처리하면서 조건에서 빠지는 행을 다루므로 OFFSET 순회는 건을 건너뛴다.
        $query->select('id')->chunkById(self::CHUNK_SIZE, function ($rows) use ($model, &$processed) {
            // toBase(): Eloquent update 는 updated_at 을 함께 바꾼다 — IP 파기는 글 수정이 아니다.
            $processed += $model::withTrashed()
                ->whereIn('id', $rows->pluck('id')->all())
                ->toBase()
                ->update(['ip_address' => null]);
        });

        return $processed;
    }
}
