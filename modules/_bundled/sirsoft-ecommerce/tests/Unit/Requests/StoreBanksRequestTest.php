<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use Illuminate\Support\Facades\Validator;
use Modules\Sirsoft\Ecommerce\Http\Requests\Admin\StoreBanksRequest;
use Tests\TestCase;

/**
 * 은행 목록 저장 요청 검증 테스트
 *
 * - banks 배열 검증
 * - banks.*.code 필수/문자열/최대길이
 * - banks.*.name 필수/다국어 배열
 * - 현재 로케일 은행명만 필수
 * - 빈 배열 허용
 */
class StoreBanksRequestTest extends TestCase
{
    /**
     * 검증 수행
     *
     * @param array $data 검증 대상 데이터
     * @return \Illuminate\Validation\Validator
     */
    protected function validate(array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreBanksRequest();

        return Validator::make($data, $request->rules());
    }

    // ──────────────────────────────────────────────
    // banks 배열 검증
    // ──────────────────────────────────────────────

    public function test_banks_accepts_valid_data(): void
    {
        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']],
                ['code' => '088', 'name' => ['ko' => '신한은행', 'en' => 'Shinhan Bank']],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '유효한 은행 데이터를 허용해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    public function test_banks_accepts_empty_array(): void
    {
        $validator = $this->validate([
            'banks' => [],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '빈 배열을 허용해야 합니다'
        );
    }

    public function test_banks_accepts_null(): void
    {
        $validator = $this->validate([
            'banks' => null,
        ]);

        $this->assertTrue(
            $validator->passes(),
            'null 값을 허용해야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // banks.*.code 검증
    // ──────────────────────────────────────────────

    public function test_bank_code_required_when_banks_present(): void
    {
        $validator = $this->validate([
            'banks' => [
                ['name' => ['ko' => '국민은행', 'en' => 'Kookmin Bank']],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.code'),
            '은행코드는 필수 항목이어야 합니다'
        );
    }

    public function test_bank_code_max_length(): void
    {
        $validator = $this->validate([
            'banks' => [
                ['code' => 'VERY_LONG_CODE', 'name' => ['ko' => '테스트', 'en' => 'Test']],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.code'),
            '은행코드는 최대 10자까지 허용해야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // banks.*.name 검증
    // ──────────────────────────────────────────────

    public function test_bank_name_required_when_banks_present(): void
    {
        $validator = $this->validate([
            'banks' => [
                ['code' => '004'],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.name'),
            '은행명은 필수 항목이어야 합니다'
        );
    }

    public function test_bank_name_must_be_array(): void
    {
        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => '국민은행'],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.name'),
            '은행명은 다국어 배열 형식이어야 합니다'
        );
    }

    public function test_bank_name_locale_max_length(): void
    {
        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => ['ko' => str_repeat('가', 101)]],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.name.ko'),
            '은행명 로케일 값은 최대 100자까지 허용해야 합니다'
        );
    }

    // ──────────────────────────────────────────────
    // 현재 로케일 기준 은행명 필수 검증
    // ──────────────────────────────────────────────

    public function test_bank_name_current_locale_only_is_valid(): void
    {
        // 기본 로케일(ko)만 입력해도 통과해야 함
        app()->setLocale('ko');

        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => ['ko' => '국민은행']],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '현재 로케일(ko)만 입력해도 유효해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    public function test_bank_name_other_locale_null_is_valid(): void
    {
        // 현재 로케일(ko) 입력, 다른 로케일은 null (ConvertEmptyStringsToNull 시뮬레이션)
        app()->setLocale('ko');

        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => ['ko' => '국민은행', 'en' => null]],
            ],
        ]);

        $this->assertTrue(
            $validator->passes(),
            '다른 로케일이 null이어도 유효해야 합니다. 오류: '.$validator->errors()->toJson()
        );
    }

    public function test_bank_name_current_locale_missing_fails(): void
    {
        // 현재 로케일(ko)이 없고 다른 로케일만 있으면 실패해야 함
        app()->setLocale('ko');

        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => ['en' => 'Kookmin Bank']],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.name.ko'),
            '현재 로케일(ko) 은행명이 없으면 실패해야 합니다'
        );
    }

    public function test_bank_name_en_locale_required_when_locale_is_en(): void
    {
        // 로케일이 en일 때는 영문 은행명이 필수
        app()->setLocale('en');

        $validator = $this->validate([
            'banks' => [
                ['code' => '004', 'name' => ['ko' => '국민은행']],
            ],
        ]);

        $this->assertTrue(
            $validator->errors()->has('banks.0.name.en'),
            '로케일이 en일 때 영문 은행명이 없으면 실패해야 합니다'
        );
    }
}
