<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Upgrade;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Modules\Sirsoft\Ecommerce\Upgrades\Upgrade_1_4_1;

/**
 * 1.4.1 업그레이드 — 쓰이지 않는 배송 설정 키 정리 (#952 P16)
 */
class Upgrade141Test extends ModuleTestCase
{
    /** 원래 있던 파일 내용 (테스트 뒤 복원) */
    private ?string $original = null;

    /**
     * 배송 설정 파일 경로를 반환합니다.
     *
     * @return string 경로
     */
    private function path(): string
    {
        return ExtensionStoragePath::module('sirsoft-ecommerce', 'settings').'/shipping.json';
    }

    /**
     * 기존 파일을 보관합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->original = File::exists($this->path()) ? File::get($this->path()) : null;
    }

    /**
     * 기존 파일을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->original !== null) {
            File::put($this->path(), $this->original);
        } elseif (File::exists($this->path())) {
            File::delete($this->path());
        }
        parent::tearDown();
    }

    /**
     * 업그레이드를 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runUpgrade(): void
    {
        (new Upgrade_1_4_1)->run(new UpgradeContext('1.4.0', '1.4.1', '1.4.1', 'extension-upgrade'));
    }

    /**
     * 두 키만 지우고 다른 배송 설정은 그대로 둔다. 다시 실행해도 같다(멱등).
     *
     * @scenario case=1_4_1_remove_keys
     *
     * @effects unused_shipping_keys_removed
     *
     * @return void 반환값 없음
     */
    public function test_removes_only_unused_keys_and_is_idempotent(): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode([
            'address_validation_enabled' => true,
            'address_api_provider' => 'daum',
            'default_country' => 'KR',
        ]));

        $this->runUpgrade();
        $after = json_decode(File::get($this->path()), true);
        $this->assertSame(['default_country' => 'KR'], $after);

        $this->runUpgrade();
        $this->assertSame(['default_country' => 'KR'], json_decode(File::get($this->path()), true));
    }

    /**
     * 파일이 없으면 만들지 않고 건너뛴다.
     *
     * @scenario case=1_4_1_no_file
     *
     * @effects unused_shipping_keys_removed
     *
     * @return void 반환값 없음
     */
    public function test_missing_file_is_skipped(): void
    {
        if (File::exists($this->path())) {
            File::delete($this->path());
        }

        $this->runUpgrade();

        $this->assertFalse(File::exists($this->path()));
    }
}
