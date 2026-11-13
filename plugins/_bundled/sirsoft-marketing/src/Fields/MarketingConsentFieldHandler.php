<?php

namespace Plugins\Sirsoft\Marketing\Fields;

use App\Fields\Contracts\FieldValueHandlerInterface;
use App\Fields\FieldContext;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Plugins\Sirsoft\Marketing\Services\MarketingConsentService;

/**
 * 동의 항목의 값을 동의 테이블(`user_marketing_consents`)에 잇는 저장소 어댑터.
 *
 * 코어 회원 저장(가입, 관리자 추가와 수정, 내 정보 수정)이 회원을 저장한 뒤 같은 트랜잭션에서 부른다.
 * 쓰기는 `MarketingConsentService` 를 거치므로 동의 이력 적재, 전체 동의와 채널의 연동, 동의와 철회 훅이
 * 종전과 같이 일어난다.
 *
 * @since 1.1.4
 */
class MarketingConsentFieldHandler implements FieldValueHandlerInterface
{
    /**
     * @param  MarketingConsentService  $service  마케팅 동의 서비스
     */
    public function __construct(
        private MarketingConsentService $service,
    ) {}

    /**
     * {@inheritDoc}
     *
     * 기록이 없는 항목은 「동의 안 함」이다 — 끈 칸이 그대로 오면 바뀐 것이 없어 쓰기가 불리지 않는다.
     */
    public function read(Model $owner, FieldDefinition $definition): mixed
    {
        if (! $owner instanceof User || ! $owner->exists) {
            return false;
        }

        return $this->service->isConsented((int) $owner->getKey(), $definition->key);
    }

    /**
     * {@inheritDoc}
     *
     * 값이 비어(null) 오면 그 항목은 건드리지 않는다.
     */
    public function write(Model $owner, FieldDefinition $definition, mixed $value, FieldContext $context): void
    {
        if (! $owner instanceof User || $value === null) {
            return;
        }

        $this->service->updateConsents(
            (int) $owner->getKey(),
            [$definition->key => (bool) $value],
            $this->sourceFor($context, $owner),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function extraRules(FieldDefinition $definition, string $attribute, FieldContext $context): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     *
     * 동의 상태와 이력은 회원 단위로 함께 지운다(항목마다 불려도 결과가 같다).
     */
    public function purge(Model $owner, FieldDefinition $definition): void
    {
        if ($owner instanceof User) {
            $this->service->deleteByUserId((int) $owner->getKey());
        }
    }

    /**
     * 동의 이력에 남길 변경 경로를 정합니다.
     *
     * | 표면 | 경로 |
     * | --- | --- |
     * | signup | register |
     * | admin_create, admin_update | admin |
     * | self_update, required_info | profile |
     * | 그 밖(확장 표면) | 요청한 사람이 본인이 아니면 admin, 본인이면 profile |
     *
     * @param  FieldContext  $context  요청 문맥
     * @param  User  $owner  값의 소유자
     * @return string 변경 경로 (register|admin|profile)
     */
    private function sourceFor(FieldContext $context, User $owner): string
    {
        return match ($context->surface) {
            FieldContext::SURFACE_SIGNUP => 'register',
            FieldContext::SURFACE_ADMIN_CREATE, FieldContext::SURFACE_ADMIN_UPDATE => 'admin',
            FieldContext::SURFACE_SELF_UPDATE, FieldContext::SURFACE_REQUIRED_INFO => 'profile',
            default => $context->actor !== null && ! $context->actor->is($owner) ? 'admin' : 'profile',
        };
    }
}
