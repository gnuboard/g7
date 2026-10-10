<?php

namespace App\Analytics;

/**
 * 수집 요청 DTO
 *
 * 비콘·서버 폴백·내부 검색 훅 세 경로가 모두 이 형태로 바뀐 뒤에야 적재 파이프라인에
 * 들어갑니다. 적재는 큐로 넘어갈 수 있으므로 배열로 직렬화되어야 하고, 그래서 요청
 * 객체나 모델을 그대로 담지 않습니다.
 *
 * 헤더와 쿠키는 통째로 담지 않습니다 — 판정에 필요한 몇 개만 골라 담아야 큐에 남는
 * 페이로드에 세션 토큰 같은 것이 섞이지 않습니다.
 */
readonly class TrackingRequest
{
    /**
     * 수집 요청을 생성합니다.
     *
     * @param  string  $type  액션 종류 (pageview, download, outlink, site_search, event, goal)
     * @param  string  $url  방문 URL
     * @param  string|null  $title  페이지 제목
     * @param  string|null  $referrer  유입 URL
     * @param  string|null  $resolution  화면 해상도 (가로x세로)
     * @param  string|null  $browserLanguage  브라우저 언어
     * @param  string|null  $locale  사이트에서 보고 있던 로케일
     * @param  int|null  $prevTimeSpent  직전 페이지 체류 시간 (초)
     * @param  bool  $init  방문의 첫 비콘인지 여부
     * @param  string|null  $target  내려받기·외부링크 대상 URL
     * @param  string|null  $name  내려받기 파일명 등 표시 이름
     * @param  string|null  $goalKey  목표 키
     * @param  array<string, mixed>  $goalAttributes  목표 속성 (금액·통화·라벨)
     * @param  array<string, string>  $dimensions  확장이 넣은 차원 값
     * @param  array<string, mixed>  $clientHints  클라이언트 힌트
     * @param  bool  $webdriver  자동화 브라우저 표식
     * @param  string|null  $searchQuery  사이트 내부 검색어
     * @param  string|null  $searchTab  검색 탭
     * @param  int|null  $searchTotal  검색 결과 건수
     * @param  string|null  $ip  요청 IP
     * @param  string|null  $userAgent  User-Agent 문자열
     * @param  array<string, string>  $headers  판정에 쓰는 헤더만 (dnt·gpc·purpose 계열)
     * @param  array<string, string>  $cookies  판정에 쓰는 쿠키만 (방문자·제외 쿠키)
     * @param  int|null  $userId  회원 ID
     * @param  bool  $isAdmin  관리자 여부
     * @param  string|null  $sessionId  세션 ID (동의 조회용)
     * @param  string  $source  수집 경로 (beacon, server)
     * @param  string  $receivedAt  수신 시각 (UTC, Y-m-d H:i:s)
     * @param  string|null  $visitorKey  응답에서 이미 정한 방문자 식별값 (큐 경로 전용)
     */
    public function __construct(
        public string $type,
        public string $url,
        public ?string $title = null,
        public ?string $referrer = null,
        public ?string $resolution = null,
        public ?string $browserLanguage = null,
        public ?string $locale = null,
        public ?int $prevTimeSpent = null,
        public bool $init = false,
        public ?string $target = null,
        public ?string $name = null,
        public ?string $goalKey = null,
        public array $goalAttributes = [],
        public array $dimensions = [],
        public array $clientHints = [],
        public bool $webdriver = false,
        public ?string $searchQuery = null,
        public ?string $searchTab = null,
        public ?int $searchTotal = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public array $headers = [],
        public array $cookies = [],
        public ?int $userId = null,
        public bool $isAdmin = false,
        public ?string $sessionId = null,
        public string $source = 'beacon',
        public string $receivedAt = '',
        public ?string $visitorKey = null,
    ) {}

    /**
     * 방문자 식별값을 고정한 사본을 만듭니다.
     *
     * 큐 경로에서 씁니다 — 쿠키는 응답에서만 심을 수 있어 식별값을 컨트롤러가 먼저
     * 정하고, 워커는 그것을 그대로 써야 응답에 심은 쿠키와 적재된 방문이 맞물립니다.
     *
     * @param  string  $key  방문자 식별값
     * @return self 식별값이 고정된 사본
     */
    public function withVisitorKey(string $key): self
    {
        return new self(
            type: $this->type,
            url: $this->url,
            title: $this->title,
            referrer: $this->referrer,
            resolution: $this->resolution,
            browserLanguage: $this->browserLanguage,
            locale: $this->locale,
            prevTimeSpent: $this->prevTimeSpent,
            init: $this->init,
            target: $this->target,
            name: $this->name,
            goalKey: $this->goalKey,
            goalAttributes: $this->goalAttributes,
            dimensions: $this->dimensions,
            clientHints: $this->clientHints,
            webdriver: $this->webdriver,
            searchQuery: $this->searchQuery,
            searchTab: $this->searchTab,
            searchTotal: $this->searchTotal,
            ip: $this->ip,
            userAgent: $this->userAgent,
            headers: $this->headers,
            cookies: $this->cookies,
            userId: $this->userId,
            isAdmin: $this->isAdmin,
            sessionId: $this->sessionId,
            source: $this->source,
            receivedAt: $this->receivedAt,
            visitorKey: $key,
        );
    }

    /**
     * 일부 값을 바꾼 사본을 만듭니다.
     *
     * 적재 서비스가 등록부로 걸러 낸 차원·검증을 통과한 목표 속성처럼, 판정 뒤의 값으로
     * 요청을 이어 쓸 때 씁니다. 키는 `toArray()` 의 이름을 따릅니다.
     *
     * @param  array<string, mixed>  $overrides  바꿀 값 (직렬화 키 → 값)
     * @return self 값이 바뀐 사본
     */
    public function with(array $overrides): self
    {
        return self::fromArray(array_merge($this->toArray(), $overrides));
    }

    /**
     * 큐 직렬화를 위해 배열로 바꿉니다.
     *
     * @return array<string, mixed> 직렬화된 배열
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'url' => $this->url,
            'title' => $this->title,
            'referrer' => $this->referrer,
            'resolution' => $this->resolution,
            'browser_language' => $this->browserLanguage,
            'locale' => $this->locale,
            'prev_time_spent' => $this->prevTimeSpent,
            'init' => $this->init,
            'target' => $this->target,
            'name' => $this->name,
            'goal_key' => $this->goalKey,
            'goal_attributes' => $this->goalAttributes,
            'dimensions' => $this->dimensions,
            'client_hints' => $this->clientHints,
            'webdriver' => $this->webdriver,
            'search_query' => $this->searchQuery,
            'search_tab' => $this->searchTab,
            'search_total' => $this->searchTotal,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'headers' => $this->headers,
            'cookies' => $this->cookies,
            'user_id' => $this->userId,
            'is_admin' => $this->isAdmin,
            'session_id' => $this->sessionId,
            'source' => $this->source,
            'received_at' => $this->receivedAt,
            'visitor_key' => $this->visitorKey,
        ];
    }

    /**
     * 직렬화된 배열에서 되돌립니다.
     *
     * @param  array<string, mixed>  $data  직렬화된 배열
     * @return self 수집 요청
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? 'pageview'),
            url: (string) ($data['url'] ?? ''),
            title: self::nullableString($data['title'] ?? null),
            referrer: self::nullableString($data['referrer'] ?? null),
            resolution: self::nullableString($data['resolution'] ?? null),
            browserLanguage: self::nullableString($data['browser_language'] ?? null),
            locale: self::nullableString($data['locale'] ?? null),
            prevTimeSpent: isset($data['prev_time_spent']) ? (int) $data['prev_time_spent'] : null,
            init: (bool) ($data['init'] ?? false),
            target: self::nullableString($data['target'] ?? null),
            name: self::nullableString($data['name'] ?? null),
            goalKey: self::nullableString($data['goal_key'] ?? null),
            goalAttributes: is_array($data['goal_attributes'] ?? null) ? $data['goal_attributes'] : [],
            dimensions: self::stringMap($data['dimensions'] ?? null),
            clientHints: is_array($data['client_hints'] ?? null) ? $data['client_hints'] : [],
            webdriver: (bool) ($data['webdriver'] ?? false),
            searchQuery: self::nullableString($data['search_query'] ?? null),
            searchTab: self::nullableString($data['search_tab'] ?? null),
            searchTotal: isset($data['search_total']) ? (int) $data['search_total'] : null,
            ip: self::nullableString($data['ip'] ?? null),
            userAgent: self::nullableString($data['user_agent'] ?? null),
            headers: self::stringMap($data['headers'] ?? null),
            cookies: self::stringMap($data['cookies'] ?? null),
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            isAdmin: (bool) ($data['is_admin'] ?? false),
            sessionId: self::nullableString($data['session_id'] ?? null),
            source: (string) ($data['source'] ?? 'beacon'),
            receivedAt: (string) ($data['received_at'] ?? ''),
            visitorKey: self::nullableString($data['visitor_key'] ?? null),
        );
    }

    /**
     * 헤더 값을 소문자 이름으로 찾습니다.
     *
     * @param  string  $name  헤더 이름
     * @return string|null 헤더 값 (없으면 null)
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * 쿠키 값을 찾습니다.
     *
     * @param  string  $name  쿠키 이름
     * @return string|null 쿠키 값 (없으면 null)
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * 값을 문자열 또는 null 로 맞춥니다.
     *
     * @param  mixed  $value  원본 값
     * @return string|null 문자열 (비었으면 null)
     */
    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * 값을 문자열 맵으로 맞춥니다.
     *
     * @param  mixed  $value  원본 값
     * @return array<string, string> 문자열 맵
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item)) {
                $result[(string) $key] = (string) $item;
            }
        }

        return $result;
    }
}
