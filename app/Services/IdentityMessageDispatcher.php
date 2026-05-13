<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Mail\IdentityMessageMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * IDV 메시지 디스패처.
 *
 * IdentityMessageResolver 로 정의/템플릿을 해석한 뒤 변수 치환 → 메일 발송
 * 흐름을 담당합니다. 발송 전/후/실패 훅을 발화하며, DbTemplateMail wrapper 를
 * 재사용해 알림 시스템과 동일한 메일 인프라(헤더, 로깅, 큐) 위에 동작합니다.
 */
class IdentityMessageDispatcher
{
    /**
     * @param  IdentityMessageResolver  $resolver
     */
    public function __construct(
        private readonly IdentityMessageResolver $resolver,
    ) {}

    /**
     * IDV 메시지를 발송합니다.
     *
     * @param  string  $providerId  IDV 프로바이더 ID (예: g7:core.mail)
     * @param  string  $purpose  IDV 목적 (signup, password_reset 등)
     * @param  string|null  $policyKey  정책 키 (정책 컨텍스트가 없으면 null)
     * @param  string  $renderHint  렌더 힌트 (text_code | link)
     * @param  string  $channel  발송 채널 (현재 'mail')
     * @param  string  $target  수신자 (mail 채널은 이메일 주소)
     * @param  array  $data  변수 치환용 데이터 (code, action_url, expire_minutes 등)
     * @param  array  $context  추가 컨텍스트 (challenge_id 등 — 훅에 전달)
     * @return bool 발송 시도 성공 여부 (정의/템플릿 미해석은 false 반환)
     */
    public function dispatch(
        string $providerId,
        string $purpose,
        ?string $policyKey,
        string $renderHint,
        string $channel,
        string $target,
        array $data,
        array $context = [],
    ): bool {
        $resolved = $this->resolver->resolve($providerId, $purpose, $policyKey, $channel);

        $hookContext = array_merge($context, [
            'provider_id' => $providerId,
            'purpose' => $purpose,
            'policy_key' => $policyKey,
            'render_hint' => $renderHint,
            'channel' => $channel,
            'target' => $target,
            'data' => $data,
        ]);

        if ($resolved === null) {
            HookManager::doAction('core.identity.message.resolve_failed', $hookContext);
            Log::warning('[IDV] 메시지 정의/템플릿 미해석으로 발송 건너뜀', [
                'provider_id' => $providerId,
                'purpose' => $purpose,
                'policy_key' => $policyKey,
                'render_hint' => $renderHint,
                'channel' => $channel,
            ]);

            return false;
        }

        $template = $resolved['template'];
        $rendered = $template->replaceVariables($data);

        $hookContext['definition_id'] = $resolved['definition']->id;
        $hookContext['template_id'] = $template->id;
        $hookContext['rendered'] = $rendered;

        HookManager::doAction('core.identity.message.before_send', $hookContext);

        try {
            if ($channel === 'mail') {
                $mailable = new IdentityMessageMail(
                    renderedSubject: $rendered['subject'],
                    renderedBody: $rendered['body'],
                    recipientEmail: $target,
                    providerId: $providerId,
                    scopeType: $resolved['definition']->scope_type->value,
                    scopeValue: (string) $resolved['definition']->scope_value,
                );

                Mail::to($target)->send($mailable);
            } else {
                // 미래 채널 확장 — 현재는 mail 만 지원
                HookManager::applyFilters(
                    'core.identity.message.send_'.$channel,
                    null,
                    $hookContext
                );
            }

            HookManager::doAction('core.identity.message.after_send', $hookContext);

            return true;
        } catch (\Throwable $e) {
            HookManager::doAction('core.identity.message.send_failed', array_merge($hookContext, [
                'error_message' => $e->getMessage(),
            ]));

            Log::warning('[IDV] 메시지 발송 실패', [
                'provider_id' => $providerId,
                'purpose' => $purpose,
                'policy_key' => $policyKey,
                'render_hint' => $renderHint,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
