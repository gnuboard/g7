<?php

namespace App\Extension\Integrity;

/**
 * 검증하는 쪽이 기대하는 패키지 정체.
 *
 * 서명 파일이 말하는 패키지(`package.type`·`identifier`·`version`)가 이 값과 다르면 서명이 맞아도 거부한다 —
 * 다른 확장의 정상 서명 파일을 옆에 붙여 통과시키는 것을 막는다. 모르는 칸(GitHub 주소로 처음 설치할 때의 식별자 등)은
 * null 로 두고, 해제 뒤 manifest 대조가 그 자리를 메운다.
 *
 * @since 7.2.0
 */
final class PackageExpectation
{
    /** 패키지 종류 — 모듈 */
    public const TYPE_MODULE = 'module';

    /** 패키지 종류 — 플러그인 */
    public const TYPE_PLUGIN = 'plugin';

    /** 패키지 종류 — 템플릿 */
    public const TYPE_TEMPLATE = 'template';

    /** 패키지 종류 — 언어팩 */
    public const TYPE_LANGUAGE_PACK = 'language_pack';

    /** 패키지 종류 — 코어 */
    public const TYPE_CORE = 'core';

    /** 코어 패키지 식별자 */
    public const CORE_IDENTIFIER = 'g7';

    /** 허용하는 패키지 종류 */
    public const TYPES = [
        self::TYPE_MODULE,
        self::TYPE_PLUGIN,
        self::TYPE_TEMPLATE,
        self::TYPE_LANGUAGE_PACK,
        self::TYPE_CORE,
    ];

    /**
     * 기대값을 만듭니다.
     *
     * @param  string  $type  패키지 종류 (TYPES 중 하나)
     * @param  string|null  $identifier  기대 식별자 (모르면 null)
     * @param  string|null  $version  기대 버전 (모르면 null, 앞의 `v` 는 떼고 비교)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $identifier = null,
        public readonly ?string $version = null,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown package type: '.$type);
        }
    }

    /**
     * 코어 패키지 기대값을 만듭니다.
     *
     * @param  string|null  $version  기대 버전
     * @return self 코어 기대값
     */
    public static function core(?string $version = null): self
    {
        return new self(self::TYPE_CORE, self::CORE_IDENTIFIER, $version);
    }

    /**
     * 확장 종류 이름(modules|module 등)을 패키지 종류로 바꿔 기대값을 만듭니다.
     *
     * @param  string  $extensionType  확장 종류 (module|plugin|template 또는 복수형)
     * @param  string|null  $identifier  기대 식별자
     * @param  string|null  $version  기대 버전
     * @return self 기대값
     */
    public static function forExtension(string $extensionType, ?string $identifier = null, ?string $version = null): self
    {
        return new self(rtrim($extensionType, 's'), $identifier, $version);
    }

    /**
     * 비교용으로 버전 앞의 `v` 를 뗍니다.
     *
     * @param  string|null  $version  버전 문자열
     * @return string|null 정규화한 버전
     */
    public static function normalizeVersion(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        return ltrim($version, 'vV');
    }
}
