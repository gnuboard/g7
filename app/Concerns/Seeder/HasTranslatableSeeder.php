<?php

namespace App\Concerns\Seeder;

use App\Extension\HookManager;

/**
 * TranslatableSeederInterface 구현체용 헬퍼 트레이트.
 *
 * 시더의 run() 안에서 $this->resolveTranslatedDefaults() 호출 시
 * 활성 언어팩의 ja/en 등 locale 키가 자동 머지된 entry 배열을 반환.
 *
 * @since 7.0.0-beta.5
 */
trait HasTranslatableSeeder
{
    /**
     * 활성 언어팩의 다국어 데이터로 머지된 시드 entry 를 반환합니다.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function resolveTranslatedDefaults(): array
    {
        return HookManager::applyFilters(
            $this->resolveTranslationFilterName(),
            $this->getDefaults(),
        );
    }

    /**
     * `seed.{ext}.{entity}.translations` 필터 키를 조립합니다.
     */
    protected function resolveTranslationFilterName(): string
    {
        $extension = $this->getExtensionIdentifier();
        $entity = $this->getTranslatableEntity();

        return $extension !== ''
            ? "seed.{$extension}.{$entity}.translations"
            : "seed.{$entity}.translations";
    }
}
