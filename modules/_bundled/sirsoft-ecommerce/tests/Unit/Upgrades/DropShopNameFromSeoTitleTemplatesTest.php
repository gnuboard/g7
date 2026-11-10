<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Psr\Log\AbstractLogger;

/**
 * 1.3.4 업그레이드 데이터 단계 — 옛 기본값 그대로인 SEO 제목 틀에서 쇼핑몰명 제거 회귀 테스트
 *
 * 운영자가 저장한 값이 옛 기본값과 글자 그대로 같을 때만 새 기본값으로 바꾸고, 고친 값은 그대로 둔다.
 * 실제 저장소를 건드리지 않도록 모듈 디스크 루트를 임시 폴더로 돌린다.
 */
class DropShopNameFromSeoTitleTemplatesTest extends ModuleTestCase
{
    private object $migration;

    private UpgradeContext $context;

    private string $root;

    /**
     * 데이터 단계를 불러오고 임시 저장 루트를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 3)
            .'/upgrades/data/1.3.4/migrations/02_DropShopNameFromSeoTitleTemplates.php';

        $class = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_3_4\\Migrations\\DropShopNameFromSeoTitleTemplates';
        $this->migration = new $class;

        $this->root = sys_get_temp_dir().'/g7-seo-title-upgrade-'.uniqid();
        File::ensureDirectoryExists($this->root.'/sirsoft-ecommerce/settings');
        Config::set('filesystems.disks.modules.root', $this->root);

        $logger = new class extends AbstractLogger
        {
            /**
             * 로그를 버립니다.
             *
             * @param  mixed  $level  수준
             * @param  string|\Stringable  $message  메시지
             * @param  array  $context  문맥
             * @return void 반환값 없음
             */
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };
        Log::shouldReceive('channel')->once()->andReturn($logger);
        $this->context = new UpgradeContext(fromVersion: '1.3.3', toVersion: '1.3.4', currentStep: '1.3.4');
        Log::clearResolvedInstance('log');
    }

    /**
     * 임시 저장 루트를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * 옛 기본값 그대로인 틀만 새 기본값으로 바꾸고 운영자가 고친 틀은 둡니다.
     *
     * @scenario case=old_default_rewritten_custom_kept
     *
     * @effects seo_title_template_old_default_rewritten_custom_kept
     *
     * @return void 반환값 없음
     */
    public function test_only_untouched_old_defaults_are_rewritten(): void
    {
        $path = $this->root.'/sirsoft-ecommerce/settings/seo.json';
        File::put($path, json_encode([
            'meta_category_title' => '{commerce_name} - {category_name}',
            'meta_search_title' => '{commerce_name} - {keyword_name}',
            'meta_product_title' => '[{commerce_name}] {product_name} 특가',
            'meta_shop_index_title' => '{commerce_name}',
            'seo_category' => true,
        ]));

        $this->migration->run($this->context);

        $saved = json_decode(File::get($path), true);
        $this->assertSame('{category_name}', $saved['meta_category_title']);
        $this->assertSame('{keyword_name}', $saved['meta_search_title']);
        $this->assertSame('[{commerce_name}] {product_name} 특가', $saved['meta_product_title']);
        $this->assertSame('{commerce_name}', $saved['meta_shop_index_title']);
        $this->assertTrue($saved['seo_category']);

        // 다시 돌아도 같은 결과 (멱등)
        $this->migration->run($this->context);
        $this->assertSame($saved, json_decode(File::get($path), true));
    }

    /**
     * 저장 파일이 없으면 아무것도 만들지 않습니다 (새 기본값이 그대로 쓰인다).
     *
     * @scenario case=settings_file_absent
     *
     * @effects seo_title_template_absent_file_untouched
     *
     * @return void 반환값 없음
     */
    public function test_absent_settings_file_is_left_alone(): void
    {
        $this->assertSame('DropShopNameFromSeoTitleTemplates', $this->migration->name());

        $this->migration->run($this->context);

        $this->assertFileDoesNotExist($this->root.'/sirsoft-ecommerce/settings/seo.json');
    }
}
