<?php

namespace App\Support;

use App\Extension\HookManager;

/**
 * 레이아웃 첨부 허용 확장자 결정
 *
 * 레이아웃 편집기에서 올릴 수 있는 파일 형식을 한 곳에서 정한다. 업로드 요청 검증(이름 확장자 + 내용 판정)이
 * 이 목록을 쓴다.
 *
 * 우선순위는 「템플릿 설정 > 코어 설정 > 기본값」 이다.
 *
 * 1. 기본값 — `DEFAULT_EXTENSIONS`
 * 2. 코어 설정 — 환경설정 > 업로드 「레이아웃 첨부 허용 확장자」(`attachment.layout_attachment_extensions`).
 *    비어 있으면 기본값을 쓴다.
 * 3. 템플릿 설정 — 필터 훅 `core.template_layout_attachment.filter_allowed_extensions`
 *    `($extensions, $templateIdentifier)`. 템플릿별 설정을 가진 쪽이 이 훅에서 그 템플릿의 목록을 돌려준다.
 *    훅이 빈 목록을 돌려주면 코어 값으로 되돌린다(업로드 전면 차단 방지).
 *
 * 템플릿별 값은 코어 설정을 덮어쓰지 않고 이 훅으로만 끼어든다.
 *
 * @since 7.1.6
 */
final class TemplateLayoutAttachmentPolicy
{
    /**
     * 기본 허용 확장자
     *
     * @var array<int, string>
     */
    public const DEFAULT_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * 템플릿별 허용 확장자를 정하는 필터 훅 이름
     */
    public const FILTER_HOOK = 'core.template_layout_attachment.filter_allowed_extensions';

    /**
     * 주어진 템플릿의 레이아웃 첨부 허용 확장자를 반환합니다.
     *
     * @param  string  $templateIdentifier  템플릿 식별자 (vendor-name 형식)
     * @return array<int, string> 허용 확장자 목록 (소문자, 비어 있지 않음)
     */
    public static function allowedExtensions(string $templateIdentifier): array
    {
        $core = self::coreExtensions();

        $filtered = AllowedExtensions::normalize(
            HookManager::applyFilters(self::FILTER_HOOK, $core, $templateIdentifier)
        );

        return $filtered === [] ? $core : $filtered;
    }

    /**
     * 코어 설정값(비어 있으면 기본값)을 반환합니다.
     *
     * @return array<int, string> 허용 확장자 목록 (소문자, 비어 있지 않음)
     */
    public static function coreExtensions(): array
    {
        $configured = AllowedExtensions::normalize(config('attachment.layout_attachment_extensions'));

        return $configured === [] ? self::DEFAULT_EXTENSIONS : $configured;
    }
}
