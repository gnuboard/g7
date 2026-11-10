<?php

namespace Modules\Sirsoft\Page\Database\Seeders\Sample;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Services\PageContentMediaService;

/**
 * #162 브라우저 실측용 페이지 시더 (CLI + G7_PLAYWRIGHT_BYPASS=1 전용, #883 4·5단계)
 *
 * 발행 페이지(본문 동영상 1)와 미발행 페이지(본문 동영상 1)를 만든다. 동영상은 실제 업로드 경로
 * (`PageContentMediaService::upload` → `syncWithContent`)로 붙인다. 슬러그 접두 `m162-` 가 정리 표지다.
 */
class Issue162MeasureSeeder extends Seeder
{
    /** 정리 표지(슬러그 접두) */
    public const SLUG_PREFIX = 'm162-';

    /** 실측 페이지 슬러그 => 발행 여부 */
    public const PAGES = ['m162-published' => true, 'm162-draft' => false];

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

        $fixture = base_path('tests/Fixtures/media/sample-1s.mp4');
        if (! is_file($fixture)) {
            return;
        }

        $admin = User::whereHas('roles', fn ($q) => $q->where('identifier', 'admin'))->first();
        if ($admin) {
            Auth::login($admin);
        }

        $service = app(PageContentMediaService::class);
        $created = 0;

        foreach (self::PAGES as $slug => $published) {
            if (Page::where('slug', $slug)->exists()) {
                continue;
            }

            $tempKey = (string) Str::uuid();
            $copy = tempnam(sys_get_temp_dir(), 'm162').'.mp4';
            copy($fixture, $copy);
            $row = $service->upload(new UploadedFile($copy, 'm162.mp4', 'video/mp4', null, true), $tempKey);
            $html = '<p>[실측-162] '.$slug.'</p><video controls src="/api/modules/sirsoft-page/pages/content-media/'.$row->hash.'"></video>';

            $page = Page::create([
                'slug' => $slug,
                'title' => ['ko' => '[실측-162] '.$slug, 'en' => '[M-162] '.$slug],
                'content' => ['ko' => $html, 'en' => $html],
                'content_mode' => 'html',
                'published' => $published,
                'published_at' => $published ? now() : null,
                'current_version' => 1,
                'created_by' => $admin?->id,
                'updated_by' => $admin?->id,
            ]);
            $service->syncWithContent($page, $tempKey);
            $created++;
        }

        if ($admin) {
            Auth::logout();
        }

        $this->command?->info(__('sirsoft-page::messages.seeders.issue162_measure.done', ['count' => $created]));
    }
}
