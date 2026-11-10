<?php

namespace Plugins\Sirsoft\HeroSection\Support\Positions;

/**
 * 위치 출력 조각 — 히어로가 자기 자리(`home_hero`·`board_hero`)에 붙이는 조각과 같은 처리(공개 API 조회·로딩·빈 응답·실패·언어·
 * 선택 인증·수정 단추·표시 폭·본문 상자·메뉴 겹침·스켈레톤·대상 전환)를 다른 위치 키로 만든다.
 *
 * 내장 조각 파일이 원본이다 — 정적 위치는 `home_hero.json`, 동적 위치는 `board_hero.json` 을 읽어 데이터 소스 id·위치 키·
 * 대상 값 경로(`route.slug`)만 바꾼다. 그래서 내장 조각을 고치면 다른 확장이 더한 위치의 출력도 같이 바뀐다.
 * 동적 위치는 화면 주소에서 대상 값을 읽을 경로 매개변수(`target.route_param`)를 알아야 만든다.
 */
final class PositionFragment
{
    /**
     * 정적 위치 원본 조각과 그 안의 데이터 소스 id·위치 키
     */
    private const STATIC_SOURCE = ['file' => 'home_hero.json', 'id' => 'heroSectionHome', 'key' => 'home_hero'];

    /**
     * 동적 위치 원본 조각과 그 안의 데이터 소스 id·위치 키·대상 값 경로
     */
    private const DYNAMIC_SOURCE = ['file' => 'board_hero.json', 'id' => 'heroSectionBoard', 'key' => 'board_hero', 'route' => 'route?.slug'];

    /**
     * 만든 조각의 편집기 데이터 소스 이름 문구 키
     */
    public const LABEL_KEY = '$t:sirsoft-hero_section.editor.data_source.positionHero';

    /**
     * 위치의 출력 조각을 만듭니다.
     *
     * @param  PositionDefinition  $definition  위치 정의
     * @param  string|null  $sourceId  데이터 소스 id (기본 `heroSection_{위치 키}`)
     * @return array{components: array<int, array<string, mixed>>, data_sources: array<int, array<string, mixed>>}|null 조각 (동적 위치인데 경로 매개변수가 없으면 null)
     */
    public static function for(PositionDefinition $definition, ?string $sourceId = null): ?array
    {
        if ($definition->isDynamic() && $definition->routeParam === null) {
            return null;
        }

        $source = $definition->isDynamic() ? self::DYNAMIC_SOURCE : self::STATIC_SOURCE;
        $fragment = self::read($source['file']);
        if ($fragment === null) {
            return null;
        }

        $id = $sourceId ?? self::sourceId($definition->key);
        $replace = [$source['id'] => $id, $source['key'] => $definition->key];
        if ($definition->isDynamic()) {
            $replace[$source['route']] = 'route?.'.$definition->routeParam;
        }

        $components = self::replace($fragment['components'] ?? [], $replace);
        $dataSources = self::replace($fragment['data_sources'] ?? [], $replace);
        foreach ($dataSources as &$dataSource) {
            if ($id !== $source['id']) {
                $dataSource['label_key'] = self::LABEL_KEY;
            }
        }
        unset($dataSource);

        return ['components' => $components, 'data_sources' => $dataSources];
    }

    /**
     * 위치 키의 기본 데이터 소스 id 를 반환합니다 — 레이아웃 안에서 겹치지 않고 바인딩 식에 그대로 쓸 수 있는 이름.
     *
     * @param  string  $key  위치 키
     * @return string 데이터 소스 id
     */
    public static function sourceId(string $key): string
    {
        return 'heroSection_'.$key;
    }

    /**
     * 원본 조각 파일을 읽습니다.
     *
     * @param  string  $file  `resources/extensions` 안 파일 이름
     * @return array<string, mixed>|null 조각 (읽지 못하면 null)
     */
    private static function read(string $file): ?array
    {
        $path = dirname(__DIR__, 3).'/resources/extensions/'.$file;
        $json = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 노드 목록 안의 글자를 바꿉니다(키는 그대로, 문자열 값만).
     *
     * @param  array<int|string, mixed>  $nodes  노드 목록
     * @param  array<string, string>  $replace  바꿀 글자 => 새 글자
     * @return array<int|string, mixed> 바꾼 목록
     */
    private static function replace(array $nodes, array $replace): array
    {
        array_walk_recursive($nodes, function (&$value) use ($replace) {
            if (is_string($value)) {
                $value = strtr($value, $replace);
            }
        });

        return $nodes;
    }
}
