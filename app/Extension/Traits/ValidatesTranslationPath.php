<?php

namespace App\Extension\Traits;

use Illuminate\Support\Facades\File;

/**
 * 확장(모듈/플러그인)의 언어 파일 경로 검증 기능을 제공하는 트레이트.
 *
 * 모듈 및 플러그인 설치 시 언어 파일이 올바른 경로에 있는지 검증합니다.
 *
 * 올바른 경로:
 * - 모듈: modules/{vendor-module}/src/lang/{locale}/*.php
 * - 플러그인: plugins/{vendor-plugin}/lang/{locale}/*.php
 *
 * 잘못된 경로:
 * - 모듈: modules/{vendor-module}/lang/ (src/lang 대신 lang 사용)
 */
trait ValidatesTranslationPath
{
    /**
     * 확장의 언어 파일 경로가 올바른지 검증합니다.
     *
     * @param  object  $extension  검증할 모듈 또는 플러그인 인스턴스
     * @param  string  $extensionType  확장 타입 ('module' 또는 'plugin')
     *
     * @throws \Exception 언어 파일 경로가 올바르지 않을 때
     */
    protected function validateTranslationPath(object $extension, string $extensionType = 'module'): void
    {
        $identifier = $extension->getIdentifier();

        if ($extensionType === 'module') {
            $this->validateModuleTranslationPath($identifier);
        } else {
            $this->validatePluginTranslationPath($identifier);
        }
    }

    /**
     * 모듈의 언어 파일 경로를 검증합니다.
     *
     * 올바른 경로: modules/{identifier}/src/lang
     * 잘못된 경로: modules/{identifier}/lang
     *
     * @param  string  $identifier  모듈 식별자
     *
     * @throws \Exception 언어 파일 경로가 올바르지 않을 때
     */
    protected function validateModuleTranslationPath(string $identifier): void
    {
        $basePath = base_path("modules/{$identifier}");
        $correctPath = $basePath.'/src/lang';
        $wrongPath = $basePath.'/lang';

        // 잘못된 경로에 언어 파일이 있는지 확인
        if (File::isDirectory($wrongPath) && $this->hasTranslationFiles($wrongPath)) {
            // 올바른 경로에도 파일이 있는지 확인
            if (! File::isDirectory($correctPath) || ! $this->hasTranslationFiles($correctPath)) {
                throw new \Exception(
                    __('modules.errors.invalid_translation_path', [
                        'identifier' => $identifier,
                        'wrong_path' => 'lang/',
                        'correct_path' => 'src/lang/',
                    ])
                );
            }
        }
    }

    /**
     * 플러그인의 언어 파일 경로를 검증합니다.
     *
     * 올바른 경로: plugins/{identifier}/lang
     *
     * @param  string  $identifier  플러그인 식별자
     *
     * @throws \Exception 언어 파일 경로가 올바르지 않을 때
     */
    protected function validatePluginTranslationPath(string $identifier): void
    {
        $basePath = base_path("plugins/{$identifier}");
        $correctPath = $basePath.'/lang';
        $wrongPath = $basePath.'/src/lang';

        // 잘못된 경로에 언어 파일이 있는지 확인
        if (File::isDirectory($wrongPath) && $this->hasTranslationFiles($wrongPath)) {
            // 올바른 경로에도 파일이 있는지 확인
            if (! File::isDirectory($correctPath) || ! $this->hasTranslationFiles($correctPath)) {
                throw new \Exception(
                    __('plugins.errors.invalid_translation_path', [
                        'identifier' => $identifier,
                        'wrong_path' => 'src/lang/',
                        'correct_path' => 'lang/',
                    ])
                );
            }
        }
    }

    /**
     * 디렉토리에 번역 파일이 있는지 확인합니다.
     *
     * 로케일 디렉토리(ko, en 등) 내에 .php 파일이 있는지 확인합니다.
     *
     * @param  string  $path  검사할 디렉토리 경로
     * @return bool 번역 파일 존재 여부
     */
    protected function hasTranslationFiles(string $path): bool
    {
        if (! File::isDirectory($path)) {
            return false;
        }

        $localeDirectories = File::directories($path);

        foreach ($localeDirectories as $localeDir) {
            $phpFiles = File::glob($localeDir.'/*.php');
            if (! empty($phpFiles)) {
                return true;
            }
        }

        return false;
    }
}
