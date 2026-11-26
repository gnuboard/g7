<?php

namespace Plugins\Sirsoft\Popup\Tests\Unit\Support;

use Plugins\Sirsoft\Popup\Plugin;
use Plugins\Sirsoft\Popup\Services\PublicPopupService;
use Plugins\Sirsoft\Popup\Support\DisplaySettings;
use Plugins\Sirsoft\Popup\Support\UploadLimit;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 설정 범위·기본값 일치 테스트 (매니페스트 admin-popups.yaml)
 *
 * 설정 값의 범위·기본값은 설정 화면 스키마(`Plugin::getSettingsSchema()`)가 원본이고, 설치 기본값(`config/settings/defaults.json`)과
 * 읽을 때 범위를 맞추는 곳(`DisplaySettings`·`UploadLimit`·`PublicPopupService::cacheMinutes()`)이 따로 값을 들고 있다.
 * 한쪽만 고치면 설정 화면이 받은 값을 레이어가 잘라 쓰게 되므로 여기서 함께 잠근다.
 */
class SettingsParityTest extends PluginTestCase
{
    /**
     * 스키마·설치 기본값·읽는 곳의 범위와 기본값이 모두 같다.
     *
     * @scenario surface=settings_ranges_match_schema
     *
     * @return void 반환값 없음
     */
    public function test_settings_ranges_match_schema(): void
    {
        $schema = (new Plugin(['identifier' => 'sirsoft-popup', 'vendor' => 'sirsoft', 'version' => '1.0.0']))->getSettingsSchema();
        $defaults = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/settings/defaults.json'), true)['defaults'];

        $this->assertEqualsCanonicalizing(array_keys($schema), array_keys($defaults), '설치 기본값과 스키마의 키가 같다');

        foreach ($schema as $key => $field) {
            $this->assertSame($field['default'], $defaults[$key], "{$key}: 설치 기본값 = 스키마 기본값");

            $this->setPluginSettings([$key => null]);
            $this->assertSame($field['default'], $this->read($key), "{$key}: 값이 없으면 스키마 기본값");

            if ($field['type'] === 'integer') {
                $this->setPluginSettings([$key => $field['min'] - 1]);
                $this->assertSame($field['min'], $this->read($key), "{$key}: 최솟값 아래는 최솟값");
                $this->setPluginSettings([$key => $field['max'] + 1]);
                $this->assertSame($field['max'], $this->read($key), "{$key}: 최댓값 위는 최댓값");
            }

            if ($field['type'] === 'enum') {
                foreach ($field['options'] as $option) {
                    $this->setPluginSettings([$key => $option]);
                    $this->assertSame($option, $this->read($key), "{$key}: 스키마 선택지 {$option} 를 그대로 읽는다");
                }
                $this->setPluginSettings([$key => 'not-an-option']);
                $this->assertSame($field['default'], $this->read($key), "{$key}: 선택지 밖 값은 기본값");
            }
        }
    }

    /**
     * 설정 키를 실제로 쓰는 곳에서 읽습니다.
     *
     * @param  string  $key  설정 키
     * @return mixed 읽은 값
     */
    private function read(string $key): mixed
    {
        return match ($key) {
            'max_upload_mb' => UploadLimit::settingMb(),
            'public_cache_minutes' => app(PublicPopupService::class)->cacheMinutes(),
            // Plugin::getStorageDiskFor('images') 와 같은 읽기식 — 문자열이 아니면(없음) 코어 설정 따름('')
            'public_asset_disk' => is_string($value = plugin_setting('sirsoft-popup', 'public_asset_disk', '')) ? $value : '',
            default => DisplaySettings::current()[$key],
        };
    }
}
