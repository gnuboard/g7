<?php

namespace Tests\Unit\Extension;

use App\Extension\Helpers\SettingsMigrator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SettingsMigratorTest extends TestCase
{
    private string $moduleSettingsDir;

    private string $pluginSettingsDir;

    private string $moduleDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->moduleSettingsDir = storage_path('app/modules/test-migrator-mod/settings');
        $this->pluginSettingsDir = storage_path('app/plugins/test-migrator-plug/settings');
        $this->moduleDir = base_path('modules/test-migrator-mod');

        // 모듈 설정 디렉토리 및 파일 생성
        File::ensureDirectoryExists($this->moduleSettingsDir);
        File::put($this->moduleSettingsDir.'/basic.json', json_encode([
            'shop_name' => 'Test Shop',
            'email' => 'test@example.com',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        File::put($this->moduleSettingsDir.'/shipping.json', json_encode([
            'default_country' => 'KR',
            'remote_area_fee' => 3000,
            'nested' => ['deep' => ['value' => 'original']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 플러그인 설정 디렉토리 및 파일 생성
        File::ensureDirectoryExists($this->pluginSettingsDir);
        File::put($this->pluginSettingsDir.'/setting.json', json_encode([
            'api_key' => '',
            'sandbox_mode' => true,
            'webhook_url' => 'https://example.com/hook',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // defaults.json 생성 (addCategory 테스트용)
        File::ensureDirectoryExists($this->moduleDir.'/config/settings');
        File::put($this->moduleDir.'/config/settings/defaults.json', json_encode([
            '_meta' => [
                'version' => '1.0.0',
                'categories' => ['basic', 'shipping'],
            ],
            'defaults' => [
                'basic' => ['shop_name' => '', 'email' => ''],
                'shipping' => ['default_country' => 'KR'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function tearDown(): void
    {
        if (File::isDirectory(storage_path('app/modules/test-migrator-mod'))) {
            File::deleteDirectory(storage_path('app/modules/test-migrator-mod'));
        }

        if (File::isDirectory(storage_path('app/plugins/test-migrator-plug'))) {
            File::deleteDirectory(storage_path('app/plugins/test-migrator-plug'));
        }

        if (File::isDirectory($this->moduleDir)) {
            File::deleteDirectory($this->moduleDir);
        }

        parent::tearDown();
    }

    // ========================================================================
    // addField 테스트
    // ========================================================================

    /**
     * 모듈에서 새 필드가 추가되는지 확인합니다.
     */
    public function test_add_field_creates_new_field_for_module(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addField('basic.phone', '010-0000-0000')
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/basic.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEquals('010-0000-0000', $data['phone']);
    }

    /**
     * 이미 존재하는 필드는 스킵되는지 확인합니다 (사용자 값 보존).
     */
    public function test_add_field_skips_existing_field(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addField('basic.shop_name', 'New Default')
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/basic.json'), true);

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertEquals('Test Shop', $data['shop_name']); // 기존 값 유지
    }

    /**
     * 플러그인에서 새 필드가 추가되는지 확인합니다.
     */
    public function test_add_field_creates_new_field_for_plugin(): void
    {
        $result = SettingsMigrator::forPlugin('test-migrator-plug')
            ->addField('timeout', 30)
            ->apply();

        $data = json_decode(File::get($this->pluginSettingsDir.'/setting.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertEquals('', $data['api_key']); // 기존 필드 유지
        $this->assertEquals(30, $data['timeout']); // 새 필드 추가
    }

    /**
     * 플러그인에서 이미 존재하는 필드는 스킵됩니다.
     */
    public function test_add_field_skips_existing_field_for_plugin(): void
    {
        $result = SettingsMigrator::forPlugin('test-migrator-plug')
            ->addField('sandbox_mode', false)
            ->apply();

        $data = json_decode(File::get($this->pluginSettingsDir.'/setting.json'), true);

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(1, $result['skipped']);
        $this->assertTrue($data['sandbox_mode']); // 기존 값 유지
    }

    // ========================================================================
    // renameField 테스트
    // ========================================================================

    /**
     * 필드 이름이 변경되는지 확인합니다.
     */
    public function test_rename_field_moves_value(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->renameField('shipping.remote_area_fee', 'shipping.remote_extra_fee')
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/shipping.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertArrayNotHasKey('remote_area_fee', $data);
        $this->assertEquals(3000, $data['remote_extra_fee']);
    }

    /**
     * 존재하지 않는 필드의 이름 변경은 스킵됩니다.
     */
    public function test_rename_field_skips_nonexistent(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->renameField('shipping.nonexistent', 'shipping.new_field')
            ->apply();

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(1, $result['skipped']);
    }

    /**
     * 플러그인에서 필드 이름이 변경됩니다.
     */
    public function test_rename_field_for_plugin(): void
    {
        $result = SettingsMigrator::forPlugin('test-migrator-plug')
            ->renameField('webhook_url', 'callback_url')
            ->apply();

        $data = json_decode(File::get($this->pluginSettingsDir.'/setting.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertArrayNotHasKey('webhook_url', $data);
        $this->assertEquals('https://example.com/hook', $data['callback_url']);
    }

    // ========================================================================
    // removeField 테스트
    // ========================================================================

    /**
     * 필드가 제거되는지 확인합니다.
     */
    public function test_remove_field_deletes_key(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->removeField('basic.email')
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/basic.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertEquals('Test Shop', $data['shop_name']); // 다른 필드 유지
    }

    /**
     * 존재하지 않는 필드 제거는 스킵됩니다.
     */
    public function test_remove_field_skips_nonexistent(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->removeField('basic.nonexistent')
            ->apply();

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(1, $result['skipped']);
    }

    // ========================================================================
    // transformField 테스트
    // ========================================================================

    /**
     * 필드 값이 콜백으로 변환되는지 확인합니다.
     */
    public function test_transform_field_applies_callback(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->transformField('shipping.remote_area_fee', fn ($v) => $v * 2)
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/shipping.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertEquals(6000, $data['remote_area_fee']);
    }

    /**
     * 존재하지 않는 필드의 변환은 스킵됩니다.
     */
    public function test_transform_field_skips_nonexistent(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->transformField('shipping.nonexistent', fn ($v) => $v)
            ->apply();

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(1, $result['skipped']);
    }

    /**
     * 플러그인에서 필드 변환이 동작합니다.
     */
    public function test_transform_field_for_plugin(): void
    {
        $result = SettingsMigrator::forPlugin('test-migrator-plug')
            ->transformField('sandbox_mode', fn ($v) => ! $v)
            ->apply();

        $data = json_decode(File::get($this->pluginSettingsDir.'/setting.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertFalse($data['sandbox_mode']);
    }

    // ========================================================================
    // addCategory 테스트
    // ========================================================================

    /**
     * 모듈에서 새 카테고리가 생성되는지 확인합니다.
     */
    public function test_add_category_creates_file_for_module(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addCategory('notifications', ['email_enabled' => true, 'sms_enabled' => false])
            ->apply();

        $categoryFile = $this->moduleSettingsDir.'/notifications.json';

        $this->assertEquals(1, $result['applied']);
        $this->assertFileExists($categoryFile);

        $data = json_decode(File::get($categoryFile), true);
        $this->assertTrue($data['email_enabled']);
        $this->assertFalse($data['sms_enabled']);

        // defaults.json의 categories 업데이트 확인
        $defaults = json_decode(File::get($this->moduleDir.'/config/settings/defaults.json'), true);
        $this->assertContains('notifications', $defaults['_meta']['categories']);
    }

    /**
     * 이미 존재하는 카테고리는 스킵됩니다.
     */
    public function test_add_category_skips_existing(): void
    {
        // basic.json이 이미 존재
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addCategory('basic', ['new_field' => true])
            ->apply();

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(1, $result['skipped']);

        // 기존 파일 내용 유지 확인
        $data = json_decode(File::get($this->moduleSettingsDir.'/basic.json'), true);
        $this->assertEquals('Test Shop', $data['shop_name']);
    }

    /**
     * 플러그인에서 addCategory 호출 시 예외가 발생합니다.
     */
    public function test_add_category_throws_for_plugin(): void
    {
        $result = SettingsMigrator::forPlugin('test-migrator-plug')
            ->addCategory('notifications', ['enabled' => true])
            ->apply();

        // 예외가 errors 배열에 기록됨
        $this->assertEquals(0, $result['applied']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('모듈에서만', $result['errors'][0]);
    }

    // ========================================================================
    // 중첩 dot path 테스트
    // ========================================================================

    /**
     * 중첩된 dot path로 필드가 추가됩니다.
     */
    public function test_nested_dot_path_add_field(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addField('shipping.nested.deep.new_key', 'new_value')
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/shipping.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertEquals('new_value', $data['nested']['deep']['new_key']);
        $this->assertEquals('original', $data['nested']['deep']['value']); // 기존 값 유지
    }

    /**
     * 중첩된 dot path로 필드가 제거됩니다.
     */
    public function test_nested_dot_path_remove_field(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->removeField('shipping.nested.deep.value')
            ->apply();

        $data = json_decode(File::get($this->moduleSettingsDir.'/shipping.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertArrayNotHasKey('value', $data['nested']['deep']);
    }

    /**
     * 플러그인에서 중첩된 dot path가 동작합니다.
     */
    public function test_nested_dot_path_for_plugin(): void
    {
        // 먼저 중첩 구조 추가
        File::put($this->pluginSettingsDir.'/setting.json', json_encode([
            'api_key' => '',
            'options' => ['retry' => ['count' => 3, 'delay' => 100]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $result = SettingsMigrator::forPlugin('test-migrator-plug')
            ->transformField('options.retry.count', fn ($v) => $v + 2)
            ->apply();

        $data = json_decode(File::get($this->pluginSettingsDir.'/setting.json'), true);

        $this->assertEquals(1, $result['applied']);
        $this->assertEquals(5, $data['options']['retry']['count']);
    }

    // ========================================================================
    // 복합 오퍼레이션 테스트
    // ========================================================================

    /**
     * 여러 오퍼레이션이 순서대로 실행됩니다.
     */
    public function test_multiple_operations_apply_in_order(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addField('basic.phone', '010-1234-5678')
            ->renameField('basic.email', 'basic.contact_email')
            ->removeField('shipping.remote_area_fee')
            ->transformField('shipping.default_country', fn ($v) => strtolower($v))
            ->apply();

        $this->assertEquals(4, $result['applied']);
        $this->assertEquals(0, $result['skipped']);
        $this->assertEmpty($result['errors']);

        $basic = json_decode(File::get($this->moduleSettingsDir.'/basic.json'), true);
        $this->assertEquals('010-1234-5678', $basic['phone']);
        $this->assertEquals('test@example.com', $basic['contact_email']);
        $this->assertArrayNotHasKey('email', $basic);

        $shipping = json_decode(File::get($this->moduleSettingsDir.'/shipping.json'), true);
        $this->assertArrayNotHasKey('remote_area_fee', $shipping);
        $this->assertEquals('kr', $shipping['default_country']);
    }

    /**
     * apply() 결과에 정확한 카운트가 반환됩니다.
     */
    public function test_apply_returns_accurate_counts(): void
    {
        $result = SettingsMigrator::forModule('test-migrator-mod')
            ->addField('basic.new_field', 'value')       // applied
            ->addField('basic.shop_name', 'overwrite')   // skipped (exists)
            ->removeField('basic.nonexistent')            // skipped (not found)
            ->removeField('basic.email')                  // applied
            ->apply();

        $this->assertEquals(2, $result['applied']);
        $this->assertEquals(2, $result['skipped']);
        $this->assertEmpty($result['errors']);
    }

    // ========================================================================
    // 설정 파일 미존재 시 테스트
    // ========================================================================

    /**
     * 설정 파일이 없으면 오퍼레이션이 스킵됩니다.
     */
    public function test_operations_skip_when_file_not_found(): void
    {
        $result = SettingsMigrator::forModule('nonexistent-module')
            ->addField('basic.field', 'value')
            ->renameField('basic.old', 'basic.new')
            ->removeField('basic.field')
            ->transformField('basic.field', fn ($v) => $v)
            ->apply();

        $this->assertEquals(0, $result['applied']);
        $this->assertEquals(4, $result['skipped']);
    }
}
