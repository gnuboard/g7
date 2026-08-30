<?php

namespace App\Support\ExtensionDoc;

use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * 번들 확장 인벤토리
 *
 * `{modules,plugins,templates}/_bundled/*` 를 패턴 스캔해 확장 목록과 manifest 를
 * 로드합니다. 확장명을 하드코딩하지 않으므로 신규 확장이 추가되면 자동으로 편입됩니다
 * (동적 로딩 원칙).
 *
 * 문서 생성 대상은 `_bundled` 뿐입니다. 활성 디렉토리는 update 커맨드의 산출물이며
 * 비번들 제3자 확장이 섞여 있어 집필 대상이 아닙니다.
 */
class ExtensionInventory
{
    /**
     * @var string 모듈 유형
     */
    public const TYPE_MODULE = 'module';

    /**
     * @var string 플러그인 유형
     */
    public const TYPE_PLUGIN = 'plugin';

    /**
     * @var string 템플릿 유형
     */
    public const TYPE_TEMPLATE = 'template';

    /**
     * 유형 → 저장소 최상위 디렉토리 / manifest 파일명 / 진입 클래스 파일명 매핑
     *
     * @var array<string, array{dir: string, manifest: string, entryFile: string|null, entryClass: string|null, rootNamespace: string|null}>
     */
    private const TYPE_MAP = [
        self::TYPE_MODULE => [
            'dir' => 'modules',
            'manifest' => 'module.json',
            'entryFile' => 'module.php',
            'entryClass' => 'Module',
            'rootNamespace' => 'Modules',
        ],
        self::TYPE_PLUGIN => [
            'dir' => 'plugins',
            'manifest' => 'plugin.json',
            'entryFile' => 'plugin.php',
            'entryClass' => 'Plugin',
            'rootNamespace' => 'Plugins',
        ],
        self::TYPE_TEMPLATE => [
            'dir' => 'templates',
            'manifest' => 'template.json',
            'entryFile' => null,
            'entryClass' => null,
            'rootNamespace' => null,
        ],
    ];

    /**
     * 지원 유형 목록을 반환합니다.
     *
     * @return array<int, string> 유형 목록
     */
    public static function types(): array
    {
        return array_keys(self::TYPE_MAP);
    }

    /**
     * 유형에 대응하는 저장소 디렉토리명을 반환합니다.
     *
     * @param  string  $type  확장 유형
     * @return string|null 디렉토리명 (미지원 유형이면 null)
     */
    public static function directoryFor(string $type): ?string
    {
        return self::TYPE_MAP[$type]['dir'] ?? null;
    }

    /**
     * scope 문자열을 파싱합니다.
     *
     * @param  string  $scope  `all` | `module:{id}` | `plugin:{id}` | `template:{id}`
     * @return array{type: string|null, id: string|null} 파싱 결과 (all 이면 둘 다 null)
     */
    public static function parseScope(string $scope): array
    {
        $scope = trim($scope);

        if ($scope === '' || $scope === 'all') {
            return ['type' => null, 'id' => null];
        }

        // 해석 실패를 "전체" 로 되돌리지 않는다. `--scope=modules:board`(복수형 오타)나
        // `--scope=modul:board` 가 조용히 번들 20개 전 문서를 기록하게 되기 때문이다.
        // 반면 `--scope=module:없는id` 는 이미 명확한 실패로 처리되므로, 두 오타가 정반대로
        // 다뤄지는 비대칭을 없앤다.
        if (! str_contains($scope, ':')) {
            throw new InvalidArgumentException(
                "scope 형식이 올바르지 않습니다: '{$scope}'. all | module:{id} | plugin:{id} | template:{id} 중 하나여야 합니다."
            );
        }

        [$type, $id] = explode(':', $scope, 2);
        $type = trim($type);
        $id = trim($id);

        if (! isset(self::TYPE_MAP[$type])) {
            throw new InvalidArgumentException(
                "알 수 없는 확장 유형입니다: '{$type}'. ".implode(' | ', array_keys(self::TYPE_MAP)).' 중 하나여야 합니다.'
            );
        }

        if ($id === '') {
            throw new InvalidArgumentException("scope 에 확장 식별자가 없습니다: '{$scope}'.");
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * scope 에 해당하는 번들 확장 목록을 수집합니다.
     *
     * @param  string  $scope  범위 (`all` | `{type}:{id}`)
     * @return array<int, array<string, mixed>> 확장 레코드 목록 (유형 → 식별자 정렬)
     */
    public function collect(string $scope = 'all'): array
    {
        $parsed = self::parseScope($scope);
        $records = [];
        $this->malformed = [];

        foreach (self::TYPE_MAP as $type => $meta) {
            if ($parsed['type'] !== null && $parsed['type'] !== $type) {
                continue;
            }

            $bundledRoot = base_path($meta['dir'].'/_bundled');
            if (! is_dir($bundledRoot)) {
                continue;
            }

            foreach (File::directories($bundledRoot) as $dirPath) {
                $id = basename($dirPath);

                if ($parsed['id'] !== null && $parsed['id'] !== $id) {
                    continue;
                }

                $record = $this->buildRecord($type, $id, $dirPath);
                if ($record !== null) {
                    $records[] = $record;
                }
            }
        }

        usort($records, function (array $a, array $b): int {
            return [$a['type'], $a['id']] <=> [$b['type'], $b['id']];
        });

        return $records;
    }

    /**
     * 단일 확장 레코드를 조회합니다.
     *
     * @param  string  $type  확장 유형
     * @param  string  $id  확장 식별자
     * @return array<string, mixed>|null 확장 레코드 (없으면 null)
     */
    public function find(string $type, string $id): ?array
    {
        $records = $this->collect("{$type}:{$id}");

        return $records[0] ?? null;
    }

    /**
     * @var array<int, array{type: string, id: string, manifest: string, reason: string}> manifest 를 읽지 못한 디렉토리
     */
    private array $malformed = [];

    /**
     * 직전 `collect()` 에서 manifest 파싱에 실패한 디렉토리 목록을 반환합니다.
     *
     * 호출자가 이 목록을 보고해야 "확장이 없다" 와 "읽지 못했다" 가 구분됩니다.
     *
     * @return array<int, array{type: string, id: string, manifest: string, reason: string}> 실패 목록
     */
    public function malformed(): array
    {
        return $this->malformed;
    }

    /**
     * 확장 레코드를 조립합니다.
     *
     * manifest 가 없거나 JSON 파싱에 실패한 디렉토리는 확장이 아니므로 제외합니다
     * (`_backup_*` · 업데이트 중 임시 디렉토리 등).
     *
     * @param  string  $type  확장 유형
     * @param  string  $id  확장 식별자
     * @param  string  $dirPath  확장 절대 경로
     * @return array<string, mixed>|null 확장 레코드 (manifest 부재 시 null)
     */
    private function buildRecord(string $type, string $id, string $dirPath): ?array
    {
        $meta = self::TYPE_MAP[$type];
        $manifestPath = $dirPath.DIRECTORY_SEPARATOR.$meta['manifest'];

        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            // manifest 가 있는데 못 읽은 것은 "확장이 아님"(`_backup_*` 등) 과 다르다.
            // 조용히 탈락시키면 그 확장이 문서 체계에서 통째로 사라지고, 검사 대상 수
            // 자체가 줄어 "20개 중 5개 보유" 분모까지 함께 줄어 회귀로 보이지 않는다.
            $this->malformed[] = [
                'type' => $type,
                'id' => $id,
                'manifest' => $meta['manifest'],
                'reason' => json_last_error_msg(),
            ];

            return null;
        }

        $namespace = $meta['rootNamespace'] !== null
            ? $meta['rootNamespace'].'\\'.ExtensionManager::directoryToNamespace($id)
            : null;

        $entryFile = $meta['entryFile'] !== null
            ? $dirPath.DIRECTORY_SEPARATOR.$meta['entryFile']
            : null;

        return [
            'type' => $type,
            'id' => $id,
            'label' => self::typeLabel($type),
            'path' => $dirPath,
            'relPath' => $meta['dir'].'/_bundled/'.$id,
            'manifest' => $manifest,
            'manifestFile' => $meta['manifest'],
            'manifestPath' => $manifestPath,
            'namespace' => $namespace,
            'entryFile' => ($entryFile !== null && is_file($entryFile)) ? $entryFile : null,
            'entryClass' => $namespace !== null ? $namespace.'\\'.$meta['entryClass'] : null,
            'entryClassShort' => $meta['entryClass'],
            'docsPath' => $dirPath.DIRECTORY_SEPARATOR.'docs',
            'version' => (string) ($manifest['version'] ?? ''),
            'name' => self::localizedName($manifest, $id),
            'description' => self::localized($manifest['description'] ?? ''),
        ];
    }

    /**
     * 유형의 한국어 라벨을 반환합니다.
     *
     * @param  string  $type  확장 유형
     * @return string 한국어 라벨
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_MODULE => '모듈',
            self::TYPE_PLUGIN => '플러그인',
            self::TYPE_TEMPLATE => '템플릿',
            default => $type,
        };
    }

    /**
     * manifest 의 name 을 한국어 우선으로 해석합니다.
     *
     * @param  array<string, mixed>  $manifest  manifest 배열
     * @param  string  $fallback  이름이 없을 때 사용할 값
     * @return string 확장명
     */
    public static function localizedName(array $manifest, string $fallback): string
    {
        $name = self::localized($manifest['name'] ?? '');

        return $name !== '' ? $name : $fallback;
    }

    /**
     * 다국어 값(문자열 또는 로케일 배열)을 한국어 우선으로 해석합니다.
     *
     * @param  mixed  $value  manifest 값
     * @return string 해석된 문자열 (해석 불가 시 빈 문자열)
     */
    public static function localized(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach (['ko', 'en'] as $locale) {
                if (isset($value[$locale]) && is_string($value[$locale])) {
                    return $value[$locale];
                }
            }

            foreach ($value as $item) {
                if (is_string($item)) {
                    return $item;
                }
            }
        }

        return '';
    }
}
