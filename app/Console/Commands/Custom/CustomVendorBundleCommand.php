<?php

namespace App\Console\Commands\Custom;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorBundler;
use Illuminate\Console\Command;

/**
 * 개발자 전용 composer(`custom/`)의 vendor 를 custom/vendor-bundle.zip 으로 빌드합니다 (#792).
 *
 * composer 를 쓸 수 없는 서버는 이 묶음을 올려 두면 설치·코어 업데이트가 풀어 준다.
 *
 * @since 7.2.0
 */
class CustomVendorBundleCommand extends Command
{
    use LocalizesDescription;
    use RunsVendorBundleAction;

    /**
     * @var string
     */
    protected $signature = 'custom:vendor-bundle
                            {--force : 해시 체크 무시, 강제 재빌드}
                            {--check : 실제 빌드 없이 stale 여부만 확인}';

    /**
     * 명령을 실행합니다.
     *
     * @param  VendorBundler  $bundler  번들 빌더
     * @return int 종료 코드
     */
    public function handle(VendorBundler $bundler): int
    {
        if (! is_file(base_path('custom/composer.json'))) {
            $this->warn(__('settings.custom_reserved.vendor.no_composer_json'));

            return self::SUCCESS;
        }

        return $this->runBuildAction(
            $bundler,
            [['type' => 'custom', 'identifier' => null]],
            (bool) $this->option('force'),
            (bool) $this->option('check'),
        );
    }
}
