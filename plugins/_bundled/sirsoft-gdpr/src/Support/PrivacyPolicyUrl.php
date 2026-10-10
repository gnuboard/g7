<?php

namespace Plugins\Sirsoft\Gdpr\Support;

use App\Support\OutboundUrlValidator;
use App\Support\SiteUrl;
use App\Support\TrustedScriptHosts;

/**
 * 개인정보처리방침 주소 해석기
 *
 * 운영자가 설정 화면에 직접 적는 처리방침 주소(`privacy_policy_url`)의 허용 형식과 렌더링 규칙의
 * 단일 출처다. 저장 검증(FormRequest 규칙) · 공개 설정 응답 · 연간 통지 메일 링크 · 정책 버전
 * 스냅샷 비교가 모두 이 클래스를 거친다 — 검증과 출력이 서로 다른 규칙을 쓰면 저장은 막혔는데
 * 설정 파일을 직접 고친 값이 그대로 링크로 나가는 틈이 생긴다.
 *
 * 허용 형식은 둘뿐이다.
 *  - `/` 로 시작하는 사이트 내부 경로 (예: `/privacy`, `/page/privacy`)
 *  - `https://` 로 시작하는 절대 주소 (예: `https://example.com/privacy`)
 *
 * 그 밖은 모두 거부한다 — `javascript:`·`data:` 등 다른 스킴, `http://`, 프로토콜 상대 주소(`//host`),
 * 브라우저가 슬래시로 읽는 백슬래시(`/\host`), 공백·제어문자·서식 문자. 브라우저 URL 파서는 탭·개행을
 * 지우고 백슬래시를 슬래시로 바꾼 뒤 해석하므로, 문자열 접두 검사만으로는 외부 호스트로 새는 주소를
 * 막지 못한다(판정 정규화는 코어 `TrustedScriptHosts::normalizeForOriginCheck` 를 쓴다).
 *
 * 1.0.x 까지는 페이지 모듈의 주소 규칙(`/page/{slug}`)으로 링크를 조합하는 슬러그(`privacy_policy_slug`)를
 * 저장했다. 그 시절 발행된 정책 버전 스냅샷은 슬러그만 담고 있으므로, 읽을 때만 같은 규칙으로
 * 풀어 보여 준다(`fromSnapshot()`).
 */
final class PrivacyPolicyUrl
{
    /**
     * 설정 키
     */
    public const SETTING_KEY = 'privacy_policy_url';

    /**
     * 1.0.x 설정 키 (정책 버전 스냅샷 읽기 호환 전용)
     */
    public const LEGACY_SETTING_KEY = 'privacy_policy_slug';

    /**
     * 주소 최대 길이
     */
    public const MAX_LENGTH = 2048;

    /**
     * 1.0.x 가 슬러그로 링크를 조합하던 경로 접두사 (스냅샷 읽기 호환 전용)
     */
    private const LEGACY_PAGE_PREFIX = '/page/';

    /**
     * 주소가 허용 형식(사이트 내부 경로 또는 https 절대 주소)인지 판정합니다.
     *
     * @param  string  $url  검사할 주소
     * @return bool 허용 형식이면 true (빈 문자열은 false)
     */
    public static function isSafe(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return false;
        }

        // 공백·제어문자·서식 문자(0폭 문자·방향 제어 포함)는 거부한다. 잘못된 UTF-8 이면 preg_match 가
        // false 를 돌려주므로 역시 거부된다.
        if (preg_match('/[\s\p{Z}\p{Cc}\p{Cf}]/u', $url) !== 0) {
            return false;
        }

        // 백슬래시는 special scheme 에서 슬래시와 같다 — `/\evil.com` 은 `//evil.com` 으로 해석된다.
        if (str_contains($url, '\\')) {
            return false;
        }

        $normalized = TrustedScriptHosts::normalizeForOriginCheck($url);

        if (str_starts_with($normalized, '/')) {
            // 프로토콜 상대 주소(`//host`)는 외부 호스트다.
            return ! str_starts_with($normalized, '//');
        }

        if (! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }

        // 호스트 필수 · userinfo(`user@host`) 위장 거부 · https 만 허용
        return OutboundUrlValidator::isStructurallySafeUrl($url, ['schemes' => ['https']]);
    }

    /**
     * 저장값을 화면·API 에 내보낼 주소로 정규화합니다.
     *
     * 허용 형식이 아니면(설정 파일을 직접 고친 값 등) 빈 문자열을 돌려 링크를 숨기게 합니다.
     *
     * @param  mixed  $value  설정 저장값
     * @return string 허용 형식의 주소, 아니면 빈 문자열
     */
    public static function normalize(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $url = trim($value);

        return self::isSafe($url) ? $url : '';
    }

    /**
     * 메일처럼 사이트 밖에서 열리는 자리에 쓸 절대 주소로 바꿉니다.
     *
     * 사이트 내부 경로는 사이트 주소(`SiteUrl::absolute()`)를 앞에 붙이고, https 절대 주소는 그대로 둡니다.
     * 요청 Host 를 쓰지 않는다 — 결과가 전 회원 통지 스냅샷에 동결된다.
     *
     * @param  mixed  $value  설정 저장값
     * @return string 절대 주소, 허용 형식이 아니거나 비었으면 빈 문자열
     */
    public static function toAbsolute(mixed $value): string
    {
        $url = self::normalize($value);

        if ($url === '') {
            return '';
        }

        return str_starts_with($url, '/') ? SiteUrl::absolute($url) : $url;
    }

    /**
     * 1.0.x 슬러그를 그 시절 링크가 가리키던 사이트 내부 경로로 풉니다.
     *
     * @param  mixed  $slug  1.0.x 슬러그 저장값
     * @return string `/page/{slug}` 경로, 슬러그가 비었으면 빈 문자열
     */
    public static function fromLegacySlug(mixed $slug): string
    {
        if (! is_string($slug) || trim($slug) === '') {
            return '';
        }

        return self::LEGACY_PAGE_PREFIX.rawurlencode(trim($slug));
    }

    /**
     * 정책 버전 스냅샷에서 처리방침 주소를 읽습니다.
     *
     * 1.1.0 이후 스냅샷은 `privacy_policy_url` 을, 그 이전 스냅샷은 `privacy_policy_slug` 를 담고
     * 있습니다. 옛 스냅샷은 그 시절 링크가 가리키던 경로로 풀고, 어느 형식이었는지 `legacy_slug` 로
     * 함께 알려 화면이 구분해 보여 줄 수 있게 합니다.
     *
     * @param  array<string, mixed>  $snapshot  정책 버전 스냅샷
     * @return array{url: string, legacy_slug: string|null} 해석된 주소(없으면 빈 문자열)와 옛 슬러그
     */
    public static function fromSnapshot(array $snapshot): array
    {
        if (array_key_exists(self::SETTING_KEY, $snapshot)) {
            return ['url' => self::normalize($snapshot[self::SETTING_KEY]), 'legacy_slug' => null];
        }

        $slug = $snapshot[self::LEGACY_SETTING_KEY] ?? null;

        if (is_string($slug) && trim($slug) !== '') {
            return ['url' => self::fromLegacySlug($slug), 'legacy_slug' => trim($slug)];
        }

        return ['url' => '', 'legacy_slug' => null];
    }

    /**
     * 연간 통지 메일에 넣을 처리방침 링크 한 줄을 만듭니다 — 주소가 없으면 빈 문자열.
     *
     * 주소가 비어 있는데 링크 줄을 그대로 두면 받는 사람에게 누를 수 없는 「개인정보처리방침」 링크가
     * 간다(결정 2026-09-28: 빈 주소면 링크 줄 생략). 주소는 이미 절대 주소로 풀린 값이어야 한다.
     *
     * @param  string  $absoluteUrl  절대 주소({@see toAbsolute()} 결과)
     * @param  string  $label  링크 문구(수신자 로케일)
     * @return string `<p><a href="…">문구</a></p>` 또는 빈 문자열
     */
    public static function mailLinkLine(string $absoluteUrl, string $label): string
    {
        if ($absoluteUrl === '') {
            return '';
        }

        return '<p><a href="'.e($absoluteUrl).'">'.e($label).'</a></p>';
    }
}
