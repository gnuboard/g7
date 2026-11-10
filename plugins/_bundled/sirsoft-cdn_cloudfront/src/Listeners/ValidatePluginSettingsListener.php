<?php

namespace Plugins\Sirsoft\CdnCloudfront\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Sirsoft\CdnCloudfront\Support\CloudFrontSettings;

/**
 * 플러그인 설정 저장 검증 규칙 — 스키마의 형·길이 검증 위에 모양 검증을 더한다.
 */
class ValidatePluginSettingsListener implements HookListenerInterface
{
    /**
     * 구독할 훅과 메서드 매핑 반환
     *
     * @return array<string, array<string, mixed>> 훅 이름 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.update_validation_rules' => ['method' => 'rules', 'type' => 'filter', 'priority' => 10],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리)
     *
     * @param  mixed  ...$args  훅 인자
     * @return void 반환값 없음
     */
    public function handle(...$args): void {}

    /**
     * 이 플러그인 설정의 검증 규칙을 덧붙입니다.
     *
     * CDN 주소는 http·https 의 호스트 주소만 받는다(쿼리·조각 금지 — 파일 경로가 그 뒤에 붙는다).
     * 배포 ID 는 CloudFront 형식(영문 대문자·숫자)이다.
     *
     * @param  array<string, mixed>  $rules  앞선 필터의 규칙
     * @param  string  $identifier  플러그인 식별자
     * @param  array<string, mixed>  $input  저장 요청 값
     * @return array<string, mixed> 규칙
     */
    public function rules(array $rules, string $identifier, array $input = []): array
    {
        if ($identifier !== CloudFrontSettings::IDENTIFIER) {
            return $rules;
        }

        $rules['cdn_base_url'] = ['sometimes', 'nullable', 'string', 'max:255', 'url:http,https', 'not_regex:/[?#]/'];
        $rules['distribution_id'] = ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Z0-9]+$/'];
        $rules['access_key'] = ['sometimes', 'nullable', 'string', 'max:128'];
        $rules['secret_key'] = ['sometimes', 'nullable', 'string', 'max:255'];
        $rules['purge_enabled'] = ['sometimes', 'boolean'];

        return $rules;
    }
}
