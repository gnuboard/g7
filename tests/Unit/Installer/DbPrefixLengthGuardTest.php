<?php

namespace Tests\Unit\Installer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * 인스톨러 DB 접두사 길이 가드 회귀 테스트
 *
 * DB prefix 가 길면 자동 생성 인덱스명이 MySQL identifier 한도(64자)를 초과해
 * 일부 확장(sirsoft-ecommerce 등) 설치 시 마이그레이션이 실패한다.
 * 코어 인스톨러가 prefix 길이를 MAX_DB_PREFIX_LENGTH 로 제한하는지 보장한다.
 *
 * 검증 축:
 *  - 상수 MAX_DB_PREFIX_LENGTH 가 6 으로 정의됨 (자동명 최대 58자 + 6 = 64)
 *  - 경계: 6자 이하 통과 / 7자 이상 거부
 *  - 가장 긴 자동 생성 인덱스명(58자)이 허용 최대 prefix 와 합쳐 64자를 넘지 않음
 *
 * 이 클래스는 config.php 를 require 해 `BASE_PATH` 를 **실 저장소 루트**로 정의한다 —
 * 본문에 define 이 없어도 결과는 같다. 격리 없이 돌면 같은 프로세스의 다른 인스톨러
 * 테스트가 R7 안전가드(temp 하위가 아니면 skip)에 걸려 통째로 건너뛰어지고, 종료 코드는
 * 0 이라 초록으로 보인다.
 */
#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
class DbPrefixLengthGuardTest extends TestCase
{
    /**
     * 인스톨러 설정 파일을 한 번 읽어 접두사 길이 상한 상수를 이 프로세스에 정의한다.
     *
     * @return void 반환값 없음
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3).'/public/install/includes/config.php';
    }

    /**
     * 접두사 길이 상한 상수가 정의돼 있고 그 값이 6 인지 확인한다.
     *
     * @scenario db_prefix_length=at_limit
     *
     * @effects installer_db_prefix_length_limit_is_six
     *
     * @return void 반환값 없음
     */
    public function test_max_db_prefix_length_constant_is_defined_as_six(): void
    {
        $this->assertTrue(defined('MAX_DB_PREFIX_LENGTH'), 'MAX_DB_PREFIX_LENGTH 상수가 정의되지 않았습니다.');
        $this->assertSame(6, MAX_DB_PREFIX_LENGTH);
    }

    /**
     * 인스톨러 서버 검증과 동일한 판정식: strlen($prefix) > MAX_DB_PREFIX_LENGTH 면 거부.
     *
     * 6자 이하는 통과하고 7자 이상은 거부되는 경계를 공급자 사례마다 확인한다.
     *
     * @scenario db_prefix_length=within_limit, db_prefix_length=at_limit, db_prefix_length=over_limit
     *
     * @effects installer_db_prefix_over_limit_is_rejected
     *
     * @param  string  $prefix  판정할 DB 접두사
     * @param  bool  $shouldPass  통과해야 하면 true, 거부돼야 하면 false
     * @return void 반환값 없음
     */
    #[DataProvider('prefixProvider')]
    public function test_prefix_length_boundary(string $prefix, bool $shouldPass): void
    {
        $isRejected = strlen($prefix) > MAX_DB_PREFIX_LENGTH;

        $this->assertSame(
            $shouldPass,
            ! $isRejected,
            sprintf('prefix "%s" (%d자) 판정이 기대와 다릅니다.', $prefix, strlen($prefix))
        );
    }

    /**
     * 기본값, 경계 6자와 7자, 빈 문자열, 명백히 긴 값의 접두사와 기대 판정을 공급한다.
     *
     * @return array<string, array{0: string, 1: bool}> 사례 이름별 [접두사, 통과 여부]
     */
    public static function prefixProvider(): array
    {
        return [
            'default g7_ (3자)' => ['g7_', true],
            'g72_ (4자) — 이슈 재현 prefix' => ['g72_', true],
            '5자' => ['shop_', true],
            '경계 6자 통과' => ['mycomp', true],
            '경계 7자 거부' => ['mycomp_', false],
            '명백히 긴 10자 거부' => ['mycompany_', false],
            '빈 문자열 통과' => ['', true],
        ];
    }

    /**
     * 허용 최대 prefix(6자) 와 가장 긴 자동 생성 인덱스명(58자)의 합이
     * MySQL identifier 한도(64자) 이내임을 보장한다.
     *
     * @scenario db_prefix_length=at_limit
     *
     * @effects installer_db_prefix_plus_longest_index_fits_identifier_limit
     *
     * @return void 반환값 없음
     */
    public function test_max_prefix_plus_longest_auto_index_name_fits_identifier_limit(): void
    {
        $longestAutoIndexBaseLength = 58; // 작업1 이후 전역 자동 생성 인덱스명 최대치
        $mysqlIdentifierLimit = 64;

        $this->assertLessThanOrEqual(
            $mysqlIdentifierLimit,
            MAX_DB_PREFIX_LENGTH + $longestAutoIndexBaseLength,
            '허용 최대 prefix + 최장 자동 인덱스명이 MySQL 한도를 초과합니다.'
        );
    }
}
