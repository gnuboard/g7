<?php

namespace Modules\Sirsoft\Page\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;

/**
 * Playwright E2E 용 페이지 도메인 시드 커맨드.
 *
 * 미발행 페이지 관리자 미리보기 E2E 를 위해 고정 슬러그의 미발행/발행 페이지와
 * 첨부를 발급하여 stdout 에 JSON 으로 반환한다. 재실행 시 동일 슬러그를 물리 삭제 후 재생성해
 * 멱등성을 보장한다 (페이지·첨부는 hard delete 모델).
 *
 * 보안 가드 (코어 PlaywrightIssueToken 과 동일 3중 패턴):
 *   ① CLI 한정 — `php_sapi_name() === 'cli'`
 *   ② G7_PLAYWRIGHT_BYPASS=1 환경변수 옵트인
 *   ③ APP_DEBUG=true inline override — production + debug=false 환경에서도 동작
 *
 * 호출 예시:
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-page --attachments=2 --json
 */
class PlaywrightSeedPage extends Command
{
    /**
     * 미리보기 E2E 용 고정 슬러그 (spec 에서 URL 로 사용).
     */
    private const UNPUBLISHED_SLUG = 'test-e2e-unpublished-preview';

    private const PUBLISHED_SLUG = 'test-e2e-published-preview';

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'playwright:seed-page
        {--attachments=2 : 페이지당 발급할 첨부 수}
        {--json : 결과를 JSON 으로 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = 'Playwright E2E 용 페이지 도메인 데이터 시드 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        // ① CLI 한정
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다.');

            return self::FAILURE;
        }

        // ② 명시 옵트인
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다.');

            return self::FAILURE;
        }

        // ③ APP_DEBUG 강제 — SettingsServiceProvider 의 bypass 분기가 settings JSON 덮어쓰기 차단 상태
        Config::set('app.debug', true);

        $attachmentsPerPage = max(1, (int) $this->option('attachments'));

        $result = DB::transaction(function () use ($attachmentsPerPage) {
            // 재실행 멱등: 고정 슬러그 페이지 물리 삭제 (첨부는 FK 로 함께 정리)
            Page::whereIn('slug', [self::UNPUBLISHED_SLUG, self::PUBLISHED_SLUG])->get()
                ->each(function (Page $page) {
                    PageAttachment::where('page_id', $page->id)->delete();
                    $page->delete();
                });

            $author = User::factory()->create();

            $unpublished = Page::factory()->create([
                'slug' => self::UNPUBLISHED_SLUG,
                'title' => ['ko' => '미발행 미리보기 대상', 'en' => 'Unpublished Preview Target'],
                'content' => ['ko' => '<p>미발행 본문</p>', 'en' => '<p>Unpublished body</p>'],
                'published' => false,
                'published_at' => null,
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            $published = Page::factory()->published()->create([
                'slug' => self::PUBLISHED_SLUG,
                'title' => ['ko' => '발행 대조군', 'en' => 'Published Control'],
                'content' => ['ko' => '<p>발행 본문</p>', 'en' => '<p>Published body</p>'],
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            $attachmentIds = [];
            foreach ([$unpublished, $published] as $page) {
                for ($i = 1; $i <= $attachmentsPerPage; $i++) {
                    // 첫 첨부는 이미지(미리보기 URL 생성 대상), 나머지는 일반 파일
                    $factory = PageAttachment::factory();
                    $factory = $i === 1 ? $factory->image() : $factory;

                    $attachment = $factory->create([
                        'page_id' => $page->id,
                        'order' => $i,
                        'created_by' => $author->id,
                    ]);
                    $attachmentIds[] = $attachment->id;
                }
            }

            return [
                'unpublished_slug' => self::UNPUBLISHED_SLUG,
                'published_slug' => self::PUBLISHED_SLUG,
                'unpublished_id' => $unpublished->id,
                'published_id' => $published->id,
                'attachment_ids' => $attachmentIds,
            ];
        });

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('페이지 시드 완료: 미발행='.self::UNPUBLISHED_SLUG.' / 발행='.self::PUBLISHED_SLUG);
        }

        return self::SUCCESS;
    }
}
