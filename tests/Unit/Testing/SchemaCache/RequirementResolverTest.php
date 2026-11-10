<?php

namespace Tests\Unit\Testing\SchemaCache;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sirsoft\Board\Tests\ModuleTestCase as BoardModuleTestCase;
use Modules\Sirsoft\Ecommerce\Database\Seeders\TestingSeeder;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase as EcommerceModuleTestCase;
use Plugins\Sirsoft\Gdpr\Tests\Feature\AnnualNoticeMigrationTest;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase as GdprPluginTestCase;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase as KoreaeximPluginTestCase;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase as PayKginicisPluginTestCase;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase as PayNhnkcpPluginTestCase;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase as PayNicepaymentsPluginTestCase;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase as TosspaymentsPluginTestCase;
use Tests\PureTestCase;
use Tests\TestCase;

/**
 * 테스트 클래스별 스키마 요구 프로필 — 베이스 클래스가 선언한 시드·첫 사용 규칙을 그대로 읽는다.
 *
 * 시드를 쓰는 베이스(`migrateFreshUsing()` 에 시더가 있음)는 `seed:<시더>`, 첫 사용 때 시드를 지우던 게시판·페이지·hello 베이스는
 * `none-exact`(그 뒤 `any`), 나머지는 `any`. SQLite 로 격리하는 테스트와 앱을 쓰지 않는 순수 테스트는 스키마를 요구하지 않는다.
 * 이 클래스 자신은 DB 트레이트가 없어 스키마를 판정하지 않는다.
 */
class RequirementResolverTest extends TestCase
{
    /**
     * 테스트 인스턴스의 보호 메서드를 호출합니다.
     *
     * @param  TestCase  $test  테스트 인스턴스
     * @param  string  $method  메서드 이름
     * @return mixed 반환값
     */
    private function invoke(TestCase $test, string $method): mixed
    {
        return (fn () => $this->{$method}())->call($test);
    }

    /**
     * 코어 RefreshDatabase 테스트와 시드 없는 플러그인(GDPR)은 any 다.
     *
     * @scenario case=core_and_unseeded_plugin
     *
     * @effects requirement_follows_base_class_declaration
     *
     * @return void 반환값 없음
     */
    public function test_core_and_unseeded_plugin_accept_any(): void
    {
        $core = new class('probe') extends TestCase
        {
            use RefreshDatabase;
        };
        $gdpr = new class('probe') extends GdprPluginTestCase {};

        $this->assertTrue($this->invoke($core, 'needsTestingSchema'));
        $this->assertSame('any', $this->invoke($core, 'requiredTestingSchema'));
        $this->assertSame('any', $this->invoke($gdpr, 'requiredTestingSchema'));
    }

    /**
     * 이커머스·결제 플러그인·koreaexim 베이스는 이커머스 TestingSeeder 시드를 요구한다(같은 시더라 서로 오가도 다시 시드하지 않는다).
     *
     * @scenario case=seeded_bases
     *
     * @effects requirement_follows_base_class_declaration, shared_seeder_shares_profile
     *
     * @return void 반환값 없음
     */
    public function test_seeded_bases_require_ecommerce_testing_seeder(): void
    {
        $expected = 'seed:'.TestingSeeder::class;

        foreach ([
            new class('probe') extends EcommerceModuleTestCase {},
            new class('probe') extends TosspaymentsPluginTestCase {},
            new class('probe') extends PayKginicisPluginTestCase {},
            new class('probe') extends PayNhnkcpPluginTestCase {},
            new class('probe') extends PayNicepaymentsPluginTestCase {},
            new class('probe') extends KoreaeximPluginTestCase {},
        ] as $test) {
            $this->assertSame($expected, $this->invoke($test, 'requiredTestingSchema'), $test::class);
        }
    }

    /**
     * 게시판 베이스는 첫 사용 때 none-exact(시드 없는 상태), 그 뒤에는 any 다.
     *
     * @scenario case=first_use_bases
     *
     * @effects requirement_follows_base_class_declaration
     *
     * @return void 반환값 없음
     */
    public function test_board_first_use_is_none_exact_then_any(): void
    {
        $board = new class('probe') extends BoardModuleTestCase {};
        $flag = new \ReflectionProperty(BoardModuleTestCase::class, 'migrated');
        $original = $flag->getValue();

        try {
            $flag->setValue(null, false);
            $this->assertSame('none-exact', $this->invoke($board, 'requiredTestingSchema'));
            $flag->setValue(null, true);
            $this->assertSame('any', $this->invoke($board, 'requiredTestingSchema'));
        } finally {
            $flag->setValue(null, $original);
        }
    }

    /**
     * SQLite 로 격리하는 GDPR 연례 통지 마이그레이션 테스트와 순수 테스트는 테스트 스키마를 판정하지 않는다.
     *
     * @scenario case=excluded
     *
     * @effects isolated_and_pure_tests_skip_schema
     *
     * @return void 반환값 없음
     */
    public function test_isolated_and_pure_tests_skip_schema(): void
    {
        $this->assertFalse($this->invoke(new AnnualNoticeMigrationTest('probe'), 'needsTestingSchema'));
        $this->assertFalse($this->invoke($this, 'needsTestingSchema'), 'DB 트레이트 없는 테스트');
        $this->assertFalse(is_subclass_of(PureTestCase::class, TestCase::class), '순수 베이스는 앱 테스트 베이스가 아니다');
    }
}
