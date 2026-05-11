<?php

namespace App\Contracts\Extension;

use App\Enums\DeactivationReason;

interface TemplateManagerInterface
{
    /**
     * 모든 템플릿을 로드하고 초기화합니다.
     */
    public function loadTemplates(): void;

    /**
     * /templates 디렉토리를 스캔하여 사용 가능한 템플릿을 발견합니다.
     *
     * @return array 발견된 템플릿 배열 (identifier => path)
     */
    public function scanTemplates(): array;

    /**
     * 로드된 모든 템플릿 인스턴스들을 반환합니다.
     *
     * @return array 모든 템플릿 배열
     */
    public function getAllTemplates(): array;

    /**
     * 활성화된 템플릿을 반환합니다.
     *
     * @param  string  $type  템플릿 타입 (admin 또는 user)
     * @return array|null 활성화된 템플릿 데이터 또는 null
     */
    public function getActiveTemplate(string $type): ?array;

    /**
     * 지정된 식별자의 템플릿 데이터를 반환합니다.
     *
     * @param  string  $identifier  템플릿 식별자 (vendor-name 형식)
     * @return array|null 템플릿 데이터 또는 null
     */
    public function getTemplate(string $identifier): ?array;

    /**
     * 지정된 템플릿을 시스템에 설치합니다.
     *
     * @param  string  $identifier  설치할 템플릿 식별자
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 템플릿을 찾을 수 없거나 의존성 문제 시
     */
    public function installTemplate(string $identifier, ?\Closure $onProgress = null): bool;

    /**
     * 지정된 템플릿을 제거합니다.
     *
     * @param  string  $identifier  제거할 템플릿 식별자
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 템플릿을 찾을 수 없을 때
     */
    public function uninstallTemplate(string $identifier, ?\Closure $onProgress = null): bool;

    /**
     * 지정된 템플릿을 활성화합니다.
     *
     * @param  string  $identifier  활성화할 템플릿 식별자
     * @param  bool  $force  의존성 미충족 시에도 강제 활성화 여부
     * @return array{success: bool, warning?: bool, missing_modules?: array, missing_plugins?: array, message?: string} 활성화 결과
     */
    public function activateTemplate(string $identifier, bool $force = false): array;

    /**
     * 지정된 템플릿을 비활성화합니다.
     *
     * @param  string  $identifier  비활성화할 템플릿 식별자
     * @param  string  $reason  비활성화 사유 (DeactivationReason enum value: manual|incompatible_core)
     * @param  string|null  $incompatibleRequiredVersion  incompatible_core 사유 시 요구된 코어 버전 제약
     * @return bool 비활성화 성공 여부
     */
    public function deactivateTemplate(
        string $identifier,
        string $reason = DeactivationReason::Manual->value,
        ?string $incompatibleRequiredVersion = null,
    ): bool;

    /**
     * 템플릿의 의존성을 검증합니다.
     *
     * @param  string  $identifier  검증할 템플릿 식별자
     * @return bool 의존성 충족 여부
     *
     * @throws \Exception 의존성이 충족되지 않을 때
     */
    public function validateTemplate(string $identifier): bool;

    /**
     * 설치되지 않은 템플릿들을 반환합니다.
     *
     * @return array 미설치 템플릿 배열
     */
    public function getUninstalledTemplates(): array;

    /**
     * 설치된 템플릿 정보를 데이터베이스 레코드와 함께 반환합니다.
     *
     * @return array 설치된 템플릿 배열
     */
    public function getInstalledTemplatesWithDetails(): array;

    /**
     * 특정 템플릿의 정보를 반환합니다 (설치 여부와 관계없이).
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array|null 템플릿 정보 배열 또는 null
     */
    public function getTemplateInfo(string $identifier): ?array;

    /**
     * 타입별 템플릿 목록을 반환합니다.
     *
     * @param  string  $type  템플릿 타입 (admin 또는 user)
     * @return array 해당 타입의 템플릿 배열
     */
    public function getTemplatesByType(string $type): array;

    /**
     * 템플릿의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{success: bool, layouts_refreshed: int} 갱신 결과 및 갱신된 레이아웃 개수
     *
     * @throws \Exception 템플릿을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshTemplateLayouts(string $identifier): array;

    /**
     * 템플릿의 의존성 충족 상태를 확인합니다.
     *
     * template.json의 dependencies를 기반으로 모든 모듈/플러그인의
     * 활성화 상태 및 버전 요구사항 충족 여부를 확인합니다.
     *
     * 각 의존성 항목 구조:
     * - identifier: 모듈/플러그인 식별자
     * - name: 로케일화된 이름 (미설치 시 identifier 사용)
     * - required_version: 요구 버전 제약조건
     * - installed_version: 설치된 버전 (null이면 미설치)
     * - is_active: 활성화 여부
     * - version_met: 버전 요구사항 충족 여부
     * - met: 전체 요구사항 충족 여부
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{met: bool, modules: array, plugins: array} 의존성 상태
     */
    public function checkDependenciesStatus(string $identifier): array;

    /**
     * 템플릿의 미충족 의존성 목록을 반환합니다.
     *
     * checkDependenciesStatus()를 활용하여 충족되지 않은 의존성만 필터링합니다.
     * 각 항목에는 identifier, name, required_version 등이 포함됩니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return array{modules: array, plugins: array} 미충족 의존성 목록
     */
    public function getUnmetDependencies(string $identifier): array;

    /**
     * 특정 모듈에 의존하는 활성 템플릿 목록을 반환합니다.
     *
     * 모든 활성화된 템플릿을 조회하여 해당 모듈을 dependencies.modules에
     * 포함하고 있는 템플릿의 identifier 목록을 반환합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return array 의존하는 템플릿 identifier 배열
     */
    public function getTemplatesDependingOnModule(string $moduleIdentifier): array;

    /**
     * 특정 플러그인에 의존하는 활성 템플릿 목록을 반환합니다.
     *
     * 모든 활성화된 템플릿을 조회하여 해당 플러그인을 dependencies.plugins에
     * 포함하고 있는 템플릿의 identifier 목록을 반환합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return array 의존하는 템플릿 identifier 배열
     */
    public function getTemplatesDependingOnPlugin(string $pluginIdentifier): array;
}
