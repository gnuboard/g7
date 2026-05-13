<?php

namespace Tests\Unit\Extension;

use App\Extension\AbstractPlugin;
use Tests\TestCase;

/**
 * AbstractPlugin 설정 관련 메서드 테스트
 *
 * hasSettings(), getSettingsLayout(), getSettingsSchema() 메서드를 테스트합니다.
 */
class AbstractPluginSettingsTest extends TestCase
{
    // ========================================================================
    // hasSettings() 테스트
    // ========================================================================

    /**
     * 설정 레이아웃 파일이 있으면 true 반환
     */
    public function test_hasSettings_returns_true_when_layout_exists(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }

            public function getSettingsLayout(): ?string
            {
                // 실제 존재하는 파일 경로 반환
                return base_path('plugins/sirsoft-daum_postcode/resources/layouts/settings.json');
            }
        };

        // settings.json 파일이 존재하는지 먼저 확인
        $layoutPath = base_path('plugins/sirsoft-daum_postcode/resources/layouts/settings.json');
        if (! file_exists($layoutPath)) {
            $this->markTestSkipped('Daum postcode plugin settings.json not found');
        }

        $this->assertTrue($plugin->hasSettings());
    }

    /**
     * 설정 레이아웃 파일이 없으면 false 반환
     */
    public function test_hasSettings_returns_false_when_layout_not_exists(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }

            public function getSettingsLayout(): ?string
            {
                return null;
            }
        };

        $this->assertFalse($plugin->hasSettings());
    }

    // ========================================================================
    // getSettingsLayout() 테스트
    // ========================================================================

    /**
     * 기본 설정 레이아웃 경로 반환 테스트 (실제 플러그인)
     */
    public function test_getSettingsLayout_returns_path_when_file_exists(): void
    {
        $layoutPath = base_path('plugins/sirsoft-daum_postcode/resources/layouts/settings.json');

        if (! file_exists($layoutPath)) {
            $this->markTestSkipped('Daum postcode plugin settings.json not found');
        }

        // 실제 Daum postcode 플러그인이 존재하면 테스트
        $pluginPath = base_path('plugins/sirsoft-daum_postcode');
        if (! is_dir($pluginPath)) {
            $this->markTestSkipped('Daum postcode plugin directory not found');
        }

        // Mock plugin that returns the actual path
        $plugin = new class($pluginPath) extends AbstractPlugin
        {
            private string $testPluginPath;

            public function __construct(string $pluginPath)
            {
                $this->testPluginPath = $pluginPath;
            }

            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }

            protected function getPluginPath(): string
            {
                return $this->testPluginPath;
            }
        };

        $result = $plugin->getSettingsLayout();

        $this->assertNotNull($result);
        $this->assertStringEndsWith('settings.json', $result);
        $this->assertTrue(file_exists($result));
    }

    /**
     * 설정 레이아웃 파일이 없으면 null 반환
     */
    public function test_getSettingsLayout_returns_null_when_file_not_exists(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }

            protected function getPluginPath(): string
            {
                return '/nonexistent/path';
            }
        };

        $result = $plugin->getSettingsLayout();

        $this->assertNull($result);
    }

    /**
     * 커스텀 레이아웃 경로 오버라이드 테스트
     */
    public function test_getSettingsLayout_can_be_overridden(): void
    {
        $customPath = '/custom/path/to/settings.json';

        $plugin = new class($customPath) extends AbstractPlugin
        {
            private string $customLayoutPath;

            public function __construct(string $customLayoutPath)
            {
                $this->customLayoutPath = $customLayoutPath;
            }

            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }

            public function getSettingsLayout(): ?string
            {
                return $this->customLayoutPath;
            }
        };

        $result = $plugin->getSettingsLayout();

        $this->assertEquals($customPath, $result);
    }

    // ========================================================================
    // getSettingsSchema() 테스트
    // ========================================================================

    /**
     * 기본 설정 스키마는 빈 배열 반환
     */
    public function test_getSettingsSchema_returns_empty_array_by_default(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }
        };

        $result = $plugin->getSettingsSchema();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 커스텀 설정 스키마 오버라이드 테스트
     */
    public function test_getSettingsSchema_can_be_overridden(): void
    {
        $plugin = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Test Plugin';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Test Description';
            }

            public function getSettingsSchema(): array
            {
                return [
                    'api_key' => [
                        'type' => 'string',
                        'label' => ['ko' => 'API 키', 'en' => 'API Key'],
                        'sensitive' => true,
                        'required' => true,
                    ],
                    'timeout' => [
                        'type' => 'integer',
                        'label' => ['ko' => '타임아웃', 'en' => 'Timeout'],
                        'default' => 30,
                    ],
                    'enabled' => [
                        'type' => 'boolean',
                        'label' => ['ko' => '활성화', 'en' => 'Enabled'],
                        'default' => true,
                    ],
                    'mode' => [
                        'type' => 'enum',
                        'options' => ['popup', 'layer'],
                        'label' => ['ko' => '모드', 'en' => 'Mode'],
                        'default' => 'layer',
                    ],
                ];
            }
        };

        $result = $plugin->getSettingsSchema();

        $this->assertIsArray($result);
        $this->assertCount(4, $result);

        // api_key 필드 검증
        $this->assertArrayHasKey('api_key', $result);
        $this->assertEquals('string', $result['api_key']['type']);
        $this->assertTrue($result['api_key']['sensitive']);
        $this->assertTrue($result['api_key']['required']);

        // timeout 필드 검증
        $this->assertArrayHasKey('timeout', $result);
        $this->assertEquals('integer', $result['timeout']['type']);
        $this->assertEquals(30, $result['timeout']['default']);

        // enabled 필드 검증
        $this->assertArrayHasKey('enabled', $result);
        $this->assertEquals('boolean', $result['enabled']['type']);

        // mode 필드 검증 (enum)
        $this->assertArrayHasKey('mode', $result);
        $this->assertEquals('enum', $result['mode']['type']);
        $this->assertEquals(['popup', 'layer'], $result['mode']['options']);
    }

    /**
     * Daum 우편번호 플러그인의 실제 설정 스키마 테스트
     */
    public function test_daum_postcode_plugin_settings_schema(): void
    {
        // _bundled 디렉토리 우선 사용 (테스트 환경에서는 _bundled 기준으로 실행)
        $pluginFile = base_path('plugins/_bundled/sirsoft-daum_postcode/plugin.php');

        if (! file_exists($pluginFile)) {
            $this->markTestSkipped('Daum postcode plugin not found in _bundled');
        }

        // 이미 bootstrap에서 로드되었을 수 있으므로 class_exists 가드 사용
        if (! class_exists('Plugins\\Sirsoft\\DaumPostcode\\Plugin', false)) {
            require_once $pluginFile;
        }

        $pluginClass = 'Plugins\\Sirsoft\\DaumPostcode\\PostcodePlugin';

        if (! class_exists($pluginClass)) {
            $this->markTestSkipped('PostcodePlugin class not found');
        }

        $plugin = new $pluginClass;
        $schema = $plugin->getSettingsSchema();

        $this->assertIsArray($schema);

        // Daum 우편번호 플러그인의 설정 스키마 필드 확인
        $this->assertArrayHasKey('display_mode', $schema);
        $this->assertEquals('enum', $schema['display_mode']['type']);
        $this->assertEquals(['popup', 'layer'], $schema['display_mode']['options']);

        $this->assertArrayHasKey('popup_width', $schema);
        $this->assertEquals('integer', $schema['popup_width']['type']);

        $this->assertArrayHasKey('popup_height', $schema);
        $this->assertEquals('integer', $schema['popup_height']['type']);

        $this->assertArrayHasKey('theme_color', $schema);
        $this->assertEquals('string', $schema['theme_color']['type']);
    }

    // ========================================================================
    // 통합 테스트
    // ========================================================================

    /**
     * hasSettings()와 getSettingsLayout() 연동 테스트
     */
    public function test_hasSettings_uses_getSettingsLayout(): void
    {
        // getSettingsLayout이 값을 반환하면 hasSettings가 true
        $pluginWithLayout = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Plugin With Layout';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Description';
            }

            public function getSettingsLayout(): ?string
            {
                return '/some/path/settings.json';
            }
        };

        $this->assertTrue($pluginWithLayout->hasSettings());

        // getSettingsLayout이 null을 반환하면 hasSettings가 false
        $pluginWithoutLayout = new class extends AbstractPlugin
        {
            public function getName(): string|array
            {
                return 'Plugin Without Layout';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getDescription(): string|array
            {
                return 'Description';
            }

            public function getSettingsLayout(): ?string
            {
                return null;
            }
        };

        $this->assertFalse($pluginWithoutLayout->hasSettings());
    }
}
