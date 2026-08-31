<?php

namespace App\Services;

use App\Extension\Helpers\StorageLinkHelper;
use Illuminate\Support\Facades\Log;

/**
 * 공개 스토리지(`storage/app/public`) 읽기 전용 서빙 경로 해석 서비스 (B-3).
 *
 * `public/storage` 심볼릭 링크를 만들 수 없는 호스팅에서 public 디스크 파일을
 * HTTP 로 내보내는 대체 통로다. 링크가 있는 환경에서는 정적 서빙(`/storage/...`)이
 * 그대로 쓰이고, 이 통로는 링크 부재 시 URL 생성기(ResolvesPublicUrl → AssetUrl)가
 * 자동 선택한다.
 *
 * 보안 계약:
 *  - 루트는 `rootPath()` 단일 지점이 정한다 (`filesystems.disks.public.root` 우선,
 *    미설정·비로컬 드라이버·상대 경로이면 `storage/app/public`) — 경로 조립에 다른 루트 불가
 *  - 경로 순회는 FormRequest(SafeTemplatePath)와 이 서비스의 realpath 봉쇄가 이중 차단
 *  - 심볼릭 링크는 추적하지 않는다 — 링크를 경유하는 접근은 파일이 루트 안을 가리켜도 거부
 *  - 읽기 전용(GET) — 쓰기 입구를 닫는 기존 정책(`serve => false`, 공개#52)과 충돌하지 않는다
 */
class PublicStorageService
{
    /**
     * 서빙 루트 폴백 경고의 프로세스 단위 memo (메시지 => true).
     *
     * @var array<string, true>
     */
    private static array $rootPathWarned = [];

    /**
     * 경고 memo 를 비웁니다 (테스트 전용).
     */
    public static function resetRootPathWarnings(): void
    {
        static::$rootPathWarned = [];
    }

    /**
     * 확장자 → MIME 타입 매핑 (finfo 가 text/plain 으로 뭉개는 웹 자산 유형 명시).
     *
     * 형제 표(`ResolvesExtensionAssetType::EXTENSION_ASSET_MIME_TYPES`)와 **합치지
     * 않는다.** 두 표는 모집단이 다르다 — 그쪽은 확장이 배포하는 자산이라 화이트리스트
     * (`Allowed*FileType`)에 묶인 닫힌 집합이고, 이쪽은 운영자가 올린 임의의 파일이라
     * 미디어·문서 유형까지 담아야 한다. 합치면 한쪽은 확장이 내보낼 수 있는 유형이
     * 넓어지고 다른 쪽은 업로드물이 전부 `application/octet-stream` 이 된다.
     *
     * 겹치는 키(`js`·`css`·`json`)에서 이쪽만 `; charset=utf-8` 을 붙이는 차이는 알고
     * 있으며 그대로 둔다 — 둘 다 유효하고(그쪽은 nginx 기본 `charset off` 인 정적 서빙과
     * 같은 형태, 이쪽은 브라우저에 인코딩을 명시하는 형태), 어느 한쪽으로 맞추는 것은
     * 이번 회차가 측정한 결함이 아니라 두 통로의 응답 헤더를 바꾸는 별개 결정이다
     * (7차 G3 라운드 C3 — 결함 아님으로 판정한 근거를 다음 라운드가 다시 올리지 않도록 남긴다).
     *
     * @var array<string, string>
     */
    private const MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        // nginx `mime.types` 와 같은 유형 — 표에 없어 octet-stream 으로 내려가면 링크가 되는
        // 서버의 정적 서빙과 갈린다. `svgz` 는 넣지 않는다: 본문이 gzip 이라 정적 서빙은
        // `Content-Encoding: gzip` 을 함께 싣는데 이 통로에는 그 축이 없어, 유형만 선언하면
        // 브라우저가 압축 바이트를 SVG 로 파싱해 깨진다. 내려받기(octet-stream)가 그보다 낫다.
        'xhtml' => 'application/xhtml+xml',
        'xml' => 'application/xml; charset=utf-8',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
        'zip' => 'application/zip',
    ];

    /**
     * 이 통로가 서빙하는 루트 디렉토리를 돌려줍니다.
     *
     * 운영자가 `filesystems.disks.public.root` 를 옮긴 배치가 있고, 파일을 **쓰는** 쪽
     * (`Storage::disk('public')`)은 그 설정을 따른다. 이 통로가 `storage_path('app/public')`
     * 를 리터럴로 쓰면 쓰는 곳과 읽는 곳이 갈려, 링크가 없는 그 사이트의 공개 자산이
     * 통째로 404 가 된다. 6차 라운드가 형제 키 `url` 에 대해 세운 「운영자 설정이 링크
     * 판정보다 우선」 규칙을 같은 축의 `root` 에도 적용한다.
     *
     * 판정 지점을 하나로 두는 이유: 종전에는 이 서비스와 FormRequest 가 각자 같은 리터럴을
     * 적고 있어 한쪽만 고치면 검증과 서빙의 기준이 어긋난다.
     *
     * 그 설정값을 **로컬 절대 경로일 때만** 신뢰한다. `root` 는 디스크 드라이버마다 뜻이
     * 다르다 — 로컬은 파일시스템 경로지만 S3 는 버킷 안의 **키 접두사**(`media` 등)다.
     * 운영자가 public 디스크를 S3 로 돌린 배치에서 그 값을 그대로 쓰면 `realpath('media')`
     * 가 **웹 프로세스의 작업 디렉토리** 기준으로 풀려, 봉쇄의 기준선이 storage 트리 밖의
     * 임의 디렉토리가 된다. 상대 경로도 같은 이유로 거부한다. 두 경우 모두 기본 루트로
     * 폴백하고 사실을 남긴다 — 조용히 폴백하면 그 사이트의 공개 자산이 왜 404 인지
     * 운영자에게 도달할 통로가 없다.
     *
     * @return string 공개 스토리지 루트 절대 경로
     */
    public static function rootPath(): string
    {
        $default = storage_path('app/public');
        $configured = config('filesystems.disks.public.root');

        if (! is_string($configured) || $configured === '') {
            return $default;
        }

        $driver = config('filesystems.disks.public.driver');

        if ($driver !== null && $driver !== 'local') {
            static::warnRootPathOnce('공개 디스크가 로컬 드라이버가 아니어서 설정된 root 를 서빙 루트로 쓰지 않습니다', [
                'driver' => $driver,
                'configured_root' => $configured,
                'fallback' => $default,
            ]);

            return $default;
        }

        if (! static::isAbsolutePath($configured)) {
            static::warnRootPathOnce('공개 디스크 root 가 절대 경로가 아니어서 서빙 루트로 쓰지 않습니다', [
                'configured_root' => $configured,
                'fallback' => $default,
            ]);

            return $default;
        }

        return $configured;
    }

    /**
     * 서빙 루트 폴백 경고를 **한 요청 안에서 1회만** 남깁니다.
     *
     * 이 판정은 한 요청 안에서 두 번 불린다(서빙 + FormRequest 규칙 생성). 접지 않으면
     * 같은 줄이 요청마다 두 벌씩 쌓인다 — 같은 파일이 `getFilePath()` 의 형제 경고에
     * 대해 이미 「파일마다 남기면 자산 요청 수만큼 쌓인다」는 이유로 조건을 좁혀 두었고,
     * 이 두 지점만 그 규율 밖이었다.
     *
     * 접히는 범위는 **한 요청**이다. 종전 독블록은 「프로세스당 1회」라 적었는데, PHP-FPM ·
     * mod_php 는 요청마다 정적 속성을 초기화하므로 그 표현은 사실과 다르다 — 배포 모델을
     * 알지 못한 채 「요청 수만큼 쌓이는 것을 막는다」고 읽으면, 설정이 잘못된 사이트에서
     * 로그가 계속 늘어나는 것을 이 접기가 막아 준다고 오해하게 된다. 그 축(설정 오류의
     * 지속 통보)은 접기가 아니라 **설정을 고치는 것**으로 끝난다. 이 memo 가 실제로 막는
     * 것은 「한 요청에서 두 번」뿐이다.
     *
     * memo 는 **클래스 정적 속성**이어야 한다. 지역 클로저 안의 `static $seen` 은
     * 클로저가 호출마다 새로 만들어지므로 매번 초기화되어 접기가 성립하지 않는다
     * (9차 라운드가 그 형태로 넣었다가 10차에서 실측으로 잡았다).
     *
     * @param  string  $message  경고 메시지 (memo 키)
     * @param  array<string, mixed>  $context  로그 컨텍스트
     */
    private static function warnRootPathOnce(string $message, array $context): void
    {
        if (isset(static::$rootPathWarned[$message])) {
            return;
        }

        static::$rootPathWarned[$message] = true;
        Log::warning($message, $context);
    }

    /**
     * 경로가 절대 경로인지 판정합니다 (POSIX `/`, Windows 드라이브 문자·UNC).
     *
     * @param  string  $path  검사할 경로
     * @return bool 절대 경로이면 true
     */
    private static function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return true;
        }

        // `C:\...` / `C:/...` — Windows 드라이브 지정 경로.
        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * 공개 스토리지 파일의 절대 경로와 MIME 타입을 해석합니다.
     *
     * @param  string  $path  `storage/app/public` 기준 상대 경로
     * @return array{success: bool, filePath: string|null, mimeType: string|null, error: string|null}
     *                                                                                                error: `file_not_found` | `path_not_allowed` | `file_not_readable`
     */
    public function getFilePath(string $path): array
    {
        // 널바이트를 **이 계층에서** 막는다. 형제 2차 봉쇄(`SecuresExtensionAssetPath`)는
        // 같은 축을 갖고 있고 그 독블록은 이 통로가 이미 갖고 있다고 적었는데, 실제로는
        // 없었다 — 문서와 구현이 서로를 근거로 삼는 상태였다.
        //
        // 그 부재는 무해하지 않다: 아래 경로 구성요소 검사가 `readlink()` 를 부르는데
        // PHP 8 의 그 함수는 널바이트에 **`ValueError` 를 던지고** `@` 로도 억눌러지지
        // 않는다(Windows junction 판정 경로에서 실측). 요청 규칙이 1차로 막고 있지만,
        // 서비스는 확장에 열려 있어 「현재 호출부가 FormRequest 를 거친다」는 방어가 되지
        // 못한다 — 형제 trait 이 같은 이유로 자기 계층에서 막는다.
        //
        // 응답은 `file_not_found` 다. 널바이트가 든 이름의 파일은 실재할 수 없고, 이
        // 통로의 거부는 정적 서빙과 같은 코드(404)를 쓴다.
        if (str_contains($path, "\0")) {
            return $this->failure('file_not_found');
        }

        $base = static::rootPath();
        $realBase = realpath($base);

        if ($realBase === false) {
            // 루트 자체를 해석하지 못했다 — 디렉토리 부재라면 정상(아직 공개 파일이 없다)이지만,
            // 권한·`open_basedir` 때문이면 **사이트의 전 공개 자산이 원인 표시 없이 404** 가 된다.
            // 「조용한 반쪽 상태 금지」 규율에 따라 후자만 기록을 남긴다 — 파일마다 남기면
            // 자산 요청 수만큼 로그가 쌓이므로 디렉토리가 실재할 때로 좁힌다.
            if (is_dir($base)) {
                Log::warning('공개 스토리지 루트를 해석하지 못했습니다 — 이 상태에서는 모든 공개 자산이 404 가 됩니다', [
                    'base' => $base,
                    'hint' => '디렉토리 권한과 open_basedir 설정을 확인하세요.',
                ]);
            }

            return $this->failure('file_not_found');
        }

        // 경로 구분자 정규화는 **Windows 에서만** 한다. POSIX 에서 백슬래시는 파일 이름에
        // 쓸 수 있는 보통 문자라, 무조건 치환하면 그런 이름의 파일이 이 통로로는 영영
        // 열리지 않는다 — 링크가 되는 서버의 정적 서빙에서는 열리므로 링크 유무로 결과가
        // 갈린다. Windows 에서는 반대로 치환하지 않으면 백슬래시 구분자가 순회로 해석된다.
        $normalized = PHP_OS_FAMILY === 'Windows'
            ? str_replace('\\', '/', $path)
            : $path;

        // 심볼릭 링크 비추적 — 루트 아래 어느 구성 요소든 링크면 거부한다. realpath 봉쇄만으로는
        // "루트 안을 가리키는 링크" 를 걸러내지 못한다 (경유 자체를 계약으로 금지).
        // 판정은 StorageLinkHelper 가 SSoT 다 — `is_link()` 만 쓰면 Windows junction 이
        // 그대로 통과해 같은 계약이 OS 에 따라 성립·불성립으로 갈린다 (R32).
        // 빈 세그먼트(`a//b.png`)는 여기서 걸러진다. 다만 **이 통로로는 도달하지 않는다** —
        // 앞단 규칙(`ValidatesExtensionAssetPath`)이 `//` 를 순회 패턴으로 보고 422 로 먼저
        // 끊기 때문이다. 그 목록은 확장 자산 세 통로와 공유하는 것이라, 이 한 자리의
        // 계층 불일치를 위해 느슨하게 만들면 나머지 셋의 방어가 함께 약해진다. 그래서
        // 여기서는 걸러 두되 「정적 서빙과 같다」는 성질이 이 통로에서는 **앞단 때문에
        // 성립하지 않는다**는 사실을 적어 둔다 (회차 6 판정).
        $cursor = $realBase;
        foreach (array_filter(explode('/', $normalized), fn ($seg) => $seg !== '') as $segment) {
            // `.` 는 거부가 아니라 **건너뛴다** — 정적 서빙은 `a/./b` 를 정규화해 200 을
            // 주므로, 여기서만 403 이면 링크 유무로 같은 주소의 결과가 갈린다.
            if ($segment === '.') {
                continue;
            }

            // `..` 는 **404** 로 거부한다 — 다만 이 통로로는 도달하지 않는다. 앞단 규칙
            // (`ValidatesExtensionAssetPath`)이 `..` 를 순회 패턴으로 보고 **422** 로 먼저 끊기
            // 때문이다(위 `//` 와 같은 사정). 그래서 실제 응답은 422 이고, 이 분기는 요청
            // 규칙이 무력해졌을 때의 2차 봉쇄다. 그 자리에서 403 이 아닌 404 를 고르는 이유는
            // 두 가지이고 둘 다 이 파일이 이미 다른 자리에서 세운 기준이다.
            //
            //  ① 형제 통로(module·plugin·template)는 같은 입력에 404 를 준다 — 그쪽 정제기가
            //     점으로 시작하는 세그먼트를 걷어내 결과 경로가 파일이 아니게 되기 때문이다.
            //     정적 서빙도 같은 자리에서 404 다. 이 통로만 403 이면 같은 사이트가 링크
            //     유무에 따라 다른 응답을 내는데, 그 차이는 오류가 아니라 조용한 동작 차이다.
            //  ② 403 이 「존재한다」를 뜻하게 되면 파일 존재 여부가 샌다 — 아래 `realpath`
            //     실패 분기가 그 사유로 이미 404 를 고른다. 그 원칙을 같은 함수 안에서
            //     둘로 가르면 어느 쪽이 기준인지 알 수 없다. 실제로 이 분기 때문에
            //     상위 디렉토리의 실재 여부가 응답 코드로 드러났다(422 vs 403).
            if ($segment === '..') {
                return $this->failure('file_not_found');
            }

            // 이름이 점으로 시작하는 파일·디렉토리는 내주지 않는다. 배포 레이아웃 ②의 루트
            // `.htaccess` 가 `<FilesMatch "^\.">` 로 숨김 파일을 막는데, 그 방어선은 정적
            // 서빙에만 걸린다 — 이 통로가 같은 규칙을 갖지 않으면 링크가 없는 사이트에서만
            // `.gitignore`·`.htaccess` 같은 파일이 열려 같은 사이트가 링크 유무로 다르게
            // 동작한다. `storage/app/public/.gitignore` 는 실제로 저장소에 있는 파일이다.
            if (str_starts_with($segment, '.')) {
                return $this->failure('file_not_found');
            }

            $cursor .= DIRECTORY_SEPARATOR.$segment;

            // 링크는 따라가지 않는다. 거부 코드는 **404** 다 — 위 `..` 분기가 세운 기준을
            // 그대로 따른다: 403 이 「존재한다」를 뜻하게 되면 무인증 프로버가 응답 코드로
            // 「그 경로에 링크가 있다」를 알아낼 수 있다. 그 원칙을 같은 함수 안에서 둘로
            // 가르면 어느 쪽이 기준인지 알 수 없다(7차 G3 라운드).
            //
            // 아래 최종 봉쇄(`realpath` 결과가 루트 밖)는 403 을 유지한다 — 그것은 파일의
            // 존재가 아니라 **서빙 트리의 경계**에 대한 판정이라 성격이 다르다.
            if (StorageLinkHelper::isLinkOrJunction($cursor)) {
                return $this->failure('file_not_found');
            }
        }

        if (! is_file($cursor)) {
            return $this->failure('file_not_found');
        }

        // 최종 봉쇄 — 위 세그먼트 검사를 통과했더라도 파일시스템의 정규화(Windows 8.3
        // 단축명·후행 점/공백·대소문자 무시 볼륨)가 다른 대상을 가리킬 수 있으므로 해석
        // 결과를 루트 접두로 다시 확인한다.
        $real = realpath($cursor);

        if ($real === false) {
            // 탈출이 아니라 **해석 실패**다(권한·`open_basedir`·판정 직후 삭제). 이것을
            // `path_not_allowed`(403)로 보고하면 403 이 「존재한다」를 뜻하게 되어 파일 존재
            // 여부가 새고, 판정과 서빙 사이에 파일이 사라진 경우에는 뒤이은 파일 응답이
            // 예외를 던져 무인증 표면에서 500 이 된다.
            return $this->failure('file_not_found');
        }

        if (! str_starts_with($real, $realBase.DIRECTORY_SEPARATOR)) {
            return $this->failure('path_not_allowed');
        }

        // 실재하지만 읽을 수 없는 파일 — 정적 서빙은 403 을 준다. 판정 없이 성공을 돌려주면
        // 뒤이은 파일 응답이 `File must be readable` 로 던져 무인증 표면이 500 이 된다.
        // 존재 판정(404)과는 다른 축이다: 여기까지 왔으면 파일이 있다는 사실은 이미 확정됐고,
        // 그 사실은 위 realpath 봉쇄를 통과한 경로에 대해서만 드러난다 (15차 G3 라운드).
        if (! $this->isReadable($real)) {
            return $this->failure('file_not_readable');
        }

        return [
            'success' => true,
            'filePath' => $real,
            'mimeType' => $this->getMimeType($real),
            'error' => null,
        ];
    }

    /**
     * 파일을 읽을 수 있는지 판정합니다 — 권한 판정의 단일 자리.
     *
     * 따로 두는 이유: Windows 는 `chmod` 로 읽기 불가 파일을 만들 수 없어 그 분기를 실측할
     * 수 없다. 이 저장소의 표준 실행 환경이 Windows 라 이 자리를 덮어 잠근다
     * (`PublicStorageServiceTest`). skip 은 게이트가 아니다.
     *
     * @param  string  $filePath  realpath 봉쇄를 통과한 절대 경로
     * @return bool 읽을 수 있으면 true
     */
    protected function isReadable(string $filePath): bool
    {
        return is_readable($filePath);
    }

    /**
     * 실패 응답 셰이프를 생성합니다.
     *
     * @param  string  $error  오류 코드 — `file_not_found` | `path_not_allowed` | `file_not_readable`
     * @return array{success: bool, filePath: null, mimeType: null, error: string}
     */
    private function failure(string $error): array
    {
        return [
            'success' => false,
            'filePath' => null,
            'mimeType' => null,
            'error' => $error,
        ];
    }

    /**
     * 파일의 MIME 타입을 결정합니다 (확장자 표만 본다 — 내용 스니핑 없음).
     *
     * @param  string  $filePath  절대 파일 경로
     * @return string MIME 타입
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (isset(self::MIME_TYPES[$extension])) {
            return self::MIME_TYPES[$extension];
        }

        // 표에 없는 확장자는 **내용을 보지 않고** 내려받기로 고정한다.
        //
        // 종전에는 finfo 로 내용을 스니핑했는데, 그러면 확장자가 없거나 낯선 업로드물이라도
        // 내용이 HTML 형태이면 `text/html` 로 승격되어 브라우저가 그것을 **페이지로 열어
        // 실행**한다. 링크가 되는 서버의 정적 서빙은 그런 파일을 미지 유형(nginx `default_type`
        // = octet-stream)으로 내려보내므로, 스니핑은 이 대체 통로를 **정적 서빙보다 넓게**
        // 만들었다 — 링크 유무에 따라 위험이 갈리는 상태였다. 형제 자산 통로(템플릿·모듈·
        // 플러그인)도 확장자 표 + octet-stream 고정이며 스니핑을 쓰지 않는다.
        // (제품 결정 2026-08-25, 3차 G3 라운드 M5)
        return 'application/octet-stream';
    }
}
