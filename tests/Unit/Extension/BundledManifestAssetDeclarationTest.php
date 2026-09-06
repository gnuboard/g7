<?php

namespace Tests\Unit\Extension;

use Tests\TestCase;

/**
 * 번들 확장 매니페스트의 자산 선언 계약 테스트
 *
 * 매니페스트가 선언한 산출물 경로는 **디스크에 실재해야 한다**. 어긋나면 증상이
 * 종류마다 다르고 전부 조용하다:
 *
 *  - 모듈/플러그인의 `assets.{js,css}` 가 배열형이면 어느 소비자도 읽지 못해
 *    그 확장의 스크립트·스타일이 영영 로드되지 않는다 (오류 없음).
 *  - 템플릿의 `assets.css` 가 없는 파일을 가리키면 봇 화면(SeoRenderer)이 404 를
 *    가리키는 `<link>` 를 싣는다 — 일반 화면에는 흔적이 없다.
 *
 * 0바이트 산출물은 **정당한 상태**다(스타일이 비어 있는 확장). 이 테스트는 존재만
 * 본다 — 크기를 보면 그 확장이 다시 배포 장애로 오판된다.
 *
 * 모집단은 glob 으로 파생한다. 손으로 적은 목록은 확장이 늘어날 때 조용히 낡는다.
 */
class BundledManifestAssetDeclarationTest extends TestCase
{
    /**
     * 모듈·플러그인 매니페스트의 `assets.{js,css}` 는 객체이며 산출물이 실재해야 한다.
     */
    public function test_module_and_plugin_asset_declarations_are_objects_pointing_to_existing_files(): void
    {
        $manifests = [];

        foreach ([['module', 'modules/_bundled'], ['plugin', 'plugins/_bundled']] as [$type, $root]) {
            foreach (glob(base_path($root).'/*/'.$type.'.json') as $manifestPath) {
                $manifests[] = [$manifestPath, dirname($manifestPath)];
            }
        }

        // 모집단 하한 가드 — glob 이 0건이면 이 테스트는 아무것도 잠그지 않는다
        $this->assertGreaterThanOrEqual(
            10,
            count($manifests),
            '번들 모듈/플러그인 매니페스트 모집단이 비었거나 지나치게 작다 — 경로 규약이 바뀐 것이 아닌지 확인하라'
        );

        $violations = [];

        foreach ($manifests as [$manifestPath, $extensionDir]) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?? [];
            $assets = $manifest['assets'] ?? null;

            if ($assets === null) {
                continue;
            }

            $this->assertIsArray($assets, $manifestPath.': assets 는 객체여야 한다');

            foreach (['js', 'css'] as $kind) {
                if (! array_key_exists($kind, $assets)) {
                    continue;
                }

                $declaration = $assets[$kind];
                $relative = base_path().DIRECTORY_SEPARATOR;
                $shortManifest = str_replace($relative, '', $manifestPath);

                if (! is_array($declaration) || ! isset($declaration['output']) || ! is_string($declaration['output']) || $declaration['output'] === '') {
                    $violations[] = sprintf(
                        '%s: assets.%s 는 { "output": "..." } 객체여야 한다 (현재: %s)',
                        $shortManifest,
                        $kind,
                        json_encode($declaration, JSON_UNESCAPED_UNICODE)
                    );

                    continue;
                }

                $artifact = $extensionDir.'/'.$declaration['output'];

                if (! is_file($artifact)) {
                    $violations[] = sprintf(
                        '%s: assets.%s.output 이 가리키는 산출물이 없다 — %s',
                        $shortManifest,
                        $kind,
                        $declaration['output']
                    );
                }
            }
        }

        $this->assertSame([], $violations, "번들 확장 매니페스트 자산 선언 위반:\n".implode("\n", $violations));
    }

    /**
     * 템플릿 매니페스트의 `assets` 경로도 실재해야 한다.
     *
     * `css`/`js` 는 파일, `fonts`/`images` 는 디렉토리로 본다. 빈 배열은 "선언 없음" 이라
     * 정상이다.
     */
    public function test_template_asset_declarations_point_to_existing_files(): void
    {
        $manifests = glob(base_path('templates/_bundled').'/*/template.json');

        $this->assertGreaterThanOrEqual(
            3,
            count($manifests),
            '번들 템플릿 매니페스트 모집단이 비었거나 지나치게 작다'
        );

        $violations = [];
        $directoryKinds = ['fonts', 'images'];

        foreach ($manifests as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true) ?? [];
            $templateDir = dirname($manifestPath);
            $shortManifest = str_replace(base_path().DIRECTORY_SEPARATOR, '', $manifestPath);

            foreach (($manifest['assets'] ?? []) as $kind => $paths) {
                foreach ((array) $paths as $candidate) {
                    $absolute = $templateDir.'/'.$candidate;
                    $exists = in_array($kind, $directoryKinds, true)
                        ? is_dir($absolute)
                        : is_file($absolute);

                    if (! $exists) {
                        $violations[] = sprintf('%s: assets.%s 의 %s 가 없다', $shortManifest, $kind, $candidate);
                    }
                }
            }
        }

        $this->assertSame([], $violations, "번들 템플릿 매니페스트 자산 선언 위반:\n".implode("\n", $violations));
    }
}
