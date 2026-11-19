<?php

namespace App\Extension\Custom;

use App\Extension\ExtensionManager;
use App\Extension\Vendor\EnvironmentDetector;
use App\Extension\Vendor\IntegrityResult;
use App\Extension\Vendor\VendorBundleInstaller;
use App\Extension\Vendor\VendorBundler;
use App\Extension\Vendor\VendorBundleResult;
use App\Extension\Vendor\VendorIntegrityChecker;
use App\Extension\Vendor\VendorMode;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 개발자 전용 composer(`custom/composer.json`·`vendor/`·`vendor-bundle.zip`, #792) 관리.
 *
 * 모듈처럼 별도 composer.json·vendor 를 둔다 — 코어 업데이트는 코어 `composer.json`·`vendor` 만 다시 설치하므로
 * Twig 같은 개발자 패키지가 사라지지 않는다. composer 가 없는 서버는 `custom/vendor-bundle.zip` 을 올려 두면
 * 설치·코어 업데이트가 풀어 준다.
 *
 * 설치된 vendor 가 현재 `custom/composer.lock` 과 맞는지는 설치 때 남기는 표식(`vendor/.g7-custom-lock.sha256`)으로
 * 판정한다 — 없거나 다르면 다시 설치한다.
 *
 * @since 7.2.0
 */
class CustomVendorService
{
    /** 설치 표식 파일 (vendor 안) */
    public const INSTALLED_MARKER = '.g7-custom-lock.sha256';

    /**
     * @param  VendorBundler  $bundler  번들 빌더
     * @param  VendorIntegrityChecker  $integrityChecker  무결성 검사기
     * @param  VendorBundleInstaller  $bundleInstaller  번들 설치기
     * @param  EnvironmentDetector  $environmentDetector  환경 감지기
     */
    public function __construct(
        private readonly VendorBundler $bundler,
        private readonly VendorIntegrityChecker $integrityChecker,
        private readonly VendorBundleInstaller $bundleInstaller,
        private readonly EnvironmentDetector $environmentDetector,
    ) {}

    /**
     * custom 디렉터리 절대 경로.
     *
     * @return string 경로
     */
    public function dir(): string
    {
        return base_path('custom');
    }

    /**
     * 개발자 composer 가 있는지 (custom/composer.json 존재).
     *
     * @return bool 있으면 true
     */
    public function hasComposer(): bool
    {
        return is_file($this->dir().DIRECTORY_SEPARATOR.'composer.json');
    }

    /**
     * vendor 번들을 빌드합니다 (`custom:vendor-bundle`).
     *
     * @param  bool  $force  강제 재빌드
     * @return VendorBundleResult 빌드 결과
     */
    public function buildBundle(bool $force = false): VendorBundleResult
    {
        return $this->bundler->buildForCustom($force);
    }

    /**
     * vendor 번들 무결성을 검사합니다 (`custom:vendor-verify`).
     *
     * @return IntegrityResult 검사 결과
     */
    public function verifyBundle(): IntegrityResult
    {
        return $this->integrityChecker->verify($this->dir());
    }

    /**
     * 설치된 vendor 가 현재 composer.lock 과 맞는지 판정합니다.
     *
     * @return bool 맞으면 true (vendor 가 없거나 표식이 다르면 false)
     */
    public function isInstalledInSync(): bool
    {
        $vendor = $this->dir().DIRECTORY_SEPARATOR.'vendor';
        $marker = $vendor.DIRECTORY_SEPARATOR.self::INSTALLED_MARKER;
        if (! is_file($vendor.DIRECTORY_SEPARATOR.'autoload.php') || ! is_file($marker)) {
            return false;
        }

        return trim((string) file_get_contents($marker)) === $this->lockHash();
    }

    /**
     * 필요할 때만 개발자 vendor 를 설치합니다 (설치기·코어 업데이트 단계).
     *
     * - custom/composer.json 이 없으면 아무 일 없음
     * - 설치본이 lock 과 맞으면 건너뜀
     * - vendor 묶음 모드(또는 auto 인데 composer 불가)면 custom/vendor-bundle.zip 을 무결성 검사 후 풀고,
     *   composer 모드면 `composer install --no-dev` 를 custom 에서 실행
     *
     * @param  VendorMode  $mode  vendor 모드
     * @return string 결과 (`skipped:no-composer`·`skipped:in-sync`·`bundled`·`composer`·`failed:{이유}`)
     */
    public function ensureInstalled(VendorMode $mode = VendorMode::Auto): string
    {
        if (! $this->hasComposer()) {
            return 'skipped:no-composer';
        }
        if ($this->isInstalledInSync()) {
            return 'skipped:in-sync';
        }

        $zip = $this->dir().DIRECTORY_SEPARATOR.VendorIntegrityChecker::ZIP_FILENAME;
        $useBundle = $mode === VendorMode::Bundled
            || ($mode === VendorMode::Auto && ! $this->environmentDetector->canExecuteComposer() && is_file($zip));

        try {
            if ($useBundle) {
                $this->bundleInstaller->install($this->dir(), $this->dir());
                $this->writeMarker();

                return 'bundled';
            }

            if (app(ExtensionManager::class)->runComposerInstallAt($this->dir())) {
                $this->writeMarker();

                return 'composer';
            }

            return 'failed:composer';
        } catch (\Throwable $e) {
            Log::warning('[custom] 개발자 vendor 설치 실패', ['error' => $e->getMessage()]);

            return 'failed:'.$e->getMessage();
        }
    }

    /**
     * 현재 composer.lock 의 sha256 (없으면 composer.json).
     *
     * @return string 해시
     */
    private function lockHash(): string
    {
        $lock = $this->dir().DIRECTORY_SEPARATOR.'composer.lock';
        $json = $this->dir().DIRECTORY_SEPARATOR.'composer.json';

        return is_file($lock) ? (string) hash_file('sha256', $lock) : (string) hash_file('sha256', $json);
    }

    /**
     * 설치 표식을 남깁니다.
     *
     * @return void 반환값 없음
     */
    private function writeMarker(): void
    {
        $vendor = $this->dir().DIRECTORY_SEPARATOR.'vendor';
        File::ensureDirectoryExists($vendor);
        File::put($vendor.DIRECTORY_SEPARATOR.self::INSTALLED_MARKER, $this->lockHash());
    }
}
