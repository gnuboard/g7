<?php

namespace App\Extension\Vendor;

use App\Extension\Vendor\Exceptions\VendorInstallException;
use Illuminate\Support\Facades\Log;

/**
 * Vendor 설치 진입점.
 *
 * CLI / 웹 인스톨러 / Admin API 모든 경로가 최종적으로 이 클래스를 경유합니다.
 * 모드를 결정(resolveMode)하고 적절한 전략으로 위임합니다.
 *
 * Composer 전략은 외부 콜백(executeComposer)에 위임 — 코어(CoreUpdateService)와
 * 확장(ExtensionManager)이 각자 기존 composer 실행 로직을 전달하는 형태.
 */
class VendorResolver
{
    public function __construct(
        private readonly EnvironmentDetector $environmentDetector,
        private readonly VendorIntegrityChecker $integrityChecker,
        private readonly VendorBundleInstaller $bundleInstaller,
    ) {}

    /**
     * 사용할 Vendor 모드를 결정합니다.
     *
     * 우선순위:
     * 1. 명시적 요청 모드 (composer/bundled) → 그대로 사용
     * 2. 업데이트 시 이전 설치 모드 상속 (auto가 아닐 때)
     * 3. 전역 기본값 config('app.install.default_vendor_mode')
     * 4. 환경 감지: composer 가능하면 composer, 불가 시 번들 존재 여부 확인
     *
     * @throws VendorInstallException
     */
    public function resolveMode(VendorInstallContext $ctx): VendorMode
    {
        // 1. 명시적 지정
        if ($ctx->requestedMode !== VendorMode::Auto) {
            return $ctx->requestedMode;
        }

        // 2. 업데이트 시 이전 모드 상속
        if ($ctx->operation === 'update'
            && $ctx->previousMode !== null
            && $ctx->previousMode !== VendorMode::Auto
        ) {
            return $ctx->previousMode;
        }

        // 3. 전역 기본값
        $configured = config('app.install.default_vendor_mode', 'auto');
        if ($configured !== 'auto') {
            $parsed = VendorMode::tryFrom((string) $configured);
            if ($parsed !== null && $parsed !== VendorMode::Auto) {
                return $parsed;
            }
        }

        // 4. auto → 환경 감지
        if ($this->environmentDetector->canExecuteComposer($ctx->composerBinaryHint)) {
            return VendorMode::Composer;
        }

        // 5. composer 불가 → 번들 존재 확인
        $zipPath = $ctx->sourceDir.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
        if (file_exists($zipPath)) {
            return VendorMode::Bundled;
        }

        throw new VendorInstallException('no_vendor_strategy_available');
    }

    /**
     * Vendor 설치를 수행합니다.
     *
     * @param  VendorInstallContext  $ctx  설치 컨텍스트
     * @param  callable|null  $composerExecutor  Composer 모드일 때 실행될 콜백
     *                                            시그니처: fn(VendorInstallContext $ctx): VendorInstallResult
     *
     * @throws VendorInstallException
     */
    public function install(VendorInstallContext $ctx, ?callable $composerExecutor = null): VendorInstallResult
    {
        $mode = $this->resolveMode($ctx);

        Log::info('Vendor 설치 모드 결정됨', [
            'target' => $ctx->label(),
            'requested_mode' => $ctx->requestedMode->value,
            'resolved_mode' => $mode->value,
            'operation' => $ctx->operation,
        ]);

        $this->validateModeFeasibility($mode, $ctx, $composerExecutor);

        return match ($mode) {
            VendorMode::Composer => $this->executeComposer($ctx, $composerExecutor),
            VendorMode::Bundled => $this->executeBundle($ctx),
            VendorMode::Auto => throw new VendorInstallException('no_vendor_strategy_available'),
        };
    }

    /**
     * 선택된 모드가 실제로 가능한지 사전 검증합니다.
     *
     * @throws VendorInstallException
     */
    private function validateModeFeasibility(
        VendorMode $mode,
        VendorInstallContext $ctx,
        ?callable $composerExecutor,
    ): void {
        if ($mode === VendorMode::Composer) {
            if (! $this->environmentDetector->canExecuteComposer($ctx->composerBinaryHint)) {
                throw new VendorInstallException('composer_not_available');
            }
            if ($composerExecutor === null) {
                throw new VendorInstallException(
                    errorKey: 'composer_execution_failed',
                    context: ['message' => 'composer executor callback not provided'],
                );
            }

            return;
        }

        if ($mode === VendorMode::Bundled) {
            $zipPath = $ctx->sourceDir.DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
            if (! file_exists($zipPath)) {
                throw new VendorInstallException(
                    errorKey: 'bundle_zip_missing',
                    context: ['path' => $zipPath],
                );
            }

            $integrity = $this->integrityChecker->verify($ctx->sourceDir);
            if (! $integrity->valid) {
                throw new VendorInstallException(
                    errorKey: 'bundle_integrity_failed',
                    context: ['details' => implode(', ', $integrity->errorMessages())],
                );
            }
        }
    }

    private function executeComposer(VendorInstallContext $ctx, callable $composerExecutor): VendorInstallResult
    {
        $startTime = microtime(true);
        $result = $composerExecutor($ctx);
        $duration = microtime(true) - $startTime;

        if ($result instanceof VendorInstallResult) {
            return $result;
        }

        // 콜백이 단순 true/void를 반환한 경우 — 기본 결과 생성
        return new VendorInstallResult(
            mode: VendorMode::Composer,
            strategy: 'composer',
            packageCount: 0,
            durationSeconds: $duration,
            details: ['raw_result' => $result],
        );
    }

    private function executeBundle(VendorInstallContext $ctx): VendorInstallResult
    {
        return $this->bundleInstaller->install($ctx->sourceDir, $ctx->targetDir);
    }
}
