<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_2_0\Migrations\RequireEcommerce130;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use Plugins\Sirsoft\PayKginicis\Upgrades\Upgrade_1_2_0;
use RuntimeException;

/**
 * 이커머스 1.3.0 미만에서 이 버전으로의 갱신을 멈추는 가드 (PU-2).
 *
 * 이 버전의 결제 콜백·환불 경로는 이커머스 1.3.0 의 새 클래스를 주입받는데, 플러그인 갱신
 * 경로는 manifest 의 의존 버전을 다시 검사하지 않는다. 이커머스 갱신이 거절·실패한 채 이
 * 플러그인만 올라가면 결제 화면이 500 으로 끝난다. 업그레이드 스텝 맨 앞의 가드가 예외를
 * 던지면 갱신 경로가 파일·버전 칸을 되돌린다.
 *
 * 이 테스트가 고정하는 것 — 부족한 버전에서 예외 · 충분한 버전·미설치에서 통과 · 가드가
 * 같은 스텝의 다른 마이그레이션보다 먼저 돈다(구 overlay 행이 지워지기 전에 멈춘다).
 * 가드 파일을 지우거나 비교를 뒤집으면 red 가 된다.
 */
#[Group('payment')]
#[Group('upgrade')]
class RequireEcommerce130Test extends PluginTestCase
{
    /**
     * 이 플러그인 식별자.
     */
    private const SELF = 'sirsoft-pay_kginicis';

    /**
     * 가드가 있는 스텝 버전.
     */
    private const STEP = '1.2.0';

    /**
     * 가드 마이그레이션을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 일반 autoload 대상이 아니다.
        require_once base_path('plugins/_bundled/'.self::SELF.'/upgrades/data/'.self::STEP.'/migrations/00_RequireEcommerce130.php');
    }

    /**
     * 이커머스가 1.3.0 미만이면 예외를 던진다.
     *
     * @scenario ecommerce=below_min
     *
     * @effects update_is_stopped_when_ecommerce_is_below_1_3_0
     *
     * @return void 반환값 없음
     */
    public function test_이커머스가_1_3_0_미만이면_멈춘다(): void
    {
        $this->setEcommerceVersion('1.2.1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('1.3.0');

        (new RequireEcommerce130)->run($this->context());
    }

    /**
     * 이커머스가 1.3.0 이상이면 통과한다.
     *
     * @scenario ecommerce=at_or_above_min
     *
     * @effects update_proceeds_when_ecommerce_is_1_3_0_or_later
     *
     * @return void 반환값 없음
     */
    public function test_이커머스가_1_3_0_이상이면_통과한다(): void
    {
        foreach (['1.3.0', '1.3.1', '2.0.0'] as $version) {
            $this->setEcommerceVersion($version);

            (new RequireEcommerce130)->run($this->context());
        }

        $this->assertSame('2.0.0', DB::table('modules')->where('identifier', 'sirsoft-ecommerce')->value('version'));
    }

    /**
     * 이커머스가 설치돼 있지 않으면 막지 않는다.
     *
     * @scenario ecommerce=not_installed
     *
     * @effects update_is_not_blocked_without_ecommerce
     *
     * @return void 반환값 없음
     */
    public function test_이커머스가_없으면_막지_않는다(): void
    {
        DB::table('modules')->where('identifier', 'sirsoft-ecommerce')->delete();

        (new RequireEcommerce130)->run($this->context());

        $this->assertSame(0, DB::table('modules')->where('identifier', 'sirsoft-ecommerce')->count());
    }

    /**
     * 스텝 전체를 돌리면 가드가 다른 마이그레이션보다 먼저 멈춘다 — 구 overlay 행이 남는다.
     *
     * @scenario ecommerce=below_min_full_step
     *
     * @effects guard_runs_before_other_migrations_of_the_step
     *
     * @return void 반환값 없음
     */
    public function test_스텝_전체에서_가드가_먼저_돈다(): void
    {
        $this->setEcommerceVersion('1.2.1');
        $legacyId = $this->seedLegacyOverlayRow();

        try {
            (new Upgrade_1_2_0)->run($this->context());
            $this->fail('이커머스 1.2.1 에서 스텝이 예외 없이 끝났다 — 가드가 없다');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1.3.0', $e->getMessage());
        }

        $this->assertSame(1, DB::table('template_layout_extensions')->where('id', $legacyId)->count(), '가드보다 다른 마이그레이션이 먼저 돌았다 — 파일명 정렬 순서를 확인하라');
    }

    /**
     * 이커머스 모듈 행의 버전을 맞춥니다(없으면 만듭니다).
     *
     * @param  string  $version  설치 버전
     * @return void 반환값 없음
     */
    private function setEcommerceVersion(string $version): void
    {
        DB::table('modules')->updateOrInsert(
            ['identifier' => 'sirsoft-ecommerce'],
            [
                'vendor' => 'sirsoft',
                'name' => json_encode(['ko' => '이커머스', 'en' => 'Ecommerce']),
                'version' => $version,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * 같은 스텝이 지우는 구 overlay 등록 행을 하나 심고 그 ID 를 돌려줍니다.
     *
     * @return int 삽입된 행 ID
     */
    private function seedLegacyOverlayRow(): int
    {
        $templateId = DB::table('templates')->value('id') ?? DB::table('templates')->insertGetId([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => json_encode(['ko' => 'basic', 'en' => 'basic']),
            'version' => '1.0.0',
            'type' => 'user',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('template_layout_extensions')->insertGetId([
            'template_id' => (int) $templateId,
            'extension_type' => 'overlay',
            'target_name' => 'mypage/orders/show',
            'source_type' => 'plugin',
            'source_identifier' => self::SELF,
            'content' => '{}',
            'priority' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * 공개판에서 이 버전으로 올리는 업그레이드 컨텍스트를 만듭니다.
     *
     * @return UpgradeContext 업그레이드 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '1.1.3',
            toVersion: self::STEP,
            currentStep: self::STEP,
        );
    }
}
