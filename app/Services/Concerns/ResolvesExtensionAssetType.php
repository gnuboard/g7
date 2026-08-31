<?php

namespace App\Services\Concerns;

/**
 * 확장 자산 서빙의 **유형 판정** (템플릿·모듈·플러그인 공유).
 *
 * 형제 봉쇄 trait(`SecuresExtensionAssetPath`)이 「어디를 열 수 있는가」를 정한다면, 이
 * trait 은 「무엇을 어떤 유형으로 내보내는가」를 정한다. 두 판정 모두 세 통로가 같은
 * 강도를 가져야 하는데, 이 축은 사본이 셋으로 갈려 있었다:
 *
 *   · **확장자 화이트리스트** — 템플릿 서비스만 2차 확인을 갖고 모듈·플러그인은 없었다.
 *     그 결과 세 컨트롤러가 공통으로 갖는 `file_type_not_allowed` 403 분기가 모듈·플러그인
 *     통로에서는 **도달 불가**였다. 「요청 규칙이 이미 막으니 무해하다」는 방어가 되지
 *     않는다 — 서비스는 확장에 열려 있어 요청 규칙을 거치지 않는 호출부가 생길 수 있고,
 *     그 순간 그 통로에는 남는 방어선이 없다(형제 봉쇄 trait 과 같은 근거다).
 *
 *   · **MIME 표** — 소스맵(`map`)은 `local` 에서만 화이트리스트에 오르는데(`Allowed*FileType`),
 *     그 완화를 실제로 쓸모 있게 만드는 `map => application/json` 항목이 템플릿 표에만
 *     있었다. 모듈·플러그인은 화이트리스트를 통과한 소스맵을 `application/octet-stream`
 *     으로 내보내 브라우저 devtools 가 읽지 못했다 — 완화가 그 두 통로에서만 사문이었고,
 *     404 도 403 도 아니라 「200 인데 devtools 가 조용히 무시」라 드러나지 않았다.
 *
 * 그래서 두 판정을 여기 한 곳으로 접는다. 화이트리스트 **목록 자체**는 통로별 규칙
 * 클래스(`Allowed{Template,Module,Plugin}FileType::getAllowedExtensions()`)가 계속 소유하고,
 * 이 trait 은 그 목록을 받아 같은 방식으로 판정하기만 한다 — 목록의 환경 분기(운영에서
 * 소스맵 차단)가 규칙 클래스 한 곳에 남아 있어야 하기 때문이다.
 *
 * 세 통로가 이 trait 을 쓰는지는 `ExtensionAssetServiceContainmentParityTest` 가 잰다.
 */
trait ResolvesExtensionAssetType
{
    /**
     * 확장자 → MIME 타입 표 (세 통로 공유 SSoT).
     *
     * 표에 없는 확장자는 `application/octet-stream` 이다 — 내용 스니핑은 하지 않는다.
     * 스니핑은 HTML 형태 업로드물을 `text/html` 로 승격시켜 브라우저가 실행하게 만든다
     * (정적 서빙은 미지 유형을 내려받기로 준다).
     *
     * @var array<string, string>
     */
    private const EXTENSION_ASSET_MIME_TYPES = [
        // Scripts
        'js' => 'application/javascript',
        'mjs' => 'application/javascript',

        // Styles
        'css' => 'text/css',

        // Data
        'json' => 'application/json',

        // Images
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',

        // 소스맵은 local 환경에서만 화이트리스트에 오른다(`Allowed*FileType`).
        // 표에 없으면 통과해도 `application/octet-stream` 이라 브라우저 devtools 가
        // 읽지 못해 그 완화가 목적을 달성하지 못한다.
        'map' => 'application/json',

        // Fonts
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    /**
     * 자산 파일의 MIME 타입을 판정합니다.
     *
     * @param  string  $filePath  파일 경로
     * @return string MIME 타입 (표에 없으면 `application/octet-stream`)
     */
    protected function extensionAssetMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return self::EXTENSION_ASSET_MIME_TYPES[$extension] ?? 'application/octet-stream';
    }

    /**
     * 자산 파일의 확장자가 허용 목록 안에 있는지 확인합니다 (2차 방어선).
     *
     * 목록은 호출부가 자기 통로의 규칙 클래스에서 가져와 넘긴다 — 여기 다시 적으면
     * 검증 계층과 서비스 계층이 갈리고, 실제로 그렇게 갈려 있었다(운영에서 소스맵을
     * 차단하는 환경 분기가 규칙 클래스에만 있다).
     *
     * @param  string  $filePath  파일 경로
     * @param  array<int, string>  $allowedExtensions  허용 확장자 목록
     * @return bool 허용되면 true
     */
    protected function extensionAssetExtensionIsAllowed(string $filePath, array $allowedExtensions): bool
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($extension, $allowedExtensions, true);
    }
}
