<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Models\ActivityLog;
use Database\Seeders\Sample\ThumbnailMeasureKit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\AttachmentService;
use Modules\Sirsoft\Board\Services\BoardService;

/**
 * 이미지 썸네일 브라우저 실측 — 게시판 데이터 정리 (이슈 #869).
 *
 * 대상은 실측 게시판 두 개뿐이다 — 슬러그(`ThumbnailMeasureSeeder::CARD_SLUG`, `GALLERY_SLUG`)와 이름의
 * 표지(`[실측-썸네일]`)가 **둘 다** 맞는 게시판만 지운다(같은 슬러그의 실데이터 게시판이 우연히 있어도 건드리지 않는다).
 *
 * 순서:
 *   1. 첨부를 파일 → 행 순으로 영구 파기 — 보유기간 삭제와 같은 `AttachmentService::purgeByPostIds`(첨부마다
 *      저장소 드라이버 delete). 파일 하나씩 지우는 경로에서 파생본이 함께 사라지는지 실측된다.
 *   2. 게시판 삭제 — 관리자 화면과 같은 `BoardService::deleteBoard`(게시판 저장 디렉터리 deleteDirectory,
 *      글, 댓글, 권한, 역할, 메뉴 정리). 디렉터리 삭제 경로의 파생본 정리도 여기서 실측된다.
 *   3. 연결되지 못한 임시 첨부 행과, 지운 행을 가리키는 활동 로그.
 *
 * 실행 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan module:seed sirsoft-board --class="Modules\Sirsoft\Board\Database\Seeders\Sample\ThumbnailMeasureCleanupSeeder"
 */
class ThumbnailMeasureCleanupSeeder extends Seeder
{
    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        $this->command?->info(__('sirsoft-board::messages.seeders.thumbnail_measure.cleanup_start'));

        ThumbnailMeasureKit::guard();

        (new ThumbnailMeasureKit)->silenceOutbound();
        $counts = $this->purge();

        ThumbnailMeasureKit::table(
            $this->command,
            __('sirsoft-board::messages.seeders.thumbnail_measure.cleanup_title'),
            ThumbnailMeasureKit::labels(array_keys($counts), __('sirsoft-board::messages.seeders.thumbnail_measure.cleanup_headers')),
            [array_values($counts)]
        );

        $this->command?->info(__('sirsoft-board::messages.seeders.thumbnail_measure.cleanup_done'));
    }

    /**
     * 실측 게시판과 그 안의 글, 첨부, 파생본을 지웁니다 (생성 시더의 재실행 멱등에도 쓴다).
     *
     * @return array{boards: int, posts: int, attachments: int, attachment_files_failed: int, temp_attachments: int, activity_logs: int} 삭제 건수
     */
    public function purge(): array
    {
        $counts = ['boards' => 0, 'posts' => 0, 'attachments' => 0, 'attachment_files_failed' => 0, 'temp_attachments' => 0, 'activity_logs' => 0];

        $boards = Board::query()
            ->whereIn('slug', [ThumbnailMeasureSeeder::CARD_SLUG, ThumbnailMeasureSeeder::GALLERY_SLUG])
            ->get()
            ->filter(fn (Board $board) => ThumbnailMeasureKit::hasMark($board->name));

        if ($boards->isEmpty()) {
            return $counts;
        }

        $previousUser = Auth::user();
        Auth::setUser(ThumbnailMeasureKit::superAdmin());

        try {
            foreach ($boards as $board) {
                $postIds = Post::withTrashed()->where('board_id', $board->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $attachmentIds = Attachment::withTrashed()->where('board_id', $board->id)->pluck('id')->all();
                $tempRows = Attachment::withTrashed()
                    ->where('board_id', 0)
                    ->where('path', 'like', $board->slug.'/temp/%')
                    ->get();

                if ($postIds !== []) {
                    $purged = app(AttachmentService::class)->purgeByPostIds($postIds);
                    $counts['attachments'] += $purged['deleted'];
                    $counts['attachment_files_failed'] += $purged['failed'];
                }

                // 임시 행은 게시판 번호가 0 이라 게시판 삭제가 지우지 않는다 — 파일은 아래 디렉터리 삭제가 지운다
                foreach ($tempRows as $row) {
                    $row->forceDelete();
                    $counts['temp_attachments']++;
                }

                app(BoardService::class)->deleteBoard($board->id, true);

                $counts['boards']++;
                $counts['posts'] += count($postIds);
                $counts['activity_logs'] += $this->purgeActivityLogs([
                    (new Board)->getMorphClass() => [$board->id],
                    (new Post)->getMorphClass() => $postIds,
                    (new Attachment)->getMorphClass() => array_merge($attachmentIds, $tempRows->pluck('id')->all()),
                ]);
            }
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }

        return $counts;
    }

    /**
     * 지운 행을 가리키는 활동 로그를 지웁니다.
     *
     * @param  array<string, list<int>>  $targets  모프 타입 ⇒ 행 번호 목록
     * @return int 지운 로그 수
     */
    private function purgeActivityLogs(array $targets): int
    {
        $deleted = 0;

        foreach ($targets as $morph => $ids) {
            if ($ids !== []) {
                $deleted += ActivityLog::query()->where('loggable_type', $morph)->whereIn('loggable_id', $ids)->delete();
            }
        }

        return $deleted;
    }
}
