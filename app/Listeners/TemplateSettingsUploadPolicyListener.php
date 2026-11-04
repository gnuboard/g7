<?php

namespace App\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\Template\TemplateSettingsSchema;
use App\Rules\FaviconFileRule;
use App\Services\TemplateSettingsService;
use App\Support\Favicon\FaviconPolicy;
use App\Support\Template\TemplateSettingCollections;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * 파비콘·템플릿 설정 첨부 칸 업로드 정책 (#434)
 *
 * 요청 컬렉션에 따라 업로드 허용 형식·크기를 바꾼다. 다른 컬렉션은 건드리지 않는다.
 *
 * - 파비콘 컬렉션(`site_favicon`, `template_settings.{템플릿}.branding.favicon`): 허용 확장자를 파비콘 형식 7종으로
 *   **교체**한다(전역 허용 목록과 무관하게 ico·svg 를 받고, 전역 목록의 pdf·zip 등은 받지 않는다) + 1MB·ico/svg 내용 검사
 *   ({@see FaviconFileRule}).
 * - 그 밖의 템플릿 설정 첨부 칸(`template_settings.{템플릿}.{섹션}.{키}`): 선언 `accept`(이미지 칸은 이미지 기본 목록)와
 *   전역 허용 목록의 **교집합**만 받고, 선언 `max_size_kb` 를 넘으면 거부한다. 선언에 없는 칸 컬렉션은 아무것도 받지 않는다
 *   (없는 칸으로 파일을 올려 두는 경로를 막는다).
 */
class TemplateSettingsUploadPolicyListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 이름 → 메서드/우선순위 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.attachment.allowed_extensions' => [
                'method' => 'filterAllowedExtensions',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
            'core.attachment.upload_validation_rules' => [
                'method' => 'filterUploadRules',
                'priority' => 20,
                'type' => 'filter',
                'sync' => true,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    /**
     * 요청 컬렉션에 맞는 허용 확장자를 돌려줍니다.
     *
     * @param  array<int, string>  $extensions  허용 확장자 (전역)
     * @param  mixed  $request  업로드 요청 (FormRequest)
     * @return array<int, string> 허용 확장자
     */
    public function filterAllowedExtensions(array $extensions, mixed $request = null): array
    {
        $collection = $this->collection($request);

        if ($collection === null) {
            return $extensions;
        }

        if (FaviconPolicy::isFaviconCollection($collection)) {
            return FaviconPolicy::EXTENSIONS;
        }

        $field = $this->templateField($collection);

        if ($field === null) {
            return TemplateSettingCollections::parse($collection) === null ? $extensions : [];
        }

        [$schema, $path] = $field;
        $global = array_map('strtolower', $extensions);

        return array_values(array_intersect($schema->acceptedExtensions($path), $global));
    }

    /**
     * 요청 컬렉션에 맞는 검증 규칙을 더합니다 — 파비콘은 내용 검사, 그 밖의 칸은 선언 크기 상한.
     *
     * @param  array<string, mixed>  $rules  검증 규칙
     * @param  mixed  $request  업로드 요청 (FormRequest)
     * @return array<string, mixed> 검증 규칙
     */
    public function filterUploadRules(array $rules, mixed $request = null): array
    {
        $collection = $this->collection($request);

        if ($collection === null) {
            return $rules;
        }

        // 크기 상한은 규칙 안에서 본다 — `max:` 를 하나 더 붙이면 업로드 요청의 `file.max` 문구(전역 상한)가 나간다.
        $fileRules = is_array($rules['file'] ?? null) ? $rules['file'] : [];

        if (FaviconPolicy::isFaviconCollection($collection)) {
            $fileRules[] = new FaviconFileRule;
            $rules['file'] = $fileRules;

            return $rules;
        }

        $field = $this->templateField($collection);

        // 받을 형식이 하나도 없는 템플릿 설정 컬렉션(선언 밖 칸·없는 템플릿·선언 accept 와 전역 허용 목록의 교집합이 빔)은
        // 허용 확장자 훅으로 막을 수 없다 — 그 훅의 빈 목록은 「제한 없음」이다. 그래서 여기서 거부 규칙을 더한다.
        if (TemplateSettingCollections::parse($collection) !== null
            && ($field === null || $this->filterAllowedExtensions($this->globalExtensions(), $request) === [])) {
            $fileRules[] = function (string $attribute, mixed $value, Closure $fail): void {
                $fail(__('attachment.validation.template_field_not_accepting'));
            };
            $rules['file'] = $fileRules;

            return $rules;
        }

        $maxKb = $field === null ? null : ($field[0]->field($field[1])['max_size_kb'] ?? null);

        if (is_int($maxKb) && $maxKb > 0) {
            $fileRules[] = function (string $attribute, mixed $value, Closure $fail) use ($maxKb): void {
                if ($value instanceof UploadedFile && (int) $value->getSize() > $maxKb * 1024) {
                    $fail(__('attachment.validation.template_field_too_large', ['max' => $maxKb >= 1024 ? round($maxKb / 1024, 1).'MB' : $maxKb.'KB']));
                }
            };
            $rules['file'] = $fileRules;
        }

        return $rules;
    }

    /**
     * 요청의 컬렉션 이름.
     *
     * @param  mixed  $request  업로드 요청
     * @return string|null 컬렉션 (없으면 null)
     */
    private function collection(mixed $request): ?string
    {
        if (! is_object($request) || ! method_exists($request, 'input')) {
            return null;
        }

        $collection = $request->input('collection');

        return is_string($collection) && $collection !== '' ? $collection : null;
    }

    /**
     * 전역 업로드 허용 확장자 (소문자·점 제거) — 허용 확장자 훅의 입력과 같은 정규화.
     *
     * @return array<int, string> 허용 확장자
     */
    private function globalExtensions(): array
    {
        return array_values(array_filter(array_map(
            static fn ($ext) => strtolower(trim(ltrim((string) $ext, '.'))),
            (array) config('attachment.allowed_extensions', [])
        )));
    }

    /**
     * 템플릿 설정 첨부 칸 컬렉션이면 그 선언과 칸 경로를 돌려줍니다 — 선언에 없는 칸이면 null.
     *
     * @param  string  $collection  컬렉션
     * @return array{0: TemplateSettingsSchema, 1: string}|null 선언·칸 경로
     */
    private function templateField(string $collection): ?array
    {
        $parsed = TemplateSettingCollections::parse($collection);

        if ($parsed === null) {
            return null;
        }

        $schema = app(TemplateSettingsService::class)->schema($parsed['template']);

        if ($schema === null || ! in_array($parsed['path'], $schema->attachmentPaths(), true)) {
            return null;
        }

        return [$schema, $parsed['path']];
    }
}
