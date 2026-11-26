<?php

namespace App\Rules;

use App\Models\Menu;
use App\Services\MenuIconService;
use App\Support\MenuIcon\MenuIconSpec;
use App\Support\MenuIcon\MenuIconSvgValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 메뉴 아이콘 구조형 값(icon_spec) 검증 규칙.
 *
 * class(FA 클래스) · image(사이트 경로·업로드 첨부·확장 패키지) · svg(인라인 코드) 세 형을 판정한다.
 * 형식이 틀리면 조용히 빈 칸으로 저장되던 종전 동작 대신 사유 문구로 422 를 낸다.
 *
 * @since 7.2.1
 */
class MenuIconSpecRule implements ValidationRule
{
    /**
     * MenuIconSpecRule 생성자
     *
     * @param  Menu|null  $menu  수정 대상 메뉴 (생성이면 null) — 첨부 소유·확장 이미지 읽기 전용 판정에 쓴다
     */
    public function __construct(
        private readonly ?Menu $menu = null,
    ) {}

    /**
     * icon_spec 값을 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_array($value)) {
            $fail(__('validation.menu.icon_spec.array'));

            return;
        }

        // 수정에서 현재 저장값을 그대로 되돌려 보내면 통과 — 예전 API 로 저장된 비클래스 문자열(이미지 주소 등)이
        // 남은 메뉴도 아이콘을 건드리지 않은 수정(이름만 바꿈)은 저장돼야 한다. 바꾼 값은 아래에서 종전대로 검사한다.
        if ($this->menu !== null && MenuIconSpec::isUnchanged($value, $this->menu->getRawOriginal('icon'))) {
            return;
        }

        $type = $value['type'] ?? null;

        match ($type) {
            MenuIconSpec::TYPE_CLASS => $this->validateClass($value, $fail),
            MenuIconSpec::TYPE_IMAGE => $this->validateImage($value, $fail),
            MenuIconSpec::TYPE_SVG => $this->validateSvg($value, $fail),
            default => $fail(__('validation.menu.icon_spec.invalid_type')),
        };
    }

    /**
     * class 형을 검증합니다.
     *
     * @param  array<string, mixed>  $value  icon_spec
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    private function validateClass(array $value, Closure $fail): void
    {
        $class = $value['value'] ?? null;
        $violation = is_string($class) ? MenuIconSpec::classViolation($class) : 'class_invalid';

        if ($violation === 'class_max') {
            $fail(__('validation.menu.icon_spec.class_max', ['max' => MenuIconSpec::CLASS_MAX_LENGTH]));
        } elseif ($violation !== null) {
            $fail(__('validation.menu.icon_spec.class_invalid'));
        }
    }

    /**
     * image 형을 검증합니다.
     *
     * @param  array<string, mixed>  $value  icon_spec
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    private function validateImage(array $value, Closure $fail): void
    {
        $source = $value['source'] ?? null;

        if ($source === MenuIconSpec::SOURCE_SITE) {
            $path = $value['path'] ?? null;
            $violation = is_string($path) ? MenuIconSpec::sitePathViolation($path) : 'empty';

            match ($violation) {
                null => null,
                'external' => $fail(__('validation.menu.icon_spec.external_url')),
                'extension' => $fail(__('validation.menu.icon_spec.image_extension_invalid', ['extensions' => implode(', ', MenuIconSpec::IMAGE_EXTENSIONS)])),
                default => $fail(__('validation.menu.icon_spec.site_path_invalid')),
            };

            return;
        }

        if ($source === MenuIconSpec::SOURCE_ATTACHMENT) {
            $id = $value['attachment_id'] ?? null;

            if (! is_numeric($id) || ! app(MenuIconService::class)->isUsableAttachment((int) $id, $this->menu?->id)) {
                $fail(__('validation.menu.icon_spec.attachment_invalid'));
            }

            return;
        }

        if ($source === MenuIconSpec::SOURCE_PACKAGE) {
            // 확장이 선언한 이미지는 운영자가 바꿀 수 없다 — 편집 화면이 그대로 되돌려 보내는 왕복만 통과
            $current = $this->menu?->iconSpec();
            $incoming = MenuIconSpec::fromArray($value);

            if ($current === null || $incoming === null || $current->toStored() !== $incoming->toStored()) {
                $fail(__('validation.menu.icon_spec.package_readonly'));
            }

            return;
        }

        $fail(__('validation.menu.icon_spec.image_source_invalid'));
    }

    /**
     * svg 형을 검증합니다.
     *
     * @param  array<string, mixed>  $value  icon_spec
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    private function validateSvg(array $value, Closure $fail): void
    {
        $svg = $value['value'] ?? null;

        // XML 선언·BOM 만 있어 정규화하면 비는 코드도 「입력 없음」이다(「안전하지 않음: empty」가 아니라)
        if (! is_string($svg) || MenuIconSvgValidator::normalize($svg) === '') {
            $fail(__('validation.menu.icon_spec.svg_required'));

            return;
        }

        $violations = MenuIconSvgValidator::violations($svg);

        if ($violations === ['too_large']) {
            $fail(__('validation.menu.icon_spec.svg_too_large', ['max' => (int) (MenuIconSvgValidator::MAX_BYTES / 1024)]));

            return;
        }

        if ($violations !== []) {
            $fail(__('validation.menu.icon_spec.svg_unsafe', ['reasons' => MenuIconSvgValidator::describe($violations)]));
        }
    }
}
