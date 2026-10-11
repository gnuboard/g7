<?php

namespace Tests\Unit\Support;

use App\Support\ConfigCacheHelper;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * config 캐시 재생성 묶기(`ConfigCacheHelper::deferRebuilds()`) 테스트 (#145 F6).
 *
 * 자동 비활성화 연쇄에서 확장 1건마다 리스너가 config:cache(일회용 앱 부팅)를 돌려 17건 연쇄에서 MySQL 연결 151개가
 * 고갈됐다. 묶는 동안의 재생성 요청은 대기 표시만 하고, 가장 바깥 호출이 끝날 때 한 번만 재생성한다.
 * testing 환경의 `rebuild()` 는 `config:clear` 만 부르므로 그 호출 횟수로 재생성 횟수를 센다.
 */
class ConfigCacheHelperDeferTest extends TestCase
{
    /**
     * Artisan 파사드를 기록용 스파이로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::spy();
    }

    /**
     * 중첩된 묶음 안의 여러 재생성 요청은 가장 바깥이 끝날 때 한 번만 실행된다.
     *
     * @scenario case=nested
     *
     * @effects deferred_rebuild_runs_once_after_outermost
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_nested_requests_rebuild_once(): void
    {
        $result = ConfigCacheHelper::deferRebuilds(function () {
            ConfigCacheHelper::rebuild();
            ConfigCacheHelper::deferRebuilds(function () {
                ConfigCacheHelper::rebuild();
                ConfigCacheHelper::rebuild();
            });
            Artisan::shouldNotHaveReceived('call', ['config:clear']);

            return 'value';
        });

        $this->assertSame('value', $result);
        Artisan::shouldHaveReceived('call')->with('config:clear')->once();
    }

    /**
     * 요청이 없으면 재생성하지 않는다.
     *
     * @scenario case=no_request
     *
     * @effects deferred_rebuild_skipped_without_request
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_no_request_no_rebuild(): void
    {
        ConfigCacheHelper::deferRebuilds(fn () => null);

        Artisan::shouldNotHaveReceived('call', ['config:clear']);
    }

    /**
     * 예외가 나도 대기 중인 재생성을 실행하고 깊이를 복구한다(이후 호출은 즉시 재생성).
     *
     * @scenario case=exception
     *
     * @effects deferred_rebuild_restores_depth_on_exception
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_exception_restores_depth(): void
    {
        try {
            ConfigCacheHelper::deferRebuilds(function () {
                ConfigCacheHelper::rebuild();
                throw new RuntimeException('boom');
            });
            $this->fail('예외가 전파돼야 한다');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        Artisan::shouldHaveReceived('call')->with('config:clear')->once();

        ConfigCacheHelper::rebuild();
        Artisan::shouldHaveReceived('call')->with('config:clear')->twice();
    }
}
