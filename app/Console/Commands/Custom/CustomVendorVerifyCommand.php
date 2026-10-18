<?php

namespace App\Console\Commands\Custom;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * custom/vendor-bundle.zip 의 SHA256 무결성을 검증합니다 (#792).
 *
 * @since 7.2.0
 */
class CustomVendorVerifyCommand extends Command
{
    use RunsVendorBundleAction;

    /**
     * @var string
     */
    protected $signature = 'custom:vendor-verify';

    /**
     * @var string
     */
    protected $description = '개발자 전용 composer 묶음(custom/vendor-bundle.zip)의 SHA256 무결성을 검증합니다';

    /**
     * 명령을 실행합니다.
     *
     * @param  VendorIntegrityChecker  $checker  무결성 검사기
     * @return int 종료 코드
     */
    public function handle(VendorIntegrityChecker $checker): int
    {
        if (! is_file(base_path('custom/'.VendorIntegrityChecker::ZIP_FILENAME))) {
            $this->warn('custom/vendor-bundle.zip 이 없습니다 — `php artisan custom:vendor-bundle` 로 먼저 만드세요.');

            return self::SUCCESS;
        }

        return $this->runVerifyAction($checker, [['type' => 'custom', 'identifier' => null]]);
    }
}
