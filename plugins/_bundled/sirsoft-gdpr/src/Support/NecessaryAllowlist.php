<?php

namespace Plugins\Sirsoft\Gdpr\Support;

use Plugins\Sirsoft\Gdpr\Plugin;

/**
 * strictly necessary 허용목록 해석기
 *
 * 허용목록은 운영자 설정(`necessary_storage_allowlist`)이고, 잠금 항목은 코드가 정한다.
 * 판정은 언제나 **운영자 목록 ∪ 잠금 집합**이며, 이 클래스가 그 합집합과 매칭 규칙의
 * 단일 출처다 — 서버(미들웨어)와 클라이언트(인터셉터 3종)가 같은 규칙을 쓰지 않으면
 * 한쪽에서만 살아 있는 항목이 생기고, 그 어긋남은 예외도 로그도 남기지 않는다.
 *
 * 클라이언트 측 대응 구현은 `resources/js/necessaryAllowlist.ts` 이며 두 구현의 일치는
 * `resources/js/__tests__/necessaryAllowlistCoverage.test.ts` 가 대조한다.
 */
class NecessaryAllowlist
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * 허용목록 스코프 (저장소 두 종류 + 쿠키)
     *
     * @var array<int, string>
     */
    public const SCOPES = ['localStorage', 'sessionStorage', 'cookie'];

    /**
     * 잠금 항목 — 운영자가 지울 수 없는 최소 집합.
     *
     * 정의는 진입 파일(`Plugin::lockedNecessaryStorage()`)이 소유한다. `getConfigValues()` 가
     * 신규 설치 흐름에서 이 클래스의 오토로드 등록보다 먼저 호출되기 때문이다. 여기서는
     * 그 정의에 위임만 한다.
     *
     * @return array<string, array<int, string>> 스코프 => 항목 배열
     */
    public static function locked(): array
    {
        return Plugin::lockedNecessaryStorage();
    }

    /**
     * 한 스코프의 판정 목록을 반환합니다 (운영자 설정 ∪ 잠금 집합).
     *
     * 설정 조회는 config 미러라 요청당 비용이 낮습니다. 조회가 비어도 잠금 집합은
     * 코드에서 합치므로 남습니다.
     *
     * @param  string  $scope  스코프 (localStorage / sessionStorage / cookie)
     * @return array<int, string> 허용 패턴 배열
     */
    public static function forScope(string $scope): array
    {
        $operator = g7_plugin_settings(self::PLUGIN_ID, 'necessary_storage_allowlist.'.$scope, []);

        if (! is_array($operator)) {
            $operator = [];
        }

        $operator = array_values(array_filter(
            $operator,
            fn ($pattern) => is_string($pattern) && $pattern !== '',
        ));

        $locked = self::locked()[$scope] ?? [];

        return array_values(array_unique(array_merge($operator, $locked)));
    }

    /**
     * 한 패턴이 이름에 매칭되는지 판정합니다.
     *
     * 끝에 `*` 가 붙으면 앞부분 매칭, 없으면 정확 일치. 접두사가 빈 `*` 단독 표기는
     * 전체 개방이 되므로 매칭하지 않습니다 (검증에서도 거릅니다).
     *
     * @param  string  $name  검사할 키 또는 쿠키 이름
     * @param  string  $pattern  운영자 표기 패턴
     * @return bool 매칭 여부
     */
    public static function matchesPattern(string $name, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = substr($pattern, 0, -1);

            return $prefix !== '' && str_starts_with($name, $prefix);
        }

        return $pattern === $name;
    }

    /**
     * 이름이 해당 스코프의 허용목록에 있는지 판정합니다.
     *
     * @param  string  $name  검사할 키 또는 쿠키 이름
     * @param  string  $scope  스코프
     * @return bool 허용 여부
     */
    public static function matches(string $name, string $scope): bool
    {
        foreach (self::forScope($scope) as $pattern) {
            if (self::matchesPattern($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 출하 기본 카탈로그를 반환합니다 (신규 설치 시드 + 관리자 화면 추천 목록).
     *
     * @return array<string, array<int, string>> 스코프 => 항목 배열
     */
    public static function catalog(): array
    {
        return Plugin::DEFAULT_NECESSARY_ALLOWLIST_CATALOG;
    }
}
