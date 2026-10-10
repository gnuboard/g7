<?php

namespace Tests\Unit\Rules;

use App\Rules\WhitelistedEndpoint;
use Tests\Support\ScansDocRoots;
use Tests\TestCase;

/**
 * 문서가 가르치는 레이아웃 엔드포인트가 실제 화이트리스트를 통과하는지 본다 (S20 4차 감사 신설).
 *
 * ## 왜 필요한가
 *
 * S20 이 문서의 엔드포인트 71자리를 모듈 네임스페이스 형태로 고치고 "전 문서 위반 0" 이라
 * 보고했는데, 판정에 쓴 도구가 **세션 스크래치패드의 근사 정규식**이었다. 저장소에 남지 않아
 * 재현할 수 없었고, 실제 Rule 로 다시 재면 레이아웃 계약 자리에 11줄이 남아 있었다.
 * 그중 한 줄은 S20 이 그 회차에 새로 만든 병기본이라 **위반을 재생산**한 것이었다.
 *
 * 근사 판정은 두 번 진다 — 처음엔 틀린 초록을 내고, 다음엔 재현 수단이 없어 반박도 못 한다.
 * 그래서 판정 주체를 **프로덕션 Rule 자신**으로 옮긴다. 화이트리스트는 라우트 표에서 도출되므로
 * (`WhitelistedEndpoint` 독블록), 라우트가 늘면 이 검사도 자동으로 따라온다.
 *
 * ## 모집단
 *
 * 문서의 JSON 펜스 안에서 `"endpoint"` / apiCall `"target"` 값으로 쓰인 `/api/...` 문자열.
 * 산문·PHP·TS 코드 예시의 URL 은 레이아웃 계약이 아니므로 대상이 아니다.
 *
 * 부정 예시(`❌`·"금지"·"잘못된" 등이 붙은 블록)는 일부러 위반을 싣는 자리이므로 제외한다 —
 * `docs/SECURITY.md` 의 공격 레이아웃 표본이 그 예다.
 */
class DocLayoutEndpointWhitelistTest extends TestCase
{
    use ScansDocRoots;

    /** 부정 예시 표지 — 이 표지가 붙은 블록은 일부러 위반을 싣는다. */
    private const NEGATIVE_MARKS = ['❌', '금지', '잘못된', '차단', '공격', 'attack', '거부'];

    /**
     * 문서의 레이아웃 엔드포인트가 전건 화이트리스트를 통과합니다.
     *
     * @scenario compile_unit=install,source_health=valid
     *
     * @effects doc_layout_endpoints_pass_production_whitelist_rule
     */
    public function test_문서_레이아웃_엔드포인트가_화이트리스트를_통과한다(): void
    {
        $rule = new WhitelistedEndpoint;
        $violations = [];
        $inspected = 0;

        foreach ($this->docFiles() as $file) {
            $lines = explode("\n", file_get_contents($file));

            foreach ($this->jsonFences($lines) as $fence) {
                $body = implode("\n", array_slice($lines, $fence['from'], $fence['to'] - $fence['from']));
                $lead = implode("\n", array_slice($lines, max(0, $fence['from'] - 5), 5));

                foreach (self::NEGATIVE_MARKS as $mark) {
                    if (str_contains($lead, $mark) || str_contains($body, $mark)) {
                        continue 2;
                    }
                }

                if (! preg_match_all('~"(?:endpoint|target)"\s*:\s*"(/api/[^"]*)"~', $body, $m)) {
                    continue;
                }

                foreach ($m[1] as $endpoint) {
                    $inspected++;
                    $failed = null;
                    $rule->validate('endpoint', $endpoint, function ($message) use (&$failed) {
                        $failed = $message;
                    });

                    if ($failed !== null) {
                        $rel = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
                        $violations[] = str_replace('\\', '/', $rel).' — '.$endpoint;
                    }
                }
            }
        }

        // "위반 0" 과 "검사 대상 0" 은 다른 명제다 — 추출식이 깨지면 이 단언이 먼저 red 다.
        $this->assertGreaterThan(
            50,
            $inspected,
            "검사한 엔드포인트가 {$inspected}건뿐 — 펜스/키 추출식이 어긋났는지 먼저 확인할 것"
        );

        $this->assertSame(
            [],
            $violations,
            "화이트리스트 밖 엔드포인트를 가르치는 문서:\n".implode("\n", $violations)
        );
    }

    /**
     * 검사 대상 문서 파일 목록을 돌려줍니다.
     *
     * @return array<int, string> 절대 경로
     */
    private function docFiles(): array
    {
        $files = [];

        foreach ($this->docRoots() as $dir) {
            $abs = base_path($dir);
            if (! is_dir($abs)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs));
            foreach ($it as $entry) {
                if (! $entry->isFile() || ! str_ends_with($entry->getFilename(), '.md')) {
                    continue;
                }
                // 벤더 산출물은 이 저장소가 작성하지 않는다.
                if (str_contains(str_replace('\\', '/', $entry->getPathname()), '/node_modules/')) {
                    continue;
                }
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    /**
     * 마크다운 줄 배열에서 JSON 코드 펜스의 본문 범위를 돌려줍니다.
     *
     * @param  array<int, string>  $lines
     * @return array<int, array{from: int, to: int}> 0-based 본문 시작/끝(배타)
     */
    private function jsonFences(array $lines): array
    {
        $fences = [];
        $open = null;

        foreach ($lines as $i => $line) {
            if (! preg_match('/^\s*(`{3,})\s*([A-Za-z0-9_.-]*)\s*$/', $line, $m)) {
                continue;
            }

            if ($open === null) {
                $open = ['lang' => strtolower($m[2]), 'from' => $i + 1];
            } elseif ($m[2] === '') {
                if (in_array($open['lang'], ['json', 'jsonc', 'json5'], true)) {
                    $fences[] = ['from' => $open['from'], 'to' => $i];
                }
                $open = null;
            }
        }

        return $fences;
    }
}
