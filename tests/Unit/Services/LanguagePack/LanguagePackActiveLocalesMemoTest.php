<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePackService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 활성 로케일 조회가 언어팩 레지스트리 메모를 재사용 (#860 W8)
 *
 * 부팅에서 레지스트리가 활성 언어팩을 이미 한 번 읽는다. 언어 셀렉터·프런트 설정이 활성 로케일을 물을 때
 * DB 를 다시 조회하지 않고 그 메모를 쓰며, 언어팩 상태가 바뀌어 `invalidate()` 되면 다시 읽는다.
 */
class LanguagePackActiveLocalesMemoTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * language_packs 조회 건수를 세며 콜백을 실행합니다.
     *
     * @param  \Closure  $callback  실행할 작업
     * @return int 조회 건수
     */
    private function countPackQueries(\Closure $callback): int
    {
        $count = 0;
        DB::listen(static function ($query) use (&$count): void {
            if (str_contains($query->sql, 'language_packs')) {
                $count++;
            }
        });

        $callback();

        return $count;
    }

    /**
     * 레지스트리가 읽어 둔 뒤의 활성 로케일 조회는 DB 를 다시 읽지 않습니다.
     *
     * @effects active_locales_reuse_registry_memo
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 레지스트리_메모를_재사용하고_무효화_뒤에_다시_읽는다(): void
    {
        $registry = $this->app->make(LanguagePackRegistry::class);
        $service = $this->app->make(LanguagePackService::class);
        $registry->invalidate();
        $registry->getActivePacks();

        $queries = $this->countPackQueries(function () use ($service, &$locales): void {
            $locales = $service->getActiveLocales();
            $service->getActiveLocales();
        });

        $this->assertSame(0, $queries, '부팅이 이미 읽은 활성 언어팩을 다시 조회하면 안 된다.');
        foreach ((array) config('app.supported_locales', ['ko', 'en']) as $configured) {
            $this->assertContains($configured, $locales, '설정의 지원 로케일은 항상 포함된다.');
        }

        $registry->invalidate();
        $this->assertGreaterThan(0, $this->countPackQueries(fn () => $service->getActiveLocales()), '무효화 뒤에는 다시 읽어야 한다.');
    }
}
