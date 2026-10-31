<?php

namespace App\Analytics;

use Illuminate\Support\Facades\Log;

/**
 * 판정표 — 코어 기본값에 확장 기여를 더한 결과
 *
 * 검색엔진·캠페인 파라미터·추적 파라미터·브라우저/OS 판정 패턴은 코어가 기본값을 갖고,
 * 확장이 필터로 항목을 보탭니다(국내 포털·인앱 브라우저·새 광고 매체처럼 코어가 미리 알 수
 * 없는 것). 판정 코드는 설정을 직접 읽지 않고 이 클래스를 거칩니다 — 필터를 한곳에서 걸어야
 * 경로마다 다른 표를 보는 일이 없습니다.
 *
 * 필터가 형식을 깨뜨린 항목을 돌려주면 그 항목만 버립니다. 판정표 하나가 깨졌다고 수집이
 * 멈추면 안 됩니다.
 */
final class AnalyticsTables
{
    /**
     * 판정표의 이름이 들어가는 원장 열과 그 폭(글자 수)
     *
     * 브라우저·OS 이름은 `browser_name`·`os_name`, 검색엔진 이름은 `referrer_name` 에 그대로
     * 저장됩니다. 확장이 더 긴 이름을 넣으면 방문 저장이 DB 오류로 실패해 그 브라우저·검색엔진의
     * 방문이 전부 사라지므로, 열 폭으로 잘라 넣습니다. 폭은 마이그레이션과 같아야 합니다.
     *
     * @var array<string, int>
     */
    public const NAME_COLUMNS = [
        'browser_name' => 50,
        'os_name' => 50,
        'referrer_name' => 100,
    ];

    /**
     * 검색엔진 표를 반환합니다.
     *
     * 필터 `core.analytics.search_engines` — 인자: 코어 표. 항목 형식은
     * `['name' => 표시 이름, 'hosts' => [호스트 끝부분…], 'params' => [검색어 파라미터…]]`.
     *
     * @return array<int, array{name: string, hosts: array<int, string>, params: array<int, string>}> 검색엔진 표
     */
    public static function searchEngines(): array
    {
        $engines = ExtensionFilter::apply(
            'core.analytics.search_engines',
            (array) config('analytics.search_engines', []),
        );

        $valid = [];

        foreach (is_array($engines) ? $engines : [] as $engine) {
            if (! is_array($engine) || ! is_array($engine['hosts'] ?? null)) {
                continue;
            }

            $hosts = array_values(array_filter($engine['hosts'], fn ($host) => is_string($host) && $host !== ''));

            if ($hosts === []) {
                continue;
            }

            $valid[] = [
                'name' => self::fitName(
                    is_string($engine['name'] ?? null) && $engine['name'] !== '' ? $engine['name'] : $hosts[0],
                    'referrer_name',
                    'core.analytics.search_engines',
                ),
                'hosts' => $hosts,
                'params' => array_values(array_filter((array) ($engine['params'] ?? []), 'is_string')),
            ];
        }

        return $valid;
    }

    /**
     * 캠페인 파라미터 이름을 반환합니다.
     *
     * 필터 `core.analytics.campaign_params` — 인자: `['name' => …, 'source' => …, 'medium' => …]`.
     * 매체가 쓰는 다른 이름(예: 자체 광고 시스템의 `cmp`)으로 바꿀 수 있습니다.
     *
     * @return array{name: string|null, source: string|null, medium: string|null} 역할 → 파라미터 이름
     */
    public static function campaignParams(): array
    {
        $params = ExtensionFilter::apply(
            'core.analytics.campaign_params',
            (array) config('analytics.campaign_params', []),
        );

        $params = is_array($params) ? $params : [];
        $pick = fn (string $role): ?string => is_string($params[$role] ?? null) && $params[$role] !== '' ? $params[$role] : null;

        return [
            'name' => $pick('name'),
            'source' => $pick('source'),
            'medium' => $pick('medium'),
        ];
    }

    /**
     * URL 정규화 때 지울 추적 파라미터 목록을 반환합니다.
     *
     * 필터 `core.analytics.stripped_query_params` — 인자: 코어 목록. 페이지를 가르지 않는
     * 파라미터(광고 클릭 ID 등)를 더하면 같은 페이지가 클릭마다 다른 주소로 세어지지 않습니다.
     *
     * @return array<int, string> 파라미터 이름 목록
     */
    public static function strippedQueryParams(): array
    {
        $params = ExtensionFilter::apply(
            'core.analytics.stripped_query_params',
            (array) config('analytics.stripped_query_params', []),
        );

        return array_values(array_unique(array_filter(
            is_array($params) ? $params : [],
            fn ($param) => is_string($param) && $param !== '',
        )));
    }

    /**
     * 브라우저·OS 판정 패턴을 반환합니다.
     *
     * 필터 `core.analytics.ua_rules` — 인자: `['browsers' => [[찾을 문자열, 이름]…], 'os' => [[…]…],
     * 'hint_brands' => [힌트 브랜드 => 이름]]`. 판정은 **앞에서부터** 처음 맞는 항목이므로,
     * 인앱 브라우저처럼 다른 이름을 함께 싣는 UA 는 앞에 끼워 넣어야 잡힙니다.
     *
     * @param  array{browsers: array<int, array{0: string, 1: string}>, os: array<int, array{0: string, 1: string}>, hint_brands: array<string, string>}  $defaults  코어 기본 패턴
     * @return array{browsers: array<int, array{0: string, 1: string}>, os: array<int, array{0: string, 1: string}>, hint_brands: array<string, string>} 판정 패턴
     */
    public static function uaRules(array $defaults): array
    {
        $rules = ExtensionFilter::apply('core.analytics.ua_rules', $defaults);
        $rules = is_array($rules) ? $rules : $defaults;

        $pairs = function (mixed $list, array $fallback, string $column): array {
            if (! is_array($list)) {
                return $fallback;
            }

            $valid = array_values(array_filter(
                $list,
                fn ($pair) => is_array($pair)
                    && is_string($pair[0] ?? null) && $pair[0] !== ''
                    && is_string($pair[1] ?? null) && $pair[1] !== '',
            ));

            return array_map(
                fn (array $pair): array => [$pair[0], self::fitName($pair[1], $column, 'core.analytics.ua_rules')],
                $valid,
            );
        };

        $brands = [];
        foreach ((array) ($rules['hint_brands'] ?? $defaults['hint_brands']) as $brand => $name) {
            if (is_string($brand) && $brand !== '' && is_string($name) && $name !== '') {
                $brands[$brand] = self::fitName($name, 'browser_name', 'core.analytics.ua_rules');
            }
        }

        return [
            'browsers' => $pairs($rules['browsers'] ?? null, $defaults['browsers'], 'browser_name'),
            'os' => $pairs($rules['os'] ?? null, $defaults['os'], 'os_name'),
            'hint_brands' => $brands,
        ];
    }

    /**
     * 판정표 이름을 저장될 원장 열 폭에 맞춥니다.
     *
     * 넘치면 잘라 넣고 경고를 남깁니다 — 버리면 확장이 넣은 판정이 사라지고, 그대로 두면 방문
     * 저장이 실패합니다.
     *
     * @param  string  $name  판정표 이름
     * @param  string  $column  저장될 열 (`NAME_COLUMNS` 의 키)
     * @param  string  $hook  이름을 보탠 필터
     * @return string 열 폭 안의 이름
     */
    private static function fitName(string $name, string $column, string $hook): string
    {
        $width = self::NAME_COLUMNS[$column];

        if (mb_strlen($name) <= $width) {
            return $name;
        }

        Log::warning('방문자 통계 판정표 이름이 원장 열보다 길어 잘랐습니다.', [
            'hook' => $hook,
            'column' => $column,
            'name' => $name,
        ]);

        return mb_substr($name, 0, $width);
    }
}
