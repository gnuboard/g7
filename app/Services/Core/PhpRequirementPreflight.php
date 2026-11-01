<?php

namespace App\Services\Core;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Support\PhpExtensionProbe;
use Illuminate\Support\Facades\Log;

/**
 * 코어 업데이트 PHP 확장 사전점검 (#151 D9).
 *
 * 새 배포본의 코어 SSoT(`app/Support/Requirements/php-extensions.json`)가 요구하는 필수 확장이 서버에
 * 없으면 업데이트를 막는다 — 파일을 적용한 뒤에야 부팅이 깨지는 것보다 시작 전에 멈추는 편이 사이트가 온전하다.
 * 새 패키지의 PHP 클래스를 `require` 하면 이미 로드된 같은 이름 클래스와 충돌하므로 JSON 만 읽는다.
 *
 * 같은 시점에 「이번에 함께 갱신될 설치된 번들 확장」의 새 composer.json `require.ext-*` 도 계산하지만,
 * 그쪽은 경고만 낸다(확장 업데이트는 막지 않는다 — D3).
 *
 * 새 배포본에 JSON 이 없으면(이 체계 도입 전 판) 코어 판정은 건너뛴다.
 */
class PhpRequirementPreflight
{
    /** 배포본 루트 기준 SSoT JSON 상대 경로 */
    public const REQUIREMENTS_PATH = 'app/Support/Requirements/php-extensions.json';

    /**
     * @param  ModuleRepositoryInterface  $moduleRepository  설치된 모듈 조회
     * @param  PluginRepositoryInterface  $pluginRepository  설치된 플러그인 조회
     * @param  PhpExtensionProbe  $probe  확장 로드 여부 판정 (테스트 주입 지점)
     */
    public function __construct(
        private ModuleRepositoryInterface $moduleRepository,
        private PluginRepositoryInterface $pluginRepository,
        private PhpExtensionProbe $probe,
    ) {}

    /**
     * 디렉토리 배포본(`--source`, GitHub 다운로드 뒤 `_pending`)을 점검합니다.
     *
     * @param  string  $root  배포본 루트 디렉토리
     * @return array{core_checked: bool, core_missing: list<string>, extension_missing: array<string, list<string>>} 코어 판정 여부·없는 코어 필수 확장·확장(유형:식별자)별 없는 필수 확장
     */
    public function inspectDirectory(string $root): array
    {
        $root = rtrim($root, '/\\');

        return $this->inspect(
            fn (string $relative): ?string => is_file($root.'/'.$relative) ? (string) file_get_contents($root.'/'.$relative) : null,
        );
    }

    /**
     * zip 배포본(`--zip`)을 풀지 않고 점검합니다.
     *
     * @param  string  $zipPath  zip 파일 경로
     * @return array{core_checked: bool, core_missing: list<string>, extension_missing: array<string, list<string>>}|null 점검 결과, zip 을 읽지 못하면 null (추출 뒤 디렉토리로 다시 점검)
     */
    public function inspectZip(string $zipPath): ?array
    {
        if (! class_exists(\ZipArchive::class) || ! is_file($zipPath)) {
            return null;
        }

        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return null;
        }

        try {
            $prefix = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
                if (preg_match('#^((?:[^/]+/)?)config/app\.php$#', $name, $m)) {
                    $prefix = $m[1];
                    if ($prefix === '') {
                        break;
                    }
                }
            }

            if ($prefix === null) {
                return null;
            }

            return $this->inspect(function (string $relative) use ($zip, $prefix): ?string {
                $content = $zip->getFromName($prefix.$relative);

                return is_string($content) ? $content : null;
            });
        } finally {
            $zip->close();
        }
    }

    /**
     * 파일 읽기 콜백으로 코어·번들 확장 요구를 판정합니다.
     *
     * @param  \Closure(string): ?string  $read  배포본 루트 기준 상대 경로 → 내용(없으면 null)
     * @return array{core_checked: bool, core_missing: list<string>, extension_missing: array<string, list<string>>} 판정 결과
     */
    private function inspect(\Closure $read): array
    {
        $result = ['core_checked' => false, 'core_missing' => [], 'extension_missing' => []];

        $json = $read(self::REQUIREMENTS_PATH);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (is_array($data) && is_array($data['required'] ?? null)) {
            $result['core_checked'] = true;
            $result['core_missing'] = $this->probe->missing(array_values(array_map('strval', $data['required'])));
        } else {
            Log::info('코어 업데이트 PHP 확장 사전점검: 새 배포본에 요구 목록이 없어 코어 판정을 건너뜀');
        }

        foreach ($this->installedExtensions() as [$type, $directory, $identifier]) {
            // audit:allow install-path-reads-bundled-or-template-id reason: 새 배포본(업데이트 소스)의 _bundled 를 읽는다 — 번들 일괄 업데이트가 설치할 다음 판의 composer.json
            $composer = json_decode((string) $read($directory.'/_bundled/'.$identifier.'/composer.json'), true);
            $required = [];
            foreach (array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []) as $package) {
                if (str_starts_with((string) $package, 'ext-')) {
                    $required[] = strtolower(substr((string) $package, 4));
                }
            }

            $missing = $this->probe->missing($required);
            if ($missing !== []) {
                $result['extension_missing'][$type.':'.$identifier] = $missing;
            }
        }

        return $result;
    }

    /**
     * 설치된 모듈·플러그인 목록을 반환합니다. (DB 를 읽지 못하면 빈 목록 — 경고 계산만 건너뛴다)
     *
     * @return list<array{0: string, 1: string, 2: string}> [유형, 도메인 디렉토리, 식별자]
     */
    private function installedExtensions(): array
    {
        try {
            $list = [];
            foreach ($this->moduleRepository->getInstalledIdentifiers() as $identifier) {
                $list[] = ['module', 'modules', (string) $identifier];
            }
            foreach ($this->pluginRepository->getInstalledIdentifiers() as $identifier) {
                $list[] = ['plugin', 'plugins', (string) $identifier];
            }

            return $list;
        } catch (\Throwable $e) {
            Log::warning('코어 업데이트 PHP 확장 사전점검: 설치된 확장 목록을 읽지 못해 확장 경고를 건너뜀', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
