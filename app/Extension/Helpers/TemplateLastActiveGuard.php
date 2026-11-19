<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Template;

/**
 * 「유형(관리자·사용자)의 마지막 활성 템플릿」 판정 — 관리자 화면 경로(TemplateService)와 CLI 제거 명령이 같은 판정을 쓴다.
 *
 * 유형마다 활성 템플릿은 하나이고, 그것이 없으면 그 화면(관리자 또는 사이트)이 통째로 그려지지 않는다. 비활성 템플릿이나
 * 같은 유형에 다른 활성 템플릿이 남는 경우는 해당하지 않는다.
 *
 * 매니저 메서드에는 넣지 않는다 — 코어 비호환 자동 비활성화(IncompatibleExtensionDeactivator)가 같은 비활성화 메서드를
 * 써서 마지막 활성 템플릿도 꺼야 하기 때문이다.
 */
final class TemplateLastActiveGuard
{
    /**
     * 템플릿이 그 유형의 마지막 활성 템플릿인지 판정합니다.
     *
     * @param  Template  $template  대상 템플릿 레코드
     * @param  TemplateRepositoryInterface  $repository  템플릿 저장소
     * @return bool 마지막 활성 템플릿이면 true
     */
    public static function isLastActiveOfType(Template $template, TemplateRepositoryInterface $repository): bool
    {
        if ($template->status !== ExtensionStatus::Active->value) {
            return false;
        }

        return ! $repository->getActiveByType((string) $template->type)
            ->contains(fn (Template $active) => $active->identifier !== $template->identifier);
    }

    /**
     * 거절 문구를 만듭니다 (`:type` 에 유형 이름).
     *
     * @param  Template  $template  대상 템플릿 레코드
     * @param  string  $messageKey  번역 키 (`templates.errors.last_active_uninstall` 등)
     * @return string 거절 문구
     */
    public static function message(Template $template, string $messageKey): string
    {
        return __($messageKey, ['type' => __('templates.types.'.$template->type)]);
    }
}
