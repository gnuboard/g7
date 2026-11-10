<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Contracts\Extension\StorageInterface;
use App\Extension\ModuleManager;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\BoardService;

/**
 * #162 브라우저 실측 데이터 정리 시더 (CLI + G7_PLAYWRIGHT_BYPASS=1 전용)
 *
 * `Issue162MeasureSeeder` 가 만든 것만 지운다 — 실측 게시판 셋(그 글·첨부·파일 포함), 실측 회원 둘.
 * 다른 게시판에 실측 중 올린 `[실측-162]` 표지 글도 지운다. 삭제 건수를 출력한다.
 */
class Issue162MeasureCleanupSeeder extends Seeder
{
    /**
     * 시더를 실행합니다.
     *
     * @return void 반환값 없음
     */
    public function run(): void
    {
        if (php_sapi_name() !== 'cli' || env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->command?->error(__('sirsoft-board::messages.seeders.issue162_measure.guard'));

            return;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->first();
        if ($admin) {
            Auth::login($admin);
        }

        // 게시판 서비스들이 주입받는 것과 같은 모듈 저장소 — 모듈 인스턴스의 저장소(모듈이 정한 디스크)다.
        // `new ModuleStorageDriver('sirsoft-board')` 는 기본 `local` 디스크를 봐서 실제 파일을 지우지 못했다.
        $storage = app(ModuleManager::class)->getModule('sirsoft-board')->getStorage();
        $counts = ['boards' => 0, 'posts' => 0, 'attachments' => 0, 'files' => 0, 'users' => 0];

        // 다른 게시판에 실측 중 쓴 표지 글과 그 첨부
        $markedPosts = Post::withTrashed()->where('title', 'like', Issue162MeasureSeeder::MARKER.'%')->get();
        foreach ($markedPosts as $post) {
            $counts['attachments'] += $this->purgeAttachments($storage, Attachment::withTrashed()->where('post_id', $post->id)->get(), $counts);
            $post->forceDelete();
            $counts['posts']++;
        }

        // 실측 회원이 남긴 연결 전 임시 첨부
        $memberIds = User::whereIn('email', Issue162MeasureSeeder::MEMBER_EMAILS)->pluck('id');
        $counts['attachments'] += $this->purgeAttachments(
            $storage,
            Attachment::withTrashed()->whereNull('post_id')->whereIn('created_by', $memberIds)->get(),
            $counts
        );

        foreach (Issue162MeasureSeeder::BOARD_SLUGS as $slug) {
            $board = Board::where('slug', $slug)->first();
            if (! $board) {
                continue;
            }

            $counts['attachments'] += $this->purgeAttachments(
                $storage,
                Attachment::withTrashed()->where('path', 'like', $slug.'/%')->get(),
                $counts
            );
            app(BoardService::class)->deleteBoard($board->id);
            $counts['boards']++;
        }

        foreach (User::whereIn('email', Issue162MeasureSeeder::MEMBER_EMAILS)->get() as $user) {
            $user->roles()->detach();
            $user->forceDelete();
            $counts['users']++;
        }

        if ($admin) {
            Auth::logout();
        }

        $this->command?->info(__('sirsoft-board::messages.seeders.issue162_measure.cleanup_done', ['counts' => json_encode($counts)]));
    }

    /**
     * 첨부 행과 파일을 지웁니다.
     *
     * @param  StorageInterface  $storage  게시판 첨부 저장소
     * @param  iterable<Attachment>  $attachments  지울 첨부
     * @param  array<string, int>  $counts  집계 (파일 수를 더한다)
     * @return int 지운 행 수
     */
    private function purgeAttachments(StorageInterface $storage, iterable $attachments, array &$counts): int
    {
        $deleted = 0;
        foreach ($attachments as $attachment) {
            if ($storage->exists('attachments', $attachment->path) && $storage->delete('attachments', $attachment->path)) {
                $counts['files']++;
            }
            $attachment->forceDelete();
            $deleted++;
        }

        return $deleted;
    }
}
