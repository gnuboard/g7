<?php

namespace App\Console\Commands\Plugin;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * 플러그인 vendor-bundle.zip 무결성을 검증합니다.
 */
class PluginVendorVerifyCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'plugin:vendor-verify
                            {identifier? : 검증할 플러그인 식별자 (생략 시 --all 필요)}
                            {--all : 모든 _bundled 플러그인 검증}';

    protected $description = '플러그인 vendor-bundle.zip 의 SHA256 무결성을 검증합니다';

    public function handle(VendorIntegrityChecker $checker): int
    {
        $identifier = $this->argument('identifier');
        $all = (bool) $this->option('all');

        if (! $identifier && ! $all) {
            $this->error('식별자 또는 --all 옵션을 지정해야 합니다.');

            return self::FAILURE;
        }

        $targets = [];
        if ($all) {
            foreach ($this->discoverBundledIdentifiers('plugin') as $id) {
                $targets[] = ['type' => 'plugin', 'identifier' => $id];
            }
        } else {
            $targets[] = ['type' => 'plugin', 'identifier' => $identifier];
        }

        return $this->runVerifyAction($checker, $targets);
    }
}
