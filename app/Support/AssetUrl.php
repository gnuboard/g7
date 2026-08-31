<?php

namespace App\Support;

use App\Services\ExtensionStaticCacheService;
use App\Support\Routing\DualExtensionRoute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 자산·동적 엔드포인트 URL 생성기 (서버측 SSoT).
 *
 * ## 왜 필요한가
 *
 * G7 은 동적 API 엔드포인트에 정적 파일 확장자를 붙여 쓴다. 그런데 nginx/Apache 의
 * 표준적 정적 최적화 블록(`location ~* \.(js|css|json)$`)은 URL 마지막 확장자로
 * 분기하며, nginx 에서 정규식 location 은 프리픽스 location 보다 먼저 매칭되므로
 * `try_files ... /index.php` 폴백이 실행될 기회가 없다. 그런 환경에서는 확장자 붙은
 * 동적 응답이 PHP 에 도달하지 못하고 404 가 된다.
 *
 * 라우트는 이미 두 형태로 등록되어 있다(`DualExtensionRoute`). 남은 문제는 **URL 을
 * 만드는 쪽**이 13개 지점에 흩어져 하드코딩되어 있었다는 것이다. 한 곳만 빠뜨려도
 * 그 자산만 404 가 되고, 어느 지점이 빠졌는지는 화면이 죽어야 알 수 있다.
 * 그래서 생성 경로를 여기 하나로 모은다.
 *
 * ## 모드
 *
 * `general.asset_url_mode` 설정값을 따른다.
 *
 * | 모드 | 의미 |
 * |---|---|
 * | `extension` (기본) | 확장자 유지 — 정상 환경. 확장자 기반 캐시/gzip 최적화를 보존한다 |
 * | `extensionless` | 확장자 제거 — 정적 블록이 가로채는 환경 |
 *
 * 기본값이 `extension` 인 이유는 계획서 §"채택 방향" 을 따른다. 확장자를 일괄 제거하면
 * `expires max` / `gzip_static` / CDN TTL 규칙이 함께 걸린 다수의 정상 환경에서
 * 그 최적화를 전부 잃는다.
 *
 * @see DualExtensionRoute 라우트 이중 등록
 */
class AssetUrl
{
    /** 테스트가 고정한 경로 접두사 (null 이면 요청에서 판정). */
    private static ?string $basePrefixOverride = null;

    /** `absolute()` 오리진 미해결 경고를 이미 남겼는지 (프로세스당 1회). */
    private static bool $originUnresolvableWarned = false;

    /**
     * 접두사 불일치 경고를 이미 남겼음을 요청 자신에 표시하는 속성 키 (요청당 1회).
     *
     * 정적 `spl_object_id` 저장은 쓰지 않는다 — 그 id 는 객체가 해제되면 **재사용**되므로
     * 장수 프로세스(Octane·큐 워커)에서 앞 요청 직후의 다음 요청이 같은 id 를 받아 경고가
     * 조용히 삼켜졌다. 요청의 속성에 두면 그 의미가 객체 수명과 함께 끝난다 (16차 G3 라운드 B-8).
     */
    private const PREFIX_MISMATCH_WARNED_ATTRIBUTE = '_g7_asset_url_prefix_mismatch_warned';

    /**
     * 확장자 유지 모드 식별자.
     */
    public const MODE_EXTENSION = 'extension';

    /**
     * 확장자 제거 모드 식별자.
     */
    public const MODE_EXTENSIONLESS = 'extensionless';

    /**
     * 확장자 없는 자산 URL 에서 파일 경로를 담는 쿼리 파라미터명.
     */
    public const FILE_QUERY_PARAM = 'file';

    /**
     * 테스트/렌더 단위에서 모드를 강제하기 위한 오버라이드 값.
     *
     * null 이면 설정값을 조회한다.
     */
    private static ?string $modeOverride = null;

    /**
     * 정적 게시(bake) 베이스 경로 메모 (요청당 1회 판정).
     */
    private static ?string $staticExtBaseMemo = null;

    /**
     * 정적 게시 베이스 판정 완료 여부.
     */
    private static bool $staticExtBaseResolved = false;

    /**
     * 태그 계층 파일 단위 게이트 메모 (상대 경로 => 존재 여부).
     *
     * @var array<string, bool>
     */
    private static array $staticFileMemo = [];

    /**
     * 현재 자산 URL 모드를 반환합니다.
     *
     * 설정 조회가 실패해도(설치 전·마이그레이션 전 등) 예외를 던지지 않고
     * 기본 모드로 폴백한다 — 이 값은 blade 렌더 경로에서 읽히므로 여기서
     * 터지면 화면 전체가 죽는다.
     *
     * @return string `extension` 또는 `extensionless`
     */
    public static function mode(): string
    {
        if (self::$modeOverride !== null) {
            return self::$modeOverride;
        }

        try {
            $mode = g7_core_settings('general.asset_url_mode', self::MODE_EXTENSION);
        } catch (\Throwable $e) {
            return self::MODE_EXTENSION;
        }

        return $mode === self::MODE_EXTENSIONLESS ? self::MODE_EXTENSIONLESS : self::MODE_EXTENSION;
    }

    /**
     * 현재 모드가 확장자 없는 모드인지 여부를 반환합니다.
     *
     * @return bool 확장자 없는 모드이면 true
     */
    public static function isExtensionless(): bool
    {
        return self::mode() === self::MODE_EXTENSIONLESS;
    }

    /**
     * 모드를 강제로 지정합니다 (테스트 전용).
     *
     * @param  string|null  $mode  강제할 모드. null 이면 오버라이드 해제
     */
    public static function forceMode(?string $mode): void
    {
        self::$modeOverride = $mode;
    }

    /**
     * 정적 게시(bake) 베이스 경로를 반환합니다 (#122).
     *
     * 게이트 3조건 — ① 프로덕션 ② `core.static_cache.enabled` ③ 현재 버전 게시
     * 완료(manifest 존재) — 을 전부 통과할 때만 `/build/ext/{v}` 를 반환한다.
     * 아니면 null (종전 API URL 방출). 요청당 1회 판정 후 메모이즈한다.
     *
     * 자가 치유 — 프로덕션인데 현재 버전이 미게시면 terminating 게시를 예약한다.
     * 이번 응답은 종전 API URL 로 나가고(첫 방문자 1회만 종전 속도), 다음
     * 렌더부터 정적 fast path 가 적용된다.
     *
     * blade 렌더 경로에서 호출되므로 어떤 실패도 예외로 새 나가면 안 된다.
     *
     * @return string|null 정적 베이스 경로 또는 null
     */
    public static function staticExtBase(): ?string
    {
        if (self::$staticExtBaseResolved) {
            return self::$staticExtBaseMemo;
        }

        self::$staticExtBaseResolved = true;
        self::$staticExtBaseMemo = null;

        try {
            if (! app()->environment('production')) {
                return null;
            }

            if (! (bool) config('core.static_cache.enabled', true)) {
                return null;
            }

            // 트레이트 정적 메서드 직접 호출(ClearsTemplateCaches::)은 PHP 8.1+ E_DEPRECATED
            // — 트레이트를 사용하는 클래스 경유로 호출한다 (게이트·게시자가 같은 static
            // 스토어 메모 슬롯을 공유하게 되는 부수 이점도 있다)
            $version = ExtensionStaticCacheService::getExtensionCacheVersion();

            if (! app(ExtensionStaticCacheService::class)->isPublished($version)) {
                // 자가 치유 — 응답 종료 후 게시 시도 (실패해도 API 폴백으로 정상)
                ExtensionStaticCacheService::schedulePublishOnTerminate();

                return null;
            }

            self::$staticExtBaseMemo = '/build/ext/'.$version;
        } catch (\Throwable) {
            return null;
        }

        return self::$staticExtBaseMemo;
    }

    /**
     * 정적 게시 베이스 메모를 초기화합니다 (테스트 전용).
     */
    public static function resetStaticExtBaseMemo(): void
    {
        self::$staticExtBaseResolved = false;
        self::$staticExtBaseMemo = null;
        self::$staticFileMemo = [];
    }

    /**
     * 정적 게시본 내 파일 존재 여부를 확인합니다 (태그 계층 파일 단위 게이트).
     *
     * `<link>`/`<script>` 태그는 404 를 받아도 스스로 재시도하지 못하므로,
     * manifest 게이트에 더해 그 자산의 실파일 존재까지 확인한 뒤에만 정적 URL 을
     * 방출한다 (요청당 태그 대상 ~6개 파일, 메모이즈).
     *
     * @param  string  $relative  게시 트리 상대 경로
     * @return bool 파일 존재 여부
     */
    private static function staticFileExists(string $relative): bool
    {
        $version = substr((string) self::$staticExtBaseMemo, strlen('/build/ext/'));

        return self::$staticFileMemo[$relative] ??= is_file(
            public_path('build/ext/'.$version.'/'.$relative)
        );
    }

    /**
     * 태그 계층 자산의 정적 게시 URL 을 반환합니다 (파일 존재 확인 포함).
     *
     * @param  string  $relative  게시 트리 상대 경로
     * @return string|null 정적 URL 또는 null (게이트 미통과)
     */
    private static function staticTagUrl(string $relative): ?string
    {
        $base = self::staticExtBase();

        if ($base === null || ! self::staticFileExists($relative)) {
            return null;
        }

        return $base.'/'.$relative;
    }

    /**
     * 사이트 절대 경로를 절대 URL 로 바꿉니다.
     *
     * `url()` 을 쓰면 안 된다 — 그 헬퍼는 요청 루트(경로 접두사 포함)를 앞에
     * 붙이는데, 이 클래스가 만드는 경로에는 이미 그 접두사가 들어 있어
     * 하위 폴더 배치에서 `/g7/g7/api/…` 가 된다. 여기서는 scheme·호스트만 붙인다.
     *
     * 오리진(scheme·host)의 출처는 **설정이 우선**이고 요청은 폴백이다. 형제 갈래
     * (`public/storage` 링크가 있는 배치)는 `filesystems.disks.public.url` 을 쓰고 그
     * 기본값이 `APP_URL` 파생이므로, 여기서 요청을 우선하면 **같은 사이트의 같은 자산이
     * 링크 유무로 오리진이 갈린다.** 실제로 갈리는 배치가 둘 있다:
     *   · TLS 를 앞단에서 끝내는 프록시 — 요청은 `http` 로 도착해 https 페이지에
     *     `http://` 이미지가 실리고 브라우저가 혼합 콘텐츠로 **조용히 차단**한다.
     *   · 요청이 없는 문맥(큐가 만드는 메일 본문·콘솔이 렌더하는 사이트맵) — 종전에는
     *     입력을 그대로 돌려줘 상대 경로가 메일에 실렸다.
     * 설정이 비어 있거나 오리진을 담고 있지 않으면 종전대로 요청에서 취한다.
     *
     * @param  string  $path  `/` 로 시작하는 사이트 절대 경로
     * @return string 절대 URL (오리진을 알 수 없으면 입력 그대로)
     */
    public static function absolute(string $path): string
    {
        $configured = self::configuredOrigin();

        if ($configured !== '') {
            return $configured.$path;
        }

        $request = self::currentRequest();

        if ($request instanceof Request) {
            return rtrim($request->getSchemeAndHttpHost(), '/').$path;
        }

        // 오리진을 알 수 없다 — `APP_URL` 에 scheme·host 가 없고 요청도 없다(큐·콘솔). 경로만
        // 돌려주면 메일·사이트맵에 상대 경로가 실려 조용히 깨진다. 그 사실을 로그로 남긴다 —
        // 경로만 반환한다는 계약은 하위호환으로 유지하되, 원인을 진단할 통로를 둔다 (17차 G3 라운드 B-9).
        self::warnOriginUnresolvableOnce($path);

        return $path;
    }

    /**
     * 절대 주소의 오리진을 알 수 없을 때 **프로세스당 한 번** 경고를 남깁니다.
     *
     * `APP_URL` 에 scheme·host 가 없고(설정 누락·경로만 적음) 요청도 없는 문맥(큐·콘솔)에서만
     * 도달한다. 이때 `absolute()` 는 경로만 돌려주므로 메일 본문·사이트맵에 상대 경로가 실려
     * 봇·메일 수신자에게 깨진 링크가 된다. 웹 요청에서는 절대 재현되지 않아 로그가 유일한 통로다.
     *
     * 요청이 없어 요청 속성에 표시를 둘 수 없으므로 프로세스 정적 플래그로 억제한다 — 장수
     * 워커에서 매 잡마다 같은 경고를 쏟지 않게 한다. 테스트 프로세스는 제외한다.
     *
     * @param  string  $path  절대화하려던 경로
     */
    private static function warnOriginUnresolvableOnce(string $path): void
    {
        if (self::$originUnresolvableWarned || app()->runningUnitTests()) {
            return;
        }

        self::$originUnresolvableWarned = true;

        Log::warning('[AssetUrl] 절대 주소의 오리진을 알 수 없다 — APP_URL 에 scheme·host 가 없고 요청도 없다(큐·콘솔). 경로만 반환한다', [
            'path' => $path,
            'app_url' => (string) config('app.url'),
            'hint' => 'APP_URL 을 https://호스트 형태로 설정하면 큐·콘솔이 만드는 메일·사이트맵 주소가 절대 주소가 된다',
        ]);
    }

    /**
     * 사이트 내부 경로에 마운트 접두사를 **멱등으로** 채웁니다.
     *
     * 이 자리에는 두 종류의 값이 함께 도착한다 — 생산자가 이미 접두사를 실어 준 값
     * (`ProductImage::apiDownloadUrl()` · `Attachment::previewUrlForSlug()` 등이
     * `basePrefix().'/api/…'` 를 돌려준다)과, 레이아웃·설정이 리터럴로 적은 사이트
     * 경로(`/images/logo.png`)다. 앞의 것에 접두사를 다시 붙이면 `/g7/g7/…` 가 되어
     * 404 고, 뒤의 것에 붙이지 않으면 하위 폴더 배치에서 **사이트 밖**을 가리킨다.
     * 그래서 이미 접두사를 가진 값은 그대로 두고 없는 값만 채운다.
     *
     * 판정 대상은 **루트 상대 경로**뿐이다. 절대 URL(`https://…`)·프로토콜 상대
     * (`//cdn/…`)·스킴 값(`data:` · `mailto:`)·상대 경로(`img.png`)는 사이트 경로가
     * 아니므로 손대지 않는다 — 이 가드가 없으면 `https://host//cdn/…` ·
     * `https://hostdata:image/…` 같은 값이 조용히 만들어진다.
     *
     * 종전에는 이 규칙이 코어 SEO 두 곳(`ComponentHtmlMapper`)과 한 곳
     * (`SeoMetaResolver`)에 **세 벌의 독립 사본**으로 살아 있었고, 번들 모듈 5지점은
     * 아예 순진한 형태(`str_starts_with('http') ? … : absolute()`)라 위 가드도 접두사
     * 채움도 없었다. 사본이 갈려도 오류가 나지 않고 그 자산만 404 가 되므로, 규칙을
     * 여기 한 곳으로 접는다 (16차 G3 라운드 A-4·A-9).
     *
     * @param  string  $path  사이트 경로 후보
     * @return string 접두사가 채워진 경로 (판정 대상이 아니면 원본 그대로)
     */
    public static function sitePath(string $path): string
    {
        // 앞뒤 공백은 판정 전에 걷어낸다 — `' /images/a.png'` 는 `/` 로 시작하지 않아
        // 사이트 경로가 아닌 값으로 읽히고, 접두사 없이 그대로 나갔다.
        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }

        $prefix = self::basePrefix();

        if ($prefix === '' || self::hasBasePrefix($path, $prefix)) {
            return $path;
        }

        return $prefix.$path;
    }

    /**
     * 루트 상대 경로가 마운트 접두사를 **이미** 갖고 있는지 판정합니다.
     *
     * 접두사 뒤가 끝·`/`·`?`·`#` 이면 접두사 보유다. 종전 판정은 끝과 `/` 두 형태만
     * 알아서, 하위 폴더 홈에 쿼리가 붙은 복귀 주소(`/g7?ref=x`)가 `/g7/g7?ref=x` 로
     * 이중 접두가 됐다(로그인 직후 사이트 밖으로 나가는 조용한 404). 반대로 `/g7x/foo`
     * 는 접두사가 경계 없이 이어진 **다른 경로**라 보유가 아니다 — `str_starts_with`
     * 로 넓히면 이쪽이 조용히 빠진다. 프론트 `withBasePrefix()`/`stripBasePrefix()` 와
     * 같은 규칙이다. 공개인 이유: 판정을 자기 사본으로 가진 소비처
     * (`ResolvesPublicUrl::stripSiteBasePrefix()`)가 있었다 — 네 번째 독립 사본이었고,
     * 사본은 갈려도 울지 않는다.
     *
     * @param  string  $path  `/` 로 시작하는 경로
     * @param  string  $prefix  `/` 로 시작하고 `/` 로 끝나지 않는 접두사
     * @return bool 접두사를 이미 갖고 있으면 true
     */
    public static function hasBasePrefix(string $path, string $prefix): bool
    {
        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        $next = substr($path, strlen($prefix), 1);

        return $next === '' || $next === '/' || $next === '?' || $next === '#';
    }

    /**
     * 사이트 내부 경로를 **접두사를 한 번만 실은** 절대 URL 로 만듭니다.
     *
     * `url()` 을 쓰면 안 된다 — 그 헬퍼는 요청 루트(오리진 **+ 마운트 접두사**)를 앞에
     * 붙이는데 이 자리에 오는 값 상당수가 접두사를 이미 품고 있어 `/g7/g7/…` 가 된다.
     * 그렇다고 `absolute()` 만 쓰면 리터럴 사이트 경로에 접두사를 붙일 주체가 사라진다.
     * 그래서 `sitePath()` 로 보정한 뒤 오리진만 붙인다.
     *
     * 크롤러·메일처럼 **화면에 아무 표시가 남지 않는** 소비처가 이 값을 받으므로,
     * 어긋나도 증상은 「미리보기 이미지가 안 나온다」뿐이다.
     *
     * @param  string  $path  사이트 경로 후보
     * @return string 절대 URL (판정 대상이 아니면 원본 그대로)
     */
    public static function siteAbsolute(string $path): string
    {
        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }

        return self::absolute(self::sitePath($path));
    }

    /**
     * 사이트의 루트 절대 주소(오리진 + 마운트 접두사, 후행 슬래시 없음)를 돌려줍니다.
     *
     * 알림·메일이 「사이트로 가기」 링크로 싣는 값이다. 종전에는 `config('app.url')` 원문을 실어
     * 운영자가 `APP_URL` 에 후행 슬래시를 붙이면 그 형태가 그대로 메일에 나갔고, 요청이 접두사를
     * 실은 배치와 설정만 아는 배치(큐)가 다른 값을 냈다. `siteAbsolute('/')` 와 같은 규칙에서
     * 후행 슬래시만 걷어낸 값이라 형제 링크(`siteAbsolute('/login')`)와 언제나 같은 앞부분을 갖는다
     * (16차 G3 라운드 B-4).
     *
     * @return string 예: `https://example.com/g7` (루트 설치면 `https://example.com`)
     */
    public static function siteRoot(): string
    {
        return rtrim(self::siteAbsolute('/'), '/');
    }

    /**
     * 현재 요청을 돌려줍니다 (없으면 `null`).
     *
     * `request()` 헬퍼는 컨테이너에 `request` 바인딩이 없으면 **예외를 던진다**.
     * 이 클래스는 큐·콘솔·부팅 초기처럼 요청이 없는 문맥에서도 불리므로, 그 상태를
     * 예외가 아니라 「요청 없음」으로 다룬다.
     *
     * @return Request|null 현재 요청
     */
    private static function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * `app.url` 이 담고 있는 오리진(scheme://host[:port])을 돌려줍니다.
     *
     * 경로 부분은 버린다 — 이 클래스가 만드는 경로에는 마운트 접두사가 이미 들어 있어
     * 여기서 다시 붙이면 `/g7/g7/api/…` 가 된다.
     *
     * 스킴·호스트는 소문자로 정규화한다 (RFC 3986 §6.2.2.1 — 둘 다 대소문자 무관).
     * `APP_URL=HTTPS://Example.COM/g7/` 로 적힌 사이트에서 크롤러가 받는 절대 주소가
     * 그 표기를 그대로 실어, 같은 자산의 정본 주소가 표기에 따라 갈렸다. 경로는 대소문자를
     * 구분하므로 손대지 않는다(여기서는 버린다).
     *
     * @return string 오리진. 설정이 비었거나 scheme·host 를 담고 있지 않으면 `''`
     */
    private static function configuredOrigin(): string
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port;
    }

    /**
     * 사이트가 마운트되는 경로 접두사를 돌려줍니다 (예: `/g7`, 루트면 빈 문자열).
     *
     * 이 클래스가 만드는 것은 **루트 상대 경로**(`/api/…`)라, 사이트가 문서 루트
     * 아래 하위 폴더에 마운트된 배치에서는 그 주소가 **사이트 밖**을 가리킨다
     * (`https://host/g7/` 에 설치했는데 `https://host/api/…` 를 부른다). 형제 헬퍼
     * `asset()`·`url()` 은 이 접두사를 알고 붙이므로, 같은 blade 안에서 코어 번들과
     * 템플릿 번들이 서로 다른 규칙을 따르는 상태였다.
     *
     * 프론트도 같은 값을 공유해야 한다 — 모드 변환기가 `/api/` 로 시작하는지만
     * 보기 때문에, 접두사가 붙은 URL 을 몰라보면 R33 자가 복구가 무력해진다.
     * 그 공유는 `window.G7Config.basePath` 가 맡는다.
     *
     * 요청의 base URL 이 **비어 있을 때만** `app.url` 의 경로 부분으로 내려간다. 요청이
     * 실어 온 값은 그 요청이 실제로 마운트된 위치라 언제나 그쪽이 권위이고, 비는 경우는
     * 둘뿐이다 — ① 루트 설치(설정 경로도 비어 있어 결과가 같다) ② 앞단 프록시가 접두사를
     * 벗겨 전달하는 배치(여기서 설정을 보지 않으면 서버가 만드는 자산 URL 이 전부 접두사를
     * 잃는다). 요청이 없는 문맥(큐·콘솔)도 같은 폴백을 탄다 — 종전에는 빈 문자열이라
     * 메일 본문의 이미지가 사이트 밖을 가리켰다.
     *
     * 이 폴백은 종전에 `ResolvesPublicUrl::stripSiteBasePrefix()` 가 자기 안에 따로
     * 갖고 있던 규칙이다. 접두사 규칙이 두 곳에서 도출되면 한쪽만 고쳐질 때 판정과
     * 생성이 어긋나므로 여기로 접었다.
     *
     * @return string `''` 또는 `/` 로 시작하고 `/` 로 끝나지 않는 접두사
     */
    public static function basePrefix(): string
    {
        if (self::$basePrefixOverride !== null) {
            return self::$basePrefixOverride;
        }

        $request = self::currentRequest();

        if ($request instanceof Request) {
            $fromRequest = rtrim($request->getBaseUrl(), '/');

            if ($fromRequest !== '') {
                self::warnPrefixMismatchOnce($request, $fromRequest);

                return $fromRequest;
            }
        }

        return self::configuredBasePath();
    }

    /**
     * 요청이 실어 온 접두사가 `app.url` 의 경로와 다르면 **요청당 한 번** 경고를 남깁니다.
     *
     * 웹 요청은 자기 접두사를 알지만 요청이 없는 문맥(큐가 만드는 메일 · 콘솔이 만드는
     * 사이트맵)은 `app.url` 의 경로 부분(`configuredBasePath()`)만 안다. 둘이 다르면 그
     * 문맥이 만드는 주소가 전부 **사이트 밖**을 가리키는데, 그 결함은 웹에서는 절대
     * 재현되지 않고 메일의 깨진 이미지 · 크롤러의 404 로만 나타난다. 웹 요청 안에서는
     * 두 값을 나란히 볼 수 있으므로 그 자리에서 로그로 남긴다 (15차 G3 라운드 W4-4).
     *
     * 테스트 프로세스는 제외한다 — 케이스마다 요청·설정을 임의로 만들어 불일치가 흔하고,
     * `Log` 파사드 기대를 가진 테스트에서 예기치 않은 호출이 된다.
     *
     * @param  Request  $request  현재 요청
     * @param  string  $fromRequest  요청이 실어 온 접두사
     */
    private static function warnPrefixMismatchOnce(Request $request, string $fromRequest): void
    {
        $configuredBasePath = self::configuredBasePath();
        $basePathMismatch = $configuredBasePath !== $fromRequest;

        // 오리진 축도 함께 본다 — `absolute()` 는 설정 오리진을 요청보다 우선하므로, 설정 오리진이
        // 요청 오리진과 다르면 큐·콘솔이 만드는 PG 복귀·메일·사이트맵 주소가 웹과 다른 호스트를
        // 가리킨다. 경로 축만 재던 종전에는 이 어긋남이 어디에도 드러나지 않았다 (17차 G3 라운드 B-10).
        $configuredOrigin = self::configuredOrigin();
        $requestOrigin = strtolower(rtrim($request->getSchemeAndHttpHost(), '/'));
        $originMismatch = $configuredOrigin !== '' && $configuredOrigin !== $requestOrigin;

        if (! $basePathMismatch && ! $originMismatch) {
            return;
        }

        if ($request->attributes->get(self::PREFIX_MISMATCH_WARNED_ATTRIBUTE) === true || app()->runningUnitTests()) {
            return;
        }

        $request->attributes->set(self::PREFIX_MISMATCH_WARNED_ATTRIBUTE, true);

        Log::warning('[AssetUrl] 요청의 마운트 위치와 APP_URL 이 다르다 — 큐·콘솔이 만드는 주소(PG 복귀·메일·사이트맵)가 사이트 밖을 가리킨다', [
            'request_base_path' => $fromRequest,
            'configured_base_path' => $configuredBasePath,
            'request_origin' => $requestOrigin,
            'configured_origin' => $configuredOrigin,
            'app_url' => (string) config('app.url'),
            'hint' => 'APP_URL 의 오리진·경로를 요청의 마운트 위치와 같게 맞추거나 php artisan g7:asset-url-mode 로 진단',
        ]);
    }

    /**
     * `app.url` 이 담고 있는 경로 접두사를 돌려줍니다.
     *
     * @return string `''` 또는 `/` 로 시작하고 `/` 로 끝나지 않는 접두사
     */
    public static function configuredBasePath(): string
    {
        $path = parse_url((string) config('app.url'), PHP_URL_PATH);

        if (! is_string($path)) {
            return '';
        }

        $path = rtrim($path, '/');

        return str_starts_with($path, '/') ? $path : '';
    }

    /**
     * 경로 접두사를 강제합니다 (테스트 전용).
     *
     * @param  string|null  $prefix  `null` 이면 요청에서 다시 판정
     */
    public static function forceBasePrefix(?string $prefix): void
    {
        self::$basePrefixOverride = $prefix === null ? null : rtrim($prefix, '/');
    }

    /**
     * 템플릿 자산 URL 을 생성합니다.
     *
     * 템플릿은 서버가 `dist/` 를 자동 부가하므로 `$path` 는 `dist/` 를 포함하지 않는다
     * (`TemplateService::getAssetFilePath`). 모듈/플러그인과 비대칭이므로 주의.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $path  `dist/` 이하 파일 경로 (예: `js/components.iife.js`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @param  bool  $allowStatic  정적 게시본(bake) 경로 허용 여부. 생성한 URL 이 정적
     *                             게시 GC(현재+직전 1개 보존)보다 오래 사는 저장소에 박제되는
     *                             호출부(SEO 페이지 캐시 등)는 false 로 종전 API URL 을 받는다
     * @return string 생성된 URL
     */
    public static function templateAsset(string $identifier, string $path, int|string|null $version = null, bool $allowStatic = true): string
    {
        // 정적 게시본(bake) 우선 (#122) — 버전 디렉토리 경로라 `?v` 쿼리가 불필요하다.
        // 게이트(프로덕션·kill-switch·게시 완료·개별 파일 존재) 미통과 시 종전 URL 그대로.
        // `$version` 이 현재 게시 버전과 다르면 정적 분기를 건너뛴다 — 정적 경로는 항상
        // 현재 게시본이므로, 다른 버전을 명시한 호출에 현재본을 주면 "요청 버전이 URL 에
        // 반영된다" 는 시그니처 계약이 조용히 깨진다 (현 호출부는 전부 현재 버전 전달).
        $static = null;
        if ($allowStatic) {
            $base = self::staticExtBase();

            if ($base !== null && ($version === null || $base === '/build/ext/'.$version)) {
                $static = self::staticTagUrl('templates/'.$identifier.'/assets/'.ltrim($path, '/'));
            }
        }

        return $static ?? self::asset('templates', $identifier, $path, $version);
    }

    /**
     * 모듈 자산 URL 을 생성합니다.
     *
     * 모듈은 모듈 루트 기준이라 `$path` 에 `dist/` 를 직접 포함해야 한다
     * (`ModuleService::getAssetFilePath`).
     *
     * @param  string  $identifier  모듈 식별자
     * @param  string  $path  모듈 루트 기준 파일 경로 (예: `dist/js/x.iife.js`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function moduleAsset(
        string $identifier,
        string $path,
        int|string|null $version = null,
        bool $allowStatic = false
    ): string {
        return self::extensionStaticOrApi('modules', $identifier, $path, $version, $allowStatic);
    }

    /**
     * 플러그인 자산 URL 을 생성합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  string  $path  플러그인 루트 기준 파일 경로 (예: `dist/js/x.iife.js`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function pluginAsset(
        string $identifier,
        string $path,
        int|string|null $version = null,
        bool $allowStatic = false
    ): string {
        return self::extensionStaticOrApi('plugins', $identifier, $path, $version, $allowStatic);
    }

    /**
     * 모듈·플러그인 자산의 정적 게시본 우선 URL 을 만듭니다.
     *
     * 정적 분기가 **기본 꺼짐**인 것이 템플릿과 다른 점이고, 그것이 의도다. 모듈·플러그인의
     * 빌드 산출물은 개별 파일로 게시되지 않고 **병합 번들**로만 게시되므로, 그 경로에
     * 존재 검사를 걸어 봐야 언제나 실패하는 파일시스템 조회만 늘어난다. 개별 게시 대상은
     * 운영자 소유 디렉토리(`custom/`) 하나뿐이라 그 호출부만 켜서 쓴다.
     *
     * @param  string  $root  `modules` | `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $path  확장 루트 기준 파일 경로
     * @param  int|string|null  $version  캐시 무효화 버전
     * @param  bool  $allowStatic  정적 게시본 우선 여부
     * @return string 생성된 URL
     */
    private static function extensionStaticOrApi(
        string $root,
        string $identifier,
        string $path,
        int|string|null $version,
        bool $allowStatic
    ): string {
        // 게이트는 템플릿과 동일하다 — 프로덕션·kill-switch·게시 완료·개별 파일 존재를
        // `staticTagUrl` 이 확인하고, 버전이 현재 게시본과 다르면 정적 분기를 건너뛴다.
        if ($allowStatic) {
            $base = self::staticExtBase();

            if ($base !== null && ($version === null || $base === '/build/ext/'.$version)) {
                $static = self::staticTagUrl($root.'/'.$identifier.'/assets/'.ltrim($path, '/'));

                if ($static !== null) {
                    return $static;
                }
            }
        }

        return self::asset($root, $identifier, $path, $version);
    }

    /**
     * 확장 타입을 인자로 받는 자산 URL 생성기.
     *
     * 모듈/플러그인을 같은 코드 경로로 처리하는 호출부(공용 트레이트 등)용.
     * 타입이 컴파일 시점에 정해져 있으면 `moduleAsset()` / `pluginAsset()` 를 쓴다.
     *
     * @param  string  $type  `templates` / `modules` / `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $path  파일 경로
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function extensionAsset(string $type, string $identifier, string $path, int|string|null $version = null): string
    {
        return self::asset($type, $identifier, $path, $version);
    }

    /**
     * 확장 병합 번들 URL 을 생성합니다.
     *
     * 접미사(js/css)가 번들 종류를 구분하므로 제거할 수 없다.
     * 확장자 없는 모드에서는 경로 세그먼트로 내린다 (`bundle.js` → `bundle/js`).
     *
     * @param  string  $type  `modules` 또는 `plugins`
     * @param  string  $kind  `js` 또는 `css`
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function extensionBundle(string $type, string $kind, int|string|null $version = null): string
    {
        // 정적 게시본(bake) 우선 (#122) — `bundles/{modules|plugins}.{js|css}` 사본.
        // `$version` 명시 호출이 현재 게시 버전과 다르면 건너뛴다 (templateAsset 과 동일 계약)
        $base = self::staticExtBase();
        $static = $base !== null && ($version === null || $base === '/build/ext/'.$version)
            ? self::staticTagUrl("bundles/{$type}.{$kind}")
            : null;

        if ($static !== null) {
            return $static;
        }

        $base = self::isExtensionless()
            ? "/api/{$type}/bundle/{$kind}"
            : "/api/{$type}/bundle.{$kind}";

        return self::basePrefix().$base.self::versionQuery($version);
    }

    /**
     * 공개 스토리지(`storage/app/public`) 대체 서빙 URL 을 생성합니다 (B-3).
     *
     * `public/storage` 심볼릭 링크가 없는 환경에서 public 디스크 파일의 공개 URL 로
     * 쓰인다 — 선택은 `ResolvesPublicUrl`(링크 판정)이 하고, 이 메서드는 형태만 만든다.
     * 와일드카드 자산 URL 이므로 확장자 없는 모드에서는 `?file=` 쿼리로 옮긴다 (R33).
     *
     * @param  string  $path  `storage/app/public` 기준 상대 경로
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function publicStorage(string $path, int|string|null $version = null): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (! self::isExtensionless()) {
            return self::basePrefix().'/api/system/public-storage/'.$path.self::versionQuery($version);
        }

        $query = self::FILE_QUERY_PARAM.'='.rawurlencode($path);

        if ($version !== null && $version !== '') {
            $query .= '&v='.$version;
        }

        return self::basePrefix().'/api/system/public-storage?'.$query;
    }

    /**
     * 고정 접미사를 갖는 동적 엔드포인트 URL 을 생성합니다.
     *
     * 확장자 없는 모드에서는 접미사를 제거한다 (`routes.json` → `routes`).
     *
     * @param  string  $path  접미사를 제외한 경로 (예: `/api/templates/foo/routes`)
     * @param  string  $suffix  접미사 (예: `json`)
     * @param  int|string|null  $version  캐시 무효화 버전 (null 이면 미부착)
     * @return string 생성된 URL
     */
    public static function suffixed(string $path, string $suffix, int|string|null $version = null): string
    {
        $base = rtrim($path, '/');
        $normalized = ltrim($suffix, '.');

        $url = self::isExtensionless() ? $base : $base.'.'.$normalized;

        // 호출부가 넘기는 `$path` 는 루트 상대(`/api/…`)다 — 접두사는 `sitePath()` 가
        // 한 번만 붙인다. 프론트 `suffixed()` 가 `withBasePrefix()` 를 경유하는 것과
        // 같은 규칙이라 프로토콜 상대(`//cdn/…`) 가드와 멱등을 함께 갖는다. 종전에는
        // `/` 로 시작하는지만 보고 무조건 붙여 `/g7//cdn/…` · `/g7/g7/api/…` 가 됐다.
        return self::sitePath($url).self::versionQuery($version);
    }

    /**
     * 확장 자산의 **API 서빙 URL** 을 생성합니다 (정적 게시본 분기 없음).
     *
     * 정적 게시본을 건너뛰는 이유: 이 메서드의 호출자는 CSS 서빙 컨트롤러다. 게시본이
     * 활성이면 그 CSS 자체가 웹서버에서 경로 형태로 나가 컨트롤러에 도달하지 않으므로,
     * 여기 도달했다는 것은 이 요청에 게시본이 적용되지 않았다는 뜻이다. 한 스타일시트
     * 안에서 서빙 경로가 갈리지 않도록 API 형태로 통일한다.
     *
     * @param  string  $type  `templates` / `modules` / `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $path  확장 기준 파일 경로
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return string 생성된 URL (현재 모드 반영)
     */
    public static function extensionApiAsset(string $type, string $identifier, string $path, int|string|null $version = null): string
    {
        return self::asset($type, $identifier, $path, $version);
    }

    /**
     * 확장 자산 URL 을 생성하는 공통 구현.
     *
     * 확장자 없는 모드에서는 파일 경로를 `?file=` 쿼리로 옮긴다. 경로가 곧 파일명이라
     * 접미사만 떼어낼 수 없기 때문이며, nginx 의 location 정규식이 쿼리스트링을 제외한
     * 경로에만 매칭되므로 이 형태가 안전하다.
     *
     * @param  string  $type  `templates` / `modules` / `plugins`
     * @param  string  $identifier  확장 식별자
     * @param  string  $path  파일 경로
     * @param  int|string|null  $version  캐시 무효화 버전
     * @return string 생성된 URL
     */
    private static function asset(string $type, string $identifier, string $path, int|string|null $version): string
    {
        $path = ltrim($path, '/');
        // 식별자는 프론트 `extensionAsset()` 과 같이 인코딩한다 — 슬러그라 발현은 없지만
        // 규칙 사본이 갈리면 「어느 쪽이 맞는가」가 다음 회차로 되돌아온다. 인코더는
        // `?file=` 값과 같은 `rawurlencode`(RFC 3986)이고, 프론트도 같은 자리에서 같은
        // 규칙의 `rawEncode()` 를 쓴다 (`encodeURIComponent` 는 `! ' ( ) *` 를 남겨
        // 갈린다 — 공유 기대표 `tests/Fixtures/AssetUrl/file-query-parity.json` 이 고정).
        $identifier = rawurlencode($identifier);

        if (! self::isExtensionless()) {
            return self::basePrefix()."/api/{$type}/assets/{$identifier}/{$path}".self::versionQuery($version);
        }

        $query = self::FILE_QUERY_PARAM.'='.rawurlencode($path);

        if ($version !== null && $version !== '') {
            $query .= '&v='.$version;
        }

        return self::basePrefix()."/api/{$type}/assets/{$identifier}?{$query}";
    }

    /**
     * 캐시 무효화 쿼리스트링을 생성합니다.
     *
     * @param  int|string|null  $version  버전 값
     * @return string `?v=...` 또는 빈 문자열
     */
    private static function versionQuery(int|string|null $version): string
    {
        if ($version === null || $version === '') {
            return '';
        }

        return '?v='.$version;
    }
}
