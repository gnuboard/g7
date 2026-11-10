<?php

namespace Modules\Sirsoft\Board\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 비회원 업로드 소유 쿠키 — 비회원이 자기가 올린 임시 첨부를 지우고 순서를 바꿀 수 있게 한다 (#883)
 *
 * 비회원 업로드 응답에 무작위 40자 값을 쿠키로 붙이고, 첨부 행에는 그 SHA-256 만 둔다(`guest_owner_hash`).
 * 같은 출처 XHR 이라 쿠키가 자동으로 실리므로 템플릿을 바꿀 필요가 없다. 글에 연결되면 해시를 지운다 — 연결 전만 의미.
 *
 * 쿠키 속성: 경로 `/api/modules/sirsoft-board`(게시판 API 로 한정), httpOnly, SameSite=Lax, 보안 연결이면 secure.
 * 이름·수명은 모듈 설정 `sirsoft-board.guest_upload_cookie` 다(기본 `g7_board_guest_upload`, 1440분).
 * api 미들웨어 그룹에는 쿠키 암호화가 없어 값은 평문으로 오간다(값 자체가 뜻 없는 난수다).
 */
class GuestUploadOwnerCookie
{
    /** 쿠키 경로 — 게시판 API 로 한정 */
    public const PATH = '/api/modules/sirsoft-board';

    /** 토큰 형식 */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{40}$/';

    /**
     * 요청에 실린 유효한 소유 토큰을 꺼냅니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 토큰 (없거나 형식이 틀리면 null)
     */
    public function resolveToken(Request $request): ?string
    {
        $value = $request->cookies->get($this->name());

        return is_string($value) && preg_match(self::TOKEN_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * 이번 업로드에 쓸 소유 토큰을 정합니다 — 유효한 쿠키가 있으면 그대로, 없으면 새로 만든다.
     *
     * 같은 비회원이 여러 파일을 올려도 한 토큰으로 묶여야 나중에 모두 지울 수 있다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 소유 토큰
     */
    public function issue(Request $request): string
    {
        return $this->resolveToken($request) ?? Str::random(40);
    }

    /**
     * 소유 토큰의 저장용 해시를 만듭니다.
     *
     * @param  string  $token  소유 토큰
     * @return string SHA-256 (16진 64자)
     */
    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 요청 쿠키의 토큰이 저장된 해시와 같은지 판정합니다.
     *
     * @param  string|null  $storedHash  첨부 행의 `guest_owner_hash`
     * @param  string|null  $token  요청의 소유 토큰
     * @return bool 같으면 true (어느 쪽이든 비면 false)
     */
    public function matches(?string $storedHash, ?string $token): bool
    {
        if ($storedHash === null || $storedHash === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($storedHash, $this->hash($token));
    }

    /**
     * 응답에 소유 쿠키를 붙입니다(수명을 매 업로드마다 새로 연장).
     *
     * @param  Response  $response  업로드 응답
     * @param  string  $token  소유 토큰
     * @param  Request  $request  HTTP 요청 (보안 연결 판정)
     * @return Response 쿠키를 붙인 응답
     */
    public function attach(Response $response, string $token, Request $request): Response
    {
        $response->headers->setCookie(new Cookie(
            $this->name(),
            $token,
            now()->addMinutes($this->minutes()),
            self::PATH,
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }

    /**
     * 쿠키 이름을 돌려줍니다.
     *
     * @return string 쿠키 이름
     */
    public function name(): string
    {
        return (string) config('sirsoft-board.guest_upload_cookie.name', 'g7_board_guest_upload');
    }

    /**
     * 쿠키 수명(분)을 돌려줍니다.
     *
     * @return int 수명
     */
    private function minutes(): int
    {
        return max(1, (int) config('sirsoft-board.guest_upload_cookie.minutes', 1440));
    }
}
