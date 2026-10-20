<?php

namespace Tests\Unit\Support;

use App\Support\LocaleTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 언어 코드(BCP 47) 단일 기준 테스트 (#148).
 *
 * 언어팩 설치 검사가 받는 코드면 화면·API·정적 게시·국가 추정에서도 그대로 쓸 수 있어야 한다.
 * 화면 짝 `resources/js/core/support/__tests__/localeTag.test.ts` 와 같은 표를 쓴다.
 */
#[Group('core')]
class LocaleTagTest extends TestCase
{
    /**
     * 이 클래스 도입 전 `LanguagePackManifestValidator::BCP47_PATTERN` 리터럴.
     */
    private const LEGACY_MANIFEST_PATTERN = '/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?(-[a-zA-Z0-9]{5,8})?$/';

    /**
     * 2글자 언어 코드(회귀)는 종전대로 통과한다.
     *
     * @scenario shape=two_letter
     *
     * @effects locale_tag_accepts_language_pack_tags
     *
     * @return void 반환값 없음
     */
    public function test_accepts_two_letter_tags(): void
    {
        foreach (['ko', 'en', 'ja', 'zh-CN'] as $tag) {
            $this->assertTrue(LocaleTag::isValid($tag), $tag);
        }
    }

    /**
     * 세 글자 언어 코드가 통과한다.
     *
     * @scenario shape=three_letter
     *
     * @effects locale_tag_accepts_language_pack_tags
     *
     * @return void 반환값 없음
     */
    public function test_accepts_language_pack_tags(): void
    {
        foreach (['fil', 'haw', 'yue'] as $tag) {
            $this->assertTrue(LocaleTag::isValid($tag), $tag);
        }
    }

    /**
     * 지역이 붙은 코드가 통과한다(가장 복잡한 변종 ①).
     *
     * @scenario shape=region_tagged
     *
     * @effects locale_tag_accepts_language_pack_tags
     *
     * @return void 반환값 없음
     */
    public function test_accepts_region_tagged_tags(): void
    {
        foreach (['fil-PH', 'pt-BR', 'zh-Hant-TW', 'sr-Latn-RS', 'de-DE-1996ab'] as $tag) {
            $this->assertTrue(LocaleTag::isValid($tag), $tag);
        }
    }

    /**
     * 문자 표기가 붙은 코드가 통과한다(가장 복잡한 변종 ②).
     *
     * @scenario shape=script_tagged
     *
     * @effects locale_tag_accepts_language_pack_tags
     *
     * @return void 반환값 없음
     */
    public function test_accepts_script_tagged_tags(): void
    {
        foreach (['zh-Hant', 'zh-Hans', 'sr-Latn'] as $tag) {
            $this->assertTrue(LocaleTag::isValid($tag), $tag);
        }
    }

    /**
     * 숫자 지역 코드가 통과하고 국가로는 읽지 않는다.
     *
     * @scenario shape=numeric_region
     *
     * @effects locale_tag_accepts_language_pack_tags
     *
     * @return void 반환값 없음
     */
    public function test_accepts_numeric_region_tags(): void
    {
        $this->assertTrue(LocaleTag::isValid('es-419'));
        $this->assertSame('419', LocaleTag::region('es-419'));
        $this->assertNull(LocaleTag::countryCode('es-419'));
    }

    /**
     * 정규형이 아니거나 경로 문자가 든 코드는 거부한다.
     *
     * @scenario shape=malformed
     *
     * @effects locale_tag_rejects_malformed_tags
     *
     * @return void 반환값 없음
     */
    public function test_rejects_malformed_tags(): void
    {
        foreach (['kor1', 'KO', 'fil-ph', 'fil_PH', 'zh-hant', 'e', '../ko', 'ko.json', 'ko/en', '', 'ko ', 'x-klingon'] as $tag) {
            $this->assertFalse(LocaleTag::isValid($tag), var_export($tag, true));
        }

        $this->assertFalse(LocaleTag::isValid(null));
        $this->assertFalse(LocaleTag::isValid(['ko']));
        $this->assertFalse(LocaleTag::isValid(12));
    }

    /**
     * `language_packs.locale` varchar(20) 를 넘는 코드는 형식이 맞아도 거부한다.
     *
     * @scenario shape=over_length
     *
     * @effects locale_tag_rejects_malformed_tags
     *
     * @return void 반환값 없음
     */
    public function test_rejects_tag_longer_than_column(): void
    {
        $tag = 'fil-Latn-419-abcdefgh';
        $this->assertSame(21, strlen($tag));
        $this->assertSame(1, preg_match(self::LEGACY_MANIFEST_PATTERN, $tag), '종전 패턴은 21자를 통과시켰다');
        $this->assertFalse(LocaleTag::isValid($tag));
        $this->assertTrue(LocaleTag::isValid('fil-Latn-419-abcdefg'));
    }

    /**
     * 20자 이하에서는 종전 언어팩 manifest 패턴과 판정이 같다.
     *
     * @effects locale_tag_accepts_language_pack_tags, locale_tag_rejects_malformed_tags
     *
     * @return void 반환값 없음
     */
    public function test_pattern_body_equals_manifest_validator_semantics(): void
    {
        $corpus = [
            'ko', 'en', 'ja', 'fil', 'haw', 'yue', 'zh-CN', 'zh-Hant', 'zh-Hant-TW', 'es-419', 'fil-PH',
            'sr-Latn-RS', 'de-DE-1996ab', 'kor1', 'KO', 'fil-ph', 'fil_PH', 'zh-hant', 'e', '../ko',
            'ko.json', 'ko/en', '', 'abcd', 'en-US-x', 'en-us', 'ZH-HANT', 'es-41', 'es-4199',
        ];

        foreach ($corpus as $tag) {
            $this->assertSame(
                preg_match(self::LEGACY_MANIFEST_PATTERN, $tag) === 1,
                LocaleTag::isValid($tag),
                var_export($tag, true)
            );
        }

        $this->assertSame('/^'.LocaleTag::PATTERN_BODY.'$/', LocaleTag::PATTERN);
        $this->assertSame(0, preg_match('#[/\\\\._]#', str_replace(['(?:', '[a-z]', '[A-Z]', '[0-9]', '[a-zA-Z0-9]'], '', LocaleTag::PATTERN_BODY)));
    }

    /**
     * 대소문자·구분자를 정규형으로 맞춘다.
     *
     * @effects locale_tag_canonicalizes_case_and_separator
     *
     * @param  string  $input  원본 언어 코드
     * @param  string|null  $expected  기대 정규형
     * @return void 반환값 없음
     */
    #[DataProvider('canonicalizeProvider')]
    public function test_canonicalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, LocaleTag::canonicalize($input));
    }

    /**
     * 정규화 입력과 기대값 표.
     *
     * @return array<string, array{0: string, 1: string|null}> 케이스 이름 → [입력, 기대]
     */
    public static function canonicalizeProvider(): array
    {
        return [
            'en-us' => ['en-us', 'en-US'],
            'zh_hant_tw' => ['zh_hant_tw', 'zh-Hant-TW'],
            'FIL-ph' => ['FIL-ph', 'fil-PH'],
            'already' => ['fil-PH', 'fil-PH'],
            'es-419' => ['es-419', 'es-419'],
            'trim' => ['  ko ', 'ko'],
            'variant' => ['DE-de-1996AB', 'de-DE-1996ab'],
            'x-klingon' => ['x-klingon', null],
            'star' => ['*', null],
            'empty' => ['', null],
            'path' => ['../ko', null],
        ];
    }

    /**
     * 주 언어·지역·국가를 뽑는다.
     *
     * @effects locale_tag_extracts_primary_region_country
     *
     * @return void 반환값 없음
     */
    public function test_primary_region_country(): void
    {
        $this->assertSame('fil', LocaleTag::primaryLanguage('fil-PH'));
        $this->assertSame('zh', LocaleTag::primaryLanguage('zh-Hant-TW'));
        $this->assertNull(LocaleTag::primaryLanguage('x-klingon'));

        $this->assertSame('PH', LocaleTag::region('fil-PH'));
        $this->assertSame('TW', LocaleTag::region('zh-Hant-TW'));
        $this->assertNull(LocaleTag::region('zh-Hant'));
        $this->assertNull(LocaleTag::region('fil'));

        $this->assertSame('PH', LocaleTag::countryCode('fil-PH'));
        $this->assertSame('TW', LocaleTag::countryCode('zh-Hant-TW'));
        $this->assertSame('BR', LocaleTag::countryCode('pt-br'));
        $this->assertNull(LocaleTag::countryCode('es-419'));
        $this->assertNull(LocaleTag::countryCode('fil'));
    }

    /**
     * 요청 언어를 정확일치 → 주 언어 → 같은 주 언어의 첫 지원 항목 순으로 고른다.
     *
     * @effects locale_tag_match_prefers_exact_then_primary, locale_tag_canonicalizes_case_and_separator
     *
     * @param  string  $requested  요청 언어
     * @param  array<int, string>  $supported  지원 언어 목록
     * @param  string|null  $expected  기대 결과
     * @return void 반환값 없음
     */
    #[DataProvider('matchProvider')]
    public function test_match(string $requested, array $supported, ?string $expected): void
    {
        $this->assertSame($expected, LocaleTag::match($requested, $supported));
    }

    /**
     * 협상 입력과 기대값 표 (화면 짝 localeTag.test.ts 와 같다).
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: string|null}> 케이스 이름 → [요청, 지원 목록, 기대]
     */
    public static function matchProvider(): array
    {
        return [
            'exact region' => ['fil-PH', ['ko', 'en', 'fil-PH'], 'fil-PH'],
            'primary to region' => ['fil', ['ko', 'fil-PH'], 'fil-PH'],
            'region to primary' => ['en-GB', ['ko', 'en'], 'en'],
            'case insensitive' => ['zh-hant-tw', ['zh-Hant-TW'], 'zh-Hant-TW'],
            'same primary first' => ['zh-TW', ['zh-Hant', 'zh-CN'], 'zh-Hant'],
            'primary preferred over sibling' => ['en-GB', ['en-US', 'en'], 'en'],
            'unsupported' => ['fr', ['ko', 'en'], null],
            'malformed' => ['../ko', ['ko'], null],
        ];
    }

    /**
     * Accept-Language 헤더를 적힌 순서대로 협상한다(q 무시, `*`·무효 건너뜀).
     *
     * @effects request_locale_negotiates_region_tagged_pack
     *
     * @return void 반환값 없음
     */
    public function test_negotiate(): void
    {
        $this->assertSame('fil-PH', LocaleTag::negotiate('fil-PH,fil;q=0.9', ['ko', 'en', 'fil-PH']));
        $this->assertSame('fil-PH', LocaleTag::negotiate('fil', ['ko', 'en', 'fil-PH']));
        $this->assertSame('ko', LocaleTag::negotiate('ko-KR,ko;q=0.9,en-US;q=0.8', ['ko', 'en']));
        $this->assertSame('en', LocaleTag::negotiate('*, ../x, fr;q=0.9, en-GB;q=0.8', ['ko', 'en']));
        $this->assertSame('ja', LocaleTag::negotiate('ja;q=0.1, ko;q=0.9', ['ko', 'ja']), '헤더 순서 우선(종전 동작)');
        $this->assertNull(LocaleTag::negotiate('fr, de', ['ko', 'en']));
        $this->assertNull(LocaleTag::negotiate('', ['ko']));

        $this->assertSame('zh-Hant-TW', LocaleTag::firstAcceptLanguageTag('zh-hant-tw,zh;q=0.9'));
        $this->assertSame('en-US', LocaleTag::firstAcceptLanguageTag('en_us'));
        $this->assertNull(LocaleTag::firstAcceptLanguageTag('*'));
        $this->assertNull(LocaleTag::firstAcceptLanguageTag(null));
        $this->assertNull(LocaleTag::firstAcceptLanguageTag('  '));
    }
}
