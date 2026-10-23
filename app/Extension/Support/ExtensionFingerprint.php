<?php

namespace App\Extension\Support;

/**
 * 활성 확장의 「파일 지문」 — 파일만 교체했을 때 낡는 캐시(훅·확장 오토로드)를 알아채는 데 쓴다 (7.1.3).
 *
 * 확장마다 매니페스트(module.json·plugin.json, version 이 들어 있다)·진입 파일(module.php·plugin.php)·composer.json
 * 의 수정 시각을 묶는다. 캐시를 만들 때 지문을 함께 기록하고, 읽을 때 같은 파일들의 지금 수정 시각과 대조한다 —
 * 확장 하나당 파일 stat 3번이라 요청당 비용이 작다(파일 내용은 읽지 않는다).
 *
 * 매니페스트는 내용(version)을 파싱하지 않고 수정 시각으로 대신한다 — 버전을 올리면 매니페스트 파일이 바뀌므로
 * 같은 변화를 잡고, 요청마다 JSON 을 파싱하지 않는다.
 */
final class ExtensionFingerprint
{
    /**
     * 요청 안 메모 — 같은 지문 대조를 훅 캐시·오토로드 캐시가 각각 부르므로 한 번만 계산한다.
     *
     * @var array<string, array<string, string>>
     */
    private static array $memo = [];

    /**
     * 확장 목록의 지문을 계산합니다.
     *
     * @param  array<int, string>  $modules  모듈 식별자(디렉터리명) 목록
     * @param  array<int, string>  $plugins  플러그인 식별자(디렉터리명) 목록
     * @return array<string, string> `modules:{id}`·`plugins:{id}` => `매니페스트mtime:진입mtime:composermtime`
     */
    public static function compute(array $modules, array $plugins): array
    {
        $fingerprint = [];
        foreach (['modules' => [$modules, 'module'], 'plugins' => [$plugins, 'plugin']] as $type => [$identifiers, $entry]) {
            foreach ($identifiers as $identifier) {
                $fingerprint[$type.':'.$identifier] = self::stamp($type, (string) $identifier, $entry);
            }
        }
        ksort($fingerprint);

        return $fingerprint;
    }

    /**
     * 기록된 지문과 같은 확장들의 지금 지문을 계산합니다 (요청 안 메모).
     *
     * @param  array<string, string>  $stored  캐시에 기록된 지문
     * @return array<string, string> 같은 키의 지금 지문
     */
    public static function currentFor(array $stored): array
    {
        $memoKey = md5(implode('|', array_keys($stored)));
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        $current = [];
        foreach (array_keys($stored) as $key) {
            [$type, $identifier] = array_pad(explode(':', (string) $key, 2), 2, '');
            $entry = $type === 'plugins' ? 'plugin' : 'module';
            $current[$key] = self::stamp($type, $identifier, $entry);
        }

        return self::$memo[$memoKey] = $current;
    }

    /**
     * 기록된 지문이 지금 파일 상태와 같은지 확인합니다.
     *
     * @param  array<string, string>  $stored  캐시에 기록된 지문
     * @return bool 모든 확장의 지문이 같으면 true
     */
    public static function matches(array $stored): bool
    {
        return self::currentFor($stored) === $stored;
    }

    /**
     * 지문이 다른 확장 키 목록을 반환합니다 (로그용).
     *
     * @param  array<string, string>  $stored  캐시에 기록된 지문
     * @return array<int, string> 달라진 확장 키
     */
    public static function changedKeys(array $stored): array
    {
        $current = self::currentFor($stored);

        return array_values(array_keys(array_filter($stored, fn ($stamp, $key) => ($current[$key] ?? null) !== $stamp, ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * 요청 안 메모를 비웁니다 (캐시를 새로 쓴 뒤·시험).
     *
     * @return void 반환값 없음
     */
    public static function forget(): void
    {
        self::$memo = [];
    }

    /**
     * 확장 하나의 지문 조각을 만듭니다.
     *
     * @param  string  $type  `modules` | `plugins`
     * @param  string  $identifier  확장 디렉터리명
     * @param  string  $entry  진입 파일 이름 (`module` | `plugin`)
     * @return string `매니페스트mtime:진입mtime:composermtime` (파일이 없으면 0)
     */
    private static function stamp(string $type, string $identifier, string $entry): string
    {
        $dir = base_path($type.DIRECTORY_SEPARATOR.$identifier).DIRECTORY_SEPARATOR;

        return implode(':', [
            (int) @filemtime($dir.$entry.'.json'),
            (int) @filemtime($dir.$entry.'.php'),
            (int) @filemtime($dir.'composer.json'),
        ]);
    }
}
