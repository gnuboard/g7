<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class LicenseService
{
    /**
     * 코어 라이선스 파일 내용을 반환합니다.
     *
     * @return string|null 라이선스 내용 (파일이 없으면 null)
     */
    public function getCoreLicense(): ?string
    {
        $licensePath = base_path('LICENSE');

        if (! File::exists($licensePath)) {
            return null;
        }

        return File::get($licensePath);
    }

    /**
     * 코어 CHANGELOG.md 파일 내용을 반환합니다.
     *
     * @return string|null 변경사항 내용 (파일이 없으면 null)
     */
    public function getCoreChangelog(): ?string
    {
        $changelogPath = base_path('CHANGELOG.md');

        if (! File::exists($changelogPath)) {
            return null;
        }

        return File::get($changelogPath);
    }

    /**
     * 확장(모듈/플러그인/템플릿)의 라이선스 파일 내용을 반환합니다.
     *
     * 활성 디렉토리에서 먼저 찾고, 없으면 _bundled 디렉토리에서 찾습니다.
     *
     * @param string $type 확장 타입 (modules, plugins, templates)
     * @param string $identifier 확장 식별자
     * @return string|null 라이선스 내용 (파일이 없으면 null)
     */
    public function getExtensionLicense(string $type, string $identifier): ?string
    {
        $licensePath = base_path("{$type}/{$identifier}/LICENSE");

        if (! File::exists($licensePath)) {
            $licensePath = base_path("{$type}/_bundled/{$identifier}/LICENSE");
        }

        if (! File::exists($licensePath)) {
            return null;
        }

        return File::get($licensePath);
    }
}
