<?php

namespace App\Providers;

use App\Support\ValidationPluralMessages;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * 서비스를 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        //
    }

    /**
     * 서비스를 부트스트랩하고 다국어 파일들을 로드합니다.
     *
     * @return void 반환값 없음
     */
    public function boot(): void
    {
        $this->loadModuleTranslations();
        $this->loadPluginTranslations();
        $this->translateValidationSummaryTail();
        $this->chooseValidationMessagesByRuleValue();
    }

    /**
     * 검증 문구의 `단수|복수` 를 규칙값으로 고르는 치환기를 등록합니다.
     *
     * 영어 표준 검증 문구(`validation.min.array` 등)는 `단수|복수` 로 적혀 있고, Laravel 검증기는 수로 고르지 않는다 —
     * 이 치환기가 `min:1` 이면 「at least 1 item」, `min:2` 면 「at least 2 items」 처럼 규칙값으로 고른다.
     *
     * @return void 반환값 없음
     */
    private function chooseValidationMessagesByRuleValue(): void
    {
        ValidationPluralMessages::register($this->app['validator']);
    }

    /**
     * 검증 실패 요약 문구의 「나머지 오류 수」 꼬리를 코어 번역으로 잇습니다.
     *
     * Laravel 은 422 응답 message 를 첫 오류 + 「(and :count more error(s))」로 만들고(ValidationException::summarize),
     * 이 꼬리는 JSON 번역 키로만 찾는다. 코어의 `lang/{locale}.json` 은 프론트 번역 진입 파일이라 그 자리에 섞지 않고,
     * 번역기가 키를 못 찾았을 때 이 두 문구만 `validation.summary_more_error`(단수)·`summary_more_errors`(복수)로 넘긴다. 다른 키는 건드리지 않는다
     * (콜백이 null 을 돌려주면 번역기는 종전처럼 키를 그대로 쓴다). 언어팩이 이 키를 두지 않은 로케일은 영어 원문으로 남는다.
     *
     * @return void 반환값 없음
     */
    private function translateValidationSummaryTail(): void
    {
        // Laravel 은 단수·복수를 서로 다른 키로 부른다 — 키마다 따로 이어 choice() 의 복수 규칙 선택을 거치지 않게 한다.
        $tails = [
            '(and :count more error)' => 'validation.summary_more_error',
            '(and :count more errors)' => 'validation.summary_more_errors',
        ];

        $this->app['translator']->handleMissingKeysUsing(function (string $key) use ($tails) {
            if (! isset($tails[$key])) {
                return null;
            }

            // 넘어오는 로케일은 쓰지 않는다 — choice() 는 키가 없으면 로케일을 대체 로케일로 바꿔 부르므로(localeForChoice),
            // 그 값을 따르면 영어 화면에도 대체 로케일(ko) 문구가 붙는다. 요약 문구는 늘 현재 로케일로 만든다.
            $translator = $this->app['translator'];
            $line = $translator->get($tails[$key], [], $translator->getLocale(), false);

            return $line === $tails[$key] ? null : $line;
        });
    }

    /**
     * 모든 모듈의 다국어 파일들을 로드하고 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function loadModuleTranslations(): void
    {
        $modulesPath = base_path('modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        $modules = File::directories($modulesPath);

        foreach ($modules as $modulePath) {
            $moduleName = basename($modulePath);
            $langPath = $modulePath.'/src/lang';

            if (File::exists($langPath)) {
                $this->loadTranslationsFrom($langPath, strtolower($moduleName));
            }
        }
    }

    /**
     * 모든 플러그인의 다국어 파일들을 로드하고 등록합니다.
     *
     * @return void 반환값 없음
     */
    private function loadPluginTranslations(): void
    {
        $pluginsPath = base_path('plugins');

        if (! File::exists($pluginsPath)) {
            return;
        }

        $plugins = File::directories($pluginsPath);

        foreach ($plugins as $pluginPath) {
            $pluginName = basename($pluginPath);
            $langPath = $pluginPath.'/lang';

            if (File::exists($langPath)) {
                $this->loadTranslationsFrom($langPath, strtolower($pluginName));
            }
        }
    }
}
