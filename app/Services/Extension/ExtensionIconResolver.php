<?php

namespace App\Services\Extension;

use App\Extension\Helpers\InstalledExtensionLookup;
use App\Support\ExtensionIcon\ExtensionIconValidator;

/**
 * 확장 아이콘 해석기
 *
 * 확장 하나의 아이콘 파일을 찾아 준다. **설치된** 확장(DB 에 active·inactive 행)은
 * 설치본(활성 디렉터리)만 본다 — 설치본에 아이콘이 없으면 없는 것이다(동봉 원본으로
 * 채우지 않는다). **미설치** 확장만 「활성 → _pending → _bundled」 순으로 찾는다.
 * 자산 서빙 lane(PluginService::getAssetFilePath 등)은 활성 디렉터리만 보므로
 * 미설치 번들 확장의 아이콘은 이 해석기만 찾아낼 수 있다.
 *
 * 규격 위반 아이콘은 **해석 실패(null)** 로 취급한다 — 서빙도 목록 노출도 하지
 * 않고 화면이 유형 글리프로 떨어진다. 규정 원문은 docs/extension/extension-icons.md.
 *
 * 무상태 + 요청 단위 메모다. 목록 한 번에 행마다 같은 확장을 여러 번 물어도
 * SVG 파싱은 확장당 1회다. 디스크 캐시·DB 컬럼은 두지 않는다.
 */
final class ExtensionIconResolver
{
    /** @var list<string> 지원 확장 유형 */
    public const TYPES = ['module', 'plugin', 'template', 'language-pack'];

    /**
     * @var list<string> 파일 관례 후보 (이 순서로 첫 존재 파일만 검사)
     *
     * 목록 자체는 검증기가 갖는다 — 웹 인스톨러가 Laravel 없이 검증기만 읽어
     * 같은 순서로 아이콘을 찾기 때문이다. 여기 사본을 두면 두 화면이 갈린다.
     */
    public const CONVENTION_CANDIDATES = ExtensionIconValidator::CONVENTION_CANDIDATES;

    /** @var string 코어 아이콘의 정적 URL (웹서버가 직접 서빙) */
    public const CORE_ICON_URL = '/assets/icons/core/g7-core.svg';

    /** @var array<string, array{path: string, mime: string, source: string, location: string}|null> 요청 단위 메모 */
    private array $memo = [];

    /**
     * 확장의 아이콘을 해석합니다.
     *
     * 설치된 확장은 활성 디렉터리만, 미설치 확장은 활성 → _pending → _bundled 순으로
     * 첫 유효 아이콘을 돌려준다. 어느 루트에도 없거나 찾은 파일이 규격 위반이면 null 이다.
     *
     * @param  string  $type  확장 유형 (module|plugin|template|language-pack)
     * @param  string  $identifier  확장 식별자
     * @return array{path: string, mime: string, source: 'manifest'|'convention', location: 'active'|'pending'|'bundled'}|null 해석 결과, 없으면 null
     */
    public function resolve(string $type, string $identifier): ?array
    {
        $key = $type.'|'.$identifier;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $resolved = null;

        foreach ($this->candidateRoots($type, $identifier) as $location => $root) {
            if (! is_dir($root)) {
                continue;
            }

            $inspection = $this->inspectRoot($root, $this->readManifestIcon($type, $root));

            if ($inspection['present'] && $inspection['valid'] && $inspection['path'] !== null) {
                $extension = strtolower((string) pathinfo($inspection['path'], PATHINFO_EXTENSION));

                $resolved = [
                    'path' => $inspection['path'],
                    'mime' => ExtensionIconValidator::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream',
                    'source' => $inspection['source'],
                    'location' => $location,
                ];

                break;
            }
        }

        return $this->memo[$key] = $resolved;
    }

    /**
     * 루트 하나를 검사합니다 (ZIP 미리보기·인스톨러 공유).
     *
     * 매니페스트 `icon` 이 있으면 경로 규칙 → 실재 순으로 보고, 위반이면 경고를
     * 남긴 뒤 관례로 폴백한다. 관례는 svg→png→webp 순 **첫 존재 파일만** 검사한다
     * (존재하는데 규격 위반이면 다음 후보로 넘어가지 않는다 — 고칠 대상이 분명해야 한다).
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string|null  $manifestIcon  매니페스트 icon 선언값
     * @return array{present: bool, path: ?string, relative: ?string, source: 'manifest'|'convention'|'none', valid: bool, format: ?string, warnings: list<string>} 검사 결과
     */
    public function inspectRoot(string $root, ?string $manifestIcon): array
    {
        $warnings = [];

        if (is_string($manifestIcon) && trim($manifestIcon) !== '') {
            $violation = ExtensionIconValidator::manifestPathViolation($manifestIcon);

            if ($violation !== null) {
                $warnings[] = 'manifest_icon_'.$violation;
            } else {
                $absolute = ExtensionIconValidator::resolveInsideRoot($root, $manifestIcon);

                if ($absolute === null) {
                    $warnings[] = 'manifest_icon_missing';
                } else {
                    $result = ExtensionIconValidator::validateFile($absolute);

                    return [
                        'present' => true,
                        'path' => $absolute,
                        'relative' => $this->relativize($root, $absolute),
                        'source' => 'manifest',
                        'valid' => $result['valid'],
                        'format' => $result['format'],
                        'warnings' => array_values(array_merge($warnings, $result['errors'])),
                    ];
                }
            }
        }

        foreach (self::CONVENTION_CANDIDATES as $candidate) {
            $absolute = ExtensionIconValidator::resolveInsideRoot($root, $candidate);

            if ($absolute === null) {
                continue;
            }

            $result = ExtensionIconValidator::validateFile($absolute);

            return [
                'present' => true,
                'path' => $absolute,
                'relative' => $candidate,
                'source' => 'convention',
                'valid' => $result['valid'],
                'format' => $result['format'],
                'warnings' => array_values(array_merge($warnings, $result['errors'])),
            ];
        }

        return [
            'present' => false,
            'path' => null,
            'relative' => null,
            'source' => 'none',
            'valid' => false,
            'format' => null,
            'warnings' => $warnings,
        ];
    }

    /**
     * 미리보기 응답용으로 경고를 코드·문장 쌍으로 바꾼 검사 결과를 돌려줍니다.
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string|null  $manifestIcon  매니페스트 icon 선언값
     * @return array{present: bool, path: ?string, source: string, valid: bool, format: ?string, warnings: list<array{code: string, message: string}>} 미리보기용 검사 결과
     */
    public function inspectRootForApi(string $root, ?string $manifestIcon): array
    {
        $inspection = $this->inspectRoot($root, $manifestIcon);

        $warnings = array_map(
            static fn (string $code): array => [
                'code' => $code,
                'message' => __('extensions.icon.warnings.'.$code),
            ],
            $inspection['warnings']
        );

        return [
            'present' => $inspection['present'],
            'path' => $inspection['relative'],
            'source' => $inspection['source'],
            'valid' => $inspection['valid'],
            'format' => $inspection['format'],
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * 목록·상세 행에 얹을 아이콘 필드를 만듭니다.
     *
     * @param  string  $type  확장 유형
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $version  캐시 무효화에 쓸 버전
     * @return array{icon_url: ?string, icon_source: string} 행에 병합할 필드
     */
    public function describe(string $type, string $identifier, ?string $version = null): array
    {
        $resolved = $this->resolve($type, $identifier);

        if ($resolved === null) {
            return ['icon_url' => null, 'icon_source' => 'none'];
        }

        return [
            'icon_url' => $this->url($type, $identifier, $version),
            'icon_source' => $resolved['source'],
        ];
    }

    /**
     * 아이콘 서빙 URL 을 만듭니다.
     *
     * @param  string  $type  확장 유형
     * @param  string  $identifier  확장 식별자
     * @param  string|null  $version  캐시 무효화용 버전 (있으면 ?v=)
     * @return string 아이콘 URL
     */
    public function url(string $type, string $identifier, ?string $version = null): string
    {
        $url = '/api/extensions/'.$type.'/'.$identifier.'/icon';

        return $version !== null && $version !== ''
            ? $url.'?v='.rawurlencode($version)
            : $url;
    }

    /**
     * 유형별 아이콘 후보 루트를 위치 이름 → 절대 경로로 돌려줍니다.
     *
     * 모듈·플러그인·템플릿이 설치되어 있으면(InstalledExtensionLookup) 설치본 하나만 후보다 —
     * 운영에서 동작하는 것은 설치본이고, 설치본은 동봉 원본과 버전이 다르거나 원본에 없을 수 있다.
     * 미설치 확장(목록의 번들·_pending 행)만 _pending·_bundled 까지 본다.
     *
     * 언어팩은 _pending 이 없다 — 설치본과 _bundled 둘뿐이며 LanguagePack::
     * resolveDirectory() 의 폴백 규칙과 같은 순서다.
     *
     * @param  string  $type  확장 유형
     * @param  string  $identifier  확장 식별자
     * @return array<string, string> 위치 이름 → 절대 경로
     */
    private function candidateRoots(string $type, string $identifier): array
    {
        if ($type === 'language-pack') {
            return [
                'active' => base_path('lang-packs/'.$identifier),
                'bundled' => base_path('lang-packs/_bundled/'.$identifier),
            ];
        }

        $directory = match ($type) {
            'module' => 'modules',
            'plugin' => 'plugins',
            'template' => 'templates',
            default => null,
        };

        if ($directory === null) {
            return [];
        }

        if (InstalledExtensionLookup::isInstalled($type, $identifier)) {
            return ['active' => base_path($directory.'/'.$identifier)];
        }

        return [
            'active' => base_path($directory.'/'.$identifier),
            'pending' => base_path($directory.'/_pending/'.$identifier),
            'bundled' => base_path($directory.'/_bundled/'.$identifier),
        ];
    }

    /**
     * 루트의 매니페스트에서 icon 선언값을 읽습니다.
     *
     * @param  string  $type  확장 유형
     * @param  string  $root  확장 루트 절대 경로
     * @return string|null icon 문자열, 없으면 null
     */
    private function readManifestIcon(string $type, string $root): ?string
    {
        $file = match ($type) {
            'module' => 'module.json',
            'plugin' => 'plugin.json',
            'template' => 'template.json',
            'language-pack' => 'language-pack.json',
            default => null,
        };

        if ($file === null) {
            return null;
        }

        $path = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $manifest = json_decode($contents, true);

        if (! is_array($manifest) || ! isset($manifest['icon']) || ! is_string($manifest['icon'])) {
            return null;
        }

        return $manifest['icon'];
    }

    /**
     * 절대 경로를 루트 기준 상대경로로 바꿉니다 (미리보기 표시용).
     *
     * @param  string  $root  확장 루트 절대 경로
     * @param  string  $absolute  아이콘 절대 경로
     * @return string 슬래시로 정규화한 상대경로
     */
    private function relativize(string $root, string $absolute): string
    {
        $realRoot = realpath($root);
        $prefix = ($realRoot === false ? $root : $realRoot);
        $prefix = rtrim($prefix, '/\\').DIRECTORY_SEPARATOR;

        $relative = strpos($absolute, $prefix) === 0
            ? substr($absolute, strlen($prefix))
            : $absolute;

        return str_replace('\\', '/', $relative);
    }
}
