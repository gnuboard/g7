<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * editor-spec.json 합본 헬퍼.
 *
 * editor-spec.json 이 과대해져(admin 18k 줄) 상위 블록 단위로 분할되었다. 분할
 * 형식은 manifest(`editor-spec.json`)에 메타 + 소형 블록을 인라인으로 두고, 대형
 * 블록은 `$include` 맵으로 `editor-spec/{block}.json` 을 참조하는 구조다.
 *
 * 서빙 4개 사이트는 활성 디렉토리만 기준으로 합본한다 — `_bundled` 폴백은 없다
 * `_bundled` 작업분은 `{module,plugin,template}:update` 로 활성 디렉토리에
 * 반영된 뒤에만 런타임에 보인다. 다음 형태의 manifest 를 받는다:
 *
 *   {
 *     "templateId": "...", "version": "...", "darkMode": { ... },
 *     "$include": {
 *       "componentPalette": "editor-spec/componentPalette.json",
 *       "controls": "editor-spec/controls.json"
 *     }
 *   }
 *
 * 본 헬퍼는 manifest 의 `$include` 를 manifest 디렉토리 기준으로 해석해 단일
 * 병합 spec(top-level merge)으로 합본한다. 런타임 API 응답은 분할 전 단일 파일과
 * 동일한 형태(`array<string,mixed>`)를 유지하므로 프론트엔드 로더는 무영향이다.
 *
 * `$include` 가 없는 구버전/미분할 editor-spec.json 은 manifest 원본을 그대로
 * 반환한다(하위 호환). include 파일 미존재/파싱 실패 시 해당 키만 누락하고 경고를
 * 남긴다(무손실 디그레이드).
 *
 */
class EditorSpecAssembler
{
    /**
     * manifest 파일을 디코드하고 `$include` 블록을 합본한 단일 spec 을 반환합니다.
     *
     * `$include` 부재 시 manifest 원본을 그대로 반환합니다(하위 호환). include 경로는
     * manifest 가 위치한 디렉토리 기준 상대 경로로 해석합니다.
     *
     * @param  string  $manifestPath  editor-spec.json manifest 의 절대 경로
     * @return array<string, mixed>|null 합본 spec, 디코드 실패 시 null
     */
    public static function assemble(string $manifestPath): ?array
    {
        $manifest = self::decodeJsonFile($manifestPath);
        if ($manifest === null) {
            return null;
        }

        $includes = $manifest['$include'] ?? null;
        unset($manifest['$include']);

        if (! is_array($includes) || $includes === []) {
            // 미분할(구버전) editor-spec.json — manifest 원본 그대로 반환.
            return $manifest;
        }

        $baseDir = \dirname($manifestPath);

        foreach ($includes as $key => $relative) {
            if (! is_string($key) || ! is_string($relative) || $relative === '') {
                continue;
            }

            $blockPath = $baseDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $block = self::decodeJsonFile($blockPath);

            if ($block === null) {
                // include 파일 미존재/파싱 실패 — 해당 키 누락(무손실 디그레이드) + 경고.
                Log::warning('EditorSpecAssembler: include 블록 로드 실패', [
                    'manifest' => $manifestPath,
                    'block' => $key,
                    'path' => $blockPath,
                ]);

                continue;
            }

            // top-level merge — include 결과가 해당 key 의 값이 된다.
            // manifest 인라인 값이 있으면 include 가 덮어쓴다(분할본이 정본).
            $manifest[$key] = $block;
        }

        return $manifest;
    }

    /**
     * JSON 파일을 읽어 배열로 디코드합니다. 미존재/비배열/파싱 실패 시 null.
     *
     * @param  string  $path  절대 경로
     * @return array<string, mixed>|null 디코드된 배열, 실패 시 null
     */
    private static function decodeJsonFile(string $path): ?array
    {
        if (! file_exists($path) || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
