<?php

namespace App\Services;

use App\Models\IdentityMessageDefinition;
use App\Models\IdentityMessageTemplate;

/**
 * IDV 메시지 Resolver.
 *
 * (provider_id, purpose, policy_key) 컨텍스트에서 가장 구체적인 정의/템플릿을
 * 해석합니다. 우선순위: policy → purpose → provider_default.
 */
class IdentityMessageResolver
{
    /**
     * @param  IdentityMessageDefinitionService  $definitionService
     * @param  IdentityMessageTemplateService  $templateService
     */
    public function __construct(
        private readonly IdentityMessageDefinitionService $definitionService,
        private readonly IdentityMessageTemplateService $templateService,
    ) {}

    /**
     * (provider, purpose, policy) 컨텍스트로 활성 정의 + 템플릿을 해석합니다.
     *
     * 우선순위 (가장 구체적인 것 우선):
     *   1. policy:{policyKey}
     *   2. purpose:{purpose}
     *   3. provider_default
     *
     * @param  string  $providerId
     * @param  string  $purpose
     * @param  string|null  $policyKey
     * @param  string  $channel  발송 채널 (현재 'mail')
     * @return array{definition: IdentityMessageDefinition, template: IdentityMessageTemplate}|null
     */
    public function resolve(string $providerId, string $purpose, ?string $policyKey, string $channel = 'mail'): ?array
    {
        $candidates = [];

        if ($policyKey !== null && $policyKey !== '') {
            $candidates[] = [IdentityMessageDefinition::SCOPE_POLICY, $policyKey];
        }

        $candidates[] = [IdentityMessageDefinition::SCOPE_PURPOSE, $purpose];
        $candidates[] = [IdentityMessageDefinition::SCOPE_PROVIDER_DEFAULT, ''];

        foreach ($candidates as [$scopeType, $scopeValue]) {
            $definition = $this->definitionService->resolve($providerId, $scopeType, $scopeValue);

            if (! $definition instanceof IdentityMessageDefinition) {
                continue;
            }

            $template = $this->templateService->resolve($definition->id, $channel);

            if (! $template instanceof IdentityMessageTemplate) {
                continue;
            }

            return [
                'definition' => $definition,
                'template' => $template,
            ];
        }

        return null;
    }
}
