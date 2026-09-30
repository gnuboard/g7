<?php

namespace Plugins\Sirsoft\Marketing\Http\Requests;

use App\Helpers\ResponseHelper;
use App\Rules\LocaleRequiredTranslatable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 채널 목록 저장 요청 검증
 *
 * PUT /api/admin/plugins/sirsoft-marketing/channels
 */
class ChannelUpdateRequest extends FormRequest
{
    /**
     * 동의한 회원이 있어 삭제가 막힌 채널 키 목록 (검증 중 채워진다)
     *
     * @var array<int, string>
     */
    private array $blockedChannelKeys = [];

    /**
     * @param MarketingConsentService $consentService 마케팅 동의 서비스
     */
    public function __construct(private MarketingConsentService $consentService)
    {
        parent::__construct();
    }

    /**
     * 권한 확인 (미들웨어에서 관리자 인증 처리)
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channels'              => ['required', 'array', 'max:10'],
            'channels.*.key'        => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
            'channels.*.label'      => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 50)],
            'channels.*.page_slug'  => ['nullable', 'string', 'max:100'],
            'channels.*.enabled'    => ['required', 'boolean'],
            'channels.*.is_system'  => ['required', 'boolean'],
        ];
    }

    /**
     * 커스텀 검증 — key 중복, is_system 보호, 동의 존재 시 삭제 거부
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $channels = $this->input('channels', []);

            // key 중복 검증
            $keys = array_column($channels, 'key');
            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('channels', __('sirsoft-marketing::messages.channel_key_duplicate'));
                return;
            }

            $existingChannels = collect($this->consentService->getAllChannels())->keyBy('key');

            foreach ($channels as $index => $channel) {
                $key = $channel['key'] ?? '';

                // is_system 채널의 key 변경 불가 검증
                $existing = $existingChannels->get($key);
                if ($existing && ($existing['is_system'] ?? false)) {
                    // is_system 채널이 제출된 목록에서 제거되었는지 확인
                    // (key가 동일하면 통과, 다른 is_system key가 사라진 경우 감지)
                }

                // is_system 플래그 위변조 방지: 기존에 is_system=true인 채널을 false로 변경 불가
                if ($existing && ($existing['is_system'] ?? false) && ! ($channel['is_system'] ?? false)) {
                    $validator->errors()->add(
                        "channels.{$index}.is_system",
                        __('sirsoft-marketing::messages.channel_system_protected')
                    );
                }
            }

            // is_system 채널 누락 검증 (시스템 채널은 삭제 불가)
            foreach ($existingChannels as $key => $existing) {
                if (! ($existing['is_system'] ?? false)) {
                    continue;
                }
                $found = collect($channels)->firstWhere('key', $key);
                if (! $found) {
                    $validator->errors()->add(
                        'channels',
                        __('sirsoft-marketing::messages.channel_system_cannot_delete', ['key' => $key])
                    );
                }
            }

            // 기존 동의 데이터 존재 시 채널 삭제 거부
            $submittedKeys = array_column($channels, 'key');
            foreach ($existingChannels as $key => $existing) {
                if (in_array($key, $submittedKeys, true)) {
                    continue;
                }
                // 삭제 대상 채널에 동의 데이터가 있는지 확인
                $consentCount = $this->consentService->countConsentedByKey($key);
                if ($consentCount > 0) {
                    $this->blockedChannelKeys[] = $key;
                    $validator->errors()->add(
                        'channels',
                        __('sirsoft-marketing::messages.channel_has_consents', [
                            'key'   => $key,
                            'count' => $consentCount,
                        ])
                    );
                }
            }
        });
    }

    /**
     * 검증 실패 응답 — 삭제가 막힌 채널이 있으면 그 키를 `errors.blocked_channel_keys` 에 싣는다
     *
     * 화면은 이 필드가 있을 때만 「비활성으로 전환」 창을 열고 그 키의 채널을 되살린다.
     * 번역된 안내 문장은 언어마다 달라 채널을 가리키는 값으로 쓸 수 없고, 레이아웃이 읽을 수 있는
     * 실패 문맥은 `error.errors` 뿐이라 구조화 값도 `errors` 안에 둔다.
     *
     * @param  ValidatorContract  $validator  실패한 검증기
     * @return void
     *
     * @throws ValidationException 항상
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->blockedChannelKeys === []) {
            parent::failedValidation($validator);
        }

        $errors = $validator->errors()->toArray();
        $errors['blocked_channel_keys'] = array_values(array_unique($this->blockedChannelKeys));

        throw new ValidationException($validator, ResponseHelper::error(
            $validator->errors()->first(),
            422,
            $errors
        ));
    }
}
