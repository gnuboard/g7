<?php

namespace App\Contracts\Extension;

use App\Enums\DeactivationReason;

interface ModuleManagerInterface
{
    /**
     * 모든 모듈을 로드하고 초기화합니다.
     */
    public function loadModules(): void;

    /**
     * 활성화된 모듈들만 반환합니다.
     *
     * @return array 활성화된 모듈 배열
     */
    public function getActiveModules(): array;

    /**
     * 지정된 모듈을 시스템에 설치합니다.
     *
     * @param  string  $moduleName  설치할 모듈명
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 모듈을 찾을 수 없거나 의존성 문제 시
     */
    public function installModule(string $moduleName, ?\Closure $onProgress = null): bool;

    /**
     * 지정된 모듈을 활성화합니다.
     *
     * @param  string  $moduleName  활성화할 모듈명
     * @return array{success: bool, layouts_registered: int} 활성화 결과 및 등록된 레이아웃 개수
     */
    public function activateModule(string $moduleName): array;

    /**
     * 지정된 모듈을 비활성화합니다.
     *
     * @param  string  $moduleName  비활성화할 모듈명
     * @param  bool  $force  의존 템플릿이 있어도 강제 비활성화 여부
     * @param  string  $reason  비활성화 사유 (DeactivationReason enum value: manual|incompatible_core)
     * @param  string|null  $incompatibleRequiredVersion  incompatible_core 사유 시 요구된 코어 버전 제약
     * @return array{success: bool, layouts_deleted: int, warning?: bool, dependent_templates?: array, message?: string} 비활성화 결과 및 삭제된 레이아웃 개수
     */
    public function deactivateModule(
        string $moduleName,
        bool $force = false,
        string $reason = DeactivationReason::Manual->value,
        ?string $incompatibleRequiredVersion = null,
    ): array;

    /**
     * 지정된 모듈을 시스템에서 제거합니다.
     *
     * @param  string  $moduleName  제거할 모듈명
     * @param  bool  $deleteData  모듈 데이터(테이블) 삭제 여부
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 모듈을 찾을 수 없을 때
     */
    public function uninstallModule(string $moduleName, bool $deleteData = false, ?\Closure $onProgress = null): bool;

    /**
     * 지정된 이름의 모듈 인스턴스를 반환합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return ModuleInterface|null 모듈 인스턴스 또는 null
     */
    public function getModule(string $moduleName): ?ModuleInterface;

    /**
     * 로드된 모든 모듈 인스턴스들을 반환합니다.
     *
     * @return array 모든 모듈 배열
     */
    public function getAllModules(): array;

    /**
     * 설치되지 않은 모듈들을 반환합니다.
     *
     * @return array 미설치 모듈 배열
     */
    public function getUninstalledModules(): array;

    /**
     * 설치된 모듈 정보를 데이터베이스 레코드와 함께 반환합니다.
     *
     * @return array 설치된 모듈 배열
     */
    public function getInstalledModulesWithDetails(): array;

    /**
     * 특정 모듈의 정보를 반환합니다 (설치 여부와 관계없이).
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 모듈 정보 배열 또는 null
     */
    public function getModuleInfo(string $moduleName): ?array;

    /**
     * 모듈의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  string  $moduleName  모듈명
     * @param  bool  $preserveModified  true 시 사용자가 UI에서 수정한 레이아웃은 덮어쓰지 않음
     * @return array{success: bool, layouts_refreshed: int} 갱신 결과 및 갱신된 레이아웃 개수
     *
     * @throws \Exception 모듈을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshModuleLayouts(string $moduleName, bool $preserveModified = false): array;

    /**
     * 모듈 삭제 시 삭제될 데이터 정보를 반환합니다.
     *
     * @param  string  $moduleName  모듈명
     * @return array|null 삭제 정보 (테이블 목록, 스토리지 디렉토리 목록, 용량) 또는 null
     */
    public function getModuleUninstallInfo(string $moduleName): ?array;
}
