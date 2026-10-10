<?php

namespace Tests\Unit\Enums;

use App\Enums\LayoutMarkupLintCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LayoutMarkupLintCode ↔ 다국어 메시지 키 정합성 테스트.
 *
 * lint 코드 값은 그대로 `exceptions.layout_markup_lint.{value}` 메시지 키가 된다. 코드만 추가하고
 * 키를 빠뜨리면 작성자에게 번역문 대신 키 문자열이 그대로 노출된다. `LayoutMarkupErrorCodeTest`
 * 와 같은 두 축(코어 supported_locales + 번들 언어팩 파일 직접 대조)으로 그 드리프트를 막는다 —
 * 번들 팩은 설치해야 런타임 로케일이 되므로 `__()` 로는 확인할 수 없어 파일을 직접 읽는다.
 */
class LayoutMarkupLintCodeTest extends TestCase
{
    /**
     * @scenario compile_unit=registry,source_health=valid
     *
     * @effects every_lint_code_has_a_message_in_every_shipped_locale
     */
    public function test_every_lint_code_has_a_message_in_every_supported_locale(): void
    {
        $missing = [];

        foreach (config('app.supported_locales') as $locale) {
            $this->app->setLocale($locale);

            foreach (LayoutMarkupLintCode::cases() as $code) {
                $key = 'exceptions.layout_markup_lint.'.$code->value;

                if ($code->message() === $key) {
                    $missing[] = "{$locale}: {$key}";
                }
            }
        }

        $this->assertSame([], $missing, '다국어 메시지가 없는 lint 코드: '.implode(', ', $missing));
    }

    /**
     * @effects every_lint_code_has_a_message_in_every_shipped_locale
     */
    #[DataProvider('bundledLocalePackProvider')]
    public function test_every_lint_code_has_a_message_in_every_bundled_language_pack(string $pack, string $file): void
    {
        $messages = require $file;
        $defined = array_keys($messages['layout_markup_lint'] ?? []);

        $missing = array_values(array_diff(LayoutMarkupLintCode::values(), $defined));

        $this->assertSame([], $missing, sprintf(
            '%s 에 메시지가 없는 lint 코드 %d종: %s — 언어팩 빌드 스크립트(`build-language-pack`)로 `--locale=%s %s` 증분 동기화하세요.',
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
            $pack = basename(dirname($file, 3));
            $cases[$pack] = [$pack, $file];
        }

        return $cases;
    }

    /**
     * @effects every_lint_code_has_a_message_in_every_shipped_locale
     */
    public function test_lint_code_values_are_snake_case_and_unique(): void
    {
        $values = LayoutMarkupLintCode::values();

        $this->assertSame($values, array_unique($values));

        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $value);
        }
    }

    /**
     * 프로젝트 루트를 찾습니다 (데이터 프로바이더는 앱 부팅 전 실행이라 base_path 불가).
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
}
