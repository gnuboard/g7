<?php

namespace Tests\Unit\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\Helpers\DependencyEnricher;
use App\Models\Module;
use App\Models\Plugin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 의존 확장 충족 판정식 SSoT — 정규화·순회·상태·버전 비교·사람이 읽는 문장 (#152)
 *
 * 회귀: 같은 「의존 확장이 조건을 만족하는가」 판정이 템플릿 설치·활성화·런타임 503·목록 API·미리보기·모듈 trait 에
 * 따로 구현돼 있었고, 템플릿 활성화 사본만 버전 비교를 빠뜨려 활성화 200 직후 사이트 전체가 503 이 됐다.
 * 모든 경로가 이 판정식을 부르므로 여기서 계약을 잠근다.
 */
class DependencyEnricherStatusTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 의존 대상 모듈·플러그인을 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Module::create([
            'identifier' => 'test-dep152-shop', 'vendor' => 'test', 'name' => ['ko' => '샵', 'en' => 'Shop'],
            'version' => '1.2.2', 'status' => ExtensionStatus::Active->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Module::create([
            'identifier' => 'test-dep152-off', 'vendor' => 'test', 'name' => ['ko' => '꺼진 모듈', 'en' => 'Off'],
            'version' => '1.0.0', 'status' => ExtensionStatus::Inactive->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
        Plugin::create([
            'identifier' => 'test-dep152-pay', 'vendor' => 'test', 'name' => ['ko' => '결제', 'en' => 'Pay'],
            'version' => '2.0.0', 'status' => ExtensionStatus::Updating->value, 'description' => ['ko' => '', 'en' => ''],
        ]);
    }

    /**
     * 빈 제약·null·공백·문자열이 아닌 값은 「제약 없음(*)」이다.
     *
     * @scenario surface=normalize
     *
     * @effects empty_constraint_means_any_version
     *
     * @return void 반환값 없음
     */
    public function test_normalize_constraint_treats_empty_values_as_any(): void
    {
        foreach ([null, '', '   ', ['>=1.0.0'], 3] as $raw) {
            $this->assertSame('*', DependencyEnricher::normalizeConstraint($raw), var_export($raw, true));
        }

        $this->assertSame('>=1.2.0', DependencyEnricher::normalizeConstraint('  >=1.2.0 '));
    }

    /**
     * 맵형·목록형 선언을 (식별자, 종류, 제약) 으로 펼친다.
     *
     * @scenario surface=iterate
     *
     * @effects iterate_accepts_map_and_list
     *
     * @return void 반환값 없음
     */
    public function test_iterate_flattens_map_and_list_declarations(): void
    {
        $items = iterator_to_array(DependencyEnricher::iterate([
            'modules' => ['test-dep152-shop' => '>=1.0.0', 'test-dep152-off' => null],
            'plugins' => ['test-dep152-pay'],
        ]), false);

        $this->assertSame([
            ['identifier' => 'test-dep152-shop', 'type' => 'module', 'constraint' => '>=1.0.0'],
            ['identifier' => 'test-dep152-off', 'type' => 'module', 'constraint' => '*'],
            ['identifier' => 'test-dep152-pay', 'type' => 'plugin', 'constraint' => '*'],
        ], $items);
    }

    /**
     * 상태는 미설치 → 꺼짐 → 버전 미달 → 충족 순으로 판정하고, updating 은 런타임 플래그일 때만 활성이다.
     *
     * @scenario surface=status
     *
     * @effects status_order_not_installed_inactive_version_mismatch
     *
     * @return void 반환값 없음
     */
    public function test_status_of_orders_reasons_and_respects_updating_flag(): void
    {
        $shop = Module::where('identifier', 'test-dep152-shop')->first();
        $off = Module::where('identifier', 'test-dep152-off')->first();
        $pay = Plugin::where('identifier', 'test-dep152-pay')->first();

        $this->assertSame('not_installed', DependencyEnricher::statusOf(null, '>=1.0.0'));
        $this->assertSame('inactive', DependencyEnricher::statusOf($off, '>=9.0.0'), '꺼져 있으면서 버전도 낮으면 꺼짐만 보고');
        $this->assertSame('version_mismatch', DependencyEnricher::statusOf($shop, '>=1.3.0'));
        $this->assertSame('met', DependencyEnricher::statusOf($shop, '>=1.2.0'));
        $this->assertSame('met', DependencyEnricher::statusOf($shop, '*'));

        $this->assertSame('inactive', DependencyEnricher::statusOf($pay, '>=1.0.0'), '설치·활성화 판정에서 updating 은 꺼짐');
        $this->assertSame('met', DependencyEnricher::statusOf($pay, '>=1.0.0', true), '런타임 판정에서 updating 은 활성');
    }

    /**
     * 버전 비교는 빈 제약·null 을 충족으로, 비교 불가 제약·빈 설치 버전을 미충족으로 보고 예외를 내지 않는다.
     *
     * @scenario surface=version
     *
     * @effects version_check_never_throws
     *
     * @return void 반환값 없음
     */
    public function test_check_version_constraint_never_throws(): void
    {
        $this->assertTrue(DependencyEnricher::checkVersionConstraint('1.0.0', null));
        $this->assertTrue(DependencyEnricher::checkVersionConstraint('1.0.0', ''));
        $this->assertTrue(DependencyEnricher::checkVersionConstraint(null, '*'));
        $this->assertFalse(DependencyEnricher::checkVersionConstraint(null, '>=1.0.0'));
        $this->assertFalse(DependencyEnricher::checkVersionConstraint('', '>=1.0.0'));
        $this->assertFalse(DependencyEnricher::checkVersionConstraint('1.0.0', 'not a constraint !!'));
        $this->assertTrue(DependencyEnricher::checkVersionConstraint('1.3.0', '>=1.3.0'));
    }

    /**
     * enrich 가 설치 여부·사유 상태를 싣고, 정규화한 제약을 required_version 으로 돌려준다.
     *
     * @scenario surface=enrich
     *
     * @effects enrich_exposes_status_and_installed
     *
     * @return void 반환값 없음
     */
    public function test_enrich_exposes_status_and_installed_flags(): void
    {
        $items = DependencyEnricher::enrich([
            'modules' => ['test-dep152-shop' => '>=1.3.0', 'test-dep152-off' => '', 'test-dep152-none' => '>=1.0.0'],
            'plugins' => ['test-dep152-pay' => '>=1.0.0'],
        ]);

        $this->assertSame(['version_mismatch', 'inactive', 'not_installed', 'inactive'], array_column($items, 'status'));
        $this->assertSame([true, true, false, true], array_column($items, 'is_installed'));
        $this->assertSame([false, false, false, false], array_column($items, 'is_met'));
        $this->assertSame('*', $items[1]['required_version'], '빈 제약은 * 로 정규화');

        $runtime = DependencyEnricher::enrich(['plugins' => ['test-dep152-pay' => '>=1.0.0']], true);
        $this->assertSame('met', $runtime[0]['status']);
        $this->assertTrue($runtime[0]['is_active']);
        $this->assertTrue($runtime[0]['is_met']);
    }

    /**
     * 미충족 항목을 사람이 읽는 한 문장으로 잇는다 — 사유 원문(version_mismatch)을 노출하지 않는다.
     *
     * @scenario surface=describe
     *
     * @effects describe_unmet_human_readable
     *
     * @return void 반환값 없음
     */
    public function test_describe_unmet_joins_human_readable_reasons(): void
    {
        app()->setLocale('ko');

        $text = DependencyEnricher::describeUnmet([
            ['identifier' => 'sirsoft-board', 'name' => '게시판', 'status' => 'version_mismatch', 'required_version' => '>=1.3.0', 'installed_version' => '1.2.2'],
            ['identifier' => 'sirsoft-gdpr', 'name' => 'GDPR', 'status' => 'inactive', 'required_version' => '>=1.0.0', 'installed_version' => '1.1.2'],
            ['identifier' => 'sirsoft-x', 'name' => 'sirsoft-x', 'status' => 'not_installed', 'required_version' => '*', 'installed_version' => null],
            ['identifier' => 'sirsoft-ok', 'name' => 'OK', 'status' => 'met', 'required_version' => '*', 'installed_version' => '1.0.0'],
        ]);

        $this->assertSame('게시판(sirsoft-board) 버전 미달 1.2.2 → >=1.3.0, GDPR(sirsoft-gdpr) 꺼짐, sirsoft-x 미설치', $text);
        $this->assertStringNotContainsString('version_mismatch', $text);
    }
}
