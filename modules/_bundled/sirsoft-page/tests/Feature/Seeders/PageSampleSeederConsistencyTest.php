<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Page\Database\Seeders\ActivityLogSampleSeeder;
use Modules\Sirsoft\Page\Database\Seeders\PageSampleSeeder;
use Modules\Sirsoft\Page\Database\Seeders\PageSeeder;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * 페이지 샘플 시더 정합 시험 (7.1.0 출시 점검 리포트 2.2 B-5·B-6·B-9·SB-5)
 *
 * 활동 로그 샘플이 리스너와 같은 밑줄 키를 쓰고 운영 로그를 지우지 않는지, 시연 페이지가
 * 미발행·버전 이력·첨부를 보여 주는지 고정한다.
 */
class PageSampleSeederConsistencyTest extends ModuleTestCase
{
    /**
     * 페이지 설치·샘플 시더를 순서대로 돌린다.
     *
     * @return void 반환값 없음
     */
    private function runSeeders(): void
    {
        $command = new class extends Command
        {
            /** @var string 명령 이름 */
            protected $name = 'test:page-sample-seed';
        };
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        foreach ([PageSeeder::class, PageSampleSeeder::class, ActivityLogSampleSeeder::class] as $class) {
            $seeder = $this->app->make($class);
            $seeder->setContainer($this->app)->setCommand($command);
            $seeder->__invoke();
        }
    }

    /**
     * 샘플 로그는 밑줄 키·표식을 쓰고, 재시드해도 운영 로그는 남고 샘플 로그는 중복되지 않는다.
     *
     * @scenario case=activity_logs
     *
     * @effects page_sample_logs_use_listener_keys_and_keep_operator_logs
     *
     * @return void 반환값 없음
     */
    public function test_page_sample_logs_use_listener_keys_and_keep_operator_logs(): void
    {
        Storage::fake('modules');
        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->value('id'));

        $operatorLogId = DB::table('activity_logs')->insertGetId([
            'log_type' => 'admin',
            'user_id' => $admin->id,
            'action' => 'page.update',
            'description_key' => 'sirsoft-page::activity_log.description.page_update',
            'description_params' => json_encode(['title' => ['ko' => '운영 페이지']]),
            'properties' => json_encode(['extension_origin' => 'sirsoft-page']),
            'created_at' => now()->subYear(),
        ]);

        $this->runSeeders();
        $firstRunCount = DB::table('activity_logs')->where('properties', 'like', '%"seeded_by":"sirsoft-page:sample"%')->count();
        $this->runSeeders();

        $this->assertDatabaseHas('activity_logs', ['id' => $operatorLogId]);
        $this->assertGreaterThan(0, $firstRunCount);
        $this->assertSame($firstRunCount, DB::table('activity_logs')->where('properties', 'like', '%"seeded_by":"sirsoft-page:sample"%')->count(),
            '재시드는 새로 만든 페이지가 없으면 로그를 더 만들지 않는다(중복 없음).');

        // B-6: 점 형식 description 키 없음, B-9: 조회 로그 없음
        $this->assertSame(0, DB::table('activity_logs')->where('description_key', 'like', 'sirsoft-page::activity_log.description.page.%')->count());
        $this->assertSame(0, DB::table('activity_logs')->whereIn('description_key', [
            'sirsoft-page::activity_log.description.page_show',
            'sirsoft-page::activity_log.description.page_index',
        ])->count());

        // 모든 샘플 로그 키는 번역이 있다
        foreach (DB::table('activity_logs')->where('properties', 'like', '%"seeded_by":"sirsoft-page:sample"%')->distinct()->pluck('description_key') as $key) {
            $this->assertNotSame($key, __($key), "{$key} 번역이 없다.");
        }

        // 생성 로그는 페이지 작성 시각 이후 (작성 시각과 같다)
        foreach (Page::all() as $page) {
            $createdLog = DB::table('activity_logs')->where('loggable_type', Page::class)->where('loggable_id', $page->id)
                ->where('action', 'page.create')->value('created_at');
            $this->assertNotNull($createdLog);
            $this->assertGreaterThanOrEqual($page->created_at->timestamp, strtotime($createdLog));
        }
    }

    /**
     * 운영자가 만든 페이지와 이미 있던 기본 페이지에는 활동 로그를 지어내지 않는다 — 이번 실행에서 만든 페이지만.
     *
     * 실측(개발 DB 사본): 운영자 페이지 10개와 기존 기본 페이지 6개에 2~3건씩 실제 운영자 명의의 로그가 생겼다.
     * 작성자가 탈퇴해 created_by 가 비어 있는 페이지가 있어도 실패하지 않는다.
     *
     * @scenario case=operational_site_existing_pages
     *
     * @effects page_sample_logs_only_for_pages_created_in_this_run
     *
     * @return void 반환값 없음
     */
    public function test_page_sample_logs_skip_existing_pages(): void
    {
        Storage::fake('modules');
        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->value('id'));

        // 운영자 페이지(작성자 탈퇴 — FK nullOnDelete 로 null) + 이미 있던 기본 페이지 terms
        $operatorPageId = DB::table('pages')->insertGetId([
            'slug' => 'qa-probe-page',
            'title' => json_encode(['ko' => '운영 페이지', 'en' => 'Operator page']),
            'content' => json_encode(['ko' => '<p>운영</p>', 'en' => '<p>ops</p>']),
            'content_mode' => 'html',
            'published' => true,
            'published_at' => now()->subMonth(),
            'current_version' => 1,
            'created_by' => null,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);
        DB::table('pages')->where('slug', 'terms')->delete();
        $existingTermsId = DB::table('pages')->insertGetId([
            'slug' => 'terms',
            'title' => json_encode(['ko' => '이용약관', 'en' => 'Terms of Service']),
            'content' => json_encode(['ko' => '<p>운영 약관</p>', 'en' => '<p>terms</p>']),
            'content_mode' => 'html',
            'published' => true,
            'published_at' => now()->subMonth(),
            'current_version' => 1,
            'created_by' => null,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $this->runSeeders();

        $this->assertSame(0, DB::table('activity_logs')->where('loggable_type', Page::class)
            ->whereIn('loggable_id', [$operatorPageId, $existingTermsId])->count(), '시더가 만들지 않은 페이지에 로그를 만들면 안 된다.');
        $this->assertSame(0, DB::table('activity_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->whereNotNull('l.user_id')->whereNull('u.id')->count(), '활동 로그 행위자는 실재 회원이어야 한다.');
        $sampleId = Page::where('slug', 'sample-draft')->value('id');
        $this->assertGreaterThan(0, DB::table('activity_logs')->where('loggable_type', Page::class)->where('loggable_id', $sampleId)->count());
    }

    /**
     * 시연 페이지: 미발행 초안, 버전 3개·버전 2 복원, 이미지 첨부 (SB-5).
     *
     * @scenario case=demo_pages
     *
     * @effects page_sample_seeder_creates_draft_history_and_attachment_pages
     *
     * @return void 반환값 없음
     */
    public function test_page_sample_seeder_creates_demo_pages(): void
    {
        Storage::fake('modules');
        // 활동 로그 샘플 시더는 admin 역할 회원이 없으면 건너뛴다 — 앞 시험이 남긴 행에 기대지 않고 직접 붙인다
        $admin = User::factory()->create(['is_super' => true]);
        $admin->roles()->attach(Role::where('identifier', 'admin')->value('id'));

        $this->runSeeders();

        $draft = Page::where('slug', 'sample-draft')->first();
        $this->assertNotNull($draft);
        $this->assertFalse((bool) $draft->published);

        $history = Page::where('slug', 'sample-history')->first();
        $this->assertSame(2, (int) $history->current_version);
        $this->assertSame(3, DB::table('page_versions')->where('page_id', $history->id)->count());
        $this->assertDatabaseHas('activity_logs', ['loggable_id' => $history->id, 'action' => 'page.restore']);

        $withFile = Page::where('slug', 'sample-attachment')->first();
        $this->assertSame(1, DB::table('page_attachments')->where('page_id', $withFile->id)->count());

        // 첨부 업로드 로그 속성 키는 리스너와 같은 original_filename (실제 칸 이름) — original_name 은 쓰지 않는다
        $uploadLog = DB::table('activity_logs')->where('action', 'page_attachment.upload')->value('properties');
        $this->assertNotNull($uploadLog, '첨부 업로드 샘플 로그가 있어야 한다.');
        $properties = json_decode($uploadLog, true);
        $this->assertSame('map.jpg', $properties['original_filename'] ?? null);
        $this->assertArrayNotHasKey('original_name', $properties);

        // 기본 페이지 6개는 그대로 (설치 시더는 바꾸지 않는다)
        $this->assertSame(6, Page::whereIn('slug', ['terms', 'privacy', 'refund', 'about', 'faq', 'contact'])->count());

        // 사이트 기본 언어 제목이 비지 않는다
        $siteLocale = config('app.site_locale');
        foreach ([$draft, $history, $withFile] as $page) {
            $this->assertNotEmpty($page->title[$siteLocale] ?? null);
        }
    }
}
