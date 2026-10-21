<?php

namespace Tests\Feature\Console;

use App\Enums\LanguagePackScope;
use App\Enums\LanguagePackSourceType;
use App\Enums\LanguagePackStatus;
use App\Models\LanguagePack;
use App\Providers\LanguagePackServiceProvider;
use App\Services\LanguagePack\LanguagePackRegistry;
use App\Services\LanguagePack\LanguagePackTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 운영자가 쓰는 Artisan 명령의 출력이 사이트 언어(ja·en)를 따르는지 검증한다.
 *
 * 예전에는 확장 빌드·IP 접근 제한·방문 기록 익명화 명령 등이 콘솔 문구를 코드에 한국어로 적어 두어, 일본어·영어
 * 사이트에서도 한국어로 찍혔다. 대표 명령의 입력 검증 경로(부수 효과 없이 바로 끝나는 경로)를 ja 와 en 으로 실행해
 * 그 언어 문구가 나오는지 본다. ja 기대 문구는 ja 코어 언어팩 번들 원본에서, en 은 en 원본에서 읽고 ko 원문과
 * 다른지 함께 확인한다(번역이 빠져 ko 폴백이 나오면 실패한다).
 */
class ConsoleOutputOperatorLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 번들 원본을 가리키는 활성 ja 코어 언어팩을 만듭니다.
     *
     * @return void 반환값 없음
     */
    private function activateJaCorePack(): void
    {
        LanguagePack::create([
            'identifier' => 'g7-core-ja',
            'vendor' => 'g7',
            'name' => 'G7 Core Japanese',
            'version' => '1.0.0',
            'scope' => LanguagePackScope::Core->value,
            'target_identifier' => null,
            'locale' => 'ja',
            'locale_name' => 'Japanese',
            'locale_native_name' => '日本語',
            'text_direction' => 'ltr',
            'is_protected' => false,
            'manifest' => ['identifier' => 'g7-core-ja', 'scope' => 'core', 'locale' => 'ja'],
            'status' => LanguagePackStatus::Active->value,
            'source_type' => LanguagePackSourceType::BundledWithExtension->value,
            'source_url' => 'lang-packs/_bundled/g7-core-ja',
            'installed_at' => now(),
            'activated_at' => now(),
        ]);
    }

    /**
     * 언어팩 번역 경로가 아직 등록되지 않은 번역기로 바꾸고 실행 로케일을 정합니다.
     *
     * @param  string  $locale  실행 로케일
     * @return void 반환값 없음
     */
    private function useFreshTranslator(string $locale): void
    {
        $translator = new LanguagePackTranslator($this->app->make('translation.loader'), $locale);
        $translator->setFallback((string) config('app.fallback_locale'));
        $this->app->instance('translator', $translator);
        Lang::clearResolvedInstance('translator');
        $this->app->forgetInstance(LanguagePackRegistry::class);
        $this->app->setLocale($locale);
    }

    /**
     * 명령 실행 시점의 로케일 상태를 만듭니다 — ja 는 활성 ja 코어 언어팩을 번역기에 등록합니다.
     *
     * @param  string  $locale  ja 또는 en
     * @return void 반환값 없음
     */
    private function useLocale(string $locale): void
    {
        if ($locale === 'ja') {
            $this->activateJaCorePack();
            $this->useFreshTranslator('ja');
            (new LanguagePackServiceProvider($this->app))->boot();

            return;
        }

        $this->useFreshTranslator($locale);
    }

    /**
     * 기대 문구를 원본 lang 파일에서 읽고 ko 원문과 다른지 확인합니다.
     *
     * @param  string  $locale  ja 또는 en
     * @param  string  $file  lang 파일 이름
     * @param  string  $path  점 표기 키
     * @param  array<string, string|int>  $replace  자리표시자 값
     * @return string 기대 문구
     */
    private function expected(string $locale, string $file, string $path, array $replace = []): string
    {
        $source = $locale === 'ja'
            ? base_path("lang-packs/_bundled/g7-core-ja/backend/ja/{$file}.php")
            : base_path("lang/{$locale}/{$file}.php");
        $message = data_get(require $source, $path);
        $this->assertIsString($message, "{$locale} 원본에 {$file}.{$path} 가 없습니다");

        foreach ($replace as $key => $value) {
            $message = str_replace(':'.$key, (string) $value, $message);
        }

        $this->assertNotSame(trans("{$file}.{$path}", $replace, 'ko'), $message, "{$file}.{$path} 의 {$locale} 문구가 ko 와 같습니다");

        return $message;
    }

    /**
     * 시험 로케일 목록.
     *
     * @return array<string, array{0: string}> 로케일
     */
    public static function locales(): array
    {
        return ['ja' => ['ja'], 'en' => ['en']];
    }

    /**
     * 모듈·플러그인·템플릿 빌드 명령을 대상 없이 실행하면 안내 문구가 사이트 언어로 나온다.
     *
     * @scenario surface=operator_commands
     *
     * @effects command_output_uses_site_locale
     *
     * @param  string  $locale  실행 로케일
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_extension_build_commands_print_site_locale(string $locale): void
    {
        $this->useLocale($locale);

        $this->artisan('module:build')
            ->expectsOutputToContain($this->expected($locale, 'modules', 'commands.build.missing_identifier'))
            ->expectsOutputToContain($this->expected($locale, 'modules', 'commands.build.usage'))
            ->assertExitCode(1);

        $this->artisan('plugin:build')
            ->expectsOutputToContain($this->expected($locale, 'plugins', 'commands.build.missing_identifier'))
            ->assertExitCode(1);

        $this->artisan('template:build')
            ->expectsOutputToContain($this->expected($locale, 'templates', 'commands.build.missing_identifier'))
            ->assertExitCode(1);
    }

    /**
     * IP 접근 제한 명령에 서로 맞지 않는 옵션을 함께 주면 거절 문구가 사이트 언어로 나온다.
     *
     * @scenario surface=operator_commands
     *
     * @effects command_output_uses_site_locale
     *
     * @param  string  $locale  실행 로케일
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_security_ip_restriction_conflict_prints_site_locale(string $locale): void
    {
        $this->useLocale($locale);

        $this->artisan('security:ip-restriction', ['--status' => true, '--disable' => true])
            ->expectsOutputToContain($this->expected($locale, 'common', 'console.security_ip_restriction.conflicting_options'))
            ->assertExitCode(1);
    }

    /**
     * 방문 기록 익명화 명령의 입력 검증 문구가 사이트 언어로 나온다.
     *
     * @scenario surface=operator_commands
     *
     * @effects command_output_uses_site_locale
     *
     * @param  string  $locale  실행 로케일
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('locales')]
    public function test_analytics_anonymize_validation_prints_site_locale(string $locale): void
    {
        $this->useLocale($locale);

        $this->artisan('analytics:anonymize', ['--mask-octets' => 5])
            ->expectsOutputToContain($this->expected($locale, 'analytics', 'console.anonymize.invalid_octets'))
            ->assertExitCode(1);

        $this->artisan('analytics:anonymize', ['--mask-octets' => 0])
            ->expectsOutputToContain($this->expected($locale, 'analytics', 'console.anonymize.option_required'))
            ->assertExitCode(1);
    }
}
