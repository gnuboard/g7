<?php

namespace Tests\Feature\LayoutMarkup;

use App\Support\LayoutMarkup\EmptyMap;
use App\Support\LayoutMarkup\LayoutCorpus;
use App\Support\LayoutMarkup\LayoutJsonWriter;
use Tests\TestCase;

/**
 * 빈 map 이 빈 배열로 굳는 결함의 회귀 가드 (50차).
 *
 * ## 왜 필요한가
 *
 * PHP 배열은 list/map 구분이 없어 `json_encode([])` 가 `[]` 를 낸다. 저작자가 `{}`(이름표 붙은
 * 값 묶음)를 적어도 산출 JSON 은 `[]`(순서 목록)이 되고, 프론트는 JSON 을 그대로 받으므로 둘을
 * 구분한다. 그 값이 **서버 요청 본문**이나 setState 로 채워지는 자리면 에러 없이 조용히 틀린다.
 *
 * ## 무엇을 잠그고 무엇을 잠그지 않는가
 *
 * 잠그는 것은 **기계로 판정 가능한 두 축**뿐이다:
 *
 *  1. **직렬화 계약** — 빈 map 은 `{}`, 빈 list 는 `[]`.
 *  2. **서버로 가는 자리** — `params.query`(URL 질의)·`params.body`(요청 본문)는 이름표 붙은 값
 *     묶음이라는 것이 계약이므로 `[]` 로 서면 결함이다. 이 둘이 `[]` 가 되면 Laravel 측 검증
 *     규칙이 대상을 찾지 못해 422 또는 침묵 미적용이 된다.
 *
 * 잠그지 않는 것: 그 밖의 빈 `[]` 리터럴. 저작본만 보고 map/list 를 가릴 수는 없다(50차 착수
 * 실측 — 코퍼스 `:attr="[]"` 342자리 중 대부분은 정상 빈 목록이다). 원본이 `{}` 였는지는
 * merge-base 대조로만 알 수 있고 그것은 영구 게이트의 술어가 될 수 없다. 그 자리들은 50차가
 * 1회성으로 정정했고, 재발은 축 ①(직렬화 계약)이 원천에서 막는다.
 */
class MarkupEmptyMapSerializationTest extends TestCase
{
    /**
     * 서버로 실려 가는 자리 — 이름표 붙은 값 묶음이어야 한다.
     *
     * @var array<int, string>
     */
    private const SERVER_BOUND_MAP_KEYS = ['query', 'body'];

    /**
     * 축 ① 직렬화 계약 — 빈 map 은 `{}`, 빈 list 는 `[]`.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects empty_map_stays_object_and_empty_list_stays_array
     */
    public function test_empty_map_serializes_as_object_and_empty_list_as_array(): void
    {
        $encoded = LayoutJsonWriter::encode([
            'emptyMap' => EmptyMap::instance(),
            'emptyList' => [],
        ]);

        $this->assertStringContainsString('"emptyMap": {}', $encoded, '빈 map 이 {} 로 직렬화되지 않았습니다.');
        $this->assertStringContainsString('"emptyList": []', $encoded, '빈 list 가 [] 로 직렬화되지 않았습니다.');
    }

    /**
     * 축 ① 보강 — 빈 map 은 PHP 배열 안에 중첩돼 있어도 `{}` 로 나온다.
     */
    public function test_nested_empty_map_serializes_as_object(): void
    {
        $encoded = LayoutJsonWriter::encode([
            'actions' => [['handler' => 'apiCall', 'params' => ['body' => EmptyMap::instance()]]],
        ]);

        $this->assertStringContainsString('"body": {}', $encoded, '중첩된 빈 map 이 {} 로 직렬화되지 않았습니다.');
    }

    /**
     * 축 ② 서버 전송 자리 — 코퍼스 전량의 `params.query`·`params.body` 가 `[]` 로 서 있지 않아야 한다.
     */
    public function test_server_bound_map_keys_are_never_empty_arrays_in_corpus(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach (LayoutCorpus::scan('.json') as $file) {
            $scanned++;

            // **비-assoc 디코드**여야 한다 — `assoc: true` 로 읽으면 `{}` 와 `[]` 가 둘 다 빈 PHP
            // 배열이 되어 판정 대상이 사라진다(이 테스트가 잡으려는 구분이 바로 그것이다).
            $decoded = json_decode((string) file_get_contents($file), false);

            if (! is_object($decoded) && ! is_array($decoded)) {
                continue;
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            $this->collectEmptyServerBoundMaps($decoded, '', $relative, $offenders);
        }

        // **모집단 하한** (51차 — `AssertionStrengthGuardTest` 검출). 이 테스트는 모집단을
        // 디스크에서 도출하므로, 스캔이 0건이 되면 `$offenders` 도 비어 **조용히 통과**한다.
        // 그 상태는 "위반 없음" 이 아니라 "아무것도 검사하지 않음" 이고 출력은 둘을 구분하지 못한다.
        // 코퍼스 실측은 600건대이므로 500 을 하한으로 둔다(정확 건수를 박으면 코퍼스가 자랄 때마다 낡는다).
        $this->assertGreaterThan(500, $scanned,
            "코퍼스 `.json` 스캔이 {$scanned}건이다 — 도출식이 죽었는지 먼저 확인할 것 (0건이면 이 테스트는 아무것도 보지 않는다)");

        $this->assertSame([], $offenders, "서버로 가는 자리가 빈 배열로 서 있습니다 (이름표 붙은 값 묶음이어야 합니다):\n".implode("\n", $offenders));
    }

    /**
     * `params.query`·`params.body` 가 빈 배열인 자리를 재귀 수집합니다.
     *
     * 판정은 **원문 JSON 재직렬화**로 한다 — PHP 는 `{}` 와 `[]` 를 구분하지 못하므로
     * `json_decode` 결과만 보면 둘이 같아진다. 원문 바이트에서 그 자리가 `{}` 인지 확인해야 한다.
     *
     * @param  object|array<mixed>  $node  검사할 노드 (비-assoc 디코드 산출)
     * @param  string  $path  현재 경로
     * @param  string  $relative  파일 상대 경로
     * @param  array<int, string>  $offenders  누적 위반 목록 (참조)
     */
    private function collectEmptyServerBoundMaps(object|array $node, string $path, string $relative, array &$offenders): void
    {
        foreach ((array) $node as $key => $value) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;

            // 빈 **배열**이면서 서버 전송 키면 위반이다. `{}` 는 stdClass 로 디코드되므로 걸리지 않는다.
            if (is_array($value) && $value === []
                && in_array((string) $key, self::SERVER_BOUND_MAP_KEYS, true)
                && str_ends_with($path, 'params')) {
                $offenders[] = $relative.' @ '.$childPath;

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $this->collectEmptyServerBoundMaps($value, $childPath, $relative, $offenders);
            }
        }
    }
}
