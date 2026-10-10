<?php

namespace Tests\Unit\Enums;

use App\Enums\LayoutMarkupErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LayoutMarkupErrorCode ↔ 다국어 메시지 키 정합성 테스트.
 *
 * 에러 코드 값은 그대로 `exceptions.layout_markup.{value}` 메시지 키가 된다. 코드만
 * 추가하고 키를 빠뜨리면 작성자에게 번역문 대신 `exceptions.layout_markup.xxx` 라는
 * 키 문자열이 그대로 노출되는데, 이는 컴파일 오류를 만나야만 드러나는 침묵 결함이다.
 * S2 이후 단계가 코드를 누적하므로 그 드리프트를 여기서 기계로 막는다.
 *
 * 검사 축이 둘인 이유: `config('app.supported_locales')` 는 ko/en 뿐이라 **번들 언어팩의
 * 로케일을 순회하지 않는다.** 그 축만 보면 ja 팩이 통째로 비어 있어도 green 이 되므로,
 * 번들 언어팩 파일을 파일시스템에서 직접 찾아 같은 대조를 한 번 더 한다(팩은 설치해야
 * 런타임 로케일이 되므로 테스트 환경의 `__()` 로는 확인할 수 없다).
 */
class LayoutMarkupErrorCodeTest extends TestCase
{
    /**
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects every_error_code_has_a_message_in_every_shipped_locale
     */
    public function test_every_error_code_has_a_message_in_every_supported_locale(): void
    {
        $missing = [];

        foreach (config('app.supported_locales') as $locale) {
            $this->app->setLocale($locale);

            foreach (LayoutMarkupErrorCode::cases() as $code) {
                $key = 'exceptions.layout_markup.'.$code->value;

                // __() 는 키를 찾지 못하면 키 문자열을 그대로 돌려준다.
                if ($code->message() === $key) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }

        $this->assertSame([], $missing, '다국어 메시지가 없는 에러 코드: '.implode(', ', $missing));
    }

    /**
     * @effects every_error_code_has_a_message_in_every_shipped_locale
     */
    #[DataProvider('bundledLocalePackProvider')]
    public function test_every_error_code_has_a_message_in_every_bundled_language_pack(string $pack, string $file): void
    {
        $messages = require $file;
        $defined = array_keys($messages['layout_markup'] ?? []);

        $missing = array_values(array_diff(LayoutMarkupErrorCode::values(), $defined));

        $this->assertSame([], $missing, sprintf(
            '%s 에 메시지가 없는 에러 코드 %d종: %s — 언어팩 빌드 스크립트(`build-language-pack`)로 `--locale=%s %s` 증분 동기화하세요.',
            $pack,
            count($missing),
            implode(', ', $missing),
            basename(dirname($file)),
            $pack,
        ));
    }

    /**
     * 번들 코어 언어팩의 `exceptions.php` 를 로케일별로 수집합니다.
     *
     * @return array<string, array{0: string, 1: string}> 팩 id → [팩 id, 파일 경로]
     */
    public static function bundledLocalePackProvider(): array
    {
        $root = self::projectRoot();
        $cases = [];

        foreach (glob($root.'/lang-packs/_bundled/g7-core-*/backend/*/exceptions.php') ?: [] as $file) {
            // .../g7-core-ja/backend/ja/exceptions.php → g7-core-ja
            $pack = basename(dirname($file, 3));
            $cases[$pack] = [$pack, $file];
        }

        return $cases;
    }

    /**
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects every_layout_markup_message_key_exists_in_every_shipped_locale
     */
    #[DataProvider('translatedLocaleFileProvider')]
    public function test_every_layout_markup_message_key_exists_in_every_locale(string $label, string $file): void
    {
        $expected = self::leafKeys(self::layoutMarkupMessages(self::projectRoot().'/lang/ko/exceptions.php'));
        $actual = self::leafKeys(self::layoutMarkupMessages($file));

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        $this->assertSame([], $missing, sprintf('%s 에 없는 키 %d종: %s', $label, count($missing), implode(', ', $missing)));
        $this->assertSame([], $extra, sprintf('%s 에만 있는 키 %d종(ko 에서 제거된 잔류): %s', $label, count($extra), implode(', ', $extra)));
    }

    /**
     * ko 를 제외한 모든 배포 로케일의 `exceptions.php` 를 수집합니다.
     *
     * 코어 lang 디렉토리(en)와 번들 언어팩(ja 등)을 한 축에 모은다 — 작성자에게 노출되는
     * 경로가 같으므로 검사 강도도 같아야 한다.
     *
     * @return array<string, array{0: string, 1: string}> 라벨 → [라벨, 파일 경로]
     */
    public static function translatedLocaleFileProvider(): array
    {
        $root = self::projectRoot();
        $cases = [];

        foreach (glob($root.'/lang/*/exceptions.php') ?: [] as $file) {
            $locale = basename(dirname($file));

            if ($locale !== 'ko') {
                $cases['lang/'.$locale] = ['lang/'.$locale, $file];
            }
        }

        foreach (self::bundledLocalePackProvider() as $pack => [, $file]) {
            $cases[$pack] = [$pack, $file];
        }

        return $cases;
    }

    /**
     * 언어 파일에서 `layout_markup` 하위 메시지 배열을 꺼냅니다.
     *
     * @param  string  $file  `exceptions.php` 경로
     * @return array<string, mixed> 메시지 배열
     */
    private static function layoutMarkupMessages(string $file): array
    {
        $messages = require $file;

        return $messages['layout_markup'] ?? [];
    }

    /**
     * 중첩 메시지 배열을 점 표기 리프 키 목록으로 폅니다.
     *
     * 열거형 케이스만 순회하면 `error_at` 같은 **봉투 키**와 `tag_owner.*` 하위 배열이
     * 검사 밖에 남는다. 실제로 `tag_owner` 는 값이 한국어 리터럴로 주입돼 일본어 메시지에
     * 한국어가 섞여 나온 적이 있으므로, 파일에 있는 리프 전부를 기준으로 삼는다.
     *
     * @param  array<string, mixed>  $messages  메시지 배열
     * @param  string  $prefix  상위 키 경로
     * @return list<string> 정렬된 리프 키 목록
     */
    private static function leafKeys(array $messages, string $prefix = ''): array
    {
        $keys = [];

        foreach ($messages as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = [...$keys, ...self::leafKeys($value, $path)];

                continue;
            }

            $keys[] = $path;
        }

        sort($keys);

        return $keys;
    }

    /**
     * 프로젝트 루트를 찾습니다.
     *
     * 데이터 프로바이더는 앱 부팅 전에 실행되므로 `base_path()` 를 쓸 수 없다.
     *
     * @return string 프로젝트 루트 절대 경로
     */
    private static function projectRoot(): string
    {
        $root = __DIR__;

        while (! is_file($root.'/artisan') && dirname($root) !== $root) {
            $root = dirname($root);
        }

        return $root;
    }

    /**
     * @effects every_error_code_has_a_message_in_every_shipped_locale
     */
    public function test_error_code_values_are_snake_case_and_unique(): void
    {
        $values = LayoutMarkupErrorCode::values();

        $this->assertSame($values, array_unique($values));

        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $value);
        }
    }
}
