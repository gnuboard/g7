<?php

namespace App\Support\ApiDoc;

use Illuminate\Support\Str;

/**
 * 리소스 계약 필드 설명기
 *
 * 컬럼 주석이 없는 accessor/computed 필드(Resource 의 toArray() 가 파생하는 값)의
 * 설명을 제공합니다. G7 전역에서 의미가 표준화된 필드(BaseApiResource 의 is_owner /
 * abilities, Enum 파생 status_label / status_variant, User::isAdmin() 등)와, 일관된
 * 파생 규칙(_label / _variant / _flag / _name / is_* / *_at 접미·접두)을 코드에서
 * 확인된 의미 그대로 서술합니다.
 *
 * 우선순위: 이 계약 사전은 컬럼 주석보다 앞섭니다 — created_at 은 어느 테이블이든
 * "생성 일시" 이고, status_label 은 컬럼이 아니라 Enum label() 산물이기 때문입니다.
 */
class ResourceFieldDescriber
{
    /**
     * @var array<string, string> 정확 필드명 => 설명 (BaseApiResource/공통 Resource 계약)
     */
    private const EXACT = [
        // BaseApiResource 표준 메타 (resourceMeta)
        'is_owner' => '현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타)',
        'abilities' => '현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반)',

        // 공통 식별/타임스탬프
        'id' => '기본 키 (내부 식별자)',
        'uuid' => '외부 노출용 UUID (URL/API 식별자, 내부 id 비노출)',
        'created_at' => '생성 일시',
        'updated_at' => '최종 수정 일시',
        'deleted_at' => '소프트 삭제 일시 (미삭제 시 null)',

        // 공통 콘텐츠/표시 필드 (도메인 무관하게 역할이 고정 — 요청 파라미터
        // ParameterDescriber 의 name/title/content/description 대응물).
        // 근거: 실측 시 board 게시글·category·brand·notification 등 전 도메인에서
        //       동일 역할(명칭/제목/본문/설명)로 관측. 다국어(object)/문자열 무관.
        'name' => '대상의 이름/명칭 (다국어 필드는 로케일별 값 객체)',
        'title' => '제목',
        'content' => '본문 내용',
        'description' => '설명 (다국어 필드는 로케일별 값 객체)',
        'slug' => 'URL 친화 식별자 (slug)',
        'label' => '표시용 라벨',
        'icon' => '아이콘 식별자 (아이콘 클래스/이름)',
        'thumbnail' => '썸네일 이미지 URL/경로',
        'ip_address' => '요청/행위가 발생한 IP 주소',

        // User 계약 파생 필드 (UserResource)
        'status_label' => '상태의 사람이 읽는 라벨 (상태 Enum label() 산물)',
        'status_variant' => '상태 표시 색상/스타일 변형 키 (상태 Enum variant() 산물 — UI 배지용)',
        'language_label' => '언어 코드의 현지화 라벨 (user.language.{code} 번역)',
        'country_flag' => '국가 코드의 국기 이모지 (country 값에서 파생)',
        'country_name' => '국가 코드의 현지화 국가명 (country 값에서 파생)',
        'is_admin' => '관리자 역할 보유 여부 (User::isAdmin() — 역할 관계 기반 파생)',

        // 확장 상태 계약 필드 (ModuleResource/PluginResource/TemplateResource)
        'is_bundled' => '코어에 선탑재된 번들 확장인지 여부',
        'is_pending' => '_pending 대기소에 있어 설치 대기 중인지 여부',
        'update_available' => '최신 버전 대비 업데이트 가능 여부',
        'update_source' => '업데이트 감지 출처 (github, bundled 등)',
        'latest_version' => '감지된 최신 배포 버전',
        'file_version' => '설치된 파일의 manifest 버전',
        'incompatible_required_version' => '요구 코어 버전 미충족 시 필요한 버전 (호환되면 null)',
        'status_variant_label' => '상태 표시용 라벨/변형',
        'dependencies' => '의존하는 확장 맵 (manifest 파생 — {modules, plugins})',
        'assets' => '프론트엔드 에셋 매니페스트 (manifest 파생 — js/css 진입점·로딩 전략)',

        // 관계/연관 객체 (여러 Resource 에서 동일 계약)
        'creator' => '생성자 정보 객체 (uuid/name/email — creator 관계 파생)',
        'children' => '하위 항목 배열 (계층 트리 — children 관계 파생)',
        'parent' => '상위 항목 객체 (parent 관계 파생)',
        'permissions' => '연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생)',
        'recipient' => '수신자 사용자 객체 (uuid/name/email — recipientUser 관계 파생)',
        'sender' => '발신자 사용자 객체 (uuid/name/email — senderUser 관계 파생)',
        'author' => '작성자 사용자 객체 (uuid/name — author 관계 파생)',
        'actor_name' => '행위를 수행한 주체(사용자/시스템)의 이름',

        // 시스템/집계 공통 (DashboardService/UserRepository/SettingsService)
        'total' => '전체 개수 (집계)',
        'total_users' => '전체 사용자 수 (통계 객체는 count/추이 포함)',
        'time' => '상대 시각 표시 (예: "24초 전" — diffForHumans() 산물)',
        'server_time' => '서버 현재 시각 (Y-m-d H:i:s)',
        'number' => '목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생)',

        // 로케일 (TemplateResource/LocaleController)
        'locales' => '활성 로케일 코드 배열',
        'locale_names' => '로케일 코드별 표시명 맵 (config app.locale_names)',

        // 확장/버전 공통 (Module/Plugin/Template/LanguagePack Resource, 확장 Manager)
        'extension_type' => '이 리소스를 소유한 확장의 타입 (core/module/plugin/template)',
        'extension_identifier' => '이 리소스를 소유한 확장의 식별자',
        'extension_name' => '이 리소스를 소유한 확장의 표시 이름 (manifest name)',
        'github_url' => 'GitHub 저장소 URL (manifest 파생)',
        'github_changelog_url' => 'GitHub 변경 이력(CHANGELOG) URL (manifest 파생)',
        'changelog' => '변경 이력 텍스트 (원격/파일 CHANGELOG 본문)',
        'current_core_version' => '현재 설치된 코어 버전',
        'installed_modules' => '설치된 모듈 집계 객체 (total/active)',
        'installed_templates' => '설치된 템플릿 집계 객체 (total/active)',
        'active_plugins' => '활성 플러그인 집계 객체 (total/active)',
        'bundled_identifier' => '대응하는 번들 확장 식별자 (번들 원본 매칭용)',
        'origin' => '출처 (설치/등록 원천 구분 값)',
        'target_name' => '대상 확장의 표시 이름 (scope+target_identifier 로 해석)',
        'install_blocked_reason' => '설치가 차단된 사유 (차단 없으면 null)',

        // 감사/관계 공통 (도메인 무관하게 역할 고정 — 쓰기 응답에서 흔히 등장)
        'updated_by' => '최종 수정한 사용자 정보 (uuid/name — updated_by 관계 파생, 없으면 null)',
        'creator' => '생성자 정보 객체 (uuid/name/email — creator 관계 파생)',
        'user' => '대상 사용자 정보 객체 (uuid/name/email 등 — user 관계 파생)',
        'roles' => '보유 역할 목록 (각 원소 id/name/permissions — roles 관계 파생)',
        'permissions' => '권한 목록 (각 원소 identifier/name — permissions 관계 파생)',
        'avatar' => '아바타 이미지 URL (미등록 시 null)',

        // 인증/토큰 (로그인/토큰 발급 응답)
        'token' => '발급된 API 접근 토큰 평문 (Bearer 토큰으로 사용, 발급 시 1회만 노출)',
        'token_type' => '토큰 타입 (일반적으로 Bearer)',
        'trigger_type' => '동작을 유발한 방식/주체 구분 값',

        // 스케줄/실행 이력 (Schedule 실행 결과)
        'last_duration' => '마지막 실행 소요 시간 (초/밀리초 — 실행 이력 파생)',
        'duration' => '실행 소요 시간 (초/밀리초)',
        'exit_code' => '실행 종료 코드 (0=성공, 그 외=실패)',
        'output' => '실행 표준 출력 내용',
        'error_output' => '실행 표준 에러 출력 내용 (없으면 빈 문자열/null)',
        'memory_usage' => '실행 중 최대 메모리 사용량 (바이트)',

        // 캐시/파일/집계 (일회성 동작 응답)
        'ttl' => '캐시 유효 시간 (Time To Live, 초)',
        'size_bytes' => '크기 (바이트)',
        'older_than_days' => '기준 경과 일수 (이 일수보다 오래된 대상 필터/집계)',
        'entries' => '항목 목록 (각 원소는 대상 도메인 레코드)',
        'changelog_entries' => '변경 이력 항목 목록 (버전별 변경 내용)',
        'templates' => '템플릿 목록 (각 원소 identifier/name 등 — 템플릿 관계 파생)',
        'spec' => '스펙 정의 객체 (편집기/컴포넌트 선언 스키마 등)',
        'source_meta' => '원천 메타데이터 객체 (출처·경로·해석 정보)',
        'validation_summary' => '검증 결과 요약 객체 (통과/실패 건수 등)',
    ];

    /**
     * 필드명에 대한 설명을 반환합니다. 없으면 null (호출자가 컬럼 주석/TODO 로 폴백).
     *
     * @param  string  $field  필드명
     * @param  string  $type  실측 타입 (boolean/integer/string/object/array...)
     * @return string|null 설명 (없으면 null)
     */
    public function describe(string $field, string $type = ''): ?string
    {
        // sort_order 는 응답에서 표시 정렬 순서 값(정수 컬럼)으로 고정된다.
        // 문자열이면 정렬 방향일 수 있어 도메인 특이 → TODO 유지.
        // (ParameterDescriber 의 sort_order 타입 분기와 동일 계약)
        if ($field === 'sort_order') {
            return in_array($type, ['integer', 'number'], true)
                ? '표시 정렬 순서 값 (작을수록 우선)'
                : null;
        }

        // status 는 도메인마다 허용값 집합이 다르나(active/blocked, success/failed 등) 역할은
        // 공통이다. 문자열 상태값이면 일반 설명을 주고, 구체 허용값은 status_label/변형 필드와
        // 각 도메인 문서에서 보강한다. (동반 필드 status_label/status_variant 는 EXACT 로 설명됨)
        if ($field === 'status' && in_array($type, ['string', 'integer'], true)) {
            return '상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조)';
        }

        if (isset(self::EXACT[$field])) {
            return self::EXACT[$field];
        }

        return $this->byPattern($field, $type);
    }

    /**
     * 일관된 파생 규칙(접미/접두 패턴)으로 설명을 유추합니다.
     *
     * @param  string  $field  필드명
     * @param  string  $type  실측 타입
     * @return string|null 설명 (패턴 미매칭 시 null)
     */
    private function byPattern(string $field, string $type): ?string
    {
        // *_at: 타임스탬프 (UI 포맷 문자열 또는 ISO)
        if (Str::endsWith($field, '_at')) {
            $base = $this->humanize(Str::beforeLast($field, '_at'));

            return "{$base} 일시";
        }

        // *_formatted: 원본 값을 사람이 읽는 문자열로 포맷한 표시용 파생 필드
        // (근거: size_formatted accessor, formatCurrencyPrice/formatFileSize/
        //  formatCreatedAtFormat — 통화·용량·일시 등을 로케일/단위 포맷). 도메인
        // 무관하게 "`base` 값의 표시용 포맷 문자열" 로 의미가 고정된다.
        if (Str::endsWith($field, '_formatted')) {
            $base = Str::beforeLast($field, '_formatted');

            return "`{$base}` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷)";
        }

        // *_label: 원본 값의 현지화 라벨 (Enum label() 또는 번역)
        if (Str::endsWith($field, '_label')) {
            $base = Str::beforeLast($field, '_label');

            return "`{$base}` 값의 사람이 읽는 라벨 (현지화/Enum 파생)";
        }

        // *_variant: UI 배지 색상/스타일 변형 키
        if (Str::endsWith($field, '_variant')) {
            $base = Str::beforeLast($field, '_variant');

            return "`{$base}` 값의 표시 변형 키 (UI 배지 색상/스타일)";
        }

        // can_*: abilities 맵 내부 능력 불리언
        if (Str::startsWith($field, 'can_')) {
            $action = $this->humanize(Str::after($field, 'can_'));

            return "{$action} 수행 가능 여부 (권한 기반)";
        }

        // is_*/has_*: 불리언 상태 플래그
        if ((Str::startsWith($field, 'is_') || Str::startsWith($field, 'has_')) && $type === 'boolean') {
            $base = $this->humanize(Str::after($field, '_'));

            return "{$base} 여부";
        }

        // *_count: 집계 개수
        if (Str::endsWith($field, '_count') && in_array($type, ['integer', 'number'], true)) {
            $base = $this->humanize(Str::beforeLast($field, '_count'));

            return "{$base} 개수 (집계)";
        }

        // *_raw: 다국어/현지화 이전 원본 값 (getValue 로 로케일 미해석 원본 반환)
        // 예: name_raw = name 의 원본, description_raw = description 의 원본
        if (Str::endsWith($field, '_raw')) {
            $base = Str::beforeLast($field, '_raw');

            return "`{$base}` 의 원본 값 (현재 로케일 미해석 원본 JSON/문자열)";
        }

        // localized_* / *_localized: 다국어 필드를 현재 로케일로 해석한 표시용 값
        // (근거: getLocalizedName() / getLocalizedOptionName() 등 — 다국어 JSON 을
        //  현재 로케일 문자열로 해석). 도메인 무관 파생 규칙.
        if (Str::startsWith($field, 'localized_')) {
            $base = $this->humanize(Str::after($field, 'localized_'));

            return "`{$base}` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석)";
        }
        if (Str::endsWith($field, '_localized')) {
            $base = $this->humanize(Str::beforeLast($field, '_localized'));

            return "`{$base}` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석)";
        }

        // *_url: 리소스에 접근하는 URL (썸네일/다운로드/이미지 등)
        // (근거: thumbnail_url => download_url / getThumbnailUrl()). 도메인 무관.
        if (Str::endsWith($field, '_url')) {
            $base = $this->humanize(Str::beforeLast($field, '_url'));

            return "{$base} URL";
        }

        // *_id: 연관 리소스를 참조하는 정수/UUID 식별자 (parent_id/user_id/loggable_id 등).
        // *_ids: 연관 리소스 식별자 배열.
        // 예외: login_id 는 참조 식별자가 아니라 로그인 계정 아이디(문자열)이므로 제외
        // (도메인 특이 → TODO 유지).
        if (Str::endsWith($field, '_ids')) {
            $base = $this->humanize(Str::beforeLast($field, '_ids'));

            return "{$base} 식별자 배열 (연관 리소스 참조)";
        }
        if (Str::endsWith($field, '_id') && ! Str::endsWith($field, 'login_id')) {
            $base = $this->humanize(Str::beforeLast($field, '_id'));

            return "{$base} 식별자 (연관 리소스 참조)";
        }

        // depth: 계층 트리에서의 깊이 (0 = 최상위). children/parent 트리 파생 필드로
        // 도메인 무관하게 의미가 고정된다 (근거: CommentResource/CategoryResource depth).
        if ($field === 'depth' && in_array($type, ['integer', 'number'], true)) {
            return '계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가)';
        }

        return null;
    }

    /**
     * snake_case 를 사람이 읽는 문구로 변환합니다.
     *
     * @param  string  $token  snake_case 토큰
     * @return string 공백 구분 문구
     */
    private function humanize(string $token): string
    {
        return str_replace('_', ' ', $token);
    }
}
