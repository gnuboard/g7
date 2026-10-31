<?php

namespace Plugins\Sirsoft\DaumPostcode\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Lang;

/**
 * 환경설정 저장 검증 오류 문구의 항목 이름을 요청 언어로 등록하는 filter 훅 listener.
 *
 * 코어 UpdatePluginSettingsRequest 는 `validation.attributes.{field}` 번역선이 있으면 설정 스키마
 * `label` 보다 먼저 쓴다. 스키마 label 은 ko/en 만 들고 있어, 등록하지 않으면 일본어 화면에서 팝업 크기
 * 범위 오류 같은 문구에 한국어 항목 이름(「팝업 너비 (px)」)이 섞인다. 규칙은 바꾸지 않고 이름만 등록한다.
 */
class RegisterSettingsAttributeLabelsListener implements HookListenerInterface
{
    /**
     * 본 플러그인 식별자.
     */
    private const IDENTIFIER = 'sirsoft-daum_postcode';

    /**
     * 검증 오류 문구에 언어별 항목 이름을 달 설정 필드(설정 스키마 전 항목).
     *
     * 이름은 `messages.settings.{field}_attribute` 번역에서 읽는다 — ko/en 은 플러그인 lang,
     * 그 밖의 언어(ja 등)는 언어팩이 채운다.
     *
     * @var array<int, string>
     */
    private const SETTINGS_FIELDS = ['display_mode', 'popup_width', 'popup_height', 'theme_color'];

    /**
     * 구독 훅 메타데이터.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.update_validation_rules' => [
                'method' => 'registerLabels',
                'priority' => 10,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 인터페이스 표준 진입점 — getSubscribedHooks 가 method='registerLabels' 를 명시하므로 미사용.
     *
     * @param  mixed  ...$args  사용 안 함
     * @return void
     */
    public function handle(...$args): void
    {
        // no-op — 실제 진입점은 registerLabels() (filter 훅)
    }

    /**
     * 이 플러그인의 설정 저장이면 항목 이름을 현재 요청 언어로 등록하고 규칙은 그대로 돌려준다.
     *
     * @param  array<string, mixed>  $rules  코어가 스키마로 생성한 검증 규칙
     * @param  string  $identifier  검증 대상 플러그인 식별자
     * @return array<string, mixed> 받은 규칙 그대로
     */
    public function registerLabels(array $rules, string $identifier): array
    {
        if ($identifier !== self::IDENTIFIER) {
            return $rules;
        }

        $locale = app()->getLocale();

        // addLines 가 validation 그룹을 attributes 만 든 빈 껍데기로 먼저 캐시하면 validation.php 전체가
        // 로드되지 않는다 — 그룹을 먼저 읽힌 뒤 attributes 만 보탠다.
        Lang::get('validation.required', [], $locale);

        $lines = [];
        foreach (self::SETTINGS_FIELDS as $field) {
            $key = self::IDENTIFIER."::messages.settings.{$field}_attribute";
            // 번역이 없는 언어에서는 등록하지 않고 스키마 label 폴백에 맡긴다.
            if (Lang::has($key, $locale)) {
                $lines["validation.attributes.{$field}"] = __($key, [], $locale);
            }
        }

        if ($lines !== []) {
            Lang::addLines($lines, $locale);
        }

        return $rules;
    }
}
