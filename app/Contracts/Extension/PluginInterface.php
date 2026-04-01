<?php

namespace App\Contracts\Extension;

interface PluginInterface
{
    /**
     * 플러그인의 고유 식별자를 반환합니다 (vendor-plugin 형식).
     */
    public function getIdentifier(): string;

    /**
     * 플러그인의 벤더/개발자명을 반환합니다.
     */
    public function getVendor(): string;

    /**
     * 플러그인의 이름을 반환합니다.
     *
     * @return string|array 문자열 또는 다국어 배열 ['ko' => '...', 'en' => '...']
     */
    public function getName(): string|array;

    /**
     * 플러그인의 버전을 반환합니다.
     *
     * @return string 플러그인 버전
     */
    public function getVersion(): string;

    /**
     * 플러그인의 설명을 반환합니다.
     *
     * @return string|array 문자열 또는 다국어 배열 ['ko' => '...', 'en' => '...']
     */
    public function getDescription(): string|array;

    /**
     * 플러그인의 GitHub 저장소 URL을 반환합니다.
     */
    public function getGithubUrl(): ?string;

    /**
     * 플러그인의 라이선스를 반환합니다.
     *
     * @return string|null 라이선스 또는 null
     */
    public function getLicense(): ?string;

    /**
     * 플러그인의 추가 메타데이터를 반환합니다.
     *
     * @return array 메타데이터 배열
     */
    public function getMetadata(): array;

    /**
     * 플러그인을 설치합니다.
     *
     * @return bool 설치 성공 여부
     */
    public function install(): bool;

    /**
     * 플러그인을 제거합니다.
     *
     * @return bool 제거 성공 여부
     */
    public function uninstall(): bool;

    /**
     * 플러그인이 런타임에 동적으로 생성한 테이블 목록을 반환합니다.
     *
     * 플러그인 언인스톨 시 $deleteData=true이면 마이그레이션 롤백 전에 호출됩니다.
     * 반환된 테이블들은 PluginManager가 일괄 삭제합니다.
     *
     * 주의:
     * - 마이그레이션 롤백 전에 호출되므로 메타 테이블(정적 테이블)이 아직 존재합니다.
     * - 개별 테이블 삭제 실패 시에도 언인스톨은 계속 진행됩니다.
     *
     * @return array<string> 삭제할 테이블명 배열
     */
    public function getDynamicTables(): array;

    /**
     * 플러그인을 활성화합니다.
     *
     * @return bool 활성화 성공 여부
     */
    public function activate(): bool;

    /**
     * 플러그인을 비활성화합니다.
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
     * 플러그인이 제공하는 라우트 정보를 반환합니다.
     *
     * @return array 라우트 정보 배열
     */
    public function getRoutes(): array;

    /**
     * 플러그인의 마이그레이션 파일 목록을 반환합니다.
     *
     * @return array 마이그레이션 파일 경로 배열
     */
    public function getMigrations(): array;

    /**
     * 플러그인의 뷰 파일 목록을 반환합니다.
     *
     * @return array 뷰 파일 경로 배열
     */
    public function getViews(): array;

    /**
     * 플러그인의 의존성 정보를 반환합니다.
     *
     * @return array 의존성 목록 배열
     */
    public function getDependencies(): array;

    /**
     * 플러그인이 사용하는 권한 목록을 반환합니다.
     *
     * @return array 권한 목록 배열
     */
    public function getPermissions(): array;

    /**
     * 플러그인의 설정 정보를 반환합니다.
     *
     * @return array 설정 정보 배열
     */
    public function getConfig(): array;

    /**
     * 플러그인이 제공하는 훅 정보를 반환합니다.
     *
     * @return array 훅 정보 배열
     */
    public function getHooks(): array;

    /**
     * 플러그인의 훅 리스너 목록을 반환합니다.
     *
     * @return array 훅 리스너 클래스 목록 배열
     */
    public function getHookListeners(): array;

    /**
     * 플러그인의 스케줄 작업 목록을 반환합니다.
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
     * 플러그인 설치 시 실행할 시더 클래스 목록을 반환합니다.
     *
     * 배열 순서대로 실행됩니다.
     * 빈 배열 반환 시 database/seeders/ 디렉토리의 모든 시더를 자동 검색합니다. (역호환)
     *
     * @return array<class-string<\Illuminate\Database\Seeder>> 시더 클래스명 배열 (FQCN)
     */
    public function getSeeders(): array;

    /**
     * 플러그인이 정의하는 역할 목록을 반환합니다.
     *
     * 역할 배열의 각 항목은 다음 구조를 따라야 합니다:
     * - identifier: 역할 고유 식별자 (vendor-plugin.rolename 형식)
     * - name: 다국어 배열 ['ko' => '역할명', 'en' => 'Role Name']
     * - description: 다국어 배열 ['ko' => '설명', 'en' => 'Description']
     *
     * @return array 역할 정보 배열
     */
    public function getRoles(): array;

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
     * 플러그인이 설정 페이지를 가지고 있는지 확인합니다.
     *
     * @return bool 설정 페이지 존재 여부
     */
    public function hasSettings(): bool;

    /**
     * 플러그인 설정 스키마를 반환합니다.
     *
     * 설정 스키마는 설정 필드의 타입, 라벨, 기본값, 유효성 검사 규칙 등을 정의합니다.
     * FormRequest에서 동적 유효성 검사 규칙 생성에 사용됩니다.
     *
     * @return array 설정 스키마 배열
     *
     * @example
     * ```php
     * return [
     *     'display_mode' => [
     *         'type' => 'enum',
     *         'options' => ['popup', 'layer'],
     *         'default' => 'layer',
     *         'label' => ['ko' => '표시 방식', 'en' => 'Display Mode'],
     *         'required' => false,
     *     ],
     *     'api_key' => [
     *         'type' => 'string',
     *         'label' => ['ko' => 'API 키', 'en' => 'API Key'],
     *         'sensitive' => true,  // 암호화 저장
     *         'required' => true,
     *     ],
     * ];
     * ```
     */
    public function getSettingsSchema(): array;

    /**
     * 플러그인 설정 페이지 레이아웃 경로를 반환합니다.
     *
     * 설정 페이지의 JSON 레이아웃 파일 경로를 반환합니다.
     * null 반환 시 설정 페이지가 없는 것으로 간주합니다.
     *
     * @return string|null 레이아웃 파일 절대 경로 또는 null
     */
    public function getSettingsLayout(): ?string;

    /**
     * 플러그인 설정 페이지 라우트 경로를 반환합니다.
     *
     * 플러그인 목록에서 설정 버튼 클릭 시 이동할 경로입니다.
     * null 반환 시 설정 페이지가 없는 것으로 간주합니다.
     *
     * @return string|null 설정 페이지 라우트 (예: '/admin/plugins/{identifier}/settings')
     */
    public function getSettingsRoute(): ?string;

    /**
     * 플러그인 설정 값을 반환합니다.
     *
     * 현재 저장된 설정 값을 반환합니다. (DB 조회용)
     *
     * @return array 설정 값 배열
     */
    public function getConfigValues(): array;

    /**
     * 플러그인 설정 기본값 파일 경로를 반환합니다.
     *
     * config/settings/defaults.json 파일이 존재하면 해당 경로를 반환합니다.
     * 이 파일에는 defaults(기본값)와 frontend_schema(프론트엔드 노출 스키마)가 정의됩니다.
     *
     * @return string|null defaults.json 파일 절대 경로 또는 null
     */
    public function getSettingsDefaultsPath(): ?string;
}
