<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 관련 테이블 인덱스명 길이 회귀
 *
 * DB prefix 가 기본값 g7_(3자)보다 긴 경우, 자동 생성 인덱스명이 MySQL
 * identifier 한도(64자)를 초과해 module:install 마이그레이션이 실패하던 결함.
 *
 * 근본 수정: 긴 테이블명을 가진 단일 컬럼 인덱스/유니크에 짧은 명시 이름 부여.
 *
 * 본 테스트는 prefix 길이와 무관하게, 인덱스명 base(접두사 제외)가 충분히 짧아
 * 허용 최대 prefix(MAX_DB_PREFIX_LENGTH=6) 에서도 64자를 넘지 않음을 단언한다.
 */
class ShippingPolicyIndexNameLengthTest extends ModuleTestCase
{
    /** MySQL identifier 최대 길이. */
    private const MYSQL_MAX_IDENTIFIER = 64;

    /** 코어 인스톨러가 허용하는 DB prefix 최대 길이 (MAX_DB_PREFIX_LENGTH). */
    private const MAX_DB_PREFIX_LENGTH = 6;

    /**
     * 직접 지목한 테이블 + 동일 근본원인 테이블의 모든 인덱스명 base
     * (접두사 제외) 길이가 (64 - 6) = 58자 이하여야 한다.
     *
     * 현재 환경 prefix 가 무엇이든, 실제 식별자에서 prefix 를 제거한 base 길이로
     * 판정하므로 prefix 6자까지 안전함을 보장한다.
     */
    public function test_shipping_policy_index_names_fit_within_max_prefix(): void
    {
        $tables = [
            'ecommerce_shipping_policy_extra_fee_templates',
            'ecommerce_shipping_policy_country_settings',
        ];

        $prefix = DB::getTablePrefix();
        $maxBaseLength = self::MYSQL_MAX_IDENTIFIER - self::MAX_DB_PREFIX_LENGTH;

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} 테이블이 존재하지 않습니다.");

            $names = collect(Schema::getIndexes($table))
                ->pluck('name')
                ->reject(fn ($name) => strtoupper((string) $name) === 'PRIMARY')
                ->all();

            $this->assertNotEmpty($names, "{$table} 인덱스가 introspection 되지 않았습니다.");

            foreach ($names as $name) {
                // Schema::getIndexes() 는 prefix 포함 실제 식별자를 반환하므로 base 만 추출.
                $base = $prefix !== '' && str_starts_with((string) $name, $prefix)
                    ? substr((string) $name, strlen($prefix))
                    : (string) $name;

                $this->assertLessThanOrEqual(
                    $maxBaseLength,
                    strlen($base),
                    sprintf(
                        '인덱스 base "%s" (%d자) 가 한도 %d자를 초과 — prefix %d자 환경에서 64자 초과로 설치 실패함.',
                        $base,
                        strlen($base),
                        $maxBaseLength,
                        self::MAX_DB_PREFIX_LENGTH
                    )
                );
            }
        }
    }

    /**
     * 명시화한 짧은 인덱스 이름이 실제로 적용되었는지 확인 (명시화 회귀 가드).
     */
    public function test_explicit_short_index_names_are_applied(): void
    {
        $prefix = DB::getTablePrefix();

        $expected = [
            'ecommerce_shipping_policy_extra_fee_templates' => [
                'idx_extra_fee_tpl_is_active',
                'uniq_extra_fee_tpl_zipcode',
            ],
            'ecommerce_shipping_policy_country_settings' => [
                'idx_cs_country_code',
            ],
        ];

        foreach ($expected as $table => $expectedBases) {
            $names = collect(Schema::getIndexes($table))->pluck('name')->all();
            $bases = array_map(
                fn ($name) => $prefix !== '' && str_starts_with((string) $name, $prefix)
                    ? substr((string) $name, strlen($prefix))
                    : (string) $name,
                $names
            );

            foreach ($expectedBases as $expectedBase) {
                $this->assertContains(
                    $expectedBase,
                    $bases,
                    "{$table} 에 명시 인덱스명 '{$expectedBase}' 가 적용되지 않았습니다."
                );
            }
        }
    }
}
