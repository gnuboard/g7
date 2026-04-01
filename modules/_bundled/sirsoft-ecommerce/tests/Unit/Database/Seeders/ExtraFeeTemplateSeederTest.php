<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ExtraFeeTemplateSeeder;
use Modules\Sirsoft\Ecommerce\Models\ExtraFeeTemplate;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 도서산간 추가배송비 템플릿 시더 테스트
 *
 * ExtraFeeTemplateSeeder가 36건의 도서산간 우편번호 데이터를 올바르게 생성하는지 검증
 */
class ExtraFeeTemplateSeederTest extends ModuleTestCase
{
    /**
     * 시더 실행
     *
     * @return void
     */
    protected function runSeeder(): void
    {
        $this->seed(ExtraFeeTemplateSeeder::class);
    }

    // ========================================
    // 기본 생성 테스트
    // ========================================

    /**
     * 시더 실행 시 36건 생성 확인
     */
    public function test_seeder_creates_36_templates(): void
    {
        $this->runSeeder();

        $this->assertEquals(36, ExtraFeeTemplate::count());
    }

    /**
     * 중복 실행 시 기존 데이터 삭제 후 재생성 (멱등성)
     */
    public function test_seeder_is_idempotent(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertEquals(36, ExtraFeeTemplate::count());
    }

    // ========================================
    // 데이터 무결성 테스트
    // ========================================

    /**
     * 모든 레코드의 fee가 3000인지 확인
     */
    public function test_all_templates_have_fee_3000(): void
    {
        $this->runSeeder();

        $templates = ExtraFeeTemplate::all();

        foreach ($templates as $template) {
            $this->assertEquals(
                3000,
                (float) $template->fee,
                "템플릿 #{$template->id} ({$template->zipcode})의 fee가 3000이어야 합니다."
            );
        }
    }

    /**
     * 모든 레코드의 is_active가 true인지 확인
     */
    public function test_all_templates_are_active(): void
    {
        $this->runSeeder();

        $templates = ExtraFeeTemplate::all();

        foreach ($templates as $template) {
            $this->assertTrue(
                $template->is_active,
                "템플릿 #{$template->id} ({$template->zipcode})의 is_active가 true여야 합니다."
            );
        }
    }

    /**
     * 모든 레코드에 region이 있는지 확인
     */
    public function test_all_templates_have_region(): void
    {
        $this->runSeeder();

        $templates = ExtraFeeTemplate::all();

        foreach ($templates as $template) {
            $this->assertNotEmpty(
                $template->region,
                "템플릿 #{$template->id} ({$template->zipcode})에 region이 있어야 합니다."
            );
        }
    }

    /**
     * 모든 레코드의 description이 '도서산간 지역'인지 확인
     */
    public function test_all_templates_have_correct_description(): void
    {
        $this->runSeeder();

        $templates = ExtraFeeTemplate::all();

        foreach ($templates as $template) {
            $this->assertEquals(
                '도서산간 지역',
                $template->description,
                "템플릿 #{$template->id} ({$template->zipcode})의 description이 '도서산간 지역'이어야 합니다."
            );
        }
    }

    // ========================================
    // 범위 형식 우편번호 테스트
    // ========================================

    /**
     * 범위 형식 우편번호(11자 이상)가 올바르게 저장되는지 확인
     */
    public function test_range_format_zipcodes_stored_correctly(): void
    {
        $this->runSeeder();

        // 11자 이상 범위 형식 우편번호 확인
        $rangeTemplates = ExtraFeeTemplate::whereRaw('LENGTH(zipcode) > 10')->get();

        $this->assertGreaterThan(
            0,
            $rangeTemplates->count(),
            '11자 이상의 범위 형식 우편번호가 존재해야 합니다.'
        );

        // 구체적인 범위 형식 확인
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '23100-23116',
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '58843-58866',
        ]);
    }

    /**
     * 단일 우편번호와 범위 형식 우편번호가 모두 포함되어 있는지 확인
     */
    public function test_both_single_and_range_zipcodes_exist(): void
    {
        $this->runSeeder();

        // 단일 우편번호 (하이픈 없음)
        $singleZipcodes = ExtraFeeTemplate::where('zipcode', 'not like', '%-%')->count();
        $this->assertGreaterThan(0, $singleZipcodes, '단일 우편번호가 존재해야 합니다.');

        // 범위 우편번호 (하이픈 포함)
        $rangeZipcodes = ExtraFeeTemplate::where('zipcode', 'like', '%-%')->count();
        $this->assertGreaterThan(0, $rangeZipcodes, '범위 형식 우편번호가 존재해야 합니다.');
    }

    // ========================================
    // 주요 도서산간 지역 포함 확인
    // ========================================

    /**
     * 제주도(제주시, 서귀포시) 우편번호가 포함되어 있는지 확인
     */
    public function test_jeju_zipcodes_are_included(): void
    {
        $this->runSeeder();

        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '63000-63365',
            'region' => '제주 제주시',
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_extra_fee_templates', [
            'zipcode' => '63500-63644',
            'region' => '제주 서귀포시',
        ]);
    }

    // ========================================
    // 중복 우편번호 없음 확인
    // ========================================

    /**
     * 모든 우편번호가 고유한지 확인
     */
    public function test_all_zipcodes_are_unique(): void
    {
        $this->runSeeder();

        $totalCount = ExtraFeeTemplate::count();
        $uniqueCount = ExtraFeeTemplate::distinct('zipcode')->count('zipcode');

        $this->assertEquals($totalCount, $uniqueCount, '모든 우편번호가 고유해야 합니다.');
    }
}
