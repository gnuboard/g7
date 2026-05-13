<?php

namespace App\Http\Requests\Admin\Identity;

use App\Extension\HookManager;
use App\Extension\IdentityVerification\IdentityVerificationManager;
use App\Models\IdentityMessageDefinition;
use App\Models\IdentityPolicy;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 운영자가 정책 매핑 IDV 메시지 정의를 신규 생성할 때의 검증.
 *
 * 정책:
 * - scope_type 은 'policy' 만 허용 (provider_default/purpose 는 시드 영역).
 * - scope_value 는 source_type='admin' 인 IdentityPolicy.key 와 일치해야 함.
 * - (provider_id, scope_type, scope_value) 는 unique.
 * - 권한은 라우트 permission 미들웨어가 담당.
 */
class StoreIdentityMessageDefinitionRequest extends FormRequest
{
    /**
     * 라우트 permission 미들웨어가 담당하므로 true 고정.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'provider_id' => ['required', 'string', 'max:64', $this->providerExistsRule()],
            'scope_type' => ['required', Rule::in([IdentityMessageDefinition::SCOPE_POLICY])],
            'scope_value' => [
                'required',
                'string',
                'max:120',
                $this->adminPolicyKeyRule(),
                $this->scopeUniqueRule(),
            ],
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'description' => ['nullable', 'array', new TranslatableField(maxLength: 1000)],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(['mail'])],
            'variables' => ['nullable', 'array'],
            'variables.*.key' => ['required_with:variables', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/i'],
            'variables.*.description' => ['nullable', 'string', 'max:200'],
            'templates' => ['required', 'array', 'min:1'],
            'templates.*.channel' => ['required', 'string', 'max:20'],
            'templates.*.subject' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
            'templates.*.body' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 65535)],
        ];

        return HookManager::applyFilters(
            'core.identity.message_definition.filter_store_rules',
            $rules,
        );
    }

    /**
     * provider_id 가 등록된 IDV 프로바이더인지 확인하는 closure 룰.
     *
     * @return Closure
     */
    protected function providerExistsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $manager = app(IdentityVerificationManager::class);
            if (! $manager->has($value)) {
                $fail(__('validation.identity_message.provider_not_registered'));
            }
        };
    }

    /**
     * scope_value 가 source_type='admin' 인 IdentityPolicy.key 와 일치하는지 확인.
     *
     * @return Closure
     */
    protected function adminPolicyKeyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                return;
            }

            $exists = IdentityPolicy::where('key', $value)
                ->where('source_type', 'admin')
                ->exists();

            if (! $exists) {
                $fail(__('validation.identity_message.scope_value_not_admin_policy'));
            }
        };
    }

    /**
     * (provider_id, scope_type, scope_value) 조합 중복 검사.
     *
     * @return Closure
     */
    protected function scopeUniqueRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $providerId = $this->input('provider_id');
            $scopeType = $this->input('scope_type');

            if (! is_string($providerId) || ! is_string($scopeType) || ! is_string($value)) {
                return;
            }

            $exists = IdentityMessageDefinition::where('provider_id', $providerId)
                ->where('scope_type', $scopeType)
                ->where('scope_value', $value)
                ->exists();

            if ($exists) {
                $fail(__('validation.identity_message.definition_already_exists'));
            }
        };
    }
}
