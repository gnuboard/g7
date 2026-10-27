<?php

namespace App\Console\Commands\Vendor;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorBundler;
use Illuminate\Console\Command;

/**
 * 코어 + 모든 _bundled 모듈/플러그인 vendor 번들 일괄 빌드.
 *
 * 개별 빌드는 다음 명령어를 사용:
 *   - core:vendor-bundle
 *   - module:vendor-bundle [identifier|--all]
 *   - plugin:vendor-bundle [identifier|--all]
 *
 * 본 명령어는 운영/CI 시나리오에서 한 번에 전체 번들을 갱신하기 위한 일괄 전용 명령어다.
 */
class BuildVendorBundleCommand extends Command
{
    use LocalizesDescription;
    use RunsVendorBundleAction;

    protected $signature = 'vendor-bundle:build-all
                            {--force : 해시 체크 무시, 강제 재빌드}
                            {--check : 실제 빌드 없이 stale 여부만 확인}';

    /**
     * 코어와 모든 번들 모듈·플러그인의 vendor 번들을 빌드한다(--check 면 stale 여부만).
     *
     * @param  VendorBundler  $bundler  vendor 번들 빌더
     * @return int 종료 코드
     */
    public function handle(VendorBundler $bundler): int
    {
        $targets = [['type' => 'core', 'identifier' => null]];

        foreach ($this->discoverBundledIdentifiers('module') as $id) {
            $targets[] = ['type' => 'module', 'identifier' => $id];
        }

        foreach ($this->discoverBundledIdentifiers('plugin') as $id) {
            $targets[] = ['type' => 'plugin', 'identifier' => $id];
        }

        return $this->runBuildAction(
            $bundler,
            $targets,
            (bool) $this->option('force'),
            (bool) $this->option('check'),
        );
    }
}
