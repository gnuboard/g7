<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * 사이트 기본 언어 필수 다국어 필드 검증 규칙
 *
 * 사이트 기본 언어(`app.site_locale`, 또는 지정된 로케일)의 값만 필수로 검증하고,
 * 다른 로케일은 선택적으로 검증합니다. 요청 언어(`app()->getLocale()`)를 쓰지 않는 것은
 * 필수 언어가 보는 사람마다 달라지면 다른 언어 관리자가 만든 레코드를 이어 고칠 수 없기 때문입니다.
 */
class LocaleRequiredTranslatable implements ValidationRule, ValidatorAwareRule
{
    /**
     * 이 규칙을 돌리는 검증기 (칸 표시 이름이 정해져 있는지 보는 데 쓴다)
     */
    private ?Validator $validator = null;

    /**
     * LocaleRequiredTranslatable 생성자
     *
     * @param  int  $maxLength  각 번역의 최대 길이
     * @param  int  $minLength  각 번역의 최소 길이 (기본: 0)
     * @param  string|null  $requiredLocale  필수 로케일 (null이면 사이트 기본 언어 사용)
     * @param  bool  $strictLocales  지원 목록 밖 로케일 키를 실패로 처리할지 여부
     */
    public function __construct(
        private int $maxLength = 255,
        private int $minLength = 0,
        private ?string $requiredLocale = null,
        private bool $strictLocales = false
    ) {}

    /**
     * 이 규칙을 돌리는 검증기를 받습니다.
     *
     * @param  Validator  $validator  검증기
     * @return $this 검증기를 받은 규칙 자신
     */
    public function setValidator($validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * 검증 규칙 실행
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 배열이 아닌 경우
        if (! is_array($value)) {
            $fail(__('validation.translatable.must_be_array'));

            return;
        }

        // 허용된 언어 목록 가져오기
        $allowedLanguages = config('app.translatable_locales', ['ko', 'en']);

        // 필수 로케일 결정 (지정된 값 또는 사이트 기본 언어 — 요청 언어가 아니다)
        $requiredLocale = $this->requiredLocale ?? config('app.site_locale', config('app.locale'));

        // 필수 로케일이 지원 언어에 없으면 첫 번째 언어 사용
        if (! in_array($requiredLocale, $allowedLanguages)) {
            $requiredLocale = $allowedLanguages[0];
        }

        // 필수 로케일 값 검증
        $requiredValue = $value[$requiredLocale] ?? null;

        if (is_null($requiredValue) || (is_string($requiredValue) && trim($requiredValue) === '')) {
            // 칸 이름(:attribute — 검증기가 표시 이름으로 바꾼다)과 언어 이름을 함께 알린다. 코드만 내보내면
            // 「en 언어의 값은 필수입니다」처럼 어느 칸의 어느 언어를 채워야 하는지 알 수 없었다(2026-10-07 실측).
            // 표시 이름을 정하지 않은 칸은 검증기가 필드 키(name)를 그대로 넣으므로, 그때는 칸 이름 없이 언어만 알린다.
            $languageNames = (array) config('app.locale_names', []);
            $messageKey = $this->hasDisplayName($attribute)
                ? 'validation.translatable.current_locale_required'
                : 'validation.translatable.current_locale_required_unnamed';
            $fail(__($messageKey, [
                'locale' => $requiredLocale,
                'language' => $languageNames[$requiredLocale] ?? $requiredLocale,
            ]));

            return;
        }

        // 각 언어별 검증
        foreach ($value as $lang => $text) {
            // 지원 목록 밖 로케일 키
            //
            // app.translatable_locales 는 활성 언어팩 기준으로 부팅마다 덮어써지는 가변 런타임
            // 값이다. 언어팩을 비활성화하면 기존 레코드에 남아 있던 그 로케일 키 때문에 수정
            // 자체가 차단되므로, 기본값은 "길이 검증 대상에서 제외하고 통과"로 둔다.
            // 데이터를 지우지 않으므로 언어팩을 다시 켜면 번역이 그대로 복구된다.
            if (! in_array($lang, $allowedLanguages)) {
                if ($this->strictLocales) {
                    $fail(__('validation.translatable.unsupported_language', ['lang' => $lang]));

                    return;
                }

                continue;
            }

            // null이거나 빈 문자열이 아닌 경우에만 검증
            if ($text !== null && $text !== '') {
                // 문자열이 아닌 경우
                if (! is_string($text)) {
                    $fail(__('validation.translatable.must_be_string', ['lang' => $lang]));

                    return;
                }

                $length = mb_strlen($text);

                // 최소 길이 미달
                if ($this->minLength > 0 && $length < $this->minLength) {
                    $fail(__('validation.translatable.min_length', [
                        'lang' => $lang,
                        'min' => $this->minLength,
                    ]));

                    return;
                }

                // 최대 길이 초과
                if ($length > $this->maxLength) {
                    $fail(__('validation.translatable.max_length', [
                        'lang' => $lang,
                        'max' => $this->maxLength,
                    ]));

                    return;
                }
            }
        }
    }

    /**
     * 칸에 사람이 읽는 표시 이름이 정해져 있는지 확인합니다.
     *
     * 요청의 attributes() 로 넘긴 이름이나 `validation.attributes.{칸}` 번역이 있으면(검증기의 표시 이름 규칙) 표시 이름이 있다.
     * 검증기 없이 직접 부른 경우(단위 사용)는 종전처럼 칸 이름을 싣는 문구를 쓴다.
     *
     * @param  string  $attribute  필드명
     * @return bool 표시 이름이 있으면 true
     */
    private function hasDisplayName(string $attribute): bool
    {
        if ($this->validator === null) {
            return true;
        }

        // 검증기가 표시 이름을 못 찾으면 필드 키를 snake_case 를 띄어 쓴 꼴로 돌려준다(와일드카드 칸 포함 같은 규칙)
        $displayable = $this->validator->getDisplayableAttribute($attribute);

        return $displayable !== $attribute
            && $displayable !== str_replace('_', ' ', Str::snake($attribute));
    }
}
