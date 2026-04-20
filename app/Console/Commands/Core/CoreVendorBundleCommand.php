<?php

namespace App\Console\Commands\Core;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorBundler;
use Illuminate\Console\Command;

/**
 * 코어 vendor/ 디렉토리를 vendor-bundle.zip 으로 빌드합니다.
 *
 * 기존 *:build 패턴과 일관된 코어 단독 vendor 번들 빌드 명령어.
 */
class CoreVendorBundleCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'core:vendor-bundle
                            {--force : 해시 체크 무시, 강제 재빌드}
                            {--check : 실제 빌드 없이 stale 여부만 확인}';

    protected $description = '코어 vendor/ 디렉토리를 vendor-bundle.zip 으로 빌드합니다 (공유 호스팅용)';

    public function handle(VendorBundler $bundler): int
    {
        $targets = [['type' => 'core', 'identifier' => null]];

        return $this->runBuildAction(
            $bundler,
            $targets,
            (bool) $this->option('force'),
            (bool) $this->option('check'),
        );
    }
}
