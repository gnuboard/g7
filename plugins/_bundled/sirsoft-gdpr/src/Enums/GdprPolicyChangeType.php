<?php

namespace Plugins\Sirsoft\Gdpr\Enums;

/**
 * GDPR 정책 버전 변경 종류
 *
 * - material: 카테고리 추가/삭제/key 변경, 카테고리 description 변경, privacy_policy_slug 변경
 *             → 모든 회원 재동의 트리거 (GDPR Art.6 — 새 처리에 옛 동의 사용 금지)
 * - non_material: 도메인 추가/삭제, label 변경, hint 텍스트 정정 등
 *                 → 재동의 트리거 안 함 (같은 목적 내 부수 정비)
 * - initial: 최초 발행 (마이그레이션 시드)
 */
enum GdprPolicyChangeType: string
{
    case Material = 'material';
    case NonMaterial = 'non_material';
    case Initial = 'initial';

    /**
     * 사용자 친화 라벨을 반환합니다.
     *
     * @return string lang 키 (locale 자동 반영)
     */
    public function label(): string
    {
        return match ($this) {
            self::Material => __('sirsoft-gdpr::messages.settings.policy_version.change_type.material'),
            self::NonMaterial => __('sirsoft-gdpr::messages.settings.policy_version.change_type.non_material'),
            self::Initial => __('sirsoft-gdpr::messages.settings.policy_version.change_type.initial'),
        };
    }

    /**
     * 재동의 트리거 여부.
     *
     * @return bool material/initial 시 true, non_material 시 false
     */
    public function triggersReconsent(): bool
    {
        return $this === self::Material || $this === self::Initial;
    }

    /**
     * 모든 케이스의 string 값 목록.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
