<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/**
 * `validationError()` 메시지 키 자리 계약 (#138 후속 validate)
 *
 * 응답 첫 인자는 다국어 키 전용이다(docs/backend/exceptions.md 「응답에는 메시지 키를 넘긴다」). 하위 계층이 이미
 * 번역해 둔 실패 사유를 그대로 응답 문구로 쓰고 싶을 때도 키 자리가 아니라 치환 자리로 넘긴다 —
 * `validationError($e->errors(), 'common.failure_reason', ['reason' => $firstError])`. 확장 관리 컨트롤러 9곳이
 * `validationError($e->errors(), $firstError)` 로 문장을 키 자리에 넣고 있었다. 문장에 `::`·`.` 가 섞이면
 * 네임스페이스·그룹 키로 잘못 해석될 수 있다.
 *
 * 판정은 코어와 전 번들 모듈·플러그인 컨트롤러 소스 전수 스캔이며, 호출을 하나도 못 찾으면 실패한다.
 */
class ValidationErrorKeySlotContractTest extends TestCase
{
    /**
     * 코어와 번들 확장의 컨트롤러 디렉토리 목록을 모읍니다.
     *
     * @return array<int, string> 컨트롤러 루트 디렉토리 절대경로
     */
    private function controllerRoots(): array
    {
        $roots = [base_path('app/Http/Controllers')];

        // 확장마다 컨트롤러 위치 규약이 갈린다 — `src/Http/Controllers` 와 PG 플러그인의 `src/Controllers`.
        foreach (['modules', 'plugins'] as $kind) {
            $bundled = base_path($kind.'/_bundled');
            if (! is_dir($bundled)) {
                continue;
            }
            foreach (scandir($bundled) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                foreach (['src/Http/Controllers', 'src/Controllers'] as $convention) {
                    if (is_dir($bundled.'/'.$entry.'/'.$convention)) {
                        $roots[] = $bundled.'/'.$entry.'/'.$convention;
                    }
                }
            }
        }

        return $roots;
    }

    /**
     * 컨트롤러 PHP 파일을 모읍니다.
     *
     * @return array<int, array{path: string, rel: string}> 절대경로와 저장소 상대경로
     */
    private function collectFiles(): array
    {
        $files = [];
        $prefix = str_replace('\\', '/', base_path()).'/';

        foreach ($this->controllerRoots() as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                    $files[] = ['path' => $f->getPathname(), 'rel' => str_replace($prefix, '', str_replace('\\', '/', $f->getPathname()))];
                }
            }
        }

        return $files;
    }

    /**
     * `validationError()` 의 메시지 키 자리에 변수(이미 번역된 문장)를 넘기지 않는다.
     *
     * @scenario contract_check=validation_error_key_slot
     *
     * @effects validation_error_key_slot_is_never_a_variable_repository_wide
     *
     * @return void 반환값 없음
     */
    public function test_validation_error_key_slot_is_never_a_variable(): void
    {
        $files = $this->collectFiles();
        $this->assertNotEmpty($files, '컨트롤러 스캔 결과가 비어 있습니다 — 판정기가 대상을 못 찾고 있습니다.');

        $calls = 0;
        $violations = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file['path']);
            if (! preg_match_all('/->validationError\(\s*[^,()]+(?:\([^()]*\))?\s*,\s*(\S[^,)]*)/', $source, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[1] as [$keyArg, $offset]) {
                $calls++;
                if (str_starts_with(trim($keyArg), '$')) {
                    $violations[] = $file['rel'].':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertGreaterThan(0, $calls, '메시지 키를 넘기는 validationError 호출을 하나도 찾지 못했습니다 — 판정기가 아무것도 재지 않습니다.');
        $this->assertSame([], $violations, "validationError 의 메시지 키 자리에 변수가 넘어갑니다:\n".implode("\n", $violations));
    }
}
