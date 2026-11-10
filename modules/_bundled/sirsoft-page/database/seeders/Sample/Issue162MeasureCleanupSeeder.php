<?php

namespace Modules\Sirsoft\Page\Database\Seeders\Sample;

use App\Extension\ModuleManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;

/**
 * #162 실측 페이지 정리 시더 — 슬러그 접두 `m162-` 페이지와 그 첨부·본문 미디어(행·파일)를 지운다 (#883)
 *
 * 실측 중 남은 임시 본문 미디어(`page_id` 없음)도 실측 페이지에 쓰인 적 없는 것은 판별할 수 없으므로, 이 시더 실행 이후
 * 생긴 것은 지우지 않는다 — 임시 첨부 정리 명령(`sirsoft-page:prune-temp-attachments` 보존기간)에 맡긴다.
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
            $this->command?->error(__('sirsoft-page::messages.seeders.issue162_measure.guard'));

            return;
        }

        // 페이지 서비스가 주입받는 것과 같은 모듈 저장소 — 모듈 인스턴스의 저장소(모듈이 정한 디스크)다.
        // `new ModuleStorageDriver('sirsoft-page')` 는 기본 `local` 디스크를 봐서 실제 파일을 0건 지웠다.
        $storage = app(ModuleManager::class)->getModule('sirsoft-page')->getStorage();
        $counts = ['pages' => 0, 'attachments' => 0, 'files' => 0];

        foreach (Page::where('slug', 'like', Issue162MeasureSeeder::SLUG_PREFIX.'%')->get() as $page) {
            foreach (PageAttachment::where('page_id', $page->id)->get() as $attachment) {
                if ($storage->exists('attachments', $attachment->path) && $storage->delete('attachments', $attachment->path)) {
                    $counts['files']++;
                }
                $attachment->forceDelete();
                $counts['attachments']++;
            }
            DB::table('page_versions')->where('page_id', $page->id)->delete();
            $page->forceDelete();
            $counts['pages']++;
        }

        $this->command?->info(__('sirsoft-page::messages.seeders.issue162_measure.cleanup_done', ['counts' => json_encode($counts)]));
    }
}
