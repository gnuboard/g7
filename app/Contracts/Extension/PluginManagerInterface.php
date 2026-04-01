<?php

namespace App\Contracts\Extension;

interface PluginManagerInterface
{
    /**
     * 모든 플러그인을 로드하고 초기화합니다.
     */
    public function loadPlugins(): void;

    /**
     * 활성화된 플러그인들만 반환합니다.
     *
     * @return array 활성화된 플러그인 배열
     */
    public function getActivePlugins(): array;

    /**
     * 지정된 플러그인을 시스템에 설치합니다.
     *
     * @param  string  $pluginName  설치할 플러그인명
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 설치 성공 여부
     *
     * @throws \Exception 플러그인을 찾을 수 없거나 의존성 문제 시
     */
    public function installPlugin(string $pluginName, ?\Closure $onProgress = null): bool;

    /**
     * 지정된 플러그인을 활성화합니다.
     *
     * @param  string  $pluginName  활성화할 플러그인명
     * @return array{success: bool, layouts_registered: int} 활성화 결과
     */
    public function activatePlugin(string $pluginName): array;

    /**
     * 지정된 플러그인을 비활성화합니다.
     *
     * @param  string  $pluginName  비활성화할 플러그인명
     * @param  bool  $force  의존 템플릿이 있어도 강제 비활성화 여부
     * @return array{success: bool, layouts_deleted: int, warning?: bool, dependent_templates?: array, message?: string} 비활성화 결과
     */
    public function deactivatePlugin(string $pluginName, bool $force = false): array;

    /**
     * 지정된 플러그인을 시스템에서 제거합니다.
     *
     * @param  string  $pluginName  제거할 플러그인명
     * @param  bool  $deleteData  데이터 삭제 여부 (마이그레이션 롤백)
     * @param  \Closure|null  $onProgress  진행 콜백 (?string $step, string $message)
     * @return bool 제거 성공 여부
     *
     * @throws \Exception 플러그인을 찾을 수 없을 때
     */
    public function uninstallPlugin(string $pluginName, bool $deleteData = false, ?\Closure $onProgress = null): bool;

    /**
     * 지정된 이름의 플러그인 인스턴스를 반환합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return PluginInterface|null 플러그인 인스턴스 또는 null
     */
    public function getPlugin(string $pluginName): ?PluginInterface;

    /**
     * 로드된 모든 플러그인 인스턴스들을 반환합니다.
     *
     * @return array 모든 플러그인 배열
     */
    public function getAllPlugins(): array;

    /**
     * 설치되지 않은 플러그인들을 반환합니다.
     *
     * @return array 미설치 플러그인 배열
     */
    public function getUninstalledPlugins(): array;

    /**
     * 설치된 플러그인 정보를 데이터베이스 레코드와 함께 반환합니다.
     *
     * @return array 설치된 플러그인 배열
     */
    public function getInstalledPluginsWithDetails(): array;

    /**
     * 특정 플러그인의 정보를 반환합니다 (설치 여부와 관계없이).
     *
     * @param  string  $pluginName  플러그인명
     * @return array|null 플러그인 정보 배열 또는 null
     */
    public function getPluginInfo(string $pluginName): ?array;

    /**
     * 플러그인의 레이아웃을 파일에서 다시 읽어 DB에 갱신합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return array{success: bool, layouts_refreshed: int} 갱신 결과 및 갱신된 레이아웃 개수
     *
     * @throws \Exception 플러그인을 찾을 수 없거나 레이아웃 갱신 실패 시
     */
    public function refreshPluginLayouts(string $pluginName): array;

    /**
     * 플러그인 삭제 시 삭제될 데이터 정보를 반환합니다.
     *
     * @param  string  $pluginName  플러그인명
     * @return array|null 삭제 정보 (테이블 목록, 스토리지 디렉토리 목록, 용량) 또는 null
     */
    public function getPluginUninstallInfo(string $pluginName): ?array;
}
