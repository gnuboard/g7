<?php

namespace App\Exceptions;

use App\Models\LanguagePack;
use RuntimeException;

/**
 * 같은 슬롯(scope + target_identifier + locale)에 이미 활성 언어팩이 있을 때
 * `force=false` 로 활성화를 시도하면 발생합니다. 컨트롤러는 이 예외를 잡아
 * 409 응답으로 변환하고, 프론트엔드는 사용자에게 교체 확인 모달을 띄웁니다.
 */
class LanguagePackSlotConflictException extends RuntimeException
{
    public function __construct(
        public readonly LanguagePack $current,
        public readonly LanguagePack $target,
    ) {
        parent::__construct(__('language_packs.errors.slot_conflict', [
            'current' => $current->identifier,
            'target' => $target->identifier,
        ]));
    }
}
