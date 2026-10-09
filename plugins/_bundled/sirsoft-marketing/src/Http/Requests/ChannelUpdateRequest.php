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
     * 동의 존재 확인에 쓸 서비스를 주입받습니다.
     *
     * @param  MarketingConsentService  $consentService  마케팅 동의 서비스
     */
    public function __construct(private MarketingConsentService $consentService)
    {
        parent::__construct();
    }

    /**
     * 권한 확인 (미들웨어에서 관리자 인증 처리)
     *
     * @return bool 항상 true (권한은 라우트 미들웨어가 판정)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙 정의
     *
     * @return array<string, mixed> 필드별 검증 규칙
     */
    public function rules(): array
    {
        return [
            'channels' => ['required', 'array', 'max:10'],
            'channels.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
            'channels.*.label' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 50)],
            'channels.*.page_slug' => ['nullable', 'string', 'max:100'],
            'channels.*.enabled' => ['required', 'boolean'],
            'channels.*.is_system' => ['required', 'boolean'],
        ];
    }

    /**
     * 칸 이름 — 오류 문구가 몇 번째 채널인지(`channels.1.label`)가 아니라 어느 채널인지 알리게 한다
     *
     * 화면 오류 목록은 서버 문구를 그대로 보여 준다. 검증기 기본 칸 이름이 나가면 운영자는 어느 채널의
     * 항목명을 채워야 하는지 알 수 없다(2026-10-07 실측 — 영어 화면에서 기존 채널의 영어 이름이 비어 저장 거절).
     *
     * @return array<string, string> 칸 키 → 표시 이름
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ((array) $this->input('channels', []) as $index => $channel) {
            $key = is_array($channel) && is_scalar($channel['key'] ?? null) ? trim((string) $channel['key']) : '';
            $name = $key !== '' ? $key : (string) ((int) $index + 1);

            $attributes["channels.{$index}.key"] = __('sirsoft-marketing::messages.attributes.channel_key', ['key' => $name]);
            $attributes["channels.{$index}.label"] = __('sirsoft-marketing::messages.attributes.channel_label', ['key' => $name]);
            $attributes["channels.{$index}.page_slug"] = __('sirsoft-marketing::messages.attributes.channel_page_slug', ['key' => $name]);
        }

        return $attributes;
    }

    /**
     * 규칙별 문구 — 키 형식 오류는 쓸 수 있는 문자를 알린다(채널 추가 창의 사전 안내와 같은 기준)
     *
     * @return array<string, string> 규칙 키 → 문구
     */
    public function messages(): array
    {
        return [
            'channels.*.key.regex' => __('sirsoft-marketing::messages.channel_key_invalid'),
        ];
    }

    /**
     * 커스텀 검증 — key 중복, is_system 보호, 동의 존재 시 삭제 거부
     *
     * @param  Validator  $validator  검증기
     * @return void 반환값 없음
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
            // 시스템 채널은 위에서 「삭제할 수 없음」으로 이미 막았다 — 「비활성화로 전환」으로 되살리는 대상이 아니므로
            // 동의 회원 사유와 blocked_channel_keys 를 싣지 않는다(실으면 화면이 동의 회원 안내 창을 띄운다).
            $submittedKeys = array_column($channels, 'key');
            foreach ($existingChannels as $key => $existing) {
                if (in_array($key, $submittedKeys, true) || ($existing['is_system'] ?? false)) {
                    continue;
                }
                // 삭제 대상 채널에 동의 데이터가 있는지 확인
                $consentCount = $this->consentService->countConsentedByKey($key);
                if ($consentCount > 0) {
                    $this->blockedChannelKeys[] = $key;
                    $validator->errors()->add(
                        'channels',
                        trans_count('sirsoft-marketing::messages.channel_has_consents', [
                            'key' => $key,
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
     * @return void 반환값 없음 (항상 예외를 던진다)
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
