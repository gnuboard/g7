<?php

namespace App\Contracts\Extension;

interface TemplateInterface
{
    /**
     * 템플릿의 고유 식별자를 반환합니다 (vendor-name 형식).
     *
     * @return string 템플릿 식별자 (예: sirsoft-admin_basic)
     */
    public function getIdentifier(): string;

    /**
     * 템플릿의 벤더/개발자명을 반환합니다.
     *
     * @return string 벤더명 (예: sirsoft)
     */
    public function getVendor(): string;

    /**
     * 템플릿의 이름을 반환합니다 (표시용).
     *
     * @return string 템플릿 이름 (예: Admin Basic)
     */
    public function getName(): string;

    /**
     * 템플릿의 버전을 반환합니다.
     *
     * @return string 템플릿 버전 (시맨틱 버전, 예: 1.0.0)
     */
    public function getVersion(): string;

    /**
     * 템플릿의 설명을 반환합니다.
     *
     * @return string 템플릿 설명
     */
    public function getDescription(): string;

    /**
     * 템플릿의 타입을 반환합니다.
     *
     * @return string 템플릿 타입 (admin: 관리자용, user: 사용자용)
     */
    public function getType(): string;

    /**
     * 템플릿을 설치합니다.
     *
     * @return bool 설치 성공 여부
     */
    public function install(): bool;

    /**
     * 템플릿을 제거합니다.
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool;

    /**
     * 템플릿을 활성화합니다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool;

    /**
     * 템플릿을 비활성화합니다.
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool;

    /**
     * 템플릿의 의존성 정보를 반환합니다.
     *
     * @return array 의존성 목록 배열 (모듈/플러그인 identifier => 버전)
     */
    public function getDependencies(): array;

    /**
     * 템플릿이 제공하는 기본 레이아웃 목록을 반환합니다.
     *
     * @return array 레이아웃 파일 경로 배열
     */
    public function getDefaultLayouts(): array;

    /**
     * 템플릿의 컴포넌트 매니페스트 경로를 반환합니다.
     *
     * @return string 컴포넌트 매니페스트 파일 경로 (components.json)
     */
    public function getComponentsManifestPath(): string;

    /**
     * 템플릿의 라우트 정의 파일 경로를 반환합니다.
     *
     * @return string 라우트 정의 파일 경로 (routes.json)
     */
    public function getRoutesPath(): string;

    /**
     * 템플릿의 빌드된 컴포넌트 번들 경로를 반환합니다.
     *
     * @return string 컴포넌트 번들 파일 경로 (dist/components.js)
     */
    public function getComponentsBundlePath(): string;

    /**
     * 템플릿의 에셋 디렉토리 경로를 반환합니다.
     *
     * @return string 에셋 디렉토리 경로 (assets/)
     */
    public function getAssetsPath(): string;

    /**
     * 템플릿의 다국어 파일 디렉토리 경로를 반환합니다.
     *
     * @return string 다국어 파일 디렉토리 경로 (lang/)
     */
    public function getLangPath(): string;

    /**
     * 템플릿의 메타데이터를 반환합니다.
     *
     * @return array 메타데이터 배열 (template.json 내용)
     */
    public function getMetadata(): array;

    /**
     * 템플릿의 GitHub 저장소 URL을 반환합니다.
     *
     * @return string|null GitHub URL 또는 null
     */
    public function getGithubUrl(): ?string;
}
