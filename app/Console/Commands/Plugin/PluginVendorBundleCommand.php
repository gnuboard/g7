<?php

namespace App\Console\Commands\Plugin;

use App\Console\Commands\Vendor\Concerns\RunsVendorBundleAction;
use App\Extension\Vendor\VendorBundler;
use Illuminate\Console\Command;

/**
 * 플러그인 vendor/ 디렉토리를 vendor-bundle.zip 으로 빌드합니다.
 *
 * 기존 plugin:build 패턴과 동일하게 positional identifier + --all 옵션 지원.
 */
class PluginVendorBundleCommand extends Command
{
    use RunsVendorBundleAction;

    protected $signature = 'plugin:vendor-bundle
                            {identifier? : 빌드할 플러그인 식별자 (생략 시 --all 필요)}
                            {--all : 모든 _bundled 플러그인 빌드}
                            {--force : 해시 체크 무시, 강제 재빌드}
                            {--check : 실제 빌드 없이 stale 여부만 확인}';

    protected $description = '플러그인 vendor/ 디렉토리를 vendor-bundle.zip 으로 빌드합니다 (공유 호스팅용)';

    /**
     * 지정 플러그인 또는 모든 _bundled 플러그인의 vendor 번들을 빌드하거나 stale 여부를 확인합니다.
     *
     * @param  VendorBundler  $bundler  vendor-bundle.zip 빌더
     * @return int 종료 코드
     */
    public function handle(VendorBundler $bundler): int
    {
        $identifier = $this->argument('identifier');
        $all = (bool) $this->option('all');

        if (! $identifier && ! $all) {
            $this->error(__('plugins.commands.vendor_bundle.missing_identifier'));
            $this->line(__('plugins.commands.vendor_bundle.usage'));
            $this->line('  php artisan plugin:vendor-bundle sirsoft-payment');
            $this->line('  php artisan plugin:vendor-bundle --all');

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

        return $this->runBuildAction(
            $bundler,
            $targets,
            (bool) $this->option('force'),
            (bool) $this->option('check'),
        );
    }
}
