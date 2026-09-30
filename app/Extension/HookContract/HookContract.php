<?php

namespace App\Extension\HookContract;

/**
 * 공개 훅 계약 목록 접근자.
 *
 * - `app/Extension/HookContract/data/catalog.php`   : 스캐너가 만든 발화 목록 (`php artisan hooks:catalog` 로 재생성, 손으로 고치지 않는다)
 * - `app/Extension/HookContract/data/dynamic.php`   : 이름을 조립하는 동적 발화처의 이름 패턴 (손으로 관리)
 * - `app/Extension/HookContract/data/deprecated.php`: 사용 중단된 훅과 대체 이름 (손으로 관리)
 *
 * 등록 시점 가드와 스냅샷 테스트가 이 목록을 기준으로 판정한다. 파일은 프로세스당 한 번만 읽는다.
 */
class HookContract
{
    /**
     * 데이터 디렉토리.
     */
    public const DATA_DIR = __DIR__.DIRECTORY_SEPARATOR.'data';

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $catalog = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private static ?array $deprecated = null;

    /**
     * @var array<int, string>|null
     */
    private static ?array $dynamicPatterns = null;

    /**
     * @var array<string, true>|null
     */
    private static ?array $owners = null;

    /**
     * 발화 목록을 반환합니다 (훅 이름 → type·args·owner·replacement?).
     *
     * @return array<string, array<string, mixed>> 발화 목록
     */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $file = self::DATA_DIR.DIRECTORY_SEPARATOR.'catalog.php';
            $data = is_file($file) ? require $file : [];
            self::$catalog = is_array($data['hooks'] ?? null) ? $data['hooks'] : [];
        }

        return self::$catalog;
    }

    /**
     * 사용 중단 목록을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 이름 → replacement·since·published·note
     */
    public static function deprecated(): array
    {
        if (self::$deprecated === null) {
            $file = self::DATA_DIR.DIRECTORY_SEPARATOR.'deprecated.php';
            self::$deprecated = is_file($file) ? require $file : [];
        }

        return self::$deprecated;
    }

    /**
     * 동적 발화처의 이름 패턴 목록을 반환합니다.
     *
     * @return array<int, string> `*` 가 들어간 패턴 목록
     */
    public static function dynamicPatterns(): array
    {
        if (self::$dynamicPatterns === null) {
            $file = self::DATA_DIR.DIRECTORY_SEPARATOR.'dynamic.php';
            $sites = is_file($file) ? require $file : [];
            $patterns = [];
            foreach ($sites as $site) {
                foreach ($site['patterns'] ?? [] as $pattern) {
                    $patterns[] = $pattern;
                }
            }
            self::$dynamicPatterns = array_values(array_unique($patterns));
        }

        return self::$dynamicPatterns;
    }

    /**
     * 구독한 훅 이름에 문제가 있으면 그 내용을, 없으면 null 을 반환합니다.
     *
     * 코어와 번들 확장이 소유한 이름공간(첫 마디가 `core` 또는 번들 확장 식별자)만 발화처 유무를 판정한다 —
     * 서드파티 확장의 자기 훅은 이 목록이 알 수 없으므로 판정하지 않는다.
     *
     * @param  string  $hook  구독한 훅 이름
     * @return array{kind: string, replacement: string|null, note: string}|null 문제 (deprecated|unpublished)
     */
    public static function subscriptionProblem(string $hook): ?array
    {
        $deprecated = self::deprecated()[$hook] ?? null;
        if ($deprecated !== null) {
            return [
                'kind' => 'deprecated',
                'replacement' => $deprecated['replacement'] ?? null,
                'note' => (string) ($deprecated['note'] ?? ''),
            ];
        }

        $catalog = self::catalog();
        if (isset($catalog[$hook])) {
            $replacement = $catalog[$hook]['replacement'] ?? null;

            return $replacement === null ? null : [
                'kind' => 'deprecated',
                'replacement' => $replacement,
                'note' => '호환용 옛 이름 — 표준 이름과 함께 발화 중',
            ];
        }

        $namespace = strstr($hook, '.', true);
        if ($namespace === false || ! isset(self::owners()[$namespace])) {
            return null;
        }

        foreach (self::dynamicPatterns() as $pattern) {
            if (self::matches($pattern, $hook)) {
                return null;
            }
        }

        return ['kind' => 'unpublished', 'replacement' => null, 'note' => '코어·번들 확장에 이 이름의 발화처가 없다'];
    }

    /**
     * 목록을 다시 읽도록 캐시를 비웁니다 (테스트용).
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$catalog = null;
        self::$deprecated = null;
        self::$dynamicPatterns = null;
        self::$owners = null;
    }

    /**
     * 발화처 판정 대상 이름공간(코어 + 번들 확장 식별자) 집합.
     *
     * @return array<string, true> 이름공간 → true
     */
    private static function owners(): array
    {
        if (self::$owners === null) {
            self::$owners = ['core' => true];
            foreach (self::catalog() as $name => $entry) {
                $owner = (string) ($entry['owner'] ?? '');
                if ($owner !== '' && $owner !== 'core' && str_starts_with($name, $owner.'.')) {
                    self::$owners[$owner] = true;
                }
            }
        }

        return self::$owners;
    }

    /**
     * `*` 패턴과 이름이 맞는지 확인합니다 (`*` 는 점을 포함한 아무 문자열).
     *
     * @param  string  $pattern  패턴
     * @param  string  $name  훅 이름
     * @return bool 일치 여부
     */
    private static function matches(string $pattern, string $name): bool
    {
        $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $name);
    }
}
