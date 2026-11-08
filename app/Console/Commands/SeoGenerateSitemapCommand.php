<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Enums\SitemapGenerationMode;
use App\Jobs\GenerateSitemapJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Sitemap XML 생성 Artisan 커맨드
 *
 * 큐 드라이버 설정에 따라 비동기/동기 실행을 자동 선택합니다.
 * --sync 옵션을 명시하면 큐 드라이버와 무관하게 동기 실행합니다.
 */
class SeoGenerateSitemapCommand extends Command
{
    use LocalizesDescription;

    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'seo:generate-sitemap
        {--sync : 큐 드라이버를 무시하고 동기 실행}
        {--rebuild : 저장소 상태와 무관하게 전체 재생성 (--mode=full 과 동일)}
        {--mode=auto : 재생성 모드 (full|auto|incremental)}';

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $mode = $this->resolveMode();
        if ($mode === null) {
            $allowed = implode(', ', array_map(fn (SitemapGenerationMode $m): string => $m->value, SitemapGenerationMode::cases()));
            $this->error(__('seo.console.generate_sitemap.invalid_mode', ['allowed' => $allowed]));

            return Command::INVALID;
        }

        $forceSync = (bool) $this->option('sync');
        // queue.default 는 SettingsServiceProvider 가 drivers.queue_driver 와 동기화하되,
        // testing 환경에서는 phpunit.xml 값을 보존하므로 격리가 유지된다.
        $connection = (string) Config::get('queue.default', 'sync');
        $isSyncDriver = $connection === 'sync';

        if ($forceSync || $isSyncDriver) {
            return $this->runSync($mode);
        } else {
            GenerateSitemapJob::dispatch($mode);
            $this->info(__('seo.console.generate_sitemap.dispatched', ['mode' => $mode->value]));
        }

        return Command::SUCCESS;
    }

    /**
     * 사이트맵 생성 잡을 이 프로세스에서 바로 실행합니다 — 큐 잡과 같은 중복 방지 잠금을 먼저 잡습니다.
     *
     * 큐를 거치지 않는 실행은 잡의 중복 방지 잠금(ShouldBeUnique)을 잡지 않은 채 돌고, 끝나면 그 잠금을 강제로 풀었다 — 큐에서
     * 생성 중인 잡과 겹쳐 돌고 그 잡의 잠금까지 풀어 세 번째 실행을 들였다(#161 F2). 이제 같은 잠금을 먼저 잡는다. 이미 잡혀
     * 있으면 「이미 생성 중」으로 건너뛰고 성공으로 끝낸다(큐 유일성의 조용한 건너뜀과 같은 의미 — 예약 실행이 실패로 보이지 않게).
     * 잠금 저장소가 오류를 내면 실행하지 않고 실패로 끝낸다.
     *
     * @param  SitemapGenerationMode  $mode  재생성 모드
     * @return int 명령 종료 코드
     */
    private function runSync(SitemapGenerationMode $mode): int
    {
        $job = new GenerateSitemapJob($mode);

        try {
            $lock = app(Repository::class)->lock(UniqueLock::getKey($job), $job->uniqueFor);
            $acquired = $lock->get();
        } catch (\Throwable $e) {
            Log::error('사이트맵 생성 잠금 저장소 오류 — 실행하지 않습니다', ['error' => $e->getMessage()]);
            $this->error(__('seo.console.generate_sitemap.lock_unavailable'));

            return Command::FAILURE;
        }

        if (! $acquired) {
            $this->warn(__('seo.console.generate_sitemap.already_running'));

            return Command::SUCCESS;
        }

        try {
            app(Dispatcher::class)->dispatchSync($job);
        } finally {
            // 소유자 확인 해제 — 실행 끝에 큐 처리기가 이미 풀었거나 남이 잡은 잠금이면 건드리지 않는다
            try {
                $lock->release();
            } catch (\Throwable $e) {
                Log::warning('사이트맵 생성 잠금 해제 실패 — 유지 시간이 지나면 풀립니다', ['error' => $e->getMessage()]);
            }
        }

        $this->info(__('seo.console.generate_sitemap.generated', ['mode' => $mode->value]));

        return Command::SUCCESS;
    }

    /**
     * --rebuild / --mode 옵션으로부터 재생성 모드를 해석합니다.
     *
     * --rebuild 는 --mode 보다 우선하며 Full 로 강제합니다.
     *
     * @return SitemapGenerationMode|null 재생성 모드 (유효하지 않으면 null)
     */
    private function resolveMode(): ?SitemapGenerationMode
    {
        if ((bool) $this->option('rebuild')) {
            return SitemapGenerationMode::Full;
        }

        return SitemapGenerationMode::tryFrom((string) $this->option('mode'));
    }
}
