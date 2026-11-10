<?php

namespace Tests\Feature\Cache;

use App\Extension\Cache\ModuleCacheDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 캐시 키 목록 동시 수정 회귀 테스트 (#142 F)
 *
 * 여러 프로세스가 같은 순간에 서로 다른 키를 같은 태그로 저장하면, 키 목록(태그 인덱스)의
 * 「읽기 → 고치기 → 쓰기」가 겹쳐 일부 키가 목록에서 빠졌다(수정 전 실측: 8개 중 1~3개만 남음).
 * 빠진 키는 태그 비우기로 지워지지 않아 게시판을 만들어도 메뉴 캐시가 최대 하루 동안 남았다.
 *
 * 실제 별도 프로세스 8개를 띄워 같은 시각에 저장시키고, 키 목록에 8개가 모두 남는지와
 * 태그 비우기 뒤 남은 키가 0 인지 확인한다. 저장소는 프로세스 사이에서 공유되는 file 이다.
 */
#[Group('slow')]
class CacheKeyIndexConcurrencyTest extends TestCase
{
    /**
     * 시험 키 접두사(확장 식별자 자리)
     */
    private const IDENTIFIER = 'zz-i142-race-t';

    /**
     * 동시에 띄우는 프로세스 수
     */
    private const WORKERS = 8;

    /**
     * 시험 키·키 목록을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    /**
     * 시험 키·키 목록을 지운다.
     *
     * @return void 반환값 없음
     */
    private function cleanup(): void
    {
        $driver = new ModuleCacheDriver(self::IDENTIFIER, 'file');
        for ($i = 0; $i < self::WORKERS; $i++) {
            $driver->forget("k{$i}");
        }
        Cache::store('file')->forget('g7:_idx:g7:module.'.self::IDENTIFIER);
    }

    /**
     * 8 프로세스가 동시에 저장해도 키 목록에 8개가 모두 남고, 태그 비우기 뒤 남은 키는 0 이다.
     *
     * @scenario store=file_concurrent
     *
     * @effects concurrent_writers_keep_every_index_entry, flush_tags_removes_every_concurrent_key
     *
     * @return void 반환값 없음
     */
    public function test_concurrent_writers_keep_every_key_in_index(): void
    {
        $this->cleanup();

        // 출발 장벽: 고정 8초를 기다리는 대신, 각 워커가 부팅을 마치면 ready-<n> 을 만들고 go 파일이 생길 때까지 돈다.
        // 부모는 모두 준비되면(최대 30초) go 를 만든다 — 부팅이 빠르면 그만큼 일찍 출발한다.
        $barrier = storage_path('framework/testing/cache-race-'.getmypid().'-'.uniqid());
        File::ensureDirectoryExists($barrier);
        $go = $barrier.'/go';
        $processes = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $code = sprintf(
                '$d = new App\Extension\Cache\ModuleCacheDriver(%s, "file"); touch(%s); $until = microtime(true) + 60; while (! is_file(%s) && microtime(true) < $until) { usleep(200); } $d->remember("k%d", fn () => "v%d", 600, ["race-tag"]);',
                var_export(self::IDENTIFIER, true),
                var_export($barrier.'/ready-'.$i, true),
                var_export($go, true),
                $i,
                $i
            );
            $process = new Process([PHP_BINARY, base_path('artisan'), 'tinker', '--execute='.$code], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(120);
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;
        while (count(glob($barrier.'/ready-*') ?: []) < self::WORKERS && microtime(true) < $deadline) {
            usleep(50_000);
        }
        touch($go);

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        }
        File::deleteDirectory($barrier);

        $driver = new ModuleCacheDriver(self::IDENTIFIER, 'file');
        $index = Cache::store('file')->get('g7:_idx:g7:module.'.self::IDENTIFIER, []);

        $stored = 0;
        for ($i = 0; $i < self::WORKERS; $i++) {
            if ($driver->has("k{$i}")) {
                $stored++;
            }
        }
        $this->assertSame(self::WORKERS, $stored, '모든 프로세스의 저장이 끝나야 한다');
        $this->assertCount(self::WORKERS, $index, '키 목록에서 빠진 키가 있다 — 동시 수정이 서로를 덮어썼다');

        $this->assertTrue($driver->flushTags(['race-tag']));

        $remaining = 0;
        for ($i = 0; $i < self::WORKERS; $i++) {
            if ($driver->has("k{$i}")) {
                $remaining++;
            }
        }
        $this->assertSame(0, $remaining, '태그 비우기 뒤 남은 키가 있다');
    }
}
