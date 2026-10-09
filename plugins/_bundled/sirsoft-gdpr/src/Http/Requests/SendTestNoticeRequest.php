<?php

namespace Plugins\Sirsoft\Gdpr\Http\Requests;

use App\Services\PluginSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Plugins\Sirsoft\Gdpr\Http\Requests\Concerns\ProvidesFallbackRuleMessages;

/** 수신자는 인증 회원으로 고정하고 저장된 채널 설정을 검증합니다. */
class SendTestNoticeRequest extends FormRequest
{
    use ProvidesFallbackRuleMessages;

    /**
     * 권한 검사는 라우트 미들웨어에 위임합니다.
     *
     * @return bool 처리 결과
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 저장된 채널을 시험 발송 검증 입력으로 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['channels' => app(PluginSettingsService::class)->get('sirsoft-gdpr', 'annual_notice.channels', ['mail', 'database'])]);
    }

    /**
     * 요청 입력의 허용 형식과 범위를 반환합니다.
     *
     * @return array 처리 결과
     */
    public function rules(): array
    {
        return ['channels' => ['required', 'array', 'min:1', 'max:2'], 'channels.*' => ['string', 'distinct', 'in:mail,database']];
    }

    /**
     * 검증 문구의 :attribute 자리에 넣을 운영자용 라벨을 반환합니다.
     *
     * 항목 키(channels.0)가 문구에 그대로 실리지 않도록 와일드카드도 함께 덮습니다.
     *
     * @return array 필드별 라벨
     */
    public function attributes(): array
    {
        return [
            'channels' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_channels.label'),
            'channels.*' => __('sirsoft-gdpr::messages.settings.fields.annual_notice_channels.label'),
        ];
    }
}
