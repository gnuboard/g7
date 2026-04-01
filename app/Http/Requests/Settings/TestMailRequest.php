<?php

namespace App\Http\Requests\Settings;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 테스트 메일 발송 요청 FormRequest
 *
 * 테스트 메일 발송 시 이메일 주소 검증을 위한 요청 클래스입니다.
 */
class TestMailRequest extends FormRequest
{
    /**
     * 요청 권한을 확인합니다.
     *
     * @return bool 항상 true (미들웨어에서 권한 제어)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙을 반환합니다.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $mailer = $this->input('mailer', 'smtp');

        $rules = [
            'to_email' => ['required', 'email', 'max:255'],
            'mailer' => ['sometimes', 'string', 'in:smtp,mailgun,ses'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],

            // SMTP 필드 (mailer가 smtp일 때만 필수)
            'host' => $mailer === 'smtp' ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'port' => $mailer === 'smtp' ? ['required', 'integer', 'min:1', 'max:65535'] : ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'encryption' => ['sometimes', 'nullable', 'string', 'in:tls,ssl,null'],

            // Mailgun 필드 (mailer가 mailgun일 때만 필수)
            'mailgun_domain' => $mailer === 'mailgun' ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'mailgun_secret' => $mailer === 'mailgun' ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'mailgun_endpoint' => ['sometimes', 'nullable', 'string', 'max:255'],

            // SES 필드 (mailer가 ses일 때만 필수)
            'ses_key' => $mailer === 'ses' ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'ses_secret' => $mailer === 'ses' ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
            'ses_region' => $mailer === 'ses' ? ['required', 'string', 'max:255'] : ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.settings.test_mail_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_email.required' => __('settings.invalid_email'),
            'to_email.email' => __('settings.invalid_email'),
            'to_email.max' => __('settings.invalid_email'),
            'from_address.required' => __('validation.required', ['attribute' => __('validation.attributes.from_address')]),
            'from_name.required' => __('validation.required', ['attribute' => __('validation.attributes.from_name')]),
            'host.required' => __('validation.required', ['attribute' => __('validation.attributes.host')]),
            'port.required' => __('validation.required', ['attribute' => __('validation.attributes.port')]),
            'mailgun_domain.required' => __('validation.required', ['attribute' => __('validation.attributes.mailgun_domain')]),
            'mailgun_secret.required' => __('validation.required', ['attribute' => __('validation.attributes.mailgun_secret')]),
            'ses_key.required' => __('validation.required', ['attribute' => __('validation.attributes.ses_key')]),
            'ses_secret.required' => __('validation.required', ['attribute' => __('validation.attributes.ses_secret')]),
            'ses_region.required' => __('validation.required', ['attribute' => __('validation.attributes.ses_region')]),
        ];
    }
}
