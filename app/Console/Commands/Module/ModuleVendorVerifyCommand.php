<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorIntegrityChecker;
use Illuminate\Console\Command;

/**
 * 모듈 vendor-bundle.zip 무결성을 검증합니다.
 */
class ModuleVendorVerifyCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'module:vendor-verify
                            {identifier? : 검증할 모듈 식별자 (생략 시 --all 필요)}
                            {--all : 모든 _bundled 모듈 검증}';

    protected $description = '모듈 vendor-bundle.zip 의 SHA256 무결성을 검증합니다';

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
            foreach ($this->discoverBundledIdentifiers('module') as $id) {
                $targets[] = ['type' => 'module', 'identifier' => $id];
            }
        } else {
            $targets[] = ['type' => 'module', 'identifier' => $identifier];
        }

        return $this->runVerifyAction($checker, $targets);
    }
}
