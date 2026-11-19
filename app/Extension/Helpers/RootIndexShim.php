<?php

namespace App\Extension\Helpers;

/**
 * 루트 `index.php` 가 **코어가 놓아 둔 shim** 인지 판정하는 단일 지점 (D25 · B-2).
 *
 * 배포 레이아웃 ②(문서 루트 = 프로젝트 루트)에서 루트 `index.php` 는 `public/index.php`
 * 로 위임하는 한 줄이 전부다. 형제 파일 `.htaccess` 는 마커 블록 규칙을 갖지만 이 파일은
 * 통째 교체이므로, 코어 업데이트는 교체 **직전**에 「그 파일이 코어 shim 인가」를 판정해
 * 아니면 사본을 남기고 운영자에게 알린다(레이아웃 ③ 사이트가 두던 자기 랜딩 파일).
 *
 * 그 판정 기준이 두 곳에 **다른 엄격도**로 적혀 있었다:
 *   · 런타임(`CoreUpdateService::recordRootIndexReplacement`)은 공백까지 고정한 리터럴
 *     `require __DIR__.'/public/index.php'` 를 `str_contains` 로 찾았고,
 *   · 계약 테스트(`RootEntrypointContractTest`)는 공백을 허용하는 정규식으로 봤다.
 *
 * 그래서 `require __DIR__ . '/public/index.php';` 처럼 PSR-12 스타일로 띄어 쓴 판본은
 * 계약 테스트를 통과하면서 런타임에서는 「코어 shim 이 아니다」로 읽힌다. 그 상태의 증상은
 * 조용하다 — 매 코어 업데이트마다 거짓 경고가 뜨고 `storage/app/core-update/` 에 쓸모없는
 * 사본이 쌓이는데, 게이트는 언제나 초록이다.
 *
 * 판정을 여기 하나로 접고, 런타임과 계약 테스트가 **함께** 이것을 쓴다.
 */
class RootIndexShim
{
    /**
     * 위임 구문 판정 정규식.
     *
     * 공백·개행은 허용하고(코드 스타일 도구가 넣거나 빼는 자리다), 위임 대상 경로만
     * 고정한다. 따옴표는 홑·겹 둘 다 허용한다 — PHP 에서 둘의 의미가 같은 리터럴이라,
     * 한쪽만 인정하면 그 표기로 적힌 shim 이 「운영자 파일」로 오판된다.
     *
     * 판정 대상은 **위임 구문 한 줄**이지 파일 전체의 모양이 아니다 — 릴리즈 shim 은 위임
     * 앞에 「대상 부재」 가드(`is_file(__DIR__.'/public/index.php')` → 503)를 갖는다 (G3 회차
     * 15). 가드의 `is_file(...)` 은 `require` 가 아니므로 이 정규식에 걸리지 않고, 위임 줄이
     * 그대로 있어 shim 으로 판정된다. 가드 유무로 판정을 가르면 가드 이전 판본과 이후 판본
     * 중 하나가 「운영자 파일」로 오판된다.
     */
    public const DELEGATION_PATTERN = '~require(?:_once)?\s*\(?\s*__DIR__\s*\.\s*[\'"]/public/index\.php[\'"]~';

    /**
     * 코어 shim 의 표준 위임 구문 (릴리즈 판본이 싣는 표기).
     */
    public const DELEGATION_LINE = "require __DIR__.'/public/index.php';";

    /**
     * 주어진 내용이 코어가 놓아 둔 루트 shim 인지 판정합니다.
     *
     * @param  string  $contents  루트 `index.php` 의 내용
     * @return bool 코어 shim 이면 true
     */
    public static function matches(string $contents): bool
    {
        return preg_match(self::DELEGATION_PATTERN, $contents) === 1;
    }
}
