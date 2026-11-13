<?php

namespace Plugins\Sirsoft\Sso\Listeners\Provisioning;

use App\Contracts\Extension\HookListenerInterface;
use App\Fields\FieldContext;
use App\Fields\FieldSet;
use App\Models\FieldDefinition;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Services\ConsentFieldPrefill;

/**
 * 첫 로그인 동의 창이 받는 회원 항목에 IdP 값을 초기값으로 채우고, IdP 가 관리하는 칸을 잠급니다
 * (필터 `core.field.filter_definitions`).
 *
 * 동의 창은 코어 가입 화면과 같은 API(`GET /api/auth/register/fields`)로 항목을 받으면서 동의 대기 토큰을
 * `pending_token`, 출처를 `pending_source=sirsoft-sso` 로 넘긴다. 코어는 그 값을 해석하지 않고 문맥의 부가 값으로
 * 이 필터에 넘긴다 — 여기서 대기의 신원을 읽어 항목 사본에 초기값(`constraints.default`)과 잠금(`ui.readonly`,
 * `ui.locked_reason = sso`)을 채운다. 고치는 것은 이 요청의 항목 사본뿐이라 저장된 항목 정의는 바뀌지 않는다.
 *
 * 대기가 없거나 만료됐거나 다른 브라우저의 요청이면 아무것도 채우지 않는다(항목 조회는 그대로 답한다).
 *
 * @since 1.0.0
 */
class PrefillConsentFieldsListener implements HookListenerInterface
{
    /** 가입 항목 조회의 `pending_source` 값 — 이 플러그인의 동의 대기를 가리킨다 */
    public const PENDING_SOURCE = 'sirsoft-sso';

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.field.filter_definitions' => ['method' => 'prefill', 'priority' => 20, 'type' => 'filter', 'sync' => true],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * IdP 가 값을 주는 항목에 초기값과 잠금을 채웁니다.
     *
     * @param  mixed  $definitions  앞선 필터 결과 (항목 정의 사본 목록)
     * @param  mixed  ...$args  [string $set, ?string $scopeKey, array $context]
     * @return mixed 초기값과 잠금을 채운 항목 목록
     */
    public function prefill(mixed $definitions, mixed ...$args): mixed
    {
        $context = is_array($args[2] ?? null) ? $args[2] : [];
        $extra = is_array($context['extra'] ?? null) ? $context['extra'] : [];

        if (! $definitions instanceof Collection
            || ($args[0] ?? null) !== FieldSet::CORE_USER
            || ($context['surface'] ?? null) !== FieldContext::SURFACE_SIGNUP
            || ($extra['pending_source'] ?? null) !== self::PENDING_SOURCE
            || ! is_string($extra['pending_token'] ?? null)) {
            return $definitions;
        }

        // 항목 목록은 요청마다 여러 번 만들어진다 — 대기를 읽는 서비스는 동의 창의 조회일 때만 꺼낸다.
        $initial = app(ConsentFieldPrefill::class)->forToken($extra['pending_token']);
        if ($initial === null) {
            return $definitions;
        }

        foreach ($definitions as $definition) {
            if (! $definition instanceof FieldDefinition
                || $definition->storage !== FieldDefinition::STORAGE_COLUMN
                || ! isset($initial['values'][$definition->key])) {
                continue;
            }

            $definition->constraints = [...($definition->constraints ?? []), 'default' => $initial['values'][$definition->key]];
            if (in_array($definition->key, $initial['locked'], true)) {
                $definition->ui = [...($definition->ui ?? []), 'readonly' => true, 'locked_reason' => 'sso'];
            }
        }

        return $definitions;
    }
}
