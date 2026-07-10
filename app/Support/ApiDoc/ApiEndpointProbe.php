<?php

namespace App\Support\ApiDoc;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API 엔드포인트 실측 프로브
 *
 * 임시 Sanctum 토큰을 발급해 실제 HTTP 요청으로 엔드포인트를 호출하고,
 * 실제 응답 JSON 에서 필드 스키마(키·타입·샘플값)를 관측합니다.
 * 쓰기 메서드는 기본적으로 실호출하지 않으며 GET/HEAD 만 read-only 로 실측합니다.
 */
class ApiEndpointProbe
{
    /**
     * @var string 실측 대상 기준 URL
     */
    private string $baseUrl;

    /**
     * @var string|null 발급된 임시 토큰 평문
     */
    private ?string $token = null;

    /**
     * @var string 임시 토큰 식별용 이름
     */
    private string $tokenName = 'api-docgen-probe';

    /**
     * @var User|null 실측 인증 사용자 (in-process 쓰기 실측 시 로그인 대상)
     */
    private ?User $user = null;

    /**
     * @param  string|null  $baseUrl  기준 URL (null 이면 .env 의 APP_URL 직접 사용)
     */
    public function __construct(?string $baseUrl = null)
    {
        // config('app.url') 은 테스트 환경에서 override 될 수 있으므로(test.example.com 등),
        // 실측은 .env 의 APP_URL 을 우선 신뢰한다. 명시 인자가 있으면 그것을 최우선한다.
        $resolved = $baseUrl
            ?: (string) env('APP_URL')
            ?: (string) config('app.url');

        $this->baseUrl = rtrim($resolved, '/');
    }

    /**
     * 실측 기준 URL 을 반환합니다.
     *
     * @return string 기준 URL
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * 실측용 관리자 토큰을 발급합니다.
     *
     * @param  int|null  $userId  토큰 발급 대상 사용자 ID (null 이면 첫 관리자)
     * @return bool 발급 성공 여부
     */
    public function authenticate(?int $userId = null): bool
    {
        $user = $userId
            ? User::find($userId)
            : User::query()->orderBy('id')->first();

        if (! $user) {
            return false;
        }

        $this->user = $user;
        $this->cleanupTokens();
        $this->token = $user->createToken($this->tokenName)->plainTextToken;

        return true;
    }

    /**
     * 엔드포인트를 실호출하여 응답을 관측합니다.
     *
     * GET/HEAD 는 외부 HTTP 로 read-only 실측하고, 쓰기 메서드(POST/PUT/PATCH/DELETE)는
     * **DB 트랜잭션 안에서 in-process 로 dispatch 한 뒤 롤백**하여(영속 안 함) 응답 shape 만
     * 관측한다. 외부 HTTP 는 별도 프로세스라 롤백이 불가하므로 쓰기는 in-process 경로를 쓴다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  라우트 URI (path 파라미터 치환 완료된 실제 경로)
     * @param  array<string, mixed>  $body  쓰기 요청 바디 (쓰기 메서드에서만 사용)
     * @return array{ok: bool, status: int|null, body: array<string, mixed>|null, skipped_reason: string|null}
     */
    public function probe(string $method, string $uri, array $body = []): array
    {
        $method = strtoupper($method);

        // path 파라미터가 남아 있으면(치환 실패) 실호출 불가.
        if (Str::contains($uri, '{')) {
            return ['ok' => false, 'status' => null, 'body' => null, 'skipped_reason' => 'unresolved-path-param'];
        }

        if (! $this->token) {
            return ['ok' => false, 'status' => null, 'body' => null, 'skipped_reason' => 'no-token'];
        }

        if (in_array($method, ['GET', 'HEAD'], true)) {
            return $this->probeRead($method, $uri);
        }

        return $this->probeWrite($method, $uri, $body);
    }

    /**
     * GET/HEAD 를 외부 HTTP 로 read-only 실측합니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  치환된 URI
     * @return array{ok: bool, status: int|null, body: array<string, mixed>|null, skipped_reason: string|null}
     */
    private function probeRead(string $method, string $uri): array
    {
        try {
            $response = Http::withoutVerifying()
                ->withToken($this->token)
                ->acceptJson()
                ->timeout(15)
                ->get($this->baseUrl.$uri);

            $json = $response->json();

            return [
                'ok' => $response->successful() && is_array($json),
                'status' => $response->status(),
                'body' => is_array($json) ? $json : null,
                'skipped_reason' => null,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => null, 'skipped_reason' => 'request-failed: '.$e->getMessage()];
        }
    }

    /**
     * 쓰기 메서드를 DB 트랜잭션 안에서 in-process dispatch 후 롤백하여 실측합니다.
     *
     * 응답 shape 만 관측하고 부수효과(레코드 생성/수정/삭제)는 롤백으로 영속시키지 않는다.
     * 인증은 샘플 사용자로 로그인(guard)해 수행하며, DELETE 등 바디 없는 메서드도 처리한다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $uri  치환된 URI
     * @param  array<string, mixed>  $body  요청 바디
     * @return array{ok: bool, status: int|null, body: array<string, mixed>|null, skipped_reason: string|null}
     */
    private function probeWrite(string $method, string $uri, array $body): array
    {
        if (! $this->user) {
            return ['ok' => false, 'status' => null, 'body' => null, 'skipped_reason' => 'no-token'];
        }

        DB::beginTransaction();

        try {
            // in-process dispatch 는 실제 Bearer 토큰 헤더로 인증한다(guard 종류 무관).
            // Auth::login() 은 API 기본 guard 가 RequestGuard(토큰 전용)라 사용할 수 없다.
            $request = Request::create($uri, $method, $body);
            $request->headers->set('Accept', 'application/json');
            $request->headers->set('Authorization', 'Bearer '.$this->token);

            $response = app()->handle($request);
            $content = $response->getContent();
            $json = json_decode(is_string($content) ? $content : '', true);
            $status = $response->getStatusCode();

            return [
                'ok' => $status >= 200 && $status < 300 && is_array($json),
                'status' => $status,
                'body' => is_array($json) ? $json : null,
                'skipped_reason' => (is_array($json) && $status >= 200 && $status < 300) ? null : 'http-'.$status,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => null, 'skipped_reason' => 'write-probe-failed: '.$e->getMessage()];
        } finally {
            // 쓰기 부수효과를 영속시키지 않는다 (문서 생성 목적).
            DB::rollBack();
            // 다음 실측에 인증 컨텍스트가 새지 않도록 해석된 guard 를 초기화한다
            // (API guard 는 RequestGuard 라 logout() 이 없으므로 forgetGuards 사용).
            Auth::forgetGuards();
        }
    }

    /**
     * 발급한 임시 토큰을 정리합니다.
     */
    public function cleanup(): void
    {
        $this->cleanupTokens();
        $this->token = null;
    }

    /**
     * 실측용 토큰 레코드를 모두 삭제합니다.
     */
    private function cleanupTokens(): void
    {
        PersonalAccessToken::query()
            ->where('name', $this->tokenName)
            ->delete();
    }
}
