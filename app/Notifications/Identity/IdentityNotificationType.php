<?php

namespace App\Notifications\Identity;

use App\Enums\NotificationDefinitionCategory;

/**
 * 본인인증(IDV) 알림 정의의 type 규칙 — 단일 SSoT.
 *
 * 본인인증 메시지는 알림 정의(`notification_definitions`, category=identity)로 저장되며,
 * 옛 IDV 메시지 표의 3단 폴백(policy → purpose → provider_default)을 type 후보 3개로 표현한다.
 *
 * - 기본(폴백)  : `identity.default`
 * - 목적별      : `identity.purpose.{purpose}`
 * - 정책별      : `identity.policy.{policyKey}` — type 컬럼 길이(100자)를 넘으면
 *                 `identity.policy.~{sha1 앞 20자}` 로 줄인다(같은 키는 언제나 같은 type).
 *
 * 7.1.0 업그레이드 스텝(07)은 클래스 캐싱 제약으로 이 클래스를 쓰지 않고 같은 규칙을 인라인으로
 * 갖는다 — 규칙을 바꾸면 그 스텝의 사본도 함께 바꿔야 한다(테스트가 둘의 결과를 대조한다).
 */
final class IdentityNotificationType
{
    /** @var string 본인인증 알림 분류 값 (notification_definitions.category) */
    public const CATEGORY = NotificationDefinitionCategory::Identity->value;

    /** @var string type 접두어 */
    public const PREFIX = 'identity.';

    /** @var int notification_definitions.type 컬럼 길이 */
    public const MAX_TYPE_LENGTH = 100;

    /** @var string 옛 IDV 메시지 표의 범위 값 — 기본(폴백) */
    public const LEGACY_SCOPE_PROVIDER_DEFAULT = 'provider_default';

    /** @var string 옛 IDV 메시지 표의 범위 값 — 목적 */
    public const LEGACY_SCOPE_PURPOSE = 'purpose';

    /** @var string 옛 IDV 메시지 표의 범위 값 — 정책 */
    public const LEGACY_SCOPE_POLICY = 'policy';

    /**
     * 기본(폴백) 정의의 type 을 반환합니다.
     *
     * @return string 기본 type
     */
    public static function default(): string
    {
        return self::PREFIX.'default';
    }

    /**
     * 목적별 정의의 type 을 반환합니다.
     *
     * @param  string  $purpose  인증 목적 (signup, login 등)
     * @return string 목적별 type
     */
    public static function forPurpose(string $purpose): string
    {
        return self::PREFIX.'purpose.'.$purpose;
    }

    /**
     * 정책별 정의의 type 을 반환합니다.
     *
     * 정책 키가 길어 type 이 컬럼 길이를 넘으면 정책 키의 sha1 앞 20자로 줄인다.
     *
     * @param  string  $policyKey  정책 키
     * @return string 정책별 type
     */
    public static function forPolicy(string $policyKey): string
    {
        $type = self::PREFIX.'policy.'.$policyKey;

        if (strlen($type) <= self::MAX_TYPE_LENGTH) {
            return $type;
        }

        return self::PREFIX.'policy.~'.substr(sha1($policyKey), 0, 20);
    }

    /**
     * 정책별 type 에서 정책 키를 되찾습니다.
     *
     * 짧은 type 은 접두사를 떼면 키다. 100자를 넘어 해시로 줄인 type(`identity.policy.~…`)은 되돌릴 수 없으므로
     * 후보 키(운영자 정책 키 목록)를 {@see forPolicy()} 로 다시 만들어 맞춰 본다 — 화면이 「이미 문구가 있는 정책」 을
     * 고를 때 해시를 계산할 수 없어서 서버가 키를 실어 준다.
     *
     * @param  string  $type  알림 정의 type
     * @param  iterable<int, string>  $candidateKeys  해시 type 을 맞춰 볼 정책 키 후보
     * @return string|null 정책 키 (정책별 type 이 아니거나 해시를 맞출 후보가 없으면 null)
     */
    public static function policyKeyFromType(string $type, iterable $candidateKeys = []): ?string
    {
        $prefix = self::PREFIX.'policy.';
        if (! str_starts_with($type, $prefix)) {
            return null;
        }

        $rest = substr($type, strlen($prefix));
        if (! str_starts_with($rest, '~')) {
            return $rest;
        }

        foreach ($candidateKeys as $key) {
            if (self::forPolicy((string) $key) === $type) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * 발송 시 시도할 type 후보를 가장 구체적인 것부터 반환합니다.
     *
     * @param  string  $purpose  인증 목적
     * @param  string|null  $policyKey  challenge 를 일으킨 정책 키 (없으면 null)
     * @return array<int, string> [정책?, 목적, 기본] 순서의 type 목록
     */
    public static function candidates(string $purpose, ?string $policyKey): array
    {
        $candidates = [];

        if ($policyKey !== null && $policyKey !== '') {
            $candidates[] = self::forPolicy($policyKey);
        }

        $candidates[] = self::forPurpose($purpose);
        $candidates[] = self::default();

        return $candidates;
    }

    /**
     * 옛 IDV 메시지 표의 (scope_type, scope_value) 를 type 으로 바꿉니다.
     *
     * 7.1.0 이관 스텝과 `getIdentityMessages()` 호환층이 함께 쓴다.
     *
     * @param  string  $scopeType  provider_default | purpose | policy
     * @param  string|null  $scopeValue  목적 또는 정책 키 (provider_default 는 빈 값)
     * @return string|null 변환된 type (알 수 없는 범위면 null)
     */
    public static function fromLegacyScope(string $scopeType, ?string $scopeValue): ?string
    {
        $value = (string) $scopeValue;

        return match ($scopeType) {
            self::LEGACY_SCOPE_PROVIDER_DEFAULT => self::default(),
            self::LEGACY_SCOPE_PURPOSE => $value !== '' ? self::forPurpose($value) : null,
            self::LEGACY_SCOPE_POLICY => $value !== '' ? self::forPolicy($value) : null,
            default => null,
        };
    }

    /**
     * type 이 본인인증 알림 규칙을 따르는지 판정합니다.
     *
     * @param  string  $type  알림 type
     * @return bool 본인인증 type 여부
     */
    public static function isIdentityType(string $type): bool
    {
        return str_starts_with($type, self::PREFIX);
    }

    /**
     * 모든 본인인증 알림 정의가 공유하는 변수 메타데이터 (단일 SSoT).
     *
     * @return array<int, array{key: string, description: string}> 변수 목록
     */
    public static function commonVariables(): array
    {
        return [
            ['key' => 'code', 'description' => '인증 코드 (text_code 흐름)'],
            ['key' => 'action_url', 'description' => '검증 링크 URL (link 흐름)'],
            ['key' => 'expire_minutes', 'description' => '만료까지 남은 분'],
            ['key' => 'purpose_label', 'description' => '인증 목적 라벨 (다국어)'],
            ['key' => 'app_name', 'description' => '사이트명'],
            ['key' => 'site_url', 'description' => '사이트 URL'],
            ['key' => 'recipient_email', 'description' => '수신자 이메일'],
        ];
    }

    /**
     * 발송 로그에 평문으로 남기면 안 되는 변수 키.
     *
     * 인증번호와 검증 링크(토큰 포함)는 그 자체가 인증 수단이다 — 관리자 발송 로그 화면에
     * 평문으로 남으면 로그를 볼 수 있는 사람이 남의 인증을 대신 완료할 수 있다.
     *
     * @return array<int, string> 민감 변수 키 목록
     */
    public static function sensitiveVariables(): array
    {
        return ['code', 'action_url'];
    }
}
