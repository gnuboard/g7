<?php

namespace App\Support\ApiDoc;

use Illuminate\Support\Str;

/**
 * 요청 파라미터 설명기
 *
 * G7 전역에서 의미가 표준화된 공통 요청 파라미터(페이지네이션·정렬·검색·필터·
 * 소프트삭제 토글 등)와, 일관된 명명 규칙(*_id / *Id / is_* / *_date / sort_* 등)의
 * 설명을 코드에서 확인된 계약 그대로 서술합니다.
 *
 * ResourceFieldDescriber(응답 필드)의 요청 파라미터 대응물입니다. 도메인 특이
 * 파라미터(예: refund_priority, temp_key)는 여기서 커버하지 않고 사람 서술(TODO)로
 * 남깁니다 — 자동 채움은 "도메인 무관하게 의미가 고정된 파라미터"에만 한정합니다.
 */
class ParameterDescriber
{
    /**
     * @var array<string, string> 정확 이름 => 설명 (위치 무관 공통 파라미터)
     */
    private const EXACT = [
        // 페이지네이션
        'page' => '조회할 페이지 번호 (1부터 시작)',
        'per_page' => '페이지당 항목 수',
        'limit' => '반환할 최대 항목 수',
        'offset' => '건너뛸 항목 수 (오프셋 페이지네이션)',
        'cursor' => '커서 기반 페이지네이션의 다음 페이지 커서',

        // 정렬
        'sort' => '정렬 기준 (필드명, `-` 접두 시 내림차순)',
        'sort_by' => '정렬 기준 필드명',
        'sort_field' => '정렬 기준 필드명',
        'sort_direction' => '정렬 방향 (asc / desc)',
        'order_by' => '정렬 기준 필드명',
        'direction' => '정렬 방향 (asc / desc)',
        // sort_order / order 는 문맥에 따라 정렬 방향(문자열 asc/desc)과
        // 표시 순서 값(정수)으로 갈리므로 EXACT 에 두지 않고 describe() 에서
        // 타입으로 분기한다.

        // 검색/필터
        'search' => '검색어 (지정한 검색 대상 필드에서 부분 일치)',
        'q' => '검색어 (부분 일치)',
        'keyword' => '검색 키워드 (부분 일치)',
        'search_keyword' => '검색 키워드 (부분 일치)',
        'search_field' => '검색 대상 필드명 (검색어를 적용할 컬럼)',
        'search_type' => '검색 유형 (검색 대상/방식 구분)',
        'filters' => '추가 필터 조건 맵 (필드별 조건)',
        'filter' => '필터 조건',
        'start_date' => '조회 기간 시작일 (이 날짜 이후 데이터)',
        'end_date' => '조회 기간 종료일 (이 날짜 이전 데이터)',
        'date_from' => '조회 기간 시작일',
        'date_to' => '조회 기간 종료일',
        'from' => '조회 시작 값 (기간/범위 하한)',
        'to' => '조회 종료 값 (기간/범위 상한)',
        'scope' => '조회 범위 한정 키',

        // 상태 토글/플래그
        'is_active' => '활성 여부 (true 활성 / false 비활성)',
        'is_default' => '기본값 지정 여부',
        'active' => '활성 여부',
        'published' => '발행 여부 (발행된 항목만 필터)',
        'enabled' => '사용 여부',
        'force' => '강제 실행 여부 (안전 확인/선행 검사 우회)',
        'with_trashed' => '소프트 삭제된 항목 포함 여부',
        'only_trashed' => '소프트 삭제된 항목만 조회 여부',

        // 대량 처리
        'ids' => '대상 리소스 식별자 배열 (대량 작업 대상)',
        'items' => '처리 대상 항목 배열',

        // 국제화
        'locale' => '로케일 코드 (표시 언어/지역)',
        'language' => '언어 코드',
        'country_code' => '국가 코드 (ISO 3166-1 alpha-2)',
        'timezone' => '타임존 식별자',

        // 인증/보안 공통
        'password' => '비밀번호',
        'current_password' => '현재 비밀번호 (변경 전 확인용)',
        'password_confirmation' => '비밀번호 확인 (password 와 일치해야 함)',
        'token' => '인증/검증 토큰',
        'email' => '이메일 주소',

        // 주소 공통
        'zipcode' => '우편번호',
        'address' => '기본 주소',
        'address_detail' => '상세 주소',
        'recipient_name' => '수령인 이름',
        'recipient_phone' => '수령인 연락처',
        'address_line_1' => '주소 1행 (기본 주소)',
        'address_line_2' => '주소 2행 (상세 주소)',
        'intl_city' => '도시 (국제 주소)',
        'intl_state' => '주/도 (국제 주소)',
        'intl_postal_code' => '우편번호 (국제 주소)',
        'region' => '지역/권역',

        // SEO 메타 공통 (근거: Seo\* / Page\* / Product\* FormRequest 의
        //       meta_title/meta_description — 검색엔진 노출용 메타 태그 값. 도메인 무관.)
        'meta_title' => 'SEO 메타 제목 (검색엔진/소셜 공유 표시 제목)',
        'meta_description' => 'SEO 메타 설명 (검색엔진/소셜 공유 표시 요약)',
        'alt_text' => '이미지 대체 텍스트 (접근성/이미지 미표시 시 대체 문구)',

        // 프로필/콘텐츠 공통 필드 (User/프로필/일반 리소스에서 의미 고정)
        // 근거: User\{Create,Update}UserRequest, UpdateProfileRequest, Auth\RegisterRequest,
        //       Layout\* / Menu\* / Notification* / Schedule\* FormRequest
        'name' => '대상의 이름/명칭',
        'nickname' => '닉네임',
        'description' => '설명',
        'content' => '본문 내용',
        'body' => '본문',
        'subject' => '제목',
        'title' => '제목',
        'slug' => 'URL 친화 식별자 (slug)',
        'label' => '표시용 라벨',
        'phone' => '전화번호',
        'mobile' => '휴대전화 번호',
        'homepage' => '홈페이지 URL',
        'bio' => '자기소개',
        'signature' => '서명',
        'country' => '국가 코드 (ISO 3166-1 alpha-2)',
        'url' => 'URL',
        'file' => '업로드 파일',
        'files' => '업로드 파일 배열',
        'collection' => '첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default)',
        'avatar' => '아바타 이미지',
        'icon' => '아이콘',
        'value' => '값',
        'values' => '값 배열',
        'username' => '사용자명 (로그인/인증 아이디)',
        'path' => '경로',
        'data' => '데이터 페이로드',

        // 확장/버전 공통 (module/plugin/template/language-pack 설치·업데이트 계약)
        // 근거: Module/Plugin/Template/LanguagePack Install·Update·Activate FormRequest,
        //       Extension\ChangelogRequest, Menu/Schedule/Notification* Request
        'extension_type' => '확장 유형 (core/module/plugin/template)',
        'extension_identifier' => '확장 식별자',
        'from_version' => '시작 버전 (범위 하한)',
        'to_version' => '대상 버전 (범위 상한)',
        'github_url' => 'GitHub 저장소 URL',
        'vendor' => '벤더명 (확장 제작자 식별자)',
        'vendor_mode' => '벤더 설치 모드 (auto/composer/bundled)',
        'checksum' => '무결성 검증 체크섬 (SHA-256)',
        'target_identifier' => '대상 확장 식별자',
        'source_identifier' => '출처 식별자',
        'auto_activate' => '설치 후 자동 활성화 여부',
        'cascade' => '연쇄 처리 여부 (의존 항목 함께 처리)',
        'exclude_protected' => '보호 항목 제외 여부',

        // 스케줄/작업 공통 (근거: Schedule\{Create,Update}ScheduleRequest, ScheduleListRequest)
        'command' => '실행할 아티즌 커맨드',
        'frequency' => '실행 주기',
        'priority' => '우선순위 (작을수록 우선)',
        'timeout' => '타임아웃 (초)',
        'run_in_maintenance' => '점검 모드 중 실행 여부',
        'without_overlapping' => '중복 실행 방지 여부',
        'expected_lock_version' => '낙관적 잠금 버전 (동시 편집 충돌 감지)',

        // 메일/드라이버 설정 (근거: Settings\SaveSettingsRequest,
        //       Settings\TestMailRequest, Settings\TestDriverConnectionRequest)
        'mailer' => '메일 발송 드라이버 (smtp/mailgun/ses)',
        'from_address' => '발신자 주소',
        'from_name' => '발신자 이름',
        'to_email' => '테스트 수신 주소',
        'host' => '호스트 주소',
        'port' => '포트 번호',
        'encryption' => '전송 암호화 방식 (tls/ssl)',
        'storage_driver' => '스토리지 드라이버 (local/s3)',
        'public_asset_disk' => '공개 자산 직접 URL 서빙 디스크 (none/public/s3 + 플러그인 등록 디스크)',
        'cache_driver' => '캐시 드라이버 (file/redis/memcached)',
        'session_driver' => '세션 드라이버 (file/database/redis)',
        'queue_driver' => '큐 드라이버 (sync/database/redis)',
        'redis_host' => 'Redis 호스트 주소',
        'redis_port' => 'Redis 포트 번호',
        'redis_password' => 'Redis 비밀번호',
        'redis_database' => 'Redis 데이터베이스 번호',
        'memcached_host' => 'Memcached 호스트 주소',
        'memcached_port' => 'Memcached 포트 번호',
        's3_bucket' => 'S3 버킷명',
        's3_region' => 'S3 리전 (소문자 영숫자·하이픈 — AWS 리전 코드 또는 S3 호환 스토리지 값, R2 는 auto)',
        's3_access_key' => 'S3 액세스 키',
        's3_secret_key' => 'S3 시크릿 키',
        's3_url' => 'S3 공개 URL(CDN) base — 파일 URL 생성용 (API 요청 주소 아님)',
        's3_endpoint' => 'S3 API 엔드포인트 — S3 호환 스토리지(R2/MinIO 등)용, AWS S3 는 미입력',
        's3_use_path_style' => 'S3 path-style 주소 사용 여부 (MinIO 등)',
        'ses_key' => 'SES 액세스 키',
        'ses_secret' => 'SES 시크릿 키',
        'ses_region' => 'SES 리전',
        'mailgun_domain' => 'Mailgun 도메인',
        'mailgun_secret' => 'Mailgun 시크릿 키',
        'mailgun_endpoint' => 'Mailgun 엔드포인트',
        'websocket_enabled' => 'WebSocket 사용 여부',
        'websocket_host' => 'WebSocket 호스트 주소',
        'websocket_port' => 'WebSocket 포트 번호',
        'websocket_scheme' => 'WebSocket 스킴 (http/https)',
        'websocket_app_key' => 'WebSocket 앱 키',
        'websocket_app_secret' => 'WebSocket 앱 시크릿',
        'websocket_server_host' => 'WebSocket 서버 호스트 주소 (서버측 발행 대상)',
        'websocket_server_port' => 'WebSocket 서버 포트 번호 (서버측 발행 대상)',
        'websocket_server_scheme' => 'WebSocket 서버 스킴 (http/https — 서버측 발행 대상)',
        'websocket_verify_ssl' => 'WebSocket 서버 SSL 인증서 검증 여부',
        'log_driver' => '로그 드라이버 (single/daily/stack)',
        'log_level' => '로그 레벨 (debug/info/warning/error 등)',
        'log_days' => '로그 파일 보관 일수',
        'search_engine_driver' => '검색 엔진 드라이버 (Scout 엔진 선택)',
        'session_lifetime' => '세션 유효 시간 (분)',

        // 레이아웃 JSON 스키마 최상위 키 (근거: docs/frontend/layout-json.md 필수/선택 필드 표,
        //       UpdateLayoutContentRequest 검증 규칙. 템플릿/레이아웃 저장 API 의 content.* 키.)
        'layout_name' => '레이아웃 이름 (식별자 — 파일 경로 기반, 예: board/popular)',
        'components' => '컴포넌트 트리 배열 (레이아웃이 렌더할 컴포넌트 정의)',
        'data_sources' => 'API 데이터 소스 정의 배열 (id/endpoint/method)',
        'init_actions' => '레이아웃 로드 시 실행할 초기화 액션 배열',
        'named_actions' => '이름으로 호출 가능한 재사용 액션 정의 맵',
        'defines' => '재사용 컴포넌트 정의 맵 (컴포넌트 트리에서 참조)',
        'computed' => '계산된 값 정의 맵 (키 → 표현식)',
        'modals' => '모달 컴포넌트 정의 배열',
        'scripts' => '동적 로드할 외부 스크립트 배열',
        'errorHandling' => '레이아웃 레벨 에러 핸들링 설정 (에러 코드별 핸들러 매핑)',
        'globalHeaders' => '전역 HTTP 헤더 규칙 배열 (pattern + headers)',
        'init_state' => '초기 상태 값 맵',
        'initLocal' => 'API 응답을 `_local` 상태에 자동 복사할 키/경로',
        'initGlobal' => 'API 응답을 `_global` 상태에 자동 복사할 키/경로',
        'initIsolated' => 'API 응답을 `_isolated` 상태에 자동 복사할 키/경로',
        'global_state' => '전역 상태 초기값 맵',
        'slots' => '슬롯별 삽입 콘텐츠 맵 (베이스 레이아웃의 slot 위치에 주입)',
        'extends' => '상속할 베이스 레이아웃 이름',
        'transition_overlay' => '페이지 전환 오버레이 설정 (스켈레톤/스피너)',
        'pageConfig' => '페이지 단위 설정 객체',
        'error_config' => '에러 표시 설정 객체',

        // SEO 페이지 생성기 (근거: docs/frontend/layout-json.md meta.seo 표,
        //       docs/backend/seo-system.md, Settings SEO 카탈로그)
        'changefreq' => 'sitemap changefreq 값 (daily/weekly/monthly 등)',
        'structured_data' => 'JSON-LD 구조화 데이터 정의',
        'og' => 'Open Graph 메타태그 정의 맵',
        'page_type' => 'SEO 템플릿 키를 결정하는 페이지 유형',
        'toggle_setting' => 'SEO 활성화 여부를 결정하는 설정 경로',
        'vars' => 'SEO 변수 선언 맵 (데이터 소스 값의 표현식 매핑)',
        'meta_keywords' => 'SEO 메타 키워드 (검색엔진 노출 키워드, 쉼표 구분)',
        'meta_title_suffix' => '모든 페이지 SEO 제목 뒤에 붙는 접미 문구',
        'google_site_verification' => 'Google Search Console 사이트 소유 확인 코드',
        'naver_site_verification' => '네이버 서치어드바이저 사이트 소유 확인 코드',
        'twitter_default_card' => '기본 트위터 카드 유형 (summary 등)',
        'twitter_default_site' => '기본 트위터 사이트 계정 (@handle)',
        'og_image_default_width' => '기본 Open Graph 이미지 너비 (px)',
        'og_image_default_height' => '기본 Open Graph 이미지 높이 (px)',
        'sitemap_enabled' => 'sitemap.xml 생성 사용 여부',
        'sitemap_schedule' => 'sitemap 자동 생성 주기',
        'sitemap_schedule_time' => 'sitemap 자동 생성 시각',
        'sitemap_cache_ttl' => 'sitemap 캐시 유효 시간 (초)',
        'bot_detection_enabled' => '검색엔진 봇 감지 사용 여부 (봇 요청에 SEO 렌더링 적용)',
        'bot_detection_library_enabled' => '봇 감지 라이브러리 사용 여부 (User-Agent 목록 대신 라이브러리 판정)',
        'bot_user_agents' => '봇으로 판정할 User-Agent 목록',
        'generator_enabled' => 'SEO 페이지 생성기 사용 여부',
        'generator_content' => 'SEO 렌더링 본문 생성 방식',

        // 캐시/보안/업로드 공통 (근거: Settings 카탈로그 advanced/security/upload 그룹)
        'cache_enabled' => '캐시 사용 여부',
        'cache_ttl' => '캐시 유효 시간 (초)',
        'debug_mode' => '디버그 모드 사용 여부 (상세 오류 노출)',
        'sql_query_log' => 'SQL 쿼리 로그 기록 여부',
        'outbound_proxy' => '외부 HTTP 호출이 경유할 프록시 주소 (디버그 모드에서만 적용)',
        'outbound_proxy_bypass' => '프록시를 경유하지 않을 호스트 목록',
        'maintenance_mode' => '점검 모드 사용 여부 (사이트 접근 차단)',
        'force_https' => 'HTTPS 강제 리다이렉트 여부',
        'max_login_attempts' => '로그인 실패 허용 횟수 (초과 시 잠금)',
        'login_lockout_time' => '로그인 잠금 지속 시간 (분)',
        'login_attempt_enabled' => '로그인 시도 제한 사용 여부',
        'auth_token_lifetime' => '인증 토큰 유효 시간 (분)',
        'max_file_size' => '업로드 허용 최대 파일 크기',
        'max_file_count' => '업로드 허용 최대 파일 개수',
        'allowed_extensions' => '업로드 허용 확장자 목록',
        'image_quality' => '이미지 리사이즈 시 압축 품질 (1~100)',
        'image_max_width' => '이미지 리사이즈 최대 너비 (px)',
        'image_max_height' => '이미지 리사이즈 최대 높이 (px)',

        // 사이트 기본 정보 (근거: Settings general 그룹)
        'site_url' => '사이트 기본 URL',
        'site_description' => '사이트 설명',
        'site_logo' => '사이트 로고 이미지',
        'admin_email' => '관리자 이메일 주소',
        'currency' => '통화 코드 (ISO 4217 — 예: KRW)',
    ];

    /**
     * 파라미터 설명을 반환합니다. 없으면 null (호출자가 TODO 로 폴백).
     *
     * @param  string  $name  파라미터명
     * @param  string  $location  위치 (path/query/body)
     * @param  string  $type  타입 (integer/string/boolean/array...)
     * @return string|null 설명 (없으면 null)
     */
    public function describe(string $name, string $location = '', string $type = ''): ?string
    {
        // 중첩 필드(general.site_name, shipping.zipcode 등)는 전체명으로는 어떤 규칙에도
        // 걸리지 않는다. 전체명 매칭을 먼저 시도하고, 실패하면 마지막 세그먼트(leaf)로
        // 재시도한다 — 중첩 경로의 의미는 leaf 가 결정하고 부모는 그룹 라벨일 뿐이다.
        // 다국어 로케일 접미(name.ko / alt_text.en)는 부모 필드의 로케일별 값이다.
        if (str_contains($name, '.')) {
            return $this->describeNested($name, $location, $type);
        }

        return $this->describeFlat($name, $location, $type);
    }

    /**
     * 중첩 필드(`.` 포함)의 설명을 leaf 세그먼트로 유추합니다.
     *
     * @param  string  $name  중첩 파라미터명 (예: general.site_name)
     * @param  string  $location  위치
     * @param  string  $type  타입
     * @return string|null 설명 (미매칭 시 null)
     */
    private function describeNested(string $name, string $location, string $type): ?string
    {
        $leaf = Str::afterLast($name, '.');
        $parent = Str::beforeLast($name, '.');

        // 배열 원소 인덱스(items.*.name / ids.0) — leaf 가 인덱스면 그 앞을 leaf 로 본다.
        if ($leaf === '*' || ctype_digit($leaf)) {
            return $this->describe($parent, $location, $type);
        }

        // 로케일 접미(name.ko / alt_text.en): 부모 필드의 로케일별 값.
        if (in_array($leaf, config('app.supported_locales', ['ko', 'en']), true)) {
            $parentDesc = $this->describe($parent, $location, $type);

            return $parentDesc === null
                ? null
                : "{$parentDesc} — `{$leaf}` 로케일 값";
        }

        // 부모가 의미를 한정하는 그룹인 경우: leaf 만으로는 오설명이 된다.
        // 예) seo_meta.title 은 "제목" 이 아니라 "SEO 메타 제목" 이다.
        $scoped = $this->describeScopedLeaf($parent, $leaf);
        if ($scoped !== null) {
            return $scoped;
        }

        // leaf 로 재시도 (부모 그룹 라벨은 의미를 바꾸지 않는다).
        return $this->describeFlat($leaf, $location, $type);
    }

    /**
     * 부모 그룹이 의미를 한정하는 중첩 키의 설명을 반환합니다.
     *
     * 대부분의 설정 그룹(general/mail/upload 등)은 라벨일 뿐이라 leaf 가 의미를 결정하지만,
     * SEO 메타 그룹의 `title`/`description`/`keywords` 는 일반 제목/설명이 아니라 검색엔진
     * 노출용 메타 값이다. leaf 폴백만 태우면 "제목"/"설명" 으로 축소되어 오설명이 된다.
     *
     * @param  string  $parent  부모 경로 (예: seo_meta, content.meta.seo)
     * @param  string  $leaf  마지막 세그먼트
     * @return string|null 설명 (해당 없으면 null → 일반 leaf 폴백)
     */
    private function describeScopedLeaf(string $parent, string $leaf): ?string
    {
        $parentLeaf = Str::afterLast($parent, '.');

        // SEO 메타 그룹 (근거: Page\*Request 의 seo_meta.{title,description,keywords},
        //       레이아웃 meta.seo — 검색엔진/소셜 공유 노출 값)
        if (in_array($parentLeaf, ['seo', 'seo_meta'], true)) {
            return match ($leaf) {
                'title' => 'SEO 메타 제목 (검색엔진/소셜 공유 표시 제목)',
                'description' => 'SEO 메타 설명 (검색엔진/소셜 공유 표시 요약)',
                'keywords' => 'SEO 메타 키워드 (검색엔진 노출 키워드, 쉼표 구분)',
                default => null,
            };
        }

        return null;
    }

    /**
     * 단일 세그먼트 파라미터의 설명을 반환합니다.
     *
     * @param  string  $name  파라미터명 (`.` 없음)
     * @param  string  $location  위치 (path/query/body)
     * @param  string  $type  타입
     * @return string|null 설명 (미매칭 시 null)
     */
    private function describeFlat(string $name, string $location, string $type): ?string
    {
        // path 파라미터는 언제나 리소스 식별자다(라우트 모델 바인딩). 이름이 무엇이든
        // 정렬·필터 같은 조회 파라미터 의미를 가질 수 없으므로 이름 기반 규칙보다 앞서 처리한다.
        // (회귀: `{order}` path 가 "정렬 방향 asc/desc" 로 설명되던 문제)
        if ($location === 'path') {
            return self::EXACT[$name] ?? $this->describePathParam($name);
        }

        // sort_order / order 는 타입에 따라 의미가 갈린다:
        //   - 문자열: 정렬 방향(asc/desc)
        //   - 정수: 표시 정렬 순서 값(작을수록 우선 — 컬럼 값)
        if (in_array($name, ['sort_order', 'order'], true)) {
            return match ($type) {
                'integer', 'number' => '표시 정렬 순서 값 (작을수록 우선)',
                'string' => '정렬 방향 (asc 오름차순 / desc 내림차순)',
                default => null,
            };
        }

        // status / type / category 는 query(목록 조회)에서만 필터 의미가 고정된다.
        // body(생성/수정)에서는 설정할 도메인 값이므로 의미가 도메인마다 달라
        // 사람 서술(TODO)로 남긴다.
        if (in_array($name, ['status', 'type', 'category'], true)) {
            if ($location !== 'query') {
                return null;
            }
            $label = ['status' => '상태', 'type' => '유형', 'category' => '분류'][$name];

            return "{$label} 필터 (해당 {$label}의 항목만 조회)";
        }

        if (isset(self::EXACT[$name])) {
            return self::EXACT[$name];
        }

        // path 파라미터는 위 진입부에서 이미 처리했다.
        return $this->byPattern($name, $type);
    }

    /**
     * path 파라미터(리소스 식별자)의 설명을 유추합니다.
     *
     * @param  string  $name  path 파라미터명
     * @return string|null 설명 (미매칭 시 null)
     */
    private function describePathParam(string $name): ?string
    {
        // 순수 id / *_id / *Id: 대상 리소스의 식별자
        if ($name === 'id') {
            return '대상 리소스의 식별자';
        }
        if (Str::endsWith($name, '_id')) {
            $base = $this->humanize(Str::beforeLast($name, '_id'));

            return "대상 {$base}의 식별자";
        }
        if (Str::endsWith($name, 'Id') && $name !== 'Id') {
            $base = $this->humanizeCamel(Str::beforeLast($name, 'Id'));

            return "대상 {$base}의 식별자";
        }

        // slug / identifier / hash / uuid: 리소스 지시 키
        if (in_array($name, ['slug', 'identifier', 'hash', 'uuid', 'code'], true)) {
            $labels = [
                'slug' => '대상 리소스의 slug (URL 친화 식별자)',
                'identifier' => '대상 리소스의 식별자',
                'hash' => '대상 리소스의 해시 식별자',
                'uuid' => '대상 리소스의 UUID',
                'code' => '대상 리소스의 코드',
            ];

            return $labels[$name];
        }

        // *Name (templateName, pluginName, moduleName): 확장/리소스 이름 식별자
        if (Str::endsWith($name, 'Name') && $name !== 'Name') {
            $base = $this->humanizeCamel(Str::beforeLast($name, 'Name'));

            return "대상 {$base}의 이름 (식별자)";
        }

        // *Identifier (templateIdentifier 등): 확장/리소스 식별자
        if (Str::endsWith($name, 'Identifier') && $name !== 'Identifier') {
            $base = $this->humanizeCamel(Str::beforeLast($name, 'Identifier'));

            return "대상 {$base}의 식별자";
        }

        // bare 리소스명 path 파라미터: Laravel route-model binding 은
        // `/{user}`, `/{role}`, `/{definition}` 처럼 대상 모델의 단수형(또는
        // camelCase)을 그대로 세그먼트로 쓴다. 접미 패턴(_id/slug/*Id 등)에
        // 걸리지 않은 path 파라미터는 이 바인딩 대상 리소스의 식별자로 본다.
        // 예외: key/version 은 리소스가 아니라 설정 키/버전 값이므로 EXACT 폴백.
        $bareExact = [
            'key' => '대상 설정/항목의 키',
            'version' => '대상 버전 (버전 문자열)',
        ];
        if (isset($bareExact[$name])) {
            return $bareExact[$name];
        }
        $base = str_contains($name, '_')
            ? $this->humanize($name)
            : $this->humanizeCamel($name);

        return "대상 {$base}의 식별자";
    }

    /**
     * query/body 파라미터의 일관된 명명 규칙으로 설명을 유추합니다.
     *
     * @param  string  $name  파라미터명
     * @param  string  $type  타입
     * @return string|null 설명 (미매칭 시 null)
     */
    private function byPattern(string $name, string $type): ?string
    {
        // identifier: 확장/리소스 지시 식별자 (query/body).
        // path 위치는 describePathParam 이 먼저 처리하므로 여기 도달하지 않는다.
        if ($name === 'identifier') {
            return '대상 확장/리소스의 식별자';
        }

        // *_name: 확장/리소스 이름 식별자 (template_name/plugin_name/module_name/layout_name 등).
        // EXACT 의 recipient_name/from_name 은 여기 도달 전에 이미 처리된다.
        if (Str::endsWith($name, '_name')) {
            $base = $this->humanize(Str::beforeLast($name, '_name'));

            return "{$base} 이름 (식별자)";
        }

        // *_id: 연관 리소스 식별자 참조
        if (Str::endsWith($name, '_id')) {
            $base = $this->humanize(Str::beforeLast($name, '_id'));

            return "{$base} 식별자";
        }

        // *_ids: 연관 리소스 식별자 배열
        if (Str::endsWith($name, '_ids')) {
            $base = $this->humanize(Str::beforeLast($name, '_ids'));

            return "{$base} 식별자 배열";
        }

        // is_*/has_*: 불리언 토글
        if ((Str::startsWith($name, 'is_') || Str::startsWith($name, 'has_')) && $type === 'boolean') {
            $base = $this->humanize(Str::after($name, '_'));

            return "{$base} 여부";
        }

        // *_date: 날짜 값
        if (Str::endsWith($name, '_date')) {
            $base = $this->humanize(Str::beforeLast($name, '_date'));

            return "{$base} 날짜";
        }

        // *_at: 일시 값
        if (Str::endsWith($name, '_at')) {
            $base = $this->humanize(Str::beforeLast($name, '_at'));

            return "{$base} 일시";
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

    /**
     * camelCase 를 사람이 읽는 문구로 변환합니다.
     *
     * @param  string  $token  camelCase 토큰
     * @return string 공백 구분 소문자 문구
     */
    private function humanizeCamel(string $token): string
    {
        return Str::lower(trim(preg_replace('/([A-Z])/', ' $1', $token)));
    }
}
