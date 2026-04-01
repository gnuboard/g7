<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_0_2_1;

/**
 * v0.2.1 업그레이드 스텝 테스트
 *
 * 도서산간 추가배송비 템플릿 시딩 및 캐시 클리어를 검증합니다.
 */
class Upgrade_0_2_1_Test extends ModuleTestCase
{
    private Upgrade_0_2_1 $upgradeStep;

    private UpgradeContext $context;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->upgradeStep = new Upgrade_0_2_1;
        $this->context = new UpgradeContext(
            fromVersion: '0.2.0',
            toVersion: '0.2.1',
            currentStep: '0.2.1'
        );
    }

    // ========================================
    // 기본 시딩 테스트
    // ========================================

    /**
     * 업그레이드 실행 시 34건 생성 확인
     */
    public function test_upgrade_creates_34_templates(): void
    {
        $this->upgradeStep->run($this->context);

        $this->assertEquals(34, ExtraFeeTemplate::count());
    }

    /**
     * 중복 실행 시 데이터 중복 없음 (firstOrCreate 멱등성)
     */
    public function test_upgrade_is_idempotent(): void
    {
        $this->upgradeStep->run($this->context);
        $this->upgradeStep->run($this->context);

        $this->assertEquals(34, ExtraFeeTemplate::count());
    }

    // ========================================
    // 기존 데이터 보존 테스트
    // ========================================

    /**
     * 기존 사용자 데이터가 덮어쓰기되지 않는지 확인
     */
    public function test_upgrade_preserves_existing_user_data(): void
    {
        // 사용자가 직접 등록한 템플릿 (동일 zipcode, 다른 fee)
        ExtraFeeTemplate::create([
            'zipcode' => '15654',
            'fee' => 5000,
            'region' => '사용자 지정 지역',
            'description' => '사용자 지정 설명',
            'is_active' => false,
        ]);

        $this->upgradeStep->run($this->context);

        // 기존 데이터가 보존되어야 함
        $template = ExtraFeeTemplate::where('zipcode', '15654')->first();
        $this->assertEquals(5000, (float) $template->fee);
        $this->assertEquals('사용자 지정 지역', $template->region);
        $this->assertEquals('사용자 지정 설명', $template->description);
        $this->assertFalse($template->is_active);

        // 나머지 33건은 정상 생성
        $this->assertEquals(34, ExtraFeeTemplate::count());
    }

    /**
     * 기존 데이터와 새 데이터가 함께 공존하는지 확인
     */
    public function test_upgrade_adds_missing_templates_alongside_existing(): void
    {
        // 사용자가 이미 일부 템플릿을 등록한 상태
        ExtraFeeTemplate::create([
            'zipcode' => '15654',
            'fee' => 3000,
            'region' => '경기 안산 풍도동',
            'description' => '도서산간 지역',
            'is_active' => true,
        ]);
        ExtraFeeTemplate::create([
            'zipcode' => '40200-40240',
            'fee' => 3000,
            'region' => '경북 울릉도',
            'description' => '도서산간 지역',
            'is_active' => true,
        ]);

        $this->upgradeStep->run($this->context);

        // 기존 2건 + 새 32건 = 34건
        $this->assertEquals(34, ExtraFeeTemplate::count());
    }

    // ========================================
    // 데이터 무결성 테스트
    // ========================================

    /**
     * 모든 새 레코드의 기본값이 올바른지 확인
     */
    public function test_upgrade_sets_correct_defaults(): void
    {
        $this->upgradeStep->run($this->context);

        $templates = ExtraFeeTemplate::all();

        foreach ($templates as $template) {
            $this->assertEquals(3000, (float) $template->fee, "zipcode {$template->zipcode}: fee=3000");
            $this->assertTrue($template->is_active, "zipcode {$template->zipcode}: is_active=true");
            $this->assertNotEmpty($template->region, "zipcode {$template->zipcode}: region 비어있지 않음");
            $this->assertEquals('도서산간 지역', $template->description, "zipcode {$template->zipcode}: description");
        }
    }

    /**
     * 범위 형식 우편번호가 올바르게 저장되는지 확인
     */
    public function test_upgrade_stores_range_format_zipcodes(): void
    {
        $this->upgradeStep->run($this->context);

        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '23100-23116',
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '58843-58866',
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '59781-59790',
        ]);
    }

    // ========================================
    // 캐시 클리어 테스트
    // ========================================

    /**
     * 업그레이드 실행 시 템플릿 캐시 클리어 호출 확인
     */
    public function test_upgrade_clears_template_cache(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('template:cache-clear');

        $this->upgradeStep->run($this->context);
    }

    // ========================================
    // 테이블 미존재 시 안전 처리 테스트
    // ========================================

    /**
     * 테이블이 없을 때 에러 없이 건너뛰는지 확인
     */
    public function test_upgrade_skips_gracefully_when_table_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('ecommerce_shipping_policy_extra_fee_templates')
            ->once()
            ->andReturn(false);

        Artisan::shouldReceive('call')
            ->once()
            ->with('template:cache-clear');

        // 예외 없이 실행되어야 함
        $this->upgradeStep->run($this->context);

        // 데이터 생성되지 않음 (Schema 파사드 모킹으로 인해 실제 DB 접근 안 함)
        $this->assertEquals(0, ExtraFeeTemplate::count());
    }
}
