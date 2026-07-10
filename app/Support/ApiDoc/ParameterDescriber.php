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
        's3_region' => 'S3 리전',
        's3_access_key' => 'S3 액세스 키',
        's3_secret_key' => 'S3 시크릿 키',
        's3_url' => 'S3 엔드포인트 URL',
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

        // path 파라미터는 대부분 리소스 식별자 — 위치를 근거로 유추.
        if ($location === 'path') {
            return $this->describePathParam($name);
        }

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
