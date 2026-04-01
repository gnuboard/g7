<?php

namespace App\Contracts\Extension;

interface ModuleInterface
{
    /**
     * 모듈명 반환 (표시용)
     *
     * @return string|array 문자열 또는 다국어 배열 ['ko' => '...', 'en' => '...']
     */
    public function getName(): string|array;

    /**
     * 모듈의 버전을 반환합니다.
     *
     * @return string 모듈 버전
     */
    public function getVersion(): string;

    /**
     * 모듈 설명 반환
     *
     * @return string|array 문자열 또는 다국어 배열 ['ko' => '...', 'en' => '...']
     */
    public function getDescription(): string|array;

    /**
     * 모듈을 설치합니다.
     *
     * @return bool 설치 성공 여부
     */
    public function install(): bool;

    /**
     * 모듈을 제거합니다.
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool;

    /**
     * 모듈이 런타임에 동적으로 생성한 테이블 목록을 반환합니다.
     *
     * 모듈 언인스톨 시 $deleteData=true이면 마이그레이션 롤백 전에 호출됩니다.
     * 반환된 테이블들은 ModuleManager가 일괄 삭제합니다.
     *
     * 주의:
     * - 마이그레이션 롤백 전에 호출되므로 메타 테이블(정적 테이블)이 아직 존재합니다.
     * - 개별 테이블 삭제 실패 시에도 언인스톨은 계속 진행됩니다.
     *
     * @return array<string> 삭제할 테이블명 배열
     */
    public function getDynamicTables(): array;

    /**
     * 모듈을 활성화합니다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool;

    /**
     * 모듈을 비활성화합니다.
     *
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(): bool;

    /**
     * 버전별 업그레이드 스텝을 반환합니다.
     *
     * 반환 형식: ['1.1.0' => callable|UpgradeStepInterface, ...]
     * 시스템이 fromVersion 초과 ~ toVersion 이하의 스텝을 자동 필터링 후 순차 실행합니다.
     *
     * @return array<string, callable|\App\Contracts\Extension\UpgradeStepInterface> 버전 => 스텝 매핑
     */
    public function upgrades(): array;

    /**
     * 모듈이 제공하는 라우트 정보를 반환합니다.
     *
     * @return array 라우트 정보 배열
     */
    public function getRoutes(): array;

    /**
     * 모듈의 마이그레이션 파일 목록을 반환합니다.
     *
     * @return array 마이그레이션 파일 경로 배열
     */
    public function getMigrations(): array;

    /**
     * 모듈의 뷰 파일 목록을 반환합니다.
     *
     * @return array 뷰 파일 경로 배열
     */
    public function getViews(): array;

    /**
     * 모듈의 의존성 정보를 반환합니다.
     *
     * @return array 의존성 목록 배열
     */
    public function getDependencies(): array;

    /**
     * 모듈이 사용하는 권한 목록을 반환합니다.
     *
     * @return array 권한 목록 배열
     */
    public function getPermissions(): array;

    /**
     * 모듈이 정의하는 역할 목록을 반환합니다.
     *
     * 역할 배열의 각 항목은 다음 구조를 따라야 합니다:
     * - identifier: 역할 고유 식별자 (vendor-module.rolename 형식)
     * - name: 다국어 배열 ['ko' => '역할명', 'en' => 'Role Name']
     * - description: 다국어 배열 ['ko' => '설명', 'en' => 'Description']
     *
     * @return array 역할 정보 배열
     */
    public function getRoles(): array;

    /**
     * 모듈의 설정 정보를 반환합니다.
     *
     * @return array 설정 정보 배열
     */
    public function getConfig(): array;

    /**
     * 모듈이 추가하는 관리자 메뉴 목록을 반환합니다.
     *
     * 메뉴 배열의 각 항목은 다음 구조를 따라야 합니다:
     * - name: 다국어 배열 ['ko' => '메뉴명', 'en' => 'Menu Name'] 또는 문자열 (역호환성)
     * - slug: 메뉴 고유 식별자 (string)
     * - url: 메뉴 URL (string)
     * - icon: 아이콘 클래스 (string, optional)
     * - order: 메뉴 순서 (int, optional)
     * - children: 하위 메뉴 배열 (array, optional) - 동일한 구조를 따름
     *
     * @return array 메뉴 정보 배열
     */
    public function getAdminMenus(): array;

    /**
     * 모듈의 훅 리스너 목록을 반환합니다.
     *
     * @return array 훅 리스너 클래스 목록 배열
     */
    public function getHookListeners(): array;

    /**
     * 모듈의 스케줄 작업 목록을 반환합니다.
     *
     * 스케줄 배열의 각 항목은 다음 구조를 따라야 합니다:
     * - command: Artisan 커맨드 이름
     * - schedule: 스케줄 주기 ('daily', 'hourly', 'everyMinute', 'weekly' 또는 cron 표현식)
     * - description: 작업 설명 (선택)
     * - enabled_config: 설정 키 (선택, 설정에 따라 활성화 여부 결정)
     *
     * @return array 스케줄 작업 배열
     */
    public function getSchedules(): array;

    /**
     * 모듈 설치 시 실행할 시더 클래스 목록을 반환합니다.
     *
     * 배열 순서대로 실행됩니다.
     * 빈 배열 반환 시 database/seeders/ 디렉토리의 모든 시더를 자동 검색합니다. (역호환)
     *
     * @return array<class-string<\Illuminate\Database\Seeder>> 시더 클래스명 배열 (FQCN)
     */
    public function getSeeders(): array;

    /**
     * 모듈의 고유 식별자를 반환합니다 (vendor-module 형식).
     *
     * @return string 모듈 식별자
     */
    public function getIdentifier(): string;

    /**
     * 모듈의 벤더명을 반환합니다.
     *
     * @return string 벤더명
     */
    public function getVendor(): string;

    /**
     * 모듈의 GitHub 저장소 URL을 반환합니다.
     *
     * @return string|null GitHub URL 또는 null
     */
    public function getGithubUrl(): ?string;

    /**
     * 모듈의 라이선스를 반환합니다.
     *
     * @return string|null 라이선스 또는 null
     */
    public function getLicense(): ?string;

    /**
     * 모듈의 메타데이터를 반환합니다.
     *
     * @return array 메타데이터 배열
     */
    public function getMetadata(): array;

    /**
     * 레이아웃 확장 파일 경로를 반환합니다.
     *
     * @return string extensions 디렉토리 경로
     */
    public function getExtensionsPath(): string;

    /**
     * 레이아웃 확장 파일 목록을 반환합니다.
     *
     * resources/extensions 디렉토리에서 JSON 파일을 검색합니다.
     *
     * @return array<string> JSON 파일 경로 목록
     */
    public function getLayoutExtensions(): array;

    /**
     * 그누보드7 코어 요구 버전 제약을 반환합니다.
     *
     * Semantic Versioning 제약 문자열 반환 (예: ">=1.0.0", "^1.0", "~1.2.0")
     * null 반환 시 버전 검증을 건너뜁니다 (역호환성 보장).
     *
     * @return string|null 버전 제약 문자열 또는 null
     */
    public function getRequiredCoreVersion(): ?string;

    /**
     * 모듈 설정 기본값 파일(defaults.json) 경로를 반환합니다.
     *
     * @return string|null defaults.json 파일의 절대 경로, 없으면 null
     */
    public function getSettingsDefaultsPath(): ?string;

    /**
     * 모듈에 환경설정이 있는지 확인합니다.
     *
     * @return bool 환경설정 존재 여부
     */
    public function hasSettings(): bool;

    /**
     * 모듈 설정 기본값을 반환합니다.
     *
     * @return array 설정 기본값 배열
     */
    public function getConfigValues(): array;

    /**
     * 모듈 설정 스키마를 반환합니다.
     *
     * 민감한 필드(sensitive: true) 정보 등을 포함합니다.
     *
     * @return array 설정 스키마 배열
     */
    public function getSettingsSchema(): array;
}
