<?php

namespace App\Scheduling;

use App\Support\AssetUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 웹 크론 호출 번호(토큰)의 전용 보관처 (D20).
 *
 * 이 토큰은 **인증 없는 실행 권한**을 가진 자격증명이다. 그래서 일반 설정 카탈로그에
 * 두지 않는다 — 카탈로그에 두면 설정 조회 응답(`GET /api/admin/settings`)과 단건 조회
 * (`GET /api/admin/settings/{key}`) 두 통로가 동시에 열리고, 설정을 "읽을" 수만 있는
 * 계정이 실행 자격증명을 얻는다. 저장은 이 전용 파일이고, 조회는 권한을 확인하는
 * 전용 API 한 곳뿐이다.
 *
 * 토큰 비교는 상수 시간 비교(`hash_equals`)를 쓴다 — 문자열 비교의 조기 반환은
 * 앞자리부터 맞춰 가는 추측을 가능하게 한다.
 *
 * 어떤 로그에도 토큰을 남기지 않는다. 접근 로그에 쿼리스트링이 남는 환경을 전제로
 * 화면이 재발급 안내를 함께 둔다.
 *
 * @since 7.1.0
 */
class WebCronTokenStore
{
    /**
     * 토큰을 실을 수 있는 요청 헤더 이름.
     *
     * 쿼리스트링과 헤더 둘 다 받는다 — 외부 크론 서비스 중 헤더를 설정할 수 없는 곳이
     * 많아 쿼리스트링 지원을 유지해야 하고, 설정할 수 있는 곳은 접근 로그에 남지 않는
     * 헤더를 쓰는 편이 낫다. 요청을 받는 쪽과 화면에 안내하는 쪽이 같은 값을 보도록
     * 이름의 출처는 여기 하나다.
     */
    public const TOKEN_HEADER = 'X-G7-Cron-Token';

    /** 토큰 파일이 놓이는 디렉토리 (프로젝트 상대). */
    public const DIRECTORY = 'app/schedule';

    /** 토큰 파일 이름. */
    public const FILENAME = 'web-cron-token.json';

    /** 발급 토큰의 바이트 수 (16진 표기 64자). */
    private const TOKEN_BYTES = 32;

    /** @var array<string, mixed>|null 지연 로드된 내용 */
    private ?array $cached = null;

    /**
     * @param  string|null  $path  토큰 파일 경로 (테스트 주입용)
     */
    public function __construct(private ?string $path = null)
    {
        $this->path ??= storage_path(
            str_replace('/', DIRECTORY_SEPARATOR, self::DIRECTORY).DIRECTORY_SEPARATOR.self::FILENAME,
        );
    }

    /**
     * 토큰 파일 경로를 반환합니다.
     *
     * @return string 절대 경로
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * 토큰이 발급되어 있는지 확인합니다 (값은 돌려주지 않는다).
     *
     * @return bool 발급 여부
     */
    public function exists(): bool
    {
        return $this->token() !== null;
    }

    /**
     * 마지막 발급 시각을 반환합니다.
     *
     * @return CarbonImmutable|null 기록이 없으면 null
     */
    public function issuedAt(): ?CarbonImmutable
    {
        $value = $this->read()['issued_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 저장된 토큰을 반환합니다.
     *
     * 호출부는 권한을 확인한 전용 API 이거나 요청 토큰을 대조하는 검증 경로여야 한다.
     *
     * @return string|null 토큰, 발급되지 않았으면 null
     */
    public function token(): ?string
    {
        $token = $this->read()['token'] ?? null;

        return (is_string($token) && $token !== '') ? $token : null;
    }

    /**
     * 토큰을 새로 발급하고 저장합니다 (기존 주소는 즉시 무효가 된다).
     *
     * @return string 새 토큰
     */
    public function rotate(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $this->write([
            'token' => $token,
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
        ]);

        return $token;
    }

    /**
     * 토큰이 없으면 발급하고, 있으면 그대로 돌려줍니다.
     *
     * @return string 토큰
     */
    public function ensure(): string
    {
        return $this->token() ?? $this->rotate();
    }

    /**
     * 제시된 토큰이 저장된 토큰과 같은지 상수 시간으로 비교합니다.
     *
     * @param  string|null  $candidate  제시된 토큰
     * @return bool 일치 여부 (토큰 미발급 시 언제나 false)
     */
    public function matches(?string $candidate): bool
    {
        $stored = $this->token();

        if ($stored === null || ! is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($stored, $candidate);
    }

    /**
     * 토큰을 실은 호출 주소를 만듭니다.
     *
     * 사이트 경로 접두사(하위 폴더 마운트)와 오리진을 함께 붙인다 — 운영자가 호스팅
     * 제어판에 그대로 붙여넣는 값이므로 절대 주소여야 한다.
     *
     * @param  string|null  $token  실을 토큰 (기본: 저장된 토큰)
     * @return string|null 호출 주소, 토큰이 없으면 null
     */
    public function callUrl(?string $token = null): ?string
    {
        $token ??= $this->token();

        if ($token === null) {
            return null;
        }

        return AssetUrl::siteAbsolute('/api/system/cron/run').'?token='.rawurlencode($token);
    }

    /**
     * 토큰을 폐기합니다 (파일 삭제).
     *
     * @return bool 삭제 성공 여부
     */
    public function forget(): bool
    {
        $this->cached = null;

        if (! is_file($this->path)) {
            return true;
        }

        return @unlink($this->path);
    }

    /**
     * 같은 요청 안에서 파일이 바뀐 경우 다시 읽도록 캐시를 비웁니다.
     *
     * @return void 반환값 없음
     */
    public function refresh(): void
    {
        $this->cached = null;
    }

    /**
     * 토큰 파일을 읽습니다 (부재·손상 시 빈 배열).
     *
     * @return array<string, mixed> 파일 내용
     */
    private function read(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        if (! is_file($this->path)) {
            return $this->cached = [];
        }

        $decoded = json_decode((string) @file_get_contents($this->path), true);

        return $this->cached = is_array($decoded) ? $decoded : [];
    }

    /**
     * 토큰 파일을 씁니다.
     *
     * @param  array<string, mixed>  $payload  저장할 내용
     * @return void 반환값 없음
     */
    private function write(array $payload): void
    {
        $this->cached = $payload;

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            File::ensureDirectoryExists($directory, 0755, true);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($encoded === false || File::put($this->path, $encoded) === false) {
            // 토큰 값은 로그에 남기지 않는다 — 경로와 실패 사실만 남긴다.
            Log::error('웹 크론 호출 번호를 저장하지 못했습니다.', ['path' => $this->path]);
        }
    }
}
