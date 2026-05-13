<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * ZIP 추출 + manifest 검증 공통 유틸리티.
 *
 * 확장 수동 설치(ModuleService, PluginService, TemplateService)의 ZIP 처리 중복을 제거하고,
 * 코어에서도 ZIP 추출 시 재사용할 수 있는 공통 메서드를 제공합니다.
 */
class ZipInstallHelper
{
    /**
     * ZIP 파일을 추출합니다.
     *
     * @param string $zipPath ZIP 파일 경로
     * @param string $extractPath 추출 대상 디렉토리
     * @return void
     *
     * @throws \RuntimeException ZIP 파일 열기/추출 실패 시
     */
    public static function extractZip(string $zipPath, string $extractPath): void
    {
        if (! file_exists($zipPath)) {
            throw new \RuntimeException(__('common.errors.zip_file_not_found'));
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException(__('common.errors.zip_open_failed'));
        }

        File::ensureDirectoryExists($extractPath);
        $zip->extractTo($extractPath);
        $zip->close();
    }

    /**
     * ZIP 파일을 추출하고 manifest를 찾아 검증합니다.
     *
     * extractZip + findAndValidateManifest를 한번에 수행하는 편의 메서드입니다.
     *
     * @param string $zipPath ZIP 파일 경로
     * @param string $extractPath 추출 대상 디렉토리
     * @param string $manifestName manifest 파일명 (module.json, plugin.json, template.json)
     * @param string $extensionType 확장 타입 (modules, plugins, templates)
     * @return array{identifier: string, config: array, sourcePath: string}
     *
     * @throws \RuntimeException 추출 또는 검증 실패 시
     */
    public static function extractAndValidate(
        string $zipPath,
        string $extractPath,
        string $manifestName,
        string $extensionType
    ): array {
        static::extractZip($zipPath, $extractPath);

        return static::findAndValidateManifest($extractPath, $manifestName, $extensionType);
    }

    /**
     * 추출된 디렉토리에서 manifest를 찾고 검증합니다.
     *
     * @param string $extractPath 추출된 디렉토리 경로
     * @param string $manifestName manifest 파일명 (module.json, plugin.json, template.json)
     * @param string $extensionType 확장 타입 (modules, plugins, templates)
     * @return array{identifier: string, config: array, sourcePath: string}
     *
     * @throws \RuntimeException manifest를 찾을 수 없거나 유효하지 않은 경우
     */
    public static function findAndValidateManifest(
        string $extractPath,
        string $manifestName,
        string $extensionType
    ): array {
        $manifestPath = static::findManifest($extractPath, $manifestName);

        if (! $manifestPath) {
            // 확장 타입별 에러 메시지 키 생성 (modules → module, plugins → plugin, templates → template)
            $typeKey = rtrim($extensionType, 's');
            throw new \RuntimeException(__("{$extensionType}.errors.{$typeKey}_json_not_found"));
        }

        $config = json_decode(File::get($manifestPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $typeKey = rtrim($extensionType, 's');
            throw new \RuntimeException(__("{$extensionType}.errors.{$typeKey}_json_invalid"));
        }

        $identifier = $config['identifier'] ?? null;

        if (! $identifier) {
            throw new \RuntimeException(__("{$extensionType}.errors.identifier_missing"));
        }

        return [
            'identifier' => $identifier,
            'config' => $config,
            'sourcePath' => dirname($manifestPath),
        ];
    }

    /**
     * manifest 파일을 디렉토리에서 찾습니다 (루트 + 1단계 하위).
     *
     * GitHub 아카이브는 owner-repo-hash/ 구조이므로 1단계 하위 검색이 필수입니다.
     *
     * @param string $directory 검색할 디렉토리
     * @param string $manifestName manifest 파일명
     * @return string|null manifest 파일 경로 또는 null
     */
    public static function findManifest(string $directory, string $manifestName): ?string
    {
        // 루트에 있는 경우
        $rootPath = $directory.'/'.$manifestName;
        if (File::exists($rootPath)) {
            return $rootPath;
        }

        // 하위 디렉토리 검색 (1단계만)
        $directories = File::directories($directory);
        foreach ($directories as $subDir) {
            $subPath = $subDir.'/'.$manifestName;
            if (File::exists($subPath)) {
                return $subPath;
            }
        }

        return null;
    }

    /**
     * 추출된 소스를 _pending 디렉토리로 이동합니다.
     *
     * @param string $sourcePath 소스 디렉토리 경로
     * @param string $pendingBasePath _pending 기본 경로 (예: base_path('modules/_pending'))
     * @param string $identifier 확장 식별자
     * @return string 이동된 _pending 경로
     */
    public static function moveToPending(
        string $sourcePath,
        string $pendingBasePath,
        string $identifier
    ): string {
        $pendingTargetPath = $pendingBasePath.'/'.$identifier;

        if (File::exists($pendingTargetPath)) {
            File::deleteDirectory($pendingTargetPath);
        }

        File::ensureDirectoryExists($pendingBasePath);
        File::moveDirectory($sourcePath, $pendingTargetPath);

        return $pendingTargetPath;
    }
}
