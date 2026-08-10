<?php

namespace App\Support;

/**
 * OPcache 활성화 상태 판정 (인스톨러·코어 공용 SSoT).
 *
 * 인스톨러(`public/install/`)는 Laravel 오토로드 없이 동작하는 순수 PHP 이므로
 * 이 클래스는 파사드·헬퍼 등 프레임워크 의존성을 일절 참조하지 않는다.
 * 인스톨러는 `require_once` 로 이 파일을 직접 로드해 사용한다.
 *
 * 확장 로드 여부만으로는 부족하다 — `Zend OPcache` 가 로드돼 있어도
 * `opcache.enable=0` 이면 실제로는 동작하지 않는다.
 */
final class OpcacheStatus
{
    /** OPcache PHP 확장명 */
    public const EXTENSION_NAME = 'Zend OPcache';

    /** OPcache 활성화 여부를 담은 php.ini 지시자 */
    public const ENABLE_DIRECTIVE = 'opcache.enable';

    /**
     * OPcache 상태를 조회합니다.
     *
     * `enabled` 가 null 이면 "확인 불가" 를 뜻합니다 — `ini_get` 이
     * `disable_functions` 로 차단된 환경에서 발생하며, 경고도 차단도 하지 않습니다.
     *
     * 두 콜백 파라미터는 테스트에서 환경을 주입하기 위한 것으로,
     * 운영 코드는 인수 없이 호출합니다.
     *
     * @param  callable|null  $extensionLoaded  확장 로드 여부 판정 콜백 (기본: extension_loaded)
     * @param  callable|null  $iniGet  php.ini 지시자 조회 콜백 (기본: ini_get)
     * @return array{loaded: bool, enabled: bool|null} 확장 로드 여부와 활성화 여부
     */
    public static function probe(?callable $extensionLoaded = null, ?callable $iniGet = null): array
    {
        $extensionLoaded ??= static fn (string $name): bool => extension_loaded($name);
        $iniGet ??= static fn (string $key) => ini_get($key);

        if (! $extensionLoaded(self::EXTENSION_NAME)) {
            return ['loaded' => false, 'enabled' => false];
        }

        try {
            $raw = $iniGet(self::ENABLE_DIRECTIVE);
        } catch (\Throwable) {
            // ini_get 이 disable_functions 로 차단된 환경 — 확인 불가
            return ['loaded' => true, 'enabled' => null];
        }

        // ini_get 은 지시자를 못 읽으면 false 를 반환한다 (값 '0' 과 구분해야 함)
        if ($raw === false || $raw === null || $raw === '') {
            return ['loaded' => true, 'enabled' => null];
        }

        return ['loaded' => true, 'enabled' => filter_var($raw, FILTER_VALIDATE_BOOLEAN)];
    }
}
