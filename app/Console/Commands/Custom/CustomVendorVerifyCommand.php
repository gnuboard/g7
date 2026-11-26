<?php

namespace App\Console\Commands\Custom;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * custom/vendor-bundle.zip 의 SHA256 무결성을 검증합니다.
 *
 * @since 7.2.0
 */
class CustomVendorVerifyCommand extends Command
{
    use LocalizesDescription;
    use RunsVendorBundleAction;

    /**
     * @var string
     */
    protected $signature = 'custom:vendor-verify';

    /**
     * 명령을 실행합니다.
     *
     * @param  VendorIntegrityChecker  $checker  무결성 검사기
     * @return int 종료 코드
     */
    public function handle(VendorIntegrityChecker $checker): int
    {
        if (! is_file(base_path('custom/'.VendorIntegrityChecker::ZIP_FILENAME))) {
            $this->warn(__('settings.custom_reserved.vendor.no_bundle_zip'));

            return self::SUCCESS;
        }

        return $this->runVerifyAction($checker, [['type' => 'custom', 'identifier' => null]]);
    }
}
