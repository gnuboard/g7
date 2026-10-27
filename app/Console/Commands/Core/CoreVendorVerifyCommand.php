<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * 코어 vendor-bundle.zip 무결성을 검증합니다.
 */
class CoreVendorVerifyCommand extends Command
{
    use LocalizesDescription;
    use RunsVendorBundleAction;

    protected $signature = 'core:vendor-verify';

    /**
     * 코어 vendor 번들의 무결성을 검증한다.
     *
     * @param  VendorIntegrityChecker  $checker  무결성 검사기
     * @return int 종료 코드
     */
    public function handle(VendorIntegrityChecker $checker): int
    {
        $targets = [['type' => 'core', 'identifier' => null]];

        return $this->runVerifyAction($checker, $targets);
    }
}
