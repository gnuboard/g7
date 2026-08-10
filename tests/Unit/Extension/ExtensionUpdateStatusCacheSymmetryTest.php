<?php

namespace Tests\Unit\Extension;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * 확장 업데이트의 상태 복원 ↔ 캐시 무효화 대칭성 테스트
 *
 * 업데이트는 시작 시 상태를 `updating` 으로 바꾼다. 그 창에서 누군가 활성 확장 목록을 읽으면
 * 그 확장이 빠진 목록이 캐시(기본 TTL 하루)에 남는다. 성공 경로는 마지막에 캐시를 비우지만,
 * 실패 경로가 상태만 되돌리고 캐시를 비우지 않으면 확장은 정상인데 그 관리자 화면만
 * 하루 동안 404 가 된다 — 운영자가 원인을 짐작하기 어렵고 스스로 회복되지도 않는다.
 *
 * 상태를 되돌리는 지점마다 캐시 무효화가 따라붙는지 구조로 고정한다.
 */
class ExtensionUpdateStatusCacheSymmetryTest extends TestCase
{
    /**
     * 상태 복원 횟수만큼 캐시 무효화가 존재하는지 검증합니다.
     *
     * @param  string  $relativePath  검사 대상 매니저 파일 경로
     * @param  string  $invalidateCall  해당 매니저의 캐시 무효화 호출 문자열
     */
    #[Test]
    #[TestWith(['app/Extension/ModuleManager.php', 'updateModule', 'invalidateModuleStatusCache()'])]
    #[TestWith(['app/Extension/PluginManager.php', 'updatePlugin', 'invalidatePluginStatusCache()'])]
    public function every_status_restore_is_followed_by_cache_invalidation(string $relativePath, string $method, string $invalidateCall): void
    {
        $body = $this->updateMethodSource(base_path($relativePath), $method);

        $restores = preg_match_all("/'status'\s*=>\s*\\\$previousStatus/", $body);
        $invalidations = substr_count($body, $invalidateCall);

        $this->assertGreaterThan(0, $restores, "{$relativePath} 에서 상태 복원 지점을 찾지 못했습니다 — 검사가 무력화됐습니다.");

        $this->assertGreaterThanOrEqual(
            $restores,
            $invalidations,
            "{$relativePath}: 상태 복원 {$restores}회에 비해 캐시 무효화가 {$invalidations}회뿐입니다 — "
            .'복원했는데 비우지 않은 경로가 있으면 그 확장 화면이 캐시 만료까지 404 로 남습니다.'
        );
    }

    /**
     * 업데이트 메서드 본문을 주석 제거 후 반환합니다.
     *
     * 주석을 남겨 두면 설명 문장에 들어 있는 호출 표기가 검사를 통과시킨다.
     *
     * @param  string  $path  대상 파일 절대 경로
     * @param  string  $method  대상 메서드명 (updateModule / updatePlugin)
     * @return string 주석이 제거된 메서드 본문
     */
    private function updateMethodSource(string $path, string $method): string
    {
        $this->assertFileExists($path);

        $stripped = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $start = strpos($stripped, "public function {$method}(");
        $this->assertNotFalse($start, "{$path} 에서 {$method}() 메서드를 찾지 못했습니다.");

        // 중괄호 균형으로 메서드 본문 끝을 찾는다
        $open = strpos($stripped, '{', $start);
        $depth = 0;
        $length = strlen($stripped);

        for ($i = $open; $i < $length; $i++) {
            if ($stripped[$i] === '{') {
                $depth++;
            } elseif ($stripped[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($stripped, $open, $i - $open + 1);
                }
            }
        }

        $this->fail("{$path} 의 update() 본문 끝을 찾지 못했습니다.");
    }
}
