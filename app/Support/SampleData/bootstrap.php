<?php

/**
 * FakerShim Bootstrap
 *
 * composer autoload.files 진입점으로, vendor/autoload.php 로드 직후 실행됩니다.
 * laravel/framework 의 helpers.php (autoload.files) 보다 나중에 실행되므로,
 * Faker 미설치 환경에서 Laravel 이 놓친 fake() 정의를 대체하고
 * \Faker\Factory, \Faker\Generator 를 Shim 으로 alias 합니다.
 *
 * 감지 전략:
 *  - class_exists(..., false) 는 lazy-load 된 상태를 구분하지 못하므로 사용하지 않음
 *  - file_exists 로 vendor/fakerphp/faker 설치 여부 직접 확인 (OS 파일시스템 캐시 활용, 수십 ns)
 *
 * 등록 시점:
 *  - 매 요청당 1회 (PHP-FPM 워커 수명 동안 캐시되지는 않음 — autoload.files 는 매 요청 require)
 *  - 비용: file_exists ~100ns + class_alias ~500ns (Faker 부재 시) = 총 1μs 미만
 */

$fakerInstalled = file_exists(__DIR__.'/../../../vendor/fakerphp/faker/src/Faker/Factory.php');

if (! $fakerInstalled) {
    // Faker 미설치: Shim 을 실제 Faker 클래스명으로 alias
    if (! class_exists(\Faker\Factory::class, false)) {
        class_alias(\App\Support\SampleData\FakerFactoryShim::class, \Faker\Factory::class);
    }
    if (! class_exists(\Faker\Generator::class, false)) {
        class_alias(\App\Support\SampleData\FakerShim::class, \Faker\Generator::class);
    }
}

// Laravel 의 fake() 헬퍼는 framework/helpers.php 에서
// `class_exists(\Faker\Factory::class)` 가 true 일 때만 정의됨.
// Faker 미설치 시 Laravel 이 fake() 를 정의하지 못하므로 대체 구현 제공.
if (! function_exists('fake')) {
    /**
     * FakerShim 기반 fake() 대체 구현
     *
     * @param  string|null  $locale  로케일 (기본: config('app.faker_locale') 또는 'ko_KR')
     * @return \Faker\Generator (class_alias 로 App\Support\SampleData\FakerShim 과 동일)
     */
    function fake($locale = null)
    {
        if ($locale === null && function_exists('app') && app()->bound('config')) {
            $locale = app('config')->get('app.faker_locale', 'ko_KR');
        }

        return \Faker\Factory::create($locale ?? 'ko_KR');
    }
}
