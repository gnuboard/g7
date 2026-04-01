<?php

namespace Tests\Helpers;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\StorageInterface;
use Mockery;

/**
 * 확장 시스템 Mock 객체 생성 헬퍼 Trait
 *
 * Module, Plugin, Storage 등의 Mock 객체를 일관되게 생성합니다.
 * 빌더 패턴으로 필요한 부분만 커스터마이징 가능합니다.
 *
 * 사용 예시:
 * ```php
 * $module = $this->createMockModule([
 *     'identifier' => 'test-module',
 *     'configValues' => ['api_key' => 'test'],
 *     'storage' => ['exists' => true, 'get' => '{"key":"value"}'],
 * ]);
 * ```
 */
trait MocksExtensions
{
    /**
     * Module Mock 객체를 생성합니다.
     *
     * @param  array  $config  설정 옵션
     *                         - identifier: 모듈 식별자 (기본: test-module-{uniqid})
     *                         - configValues: getConfigValues() 반환값
     *                         - settingsSchema: getSettingsSchema() 반환값
     *                         - settingsDefaultsPath: getSettingsDefaultsPath() 반환값
     *                         - withStorage: getStorage() 메서드 추가 여부 (기본: true)
     *                         - storage: StorageInterface Mock 설정 (배열)
     * @return ModuleInterface Mock 객체
     */
    protected function createMockModule(array $config = []): ModuleInterface
    {
        $mock = Mockery::mock(ModuleInterface::class);

        // 기본 메서드: getIdentifier
        $identifier = $config['identifier'] ?? 'test-module-'.uniqid();
        $mock->shouldReceive('getIdentifier')
            ->andReturn($identifier);

        // getConfigValues
        if (isset($config['configValues'])) {
            $mock->shouldReceive('getConfigValues')
                ->andReturn($config['configValues']);
        }

        // getSettingsSchema
        if (isset($config['settingsSchema'])) {
            $mock->shouldReceive('getSettingsSchema')
                ->andReturn($config['settingsSchema']);
        } else {
            // 기본값: 빈 스키마
            $mock->shouldReceive('getSettingsSchema')
                ->andReturn([]);
        }

        // getSettingsDefaultsPath
        if (array_key_exists('settingsDefaultsPath', $config)) {
            $mock->shouldReceive('getSettingsDefaultsPath')
                ->andReturn($config['settingsDefaultsPath']);
        }

        // hasSettings
        if (isset($config['hasSettings'])) {
            $mock->shouldReceive('hasSettings')
                ->andReturn($config['hasSettings']);
        }

        // getStorage - StorageInterface Mock 자동 생성
        if ($config['withStorage'] ?? true) {
            $storageMock = $this->createMockStorage(
                array_merge(
                    ['identifier' => $identifier, 'type' => 'modules'],
                    $config['storage'] ?? []
                )
            );
            $mock->shouldReceive('getStorage')
                ->andReturn($storageMock);
        }

        return $mock;
    }

    /**
     * Plugin Mock 객체를 생성합니다.
     *
     * @param  array  $config  설정 옵션
     *                         - identifier: 플러그인 식별자 (기본: test-plugin-{uniqid})
     *                         - configValues: getConfigValues() 반환값
     *                         - settingsSchema: getSettingsSchema() 반환값
     *                         - withStorage: getStorage() 메서드 추가 여부 (기본: true)
     *                         - storage: StorageInterface Mock 설정 (배열)
     * @return PluginInterface Mock 객체
     */
    protected function createMockPlugin(array $config = []): PluginInterface
    {
        $mock = Mockery::mock(PluginInterface::class);

        // 기본 메서드: getIdentifier
        $identifier = $config['identifier'] ?? 'test-plugin-'.uniqid();
        $mock->shouldReceive('getIdentifier')
            ->andReturn($identifier);

        // getConfigValues
        if (isset($config['configValues'])) {
            $mock->shouldReceive('getConfigValues')
                ->andReturn($config['configValues']);
        }

        // getSettingsSchema
        if (isset($config['settingsSchema'])) {
            $mock->shouldReceive('getSettingsSchema')
                ->andReturn($config['settingsSchema']);
        } else {
            // 기본값: 빈 스키마
            $mock->shouldReceive('getSettingsSchema')
                ->andReturn([]);
        }

        // hasSettings
        if (isset($config['hasSettings'])) {
            $mock->shouldReceive('hasSettings')
                ->andReturn($config['hasSettings']);
        }

        // getSettingsDefaultsPath
        if (array_key_exists('settingsDefaultsPath', $config)) {
            $mock->shouldReceive('getSettingsDefaultsPath')
                ->andReturn($config['settingsDefaultsPath']);
        }

        // getSettingsLayout
        if (isset($config['settingsLayout'])) {
            $mock->shouldReceive('getSettingsLayout')
                ->andReturn($config['settingsLayout']);
        }

        // getStorage - StorageInterface Mock 자동 생성
        if ($config['withStorage'] ?? true) {
            $storageMock = $this->createMockStorage(
                array_merge(
                    ['identifier' => $identifier, 'type' => 'plugins'],
                    $config['storage'] ?? []
                )
            );
            $mock->shouldReceive('getStorage')
                ->andReturn($storageMock);
        }

        return $mock;
    }

    /**
     * StorageInterface Mock 객체를 생성합니다.
     *
     * @param  array  $config  설정 옵션
     *                         - identifier: 확장 식별자
     *                         - type: 'modules' 또는 'plugins'
     *                         - disk: getDisk() 반환값 (기본: 'local')
     *                         - exists: exists() 반환값 (기본: false)
     *                         - get: get() 반환값 (기본: null)
     *                         - put: put() 반환값 (기본: true)
     *                         - delete: delete() 반환값 (기본: true)
     *                         - deleteDirectory: deleteDirectory() 반환값 (기본: true)
     *                         - deleteAll: deleteAll() 반환값 (기본: true)
     *                         - url: url() 반환값 (기본: null)
     *                         - files: files() 반환값 (기본: [])
     *                         - getBasePath: getBasePath() 반환값 (자동 생성)
     * @return StorageInterface Mock 객체
     */
    protected function createMockStorage(array $config = []): StorageInterface
    {
        $mock = Mockery::mock(StorageInterface::class);

        $disk = $config['disk'] ?? 'local';
        $identifier = $config['identifier'] ?? 'test-extension';
        $type = $config['type'] ?? 'modules';

        // getDisk
        $mock->shouldReceive('getDisk')
            ->andReturn($disk);

        // exists
        if (isset($config['exists'])) {
            $mock->shouldReceive('exists')
                ->andReturn($config['exists']);
        } else {
            // 기본: 항상 false 반환
            $mock->shouldReceive('exists')
                ->andReturn(false);
        }

        // get
        if (isset($config['get'])) {
            $mock->shouldReceive('get')
                ->andReturn($config['get']);
        } else {
            // 기본: null 반환
            $mock->shouldReceive('get')
                ->andReturn(null);
        }

        // put
        if (isset($config['put'])) {
            $mock->shouldReceive('put')
                ->andReturn($config['put']);
        } else {
            // 기본: true 반환
            $mock->shouldReceive('put')
                ->andReturn(true);
        }

        // delete
        if (isset($config['delete'])) {
            $mock->shouldReceive('delete')
                ->andReturn($config['delete']);
        } else {
            $mock->shouldReceive('delete')
                ->andReturn(true);
        }

        // deleteDirectory
        if (isset($config['deleteDirectory'])) {
            $mock->shouldReceive('deleteDirectory')
                ->andReturn($config['deleteDirectory']);
        } else {
            $mock->shouldReceive('deleteDirectory')
                ->andReturn(true);
        }

        // deleteAll
        if (isset($config['deleteAll'])) {
            $mock->shouldReceive('deleteAll')
                ->andReturn($config['deleteAll']);
        } else {
            $mock->shouldReceive('deleteAll')
                ->andReturn(true);
        }

        // url
        if (isset($config['url'])) {
            $mock->shouldReceive('url')
                ->andReturn($config['url']);
        } else {
            // 기본: null 반환 (private disk)
            $mock->shouldReceive('url')
                ->andReturn(null);
        }

        // files
        if (isset($config['files'])) {
            $mock->shouldReceive('files')
                ->andReturn($config['files']);
        } else {
            $mock->shouldReceive('files')
                ->andReturn([]);
        }

        // getBasePath
        if (isset($config['getBasePath'])) {
            $mock->shouldReceive('getBasePath')
                ->andReturn($config['getBasePath']);
        } else {
            // 자동 생성: storage/app/{type}/{identifier}/{category}
            $mock->shouldReceive('getBasePath')
                ->andReturnUsing(function ($category) use ($type, $identifier) {
                    return storage_path("app/{$type}/{$identifier}/{$category}");
                });
        }

        return $mock;
    }
}
