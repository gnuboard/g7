<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * 코어 vendor-bundle.zip 무결성을 검증합니다.
 */
class CoreVendorVerifyCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'core:vendor-verify';

    protected $description = '코어 vendor-bundle.zip 의 SHA256 무결성을 검증합니다';

    public function handle(VendorIntegrityChecker $checker): int
    {
        $targets = [['type' => 'core', 'identifier' => null]];

        return $this->runVerifyAction($checker, $targets);
    }
}
