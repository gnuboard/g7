<?php

namespace App\Support\SampleData;

/**
 * FakerFactoryShim — Faker\Factory 대체 구현
 *
 * fakerphp/faker 패키지가 설치되지 않은 환경에서 \Faker\Factory::create($locale) 호출을 대체합니다.
 * Laravel 의 fake() 헬퍼(framework helpers.php)가 \Faker\Factory::create() 를 내부 호출하므로,
 * 이 Shim 을 class_alias(FakerFactoryShim::class, \Faker\Factory::class) 로 등록해두면
 * Laravel fake() 도 자동으로 FakerShim 을 반환하게 됩니다.
 *
 * 등록 위치: app/Support/SampleData/bootstrap.php (composer autoload.files 진입점)
 */
class FakerFactoryShim
{
    /**
     * Faker\Factory::create() 호환 시그니처
     *
     * @return FakerShim FakerShim 인스턴스 (class_alias 로 \Faker\Generator 타입과 호환)
     */
    public static function create(string $locale = 'ko_KR'): FakerShim
    {
        return new FakerShim($locale);
    }
}
