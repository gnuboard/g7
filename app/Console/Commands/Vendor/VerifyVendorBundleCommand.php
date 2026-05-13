<?php

namespace App\Console\Commands\Vendor;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * 코어 + 모든 _bundled 모듈/플러그인 vendor 번들 일괄 검증.
 *
 * 개별 검증은 다음 명령어를 사용:
 *   - core:vendor-verify
 *   - module:vendor-verify [identifier|--all]
 *   - plugin:vendor-verify [identifier|--all]
 *
 * 본 명령어는 운영/CI 시나리오에서 한 번에 전체 번들의 무결성을 확인하기 위한 일괄 전용 명령어다.
 */
class VerifyVendorBundleCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'vendor-bundle:verify-all';

    protected $description = '코어 + 모든 _bundled 모듈/플러그인의 vendor 번들 무결성을 일괄 검증합니다';

    public function handle(VendorIntegrityChecker $checker): int
    {
        $targets = [['type' => 'core', 'identifier' => null]];

        foreach ($this->discoverBundledIdentifiers('module') as $id) {
            $targets[] = ['type' => 'module', 'identifier' => $id];
        }

        foreach ($this->discoverBundledIdentifiers('plugin') as $id) {
            $targets[] = ['type' => 'plugin', 'identifier' => $id];
        }

        return $this->runVerifyAction($checker, $targets);
    }
}
