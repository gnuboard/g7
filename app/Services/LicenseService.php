<?php

namespace App\Services;

use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\InstalledExtensionLookup;
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
     * 설치된 확장은 설치본(`{type}/{identifier}/LICENSE`)만 읽는다 — 설치본에 파일이 없으면
     * 라이선스가 없는 것이다(동봉 원본으로 채우지 않는다). 설치 소스가 번들이 아닐 수 있고
     * 번들 원본과 버전이 다를 수 있기 때문이다. 미설치 확장(목록의 번들·_pending 행)만
     * 활성 → _pending → _bundled 순으로 찾는다(아이콘 해석기와 같은 순서).
     *
     * @param string $type 확장 타입 (modules, plugins, templates)
     * @param string $identifier 확장 식별자
     * @return string|null 라이선스 내용 (파일이 없으면 null)
     */
    public function getExtensionLicense(string $type, string $identifier): ?string
    {
        foreach ($this->licenseCandidateRoots($type, $identifier) as $root) {
            $licensePath = $root.DIRECTORY_SEPARATOR.'LICENSE';

            if (File::exists($licensePath)) {
                return File::get($licensePath);
            }
        }

        return null;
    }

    /**
     * 라이선스 파일을 찾을 확장 루트 후보를 우선순위 순으로 반환합니다.
     *
     * @param string $type 확장 타입 (modules, plugins, templates)
     * @param string $identifier 확장 식별자
     * @return array<int, string> 확장 루트 절대 경로 목록 (설치된 확장은 설치본 하나)
     */
    private function licenseCandidateRoots(string $type, string $identifier): array
    {
        $basePath = base_path($type);
        $activeRoot = $basePath.DIRECTORY_SEPARATOR.$identifier;

        if (InstalledExtensionLookup::isInstalled($type, $identifier)) {
            return [$activeRoot];
        }

        return [
            $activeRoot,
            ExtensionPendingHelper::getPendingPath($basePath, $identifier),
            ExtensionPendingHelper::getBundledPath($basePath, $identifier),
        ];
    }
}
