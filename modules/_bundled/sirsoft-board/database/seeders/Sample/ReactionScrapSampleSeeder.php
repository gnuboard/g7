<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Enums\UserStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\BoardSampleMarker;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\SampleRunRegistry;
use Modules\Sirsoft\Board\Database\Seeders\Sample\Concerns\SyncsBoardCounts;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 반응·스크랩 샘플 시더 (B-14)
 *
 * 1.2.0 의 게시글·댓글 반응과 스크랩을 시연할 샘플을 만듭니다 — 사용자 화면의 반응 수·내 반응,
 * 마이페이지 「내 반응·내 스크랩」, 관리자 「반응·스크랩 관리」가 비지 않게 한다.
 *
 * 불변식 (운영 경로 ReactionService·PostScrapService 와 같은 결과):
 * - 대상은 이번 실행에서 게시글 샘플 시더가 만든 글·댓글뿐이다(SampleRunRegistry) — 운영 글에 실제 회원 명의의
 *   반응·스크랩을 지어내지 않는다. 그래서 이 시더만 따로 돌리면 아무것도 만들지 않는다.
 * - 반응은 반응이 켜진 샘플 게시판의 게시 상태·미삭제·비밀글 아닌 글, 그 게시판에서 켠 유형만
 * - 본인 글·본인 댓글에는 반응하지 않는다, 사용자+대상당 1건(unique)
 * - 댓글 반응은 댓글 반응이 켜진 게시판의 게시 상태·비밀 아닌 댓글만
 * - 스크랩은 스크랩이 켜진 샘플 게시판의 게시 상태 글만 (본인 글 스크랩은 허용 — 운영 경로와 같다)
 * - 시각은 대상 작성 뒤 ~ 지금, 끝에서 reaction_counts·scrap_count 를 실제 행 수로 맞춘다
 * - 첫 일반 회원은 반응·스크랩을 1건 이상 갖는다(마이페이지 시연)
 * - 샘플 게시판에 이미 반응·스크랩이 있으면 건너뛴다(운영자·회원이 남긴 반응을 지우지 않는다)
 */
class ReactionScrapSampleSeeder extends Seeder
{
    use SyncsBoardCounts;

    /**
     * 반응을 받는 글 비율 (%)
     */
    private const POST_REACTION_RATE = 30;

    /**
     * 반응을 받는 댓글 비율 (%)
     */
    private const COMMENT_REACTION_RATE = 20;

    /**
     * 스크랩되는 글 비율 (%)
     */
    private const SCRAP_RATE = 15;

    /**
     * 비추천 비율 (비추천이 켜진 게시판, %)
     */
    private const DISLIKE_RATE = 15;

    /**
     * 시더 실행
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command->info('반응·스크랩 샘플 데이터 생성 중...');

        $boards = Board::whereIn('slug', BoardSampleMarker::SAMPLE_BOARD_SLUGS)->get();
        if ($boards->isEmpty()) {
            $this->command->warn('샘플 게시판이 없어 반응·스크랩 샘플을 건너뜁니다.');

            return;
        }

        $boardIds = $boards->pluck('id')->all();
        $hasReactions = DB::table('board_reactions')->whereIn('board_id', $boardIds)->exists();
        $hasScraps = DB::table('board_post_scraps')->whereIn('board_id', $boardIds)->exists();

        if ($hasReactions || $hasScraps) {
            $this->command->warn('샘플 게시판에 이미 반응·스크랩이 있습니다. 건너뜁니다.');

            return;
        }

        $userIds = User::where('status', UserStatus::Active->value)->orderBy('id')->limit(30)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($userIds) < 2) {
            $this->command->warn('활성 회원이 2명 이상 필요합니다. 반응·스크랩 샘플을 건너뜁니다.');

            return;
        }

        $typeIds = DB::table('board_reaction_types')->where('is_active', true)->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        $reactionRows = [];
        $scrapRows = [];

        foreach ($boards as $board) {
            $reactionRows = array_merge($reactionRows, $this->postReactions($board, $userIds, $typeIds));
            $reactionRows = array_merge($reactionRows, $this->commentReactions($board, $userIds, $typeIds));
            $scrapRows = array_merge($scrapRows, $this->scraps($board, $userIds));
        }

        $this->guaranteeFirstMember($boards, $userIds, $typeIds, $reactionRows, $scrapRows);

        SampleRunRegistry::markBefore('board_reactions');
        SampleRunRegistry::markBefore('board_post_scraps');

        foreach (array_chunk($reactionRows, 500) as $chunk) {
            DB::table('board_reactions')->insertOrIgnore($chunk);
        }
        foreach (array_chunk($scrapRows, 500) as $chunk) {
            DB::table('board_post_scraps')->insertOrIgnore($chunk);
        }

        SampleRunRegistry::markAfter('board_reactions');
        SampleRunRegistry::markAfter('board_post_scraps');

        $this->syncReactionCounts($boardIds);
        $this->syncBoardCounts($boardIds);

        $this->command->info('반응 '.count($reactionRows).'건, 스크랩 '.count($scrapRows).'건 생성 완료 (reaction_counts·scrap_count 재집계)');
    }

    /**
     * 게시글 반응 행을 만듭니다.
     *
     * @param  Board  $board  게시판
     * @param  array<int, int>  $userIds  활성 회원 ID
     * @param  array<string, int>  $typeIds  활성 반응 유형 code → ID
     * @return array<int, array<string, mixed>> 반응 행
     */
    private function postReactions(Board $board, array $userIds, array $typeIds): array
    {
        $types = $this->boardTypeIds($board, $typeIds);
        if (! $board->use_reaction || $types === []) {
            return [];
        }

        $rows = [];
        foreach ($this->eligiblePosts($board) as $post) {
            if (rand(1, 100) > self::POST_REACTION_RATE) {
                continue;
            }

            foreach ($this->pickUsers($userIds, rand(0, 8), $post->user_id) as $userId) {
                $rows[] = $this->reactionRow($userId, 'post', (int) $post->id, $this->pickType($types), $board->id, Carbon::parse($post->created_at));
            }
        }

        return $rows;
    }

    /**
     * 댓글 반응 행을 만듭니다.
     *
     * @param  Board  $board  게시판
     * @param  array<int, int>  $userIds  활성 회원 ID
     * @param  array<string, int>  $typeIds  활성 반응 유형 code → ID
     * @return array<int, array<string, mixed>> 반응 행
     */
    private function commentReactions(Board $board, array $userIds, array $typeIds): array
    {
        $types = $this->boardTypeIds($board, $typeIds);
        if (! $board->use_reaction || ! $board->use_comment_reaction || ! $board->use_comment || $types === []) {
            return [];
        }

        $range = SampleRunRegistry::range('board_comments');
        if ($range === null) {
            return [];
        }

        $comments = DB::table('board_comments as c')
            ->join('board_posts as p', 'p.id', '=', 'c.post_id')
            ->where('c.board_id', $board->id)
            ->where('c.id', '>', $range[0])
            ->where('c.id', '<=', $range[1])
            ->where('c.status', 'published')
            ->whereNull('c.deleted_at')
            ->where('c.is_secret', false)
            ->where('p.status', 'published')
            ->whereNull('p.deleted_at')
            ->where('p.is_secret', false)
            ->get(['c.id', 'c.user_id', 'c.created_at']);

        $rows = [];
        foreach ($comments as $comment) {
            if (rand(1, 100) > self::COMMENT_REACTION_RATE) {
                continue;
            }

            foreach ($this->pickUsers($userIds, rand(1, 4), $comment->user_id) as $userId) {
                $rows[] = $this->reactionRow($userId, 'comment', (int) $comment->id, $this->pickType($types), $board->id, Carbon::parse($comment->created_at));
            }
        }

        return $rows;
    }

    /**
     * 스크랩 행을 만듭니다.
     *
     * @param  Board  $board  게시판
     * @param  array<int, int>  $userIds  활성 회원 ID
     * @return array<int, array<string, mixed>> 스크랩 행
     */
    private function scraps(Board $board, array $userIds): array
    {
        if (! $board->use_scrap) {
            return [];
        }

        $rows = [];
        foreach ($this->eligiblePosts($board) as $post) {
            if (rand(1, 100) > self::SCRAP_RATE) {
                continue;
            }

            foreach ($this->pickUsers($userIds, rand(1, 3), null) as $userId) {
                $rows[] = $this->scrapRow($userId, (int) $post->id, $board->id, Carbon::parse($post->created_at));
            }
        }

        return $rows;
    }

    /**
     * 첫 일반 회원(관리자가 아닌 첫 활성 회원)에게 반응·스크랩을 1건 이상 보장합니다.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Board>  $boards  샘플 게시판
     * @param  array<int, int>  $userIds  활성 회원 ID
     * @param  array<string, int>  $typeIds  활성 반응 유형 code → ID
     * @param  array<int, array<string, mixed>>  $reactionRows  반응 행 (참조로 추가)
     * @param  array<int, array<string, mixed>>  $scrapRows  스크랩 행 (참조로 추가)
     * @return void 반환값 없음
     */
    private function guaranteeFirstMember($boards, array $userIds, array $typeIds, array &$reactionRows, array &$scrapRows): void
    {
        $adminIds = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $member = collect($userIds)->first(fn ($id) => ! in_array($id, $adminIds, true));
        if ($member === null) {
            return;
        }

        $hasReaction = collect($reactionRows)->contains(fn ($row) => $row['user_id'] === $member);
        $hasScrap = collect($scrapRows)->contains(fn ($row) => $row['user_id'] === $member);

        foreach ($boards as $board) {
            if ($hasReaction && $hasScrap) {
                return;
            }

            $post = $this->eligiblePosts($board)->first(fn ($p) => (int) $p->user_id !== $member);
            if (! $post) {
                continue;
            }

            $types = $this->boardTypeIds($board, $typeIds);
            if (! $hasReaction && $board->use_reaction && $types !== []) {
                $taken = collect($reactionRows)->contains(fn ($r) => $r['user_id'] === $member && $r['target_type'] === 'post' && $r['target_id'] === (int) $post->id);
                if (! $taken) {
                    $reactionRows[] = $this->reactionRow($member, 'post', (int) $post->id, $types['like'] ?? reset($types), $board->id, Carbon::parse($post->created_at));
                }
                $hasReaction = true;
            }

            if (! $hasScrap && $board->use_scrap) {
                $taken = collect($scrapRows)->contains(fn ($r) => $r['user_id'] === $member && $r['post_id'] === (int) $post->id);
                if (! $taken) {
                    $scrapRows[] = $this->scrapRow($member, (int) $post->id, $board->id, Carbon::parse($post->created_at));
                }
                $hasScrap = true;
            }
        }
    }

    /**
     * 반응·스크랩 대상이 될 수 있는 글 (게시 상태·미삭제·비밀글 아님).
     *
     * @param  Board  $board  게시판
     * @return Collection<int, object> 글 목록
     */
    private function eligiblePosts(Board $board)
    {
        // 이번 실행에서 게시글 샘플 시더가 만든 글만 — 운영 글에 회원 명의 반응·스크랩을 지어내지 않는다
        $range = SampleRunRegistry::range('board_posts');
        if ($range === null) {
            return collect();
        }

        return DB::table('board_posts')
            ->where('board_id', $board->id)
            ->where('id', '>', $range[0])
            ->where('id', '<=', $range[1])
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where('is_secret', false)
            ->orderBy('id')
            ->get(['id', 'user_id', 'created_at']);
    }

    /**
     * 게시판에서 켠 반응 유형만 code → ID 로 돌려줍니다.
     *
     * @param  Board  $board  게시판
     * @param  array<string, int>  $typeIds  활성 반응 유형 code → ID
     * @return array<string, int> 게시판에서 쓸 수 있는 유형
     */
    private function boardTypeIds(Board $board, array $typeIds): array
    {
        $active = (array) ($board->active_reaction_types ?? []);

        return array_intersect_key($typeIds, array_flip($active));
    }

    /**
     * 유형을 고릅니다 — 추천 85%, 비추천(켜져 있으면) 15%.
     *
     * @param  array<string, int>  $types  게시판 유형 code → ID
     * @return int 반응 유형 ID
     */
    private function pickType(array $types): int
    {
        if (isset($types['dislike']) && rand(1, 100) <= self::DISLIKE_RATE) {
            return $types['dislike'];
        }

        return $types['like'] ?? reset($types);
    }

    /**
     * 대상 작성자를 뺀 회원을 고릅니다.
     *
     * @param  array<int, int>  $userIds  활성 회원 ID
     * @param  int  $count  필요한 수
     * @param  int|string|null  $authorId  대상 작성자 ID (본인 반응 금지, null 이면 제외 없음)
     * @return array<int, int> 회원 ID
     */
    private function pickUsers(array $userIds, int $count, int|string|null $authorId): array
    {
        $pool = array_values(array_filter($userIds, fn ($id) => $authorId === null || $id !== (int) $authorId));
        shuffle($pool);

        return array_slice($pool, 0, max(0, $count));
    }

    /**
     * 반응 행 1건.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $targetType  post 또는 comment
     * @param  int  $targetId  대상 ID
     * @param  int  $typeId  반응 유형 ID
     * @param  int  $boardId  게시판 ID
     * @param  Carbon  $targetCreatedAt  대상 작성 시각
     * @return array<string, mixed> 반응 행
     */
    private function reactionRow(int $userId, string $targetType, int $targetId, int $typeId, int $boardId, Carbon $targetCreatedAt): array
    {
        $at = $this->timeAfter($targetCreatedAt);

        return [
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reaction_type_id' => $typeId,
            'board_id' => $boardId,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * 스크랩 행 1건.
     *
     * @param  int  $userId  회원 ID
     * @param  int  $postId  글 ID
     * @param  int  $boardId  게시판 ID
     * @param  Carbon  $postCreatedAt  글 작성 시각
     * @return array<string, mixed> 스크랩 행
     */
    private function scrapRow(int $userId, int $postId, int $boardId, Carbon $postCreatedAt): array
    {
        $at = $this->timeAfter($postCreatedAt);

        return [
            'user_id' => $userId,
            'post_id' => $postId,
            'board_id' => $boardId,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * 기준 시각 뒤 1분 ~ 3일 사이 시각 (지금을 넘지 않음).
     *
     * @param  Carbon  $base  기준 시각
     * @return Carbon 시각
     */
    private function timeAfter(Carbon $base): Carbon
    {
        $at = $base->copy()->addMinutes(rand(1, 3 * 24 * 60));

        return $at->greaterThan(now()) ? now() : $at;
    }
}
