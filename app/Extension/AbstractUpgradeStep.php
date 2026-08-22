<?php

namespace App\Extension;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\Upgrade\DataMigration;
use App\Extension\Upgrade\DataSnapshot;
use ReflectionClass;

/**
 * 버전별 데이터 스냅샷 규약을 따르는 업그레이드 스텝 기본 클래스.
 *
 * 7.0.0-beta.5 부터 신규 업그레이드 스텝은 본 추상 클래스를 상속해야 한다.
 * runUpgradeSteps 가 인스턴스 검증 직후 상속 여부를 확인하고 미상속 시 fatal.
 *
 * 격리 원칙 ("각 스텝별 동작 100% 동일 보장"):
 *   - 카탈로그 시드는 data/{version}/*.delta.json 으로 동결
 *   - Applier 는 data/{version}/appliers/ 안에 버전 namespace 로 동결
 *   - 변환 / 단발성 핫픽스는 data/{version}/migrations/ 안에 버전 namespace 로 동결
 *   - 스텝 파일 자체는 본 추상 클래스를 상속한 빈 껍데기 (모든 비즈니스 로직은 data/ 로 위임)
 *
 * 사용 예:
 *
 *     class Upgrade_7_0_0_beta_5 extends AbstractUpgradeStep
 *     {
 *         // 모든 default 사용 — data/7.0.0-beta.5/ 의 manifest/appliers/migrations 가 SSoT
 *     }
 *
 * 상세: docs/extension/upgrade-step-guide.md §12
 */
abstract class AbstractUpgradeStep implements UpgradeStepInterface
{
    /**
     * data/{currentStep}/manifest.json 을 로드 + Applier 동적 로드.
     *
     * 카탈로그 변동이 없는 스텝은 manifest 파일 없이 자동 빈 스냅샷 반환.
     */
    protected function dataSnapshot(UpgradeContext $context): DataSnapshot
    {
        return DataSnapshot::fromManifest($this->dataDir($context), $context);
    }

    /**
     * data/{currentStep}/migrations/*.php 를 require_once + 버전 namespace 의 DataMigration
     * 구현체를 인스턴스화하여 배열로 반환. 디렉토리 부재 시 빈 배열.
     *
     * 파일명은 정렬용 prefix (예: `01_RecoverActiveExtensionDirs.php`, `02_...php`) 를 허용한다.
     * prefix 는 정규식 `^\d{2,}_` 로 클래스명 매핑 시 제거되며, 클래스명 자체는 prefix 없이
     * 선언한다 (PHP 식별자 제약). 파일 정렬 결과가 실행 순서 — 명시적 순서가 필요하면 prefix 사용.
     *
     * @return array<int, DataMigration>
     */
    protected function dataMigrations(UpgradeContext $context): array
    {
        $dir = $this->dataDir($context).'/migrations';
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.'/*.php') ?: [];
        sort($files);

        $migrations = [];
        $namespace = DataSnapshot::versionedNamespace($context, $dir).'\\Migrations';

        foreach ($files as $file) {
            require_once $file;
            $baseName = basename($file, '.php');
            $className = preg_replace('/^\d{2,}_/', '', $baseName);
            $fqcn = $namespace.'\\'.$className;

            if (class_exists($fqcn) && is_subclass_of($fqcn, DataMigration::class)) {
                $migrations[] = new $fqcn;
            }
        }

        return $migrations;
    }

    /**
     * 카탈로그/변환 외 프레임워크 레벨 부수 작업 (선택).
     *
     * 본 PR 의 beta.5 재작성에서는 사용 안 함 — 모든 비즈니스 로직은 data/ 로 격리.
     * 미래에 *모든 버전 공통* 인 부수 작업이 필요하면 override.
     *
     * default: no-op.
     */
    protected function postRun(UpgradeContext $context): void {}

    /**
     * 업그레이드 스텝 실행 — 순서: dataSnapshot → dataMigrations → postRun.
     *
     * 본 메서드는 final — 하위 클래스가 순서 무력화 못 함.
     */
    final public function run(UpgradeContext $context): void
    {
        $snapshot = $this->dataSnapshot($context);
        if ($snapshot->appliers !== []) {
            $context->logger->info(sprintf(
                '[%s] DataSnapshot 적용 — Applier %d개',
                $context->currentStep,
                count($snapshot->appliers),
            ));
        }
        $snapshot->apply($context);

        $migrations = $this->dataMigrations($context);
        if ($migrations !== []) {
            $context->logger->info(sprintf(
                '[%s] DataMigration 실행 — %d개',
                $context->currentStep,
                count($migrations),
            ));
        }
        foreach ($migrations as $migration) {
            $context->logger->info(sprintf(
                '[%s] migration: %s',
                $context->currentStep,
                $migration->name(),
            ));
            $migration->run($context);
        }

        $this->postRun($context);
    }

    /**
     * data/{currentStep}/ 절대 경로 계산. 스텝 파일 위치 기반 — 코어/확장 무관.
     */
    protected function dataDir(UpgradeContext $context): string
    {
        $stepFile = (new ReflectionClass($this))->getFileName();

        return dirname($stepFile).'/data/'.$context->currentStep;
    }
}
