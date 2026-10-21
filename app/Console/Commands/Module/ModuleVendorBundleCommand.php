<?php

namespace App\Console\Commands\Module;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorBundler;
use Illuminate\Console\Command;

/**
 * 모듈 vendor/ 디렉토리를 vendor-bundle.zip 으로 빌드합니다.
 *
 * 기존 module:build 패턴과 동일하게 positional identifier + --all 옵션 지원.
 */
class ModuleVendorBundleCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'module:vendor-bundle
                            {identifier? : 빌드할 모듈 식별자 (생략 시 --all 필요)}
                            {--all : 모든 _bundled 모듈 빌드}
                            {--force : 해시 체크 무시, 강제 재빌드}
                            {--check : 실제 빌드 없이 stale 여부만 확인}';

    protected $description = '모듈 vendor/ 디렉토리를 vendor-bundle.zip 으로 빌드합니다 (공유 호스팅용)';

    /**
     * 지정 모듈 또는 모든 _bundled 모듈의 vendor 번들을 빌드하거나 stale 여부를 확인합니다.
     *
     * @param  VendorBundler  $bundler  vendor-bundle.zip 빌더
     * @return int 종료 코드
     */
    public function handle(VendorBundler $bundler): int
    {
        $identifier = $this->argument('identifier');
        $all = (bool) $this->option('all');

        if (! $identifier && ! $all) {
            $this->error(__('modules.commands.vendor_bundle.missing_identifier'));
            $this->line(__('modules.commands.vendor_bundle.usage'));
            $this->line('  php artisan module:vendor-bundle sirsoft-ecommerce');
            $this->line('  php artisan module:vendor-bundle --all');

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

        return $this->runBuildAction(
            $bundler,
            $targets,
            (bool) $this->option('force'),
            (bool) $this->option('check'),
        );
    }
}
