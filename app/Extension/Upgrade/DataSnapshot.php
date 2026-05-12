<?php

namespace App\Extension\Upgrade;

use App\Extension\UpgradeContext;
use RuntimeException;

/**
 * 한 업그레이드 스텝이 적용할 카탈로그 delta 의 모음.
 *
 * `data/{version}/manifest.json` 을 읽어 각 kind 의 delta JSON 과 그에 대응하는
 * 버전 namespace 의 Applier 를 동적 로드 + 인스턴스화하여 `SnapshotApplier` 배열로 보유.
 *
 * 사용:
 *   $snapshot = DataSnapshot::fromManifest($dataDir, $context);
 *   $snapshot->apply($context);
 *
 * 상세: docs/extension/upgrade-step-guide.md §12
 */
final class DataSnapshot
{
    /** @var array<int, SnapshotApplier> */
    public readonly array $appliers;

    /**
     * @param  array<int, SnapshotApplier>  $appliers
     */
    public function __construct(array $appliers)
    {
        $this->appliers = $appliers;
    }

    /**
     * 빈 스냅샷 (카탈로그 변동이 없는 스텝용).
     *
     * @return self 빈 Applier 배열을 보유한 DataSnapshot
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * data/{version}/manifest.json 을 로드하여 DataSnapshot 인스턴스 생성.
     *
     * manifest 부재 시 빈 스냅샷 반환 (V-1 호환 — 디렉토리 없어도 동작).
     *
     * @param  string  $dataDir  upgrades/data/{version} 절대 경로
     * @param  UpgradeContext  $context  현재 스텝 버전 정보 (namespace 계산용)
     * @return self manifest 의 kind 별로 Applier 를 로드한 DataSnapshot
     *
     * @throws RuntimeException manifest JSON 파싱 실패 / Applier 클래스 부재 시
     */
    public static function fromManifest(string $dataDir, UpgradeContext $context): self
    {
        $manifestPath = $dataDir.'/manifest.json';
        if (! is_file($manifestPath)) {
            return self::empty();
        }

        $manifest = json_decode(
            file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $appliers = [];
        foreach ($manifest['files'] ?? [] as $entry) {
            $kind = $entry['kind'] ?? null;
            $file = $entry['file'] ?? null;
            if (! is_string($kind) || ! is_string($file)) {
                throw new RuntimeException(
                    "Invalid manifest entry in {$manifestPath}: kind/file required"
                );
            }

            $applierClass = $entry['applier_class'] ?? self::kindToClassName($kind);
            $appliers[] = self::makeApplier(
                applierClass: $applierClass,
                kind: $kind,
                dataDir: $dataDir,
                jsonPath: $dataDir.'/'.$file,
                context: $context,
            );
        }

        return new self($appliers);
    }

    /**
     * 보유한 모든 Applier 를 순차 실행.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트 (로거, 현재 스텝 버전 등)
     */
    public function apply(UpgradeContext $context): void
    {
        foreach ($this->appliers as $applier) {
            $applier->apply($context);
        }
    }

    /**
     * 버전 namespace 의 Applier 클래스를 require_once + 인스턴스화.
     *
     * 예: kind="permissions", context->currentStep="7.0.0-beta.5"
     *  → 클래스 "App\Upgrades\Data\V7_0_0_beta_5\Appliers\PermissionsApplier"
     *  → 파일 "{dataDir}/appliers/PermissionsApplier.php"
     *
     * @throws RuntimeException Applier 파일/클래스 부재 또는 인터페이스 미구현 시
     */
    private static function makeApplier(
        string $applierClass,
        string $kind,
        string $dataDir,
        string $jsonPath,
        UpgradeContext $context,
    ): SnapshotApplier {
        $applierFile = $dataDir.'/appliers/'.$applierClass.'.php';
        if (! is_file($applierFile)) {
            throw new RuntimeException(sprintf(
                'Applier file not found: %s (kind=%s)',
                $applierFile,
                $kind,
            ));
        }

        require_once $applierFile;

        $fqcn = self::versionedNamespace($context, $dataDir).'\\Appliers\\'.$applierClass;
        if (! class_exists($fqcn)) {
            throw new RuntimeException(sprintf(
                'Applier class not declared in expected namespace: %s (file=%s)',
                $fqcn,
                $applierFile,
            ));
        }
        if (! is_subclass_of($fqcn, SnapshotApplier::class)) {
            throw new RuntimeException(sprintf(
                'Applier class must implement SnapshotApplier: %s',
                $fqcn,
            ));
        }

        return new $fqcn($jsonPath);
    }

    /**
     * 현재 스텝 버전의 namespace prefix 계산.
     *
     * 코어:
     *   "7.0.0-beta.5" → "App\Upgrades\Data\V7_0_0_beta_5"
     * 번들/외부 모듈:
     *   path 가 `.../modules/.../upgrades/data/{ver}/` → "App\Upgrades\Data\Ext\Modules\{StudlyIdentifier}\V{token}"
     * 번들/외부 플러그인:
     *   path 가 `.../plugins/.../upgrades/data/{ver}/` → "App\Upgrades\Data\Ext\Plugins\{StudlyIdentifier}\V{token}"
     *
     * 확장 식별자 segment 가 namespace 에 포함되어 두 다른 확장의 같은 step 버전 + 같은
     * 클래스명 이 *별개* FQCN 으로 격리된다. 이로써 PHP compile-time fatal
     * ("Cannot declare class ... because the name is already in use") 회귀가 차단된다.
     *
     * sourceLocation 미지정 시 코어 namespace 로 폴백 (기존 동작 보존).
     *
     * @param  UpgradeContext  $context  현재 스텝 버전 정보 보유
     * @param  string|null  $sourceLocation  step 파일 또는 dataDir 의 절대 경로 (코어/확장 분기 결정)
     * @return string PSR-4 호환 namespace prefix (Applier/Migration 으로 이어짐)
     */
    public static function versionedNamespace(UpgradeContext $context, ?string $sourceLocation = null): string
    {
        $token = 'V'.str_replace(['.', '-'], '_', $context->currentStep);
        $base = 'App\\Upgrades\\Data';

        if ($sourceLocation !== null) {
            $normalized = str_replace('\\', '/', $sourceLocation);
            if (preg_match(
                '#(?:^|/)(modules|plugins)(?:/_bundled)?/([^/]+)/upgrades(?:/data)?/#',
                $normalized,
                $match,
            )) {
                $type = $match[1] === 'modules' ? 'Modules' : 'Plugins';
                $identifier = self::studlyIdentifier($match[2]);

                return "{$base}\\Ext\\{$type}\\{$identifier}\\{$token}";
            }
        }

        return "{$base}\\{$token}";
    }

    /**
     * 확장 식별자(`sirsoft-ecommerce` 등) 를 PHP namespace-safe StudlyCase 로 변환.
     *
     *   "sirsoft-ecommerce"   → "SirsoftEcommerce"
     *   "vendor_module_v2"    → "VendorModuleV2"
     */
    private static function studlyIdentifier(string $identifier): string
    {
        $parts = preg_split('/[-_]+/', $identifier) ?: [$identifier];
        $studly = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $studly .= ucfirst($part);
        }

        return $studly !== '' ? $studly : 'Anonymous';
    }

    /**
     * snake_case kind → StudlyCase Applier 클래스명.
     *
     * permissions          → PermissionsApplier
     * role_permissions     → RolePermissionsApplier
     * notification_definitions → NotificationDefinitionsApplier
     */
    private static function kindToClassName(string $kind): string
    {
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $kind)));

        return $studly.'Applier';
    }
}
