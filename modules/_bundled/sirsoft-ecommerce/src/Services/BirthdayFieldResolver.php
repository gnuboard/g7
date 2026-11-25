<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Fields\FieldRegistry;
use App\Models\FieldDefinition;
use App\Support\LocalizedValue;
use Illuminate\Support\Collection;

/**
 * 생일 쿠폰이 쓸 회원 항목을 고르는 해석기 (#952)
 *
 * 쇼핑몰 환경설정(쿠폰 탭)의 「생일로 쓸 회원 항목」(`promotion.birthday_field_key`)은 코어 회원 항목 집합
 * (`core.user`) 중 **날짜 유형이고 공용 값 테이블에 저장하는 항목**만 고를 수 있다. 꺼진 항목은 후보로 보이되
 * 화면에 「(꺼짐)」으로 표시한다. 발급 명령은 실행 시점에 이 해석기로 다시 확인한다(설정은 나중에 바뀔 수 있다).
 */
class BirthdayFieldResolver
{
    /** 회원 항목 집합 */
    public const FIELD_SET = 'core.user';

    /** 설정 키 */
    public const SETTING_KEY = 'promotion.birthday_field_key';

    /**
     * @param  FieldRegistry  $fields  코어 항목 정의 레지스트리
     */
    public function __construct(
        protected FieldRegistry $fields,
    ) {}

    /**
     * 생일로 쓸 수 있는 회원 항목 후보를 반환합니다. (날짜 유형 + 값 테이블 저장, 꺼진 항목 포함)
     *
     * @return Collection<int, FieldDefinition> 후보 항목
     */
    public function candidates(): Collection
    {
        return $this->fields->forSet(self::FIELD_SET)
            ->filter(fn (FieldDefinition $definition) => $definition->type === 'date' && $definition->storage === 'value')
            ->values();
    }

    /**
     * 화면 select 용 후보 목록을 반환합니다.
     *
     * @return array<int, array{value: string, label: string, enabled: bool}> 후보 (꺼진 항목은 이름 뒤에 「(꺼짐)」)
     */
    public function candidateOptions(): array
    {
        return $this->candidates()->map(function (FieldDefinition $definition) {
            $name = LocalizedValue::pick($definition->name) ?? $definition->key;
            $enabled = (bool) $definition->enabled;

            return [
                'value' => (string) $definition->key,
                'label' => $enabled ? $name : $name.' '.__('sirsoft-ecommerce::messages.settings.promotion.field_disabled_suffix'),
                'enabled' => $enabled,
            ];
        })->values()->all();
    }

    /**
     * 키가 후보 안에 있는지 확인합니다.
     *
     * @param  string  $key  항목 키
     * @return bool 후보면 true
     */
    public function isCandidate(string $key): bool
    {
        return $this->candidates()->contains(fn (FieldDefinition $definition) => $definition->key === $key);
    }

    /**
     * 설정에 지정된 생일 항목 정의를 반환합니다. 지정하지 않았거나 후보가 아니면 null 입니다.
     *
     * @return FieldDefinition|null 항목 정의
     */
    public function resolveDefinition(): ?FieldDefinition
    {
        $key = module_setting('sirsoft-ecommerce', self::SETTING_KEY);
        if (! is_string($key) || $key === '') {
            return null;
        }

        return $this->candidates()->first(fn (FieldDefinition $definition) => $definition->key === $key);
    }

    /**
     * 생일 항목이 쓸 수 있게 지정되어 있는지 확인합니다.
     *
     * @return bool 지정되어 있고 후보 조건을 만족하면 true
     */
    public function isConfigured(): bool
    {
        return $this->resolveDefinition()?->id !== null;
    }
}
