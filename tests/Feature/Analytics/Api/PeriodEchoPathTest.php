<?php

namespace Tests\Feature\Analytics\Api;

/**
 * 통계 화면의 기간 달력이 읽는 「서버가 되돌려 준 구간」 경로 정합 테스트
 *
 * 프리셋은 주소에 날짜를 남기지 않으므로, 기간 바의 달력 두 칸·CSV 파일명은 화면이
 * `_computed.periodFrom`·`periodTo` 로 서버 응답의 구간을 읽어 채운다. 그 경로가 응답과
 * 어긋나면 식이 조용히 브라우저 날짜 계산값으로 떨어져, 보는 사람의 시간대가 사이트와
 * 다를 때 달력이 서버가 실제로 조회한 구간과 하루 어긋난다 — 개요 한 면이 그랬다
 * (`data.from` 을 읽는데 응답은 `data.meta.from`, #682 S3 라운드 14).
 *
 * 모집단은 손으로 적지 않는다 — 구운 관리자 레이아웃에서 그 식이 읽는 소스·경로를 뽑고,
 * 그 소스의 실제 API 를 불러 경로가 날짜로 풀리는지 잰다.
 */
class PeriodEchoPathTest extends AnalyticsApiTestCase
{
    /**
     * 관리자 템플릿 레이아웃 디렉터리
     */
    private const LAYOUT_DIR = 'templates/_bundled/sirsoft-admin_basic/layouts';

    /**
     * 레이아웃에서 기간 식이 읽는 (화면, 소스, 경로, 엔드포인트) 를 뽑습니다.
     *
     * @return array<int, array{layout: string, source: string, path: string, endpoint: string}> 대조 대상
     */
    private function echoReads(): array
    {
        $reads = [];

        foreach (glob(base_path(self::LAYOUT_DIR.'/admin_analytics_*.json')) as $file) {
            $layout = json_decode((string) file_get_contents($file), true);
            $computed = $layout['computed'] ?? [];
            $expr = ($computed['periodFrom'] ?? '').' '.($computed['periodTo'] ?? '');

            preg_match_all('/(\w+)\?\.data\?\.((?:\w+\?\.)*)(from|to)\b/', $expr, $matches, PREG_SET_ORDER);

            foreach ($matches as [, $source, $middle, $leaf]) {
                $endpoint = collect($layout['data_sources'] ?? [])->firstWhere('id', $source)['endpoint'] ?? null;
                $this->assertNotNull($endpoint, basename($file)." 의 기간 식이 없는 소스 {$source} 를 읽는다");

                $reads[] = [
                    'layout' => basename($file),
                    'source' => $source,
                    'path' => str_replace('?.', '.', $middle).$leaf,
                    'endpoint' => $this->resolveEndpoint($endpoint),
                ];
            }
        }

        return $reads;
    }

    /**
     * 식이 든 엔드포인트를 기본 탭의 리포트 이름으로 풉니다.
     *
     * `…/reports/{{({a: 'x', …})[query.tab || 'a'] || 'x'}}` 처럼 탭별 이름표 끝에 기본값을 둔다.
     *
     * @param  string  $endpoint  레이아웃의 엔드포인트
     * @return string 호출할 경로
     */
    private function resolveEndpoint(string $endpoint): string
    {
        return (string) preg_replace_callback('/\{\{.*\|\|\s*\'([^\']+)\'\s*\}\}$/', fn (array $m): string => $m[1], $endpoint);
    }

    /**
     * 기간 식이 읽는 경로마다 서버 응답에 날짜가 있는지 확인합니다.
     *
     * @return void 없음
     *
     * @scenario endpoint=overview, role=read, period=preset
     *
     * @effects period_echo_path_matches_every_screen_response
     */
    public function test_every_period_echo_path_resolves_to_a_date(): void
    {
        $reads = $this->echoReads();

        // 모집단이 비면 아무것도 재지 않은 채 초록이 된다 — 기간 바를 쓰는 화면은 아홉이다.
        $this->assertGreaterThanOrEqual(9, count(array_unique(array_column($reads, 'layout'))));

        $this->actingAs($this->adminWith(['core.analytics.read', 'core.analytics.privacy']));

        foreach ($reads as $read) {
            $response = $this->getJson($read['endpoint'].'?preset=7d');
            $response->assertOk();

            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) data_get($response->json('data'), $read['path']),
                "{$read['layout']} 는 {$read['source']}.data.{$read['path']} 를 읽지만 {$read['endpoint']} 응답의 그 자리에 날짜가 없다",
            );
        }
    }
}
