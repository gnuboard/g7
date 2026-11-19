<?php

namespace App\Rules;

use App\DisplayName\DisplayNameArea;
use App\DisplayName\DisplayNameAreaRegistry;
use App\DisplayName\DisplayNameKindRegistry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 환경설정 > 회원 > 표시 이름 설정 값을 종류, 영역 등록소로 검증합니다.
 *
 * | 대상 | 검사 |
 * | --- | --- |
 * | kind | 지금 등록된 종류여야 한다 (회원이 고를 수 있는 종류의 각 원소) |
 * | default | 지금 등록된 종류이고, 함께 보낸 「회원이 고를 수 있는 종류」 안에 있어야 한다 |
 * | areas | 키가 지금 등록된 영역이어야 하고, 고정이면 종류가 등록돼 있어야 하며, 사이트 기본 영역은 「따름」일 수 없다 |
 *
 * 종류와 영역은 확장이 더하므로 고정 목록(`Rule::in`)으로는 검증할 수 없다.
 *
 * @since 7.2.0
 */
class MemberDisplayNameSettings implements DataAwareRule, ValidationRule
{
    /** 대상: 종류 키 하나 */
    public const TARGET_KIND = 'kind';

    /** 대상: 회원 기본 종류 */
    public const TARGET_DEFAULT = 'default';

    /** 대상: 영역 묶음 */
    public const TARGET_AREAS = 'areas';

    /**
     * 검증 중인 전체 데이터.
     *
     * @var array<string, mixed>
     */
    protected array $data = [];

    /**
     * @param  string  $target  검증 대상 (TARGET_*)
     */
    public function __construct(
        protected string $target,
    ) {}

    /**
     * 검증 중인 전체 데이터를 받습니다.
     *
     * @param  array<string, mixed>  $data  전체 데이터
     * @return static 자기 자신
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  값
     * @param  Closure(string): PotentiallyTranslatedString  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $kinds = app(DisplayNameKindRegistry::class);

        if ($this->target === self::TARGET_KIND) {
            if (! is_string($value) || ! $kinds->has($value)) {
                $fail(__('display_name.validation.unknown_kind', ['kind' => is_scalar($value) ? (string) $value : '']));
            }

            return;
        }

        if ($this->target === self::TARGET_DEFAULT) {
            if (! is_string($value) || ! $kinds->has($value)) {
                $fail(__('display_name.validation.unknown_kind', ['kind' => is_scalar($value) ? (string) $value : '']));

                return;
            }
            $selectable = data_get($this->data, 'member.display_name_selectable_kinds');
            if (is_array($selectable) && ! in_array($value, $selectable, true)) {
                $fail(__('display_name.validation.default_not_selectable'));
            }

            return;
        }

        $this->validateAreas($value, $kinds, $fail);
    }

    /**
     * 영역 묶음을 검증합니다.
     *
     * @param  mixed  $value  `{영역 키: {mode, kind}}`
     * @param  DisplayNameKindRegistry  $kinds  종류 등록소
     * @param  Closure(string): PotentiallyTranslatedString  $fail  실패 콜백
     * @return void 반환값 없음
     */
    protected function validateAreas(mixed $value, DisplayNameKindRegistry $kinds, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $areas = app(DisplayNameAreaRegistry::class);
        foreach ($value as $key => $setting) {
            $area = is_string($key) ? $areas->get($key) : null;
            if ($area === null) {
                $fail(__('display_name.validation.unknown_area', ['area' => (string) $key]));

                continue;
            }
            if (! is_array($setting)) {
                continue;
            }

            $mode = $setting['mode'] ?? null;
            if ($mode === DisplayNameArea::MODE_INHERIT && $key === DisplayNameAreaRegistry::AREA_SITE) {
                $fail(__('display_name.validation.site_cannot_inherit'));
            }
            if ($mode === DisplayNameArea::MODE_FIXED && (! is_string($setting['kind'] ?? null) || ! $kinds->has($setting['kind']))) {
                $fail(__('display_name.validation.fixed_kind_required', ['area' => $area->label()]));
            }
        }
    }
}
