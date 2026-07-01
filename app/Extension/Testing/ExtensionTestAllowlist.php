<?php

namespace App\Extension\Testing;

/**
 * 테스트 환경 확장 로딩 allowlist
 *
 * PHPUnit 환경에서 테스트 클래스가 명시한 확장만 ServiceProvider / route /
 * hook listener 등록 대상으로 허용합니다. allowlist 밖 확장은 테스트 앱에
 * 영향을 주지 않습니다 (예: GDPR 플러그인의 전역 미들웨어).
 *
 * provider register() 는 앱 부팅 단계라 테스트 인스턴스 메서드보다 먼저
 * 실행되므로, 인스턴스 프로퍼티 대신 static 컨텍스트로 allowlist 를 공유합니다.
 * 테스트 클래스의 setUp() 최상단(앱 생성 전)에서 set() 을 호출합니다.
 *
 * isActive() 가 false 인 환경(비-testing, 또는 allowlist 미설정)에서는
 * 가드가 동작하지 않으므로 운영/개발 환경의 확장 로딩은 영향을 받지 않습니다.
 */
class ExtensionTestAllowlist
{
    /**
     * 허용된 플러그인 디렉토리명 목록 (예: 'sirsoft-gdpr')
     *
     * @var array<string>
     */
    private static array $plugins = [];

    /**
     * 허용된 모듈 디렉토리명 목록 (예: 'sirsoft-ecommerce')
     *
     * @var array<string>
     */
    private static array $modules = [];

    /**
     * allowlist 가 명시적으로 설정되었는지 여부
     *
     * 빈 배열로 set() 된 경우(= core-only 테스트)와
     * 한 번도 set() 되지 않은 경우(= 비-테스트 부팅)를 구분합니다.
     */
    private static bool $configured = false;

    /**
     * allowlist 를 설정합니다.
     *
     * requiredExtensions 형식의 상대 경로 문자열을 받아
     * 'plugins/' / 'modules/' 프리픽스로 분류하고 디렉토리명만 추출합니다.
     *
     * @param  array<string>  $extensions  'plugins/sirsoft-gdpr' 형식의 확장 경로 배열
     */
    public static function set(array $extensions): void
    {
        self::$plugins = [];
        self::$modules = [];
        self::$configured = true;

        foreach ($extensions as $extension) {
            $extension = trim($extension, '/');

            if (str_starts_with($extension, 'plugins/')) {
                self::$plugins[] = basename($extension);
            } elseif (str_starts_with($extension, 'modules/')) {
                self::$modules[] = basename($extension);
            }
        }
    }

    /**
     * allowlist 를 초기화합니다.
     *
     * 테스트 종료 시 호출하여 프로세스 내 테스트 클래스 간 누수를 방지합니다.
     */
    public static function reset(): void
    {
        self::$plugins = [];
        self::$modules = [];
        self::$configured = false;
    }

    /**
     * 가드 활성 여부를 반환합니다.
     *
     * testing 환경이면서 allowlist 가 명시적으로 설정된 경우에만 true.
     * false 이면 provider 는 기존과 동일하게 전수 등록합니다.
     *
     * @return bool 가드 활성 여부
     */
    public static function isActive(): bool
    {
        return self::$configured && app()->environment('testing');
    }

    /**
     * 해당 확장이 allowlist 에 포함되어 있는지 반환합니다.
     *
     * @param  string  $type  확장 유형 ('plugin' | 'module')
     * @param  string  $name  확장 디렉토리명 (예: 'sirsoft-gdpr')
     * @return bool allowlist 포함 여부
     */
    public static function isAllowed(string $type, string $name): bool
    {
        return match ($type) {
            'plugin' => in_array($name, self::$plugins, true),
            'module' => in_array($name, self::$modules, true),
            default => false,
        };
    }
}
