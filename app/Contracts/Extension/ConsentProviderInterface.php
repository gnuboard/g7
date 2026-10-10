<?php

namespace App\Contracts\Extension;

use App\Extension\Consent\ConsentManager;
use App\Extension\Consent\ConsentSnapshot;
use App\Extension\Consent\ConsentSubject;
use Illuminate\Http\Request;

/**
 * 동의 제공자 계약
 *
 * 방문자의 쿠키·추적 동의를 관리하는 확장(예: 쿠키 동의 배너 플러그인)이 구현합니다. 코어는 어느
 * 확장이 동의를 관리하는지 모르고, 필터 훅 `core.consent.registered_providers` 로 등록된 구현체를
 * {@see ConsentManager} 가 모아 운영자가 고른 하나에게 묻습니다.
 *
 * 판단은 두 단계입니다. 요청이 살아 있을 때 `resolveSubjectKey()` 로 게스트 식별자를 구해 두고,
 * 나중에(요청이 없는 큐 처리 등) `getSnapshot()` 으로 그 주체의 동의를 묻습니다. 방문자 통계처럼
 * 요청을 DTO 로 바꾼 뒤 판정하는 소비자가 있기 때문입니다.
 *
 * 판단할 수 없으면 예외를 던지거나 `ConsentSnapshot::unknown()` 을 돌려줍니다 — 매니저는 둘 다
 * 「모름」으로 다루고, 소비자는 모름을 동의하지 않은 것으로 봅니다(fail-closed).
 *
 * @since 7.1.0
 */
interface ConsentProviderInterface
{
    /**
     * 제공자 식별자를 반환합니다. 운영자 선택값(`privacy.consent_provider`)에 저장됩니다.
     *
     * @return string 제공자 식별자 (보통 확장 식별자)
     */
    public function getId(): string;

    /**
     * 관리자 화면에 보일 제공자 이름을 반환합니다 (현재 로케일).
     *
     * @return string 표시 이름
     */
    public function getLabel(): string;

    /**
     * 지금 동의를 모으고 있는지 반환합니다.
     *
     * 확장이 활성이어도 운영자가 동의 수집을 꺼 두었으면 false 입니다. false 인 제공자는 자동 선택
     * 후보에서 빠지고, 운영자가 명시로 고른 경우에는 「동의 관리 없음」(`none`)으로 다뤄집니다.
     *
     * @return bool 동의 수집 여부
     */
    public function isAvailable(): bool;

    /**
     * 제공자가 다루는 동의 카테고리를 반환합니다.
     *
     * 표준 어휘는 `necessary`, `functional`, `analytics`, `marketing` 입니다. 제공자는 카테고리를
     * 더 낼 수 있습니다. `necessary` 는 언제나 동의된 것으로 취급됩니다.
     *
     * @return array<int, array{key: string, label: string, required: bool}> 카테고리 목록
     */
    public function getCategories(): array;

    /**
     * 요청에서 게스트 동의를 찾을 식별자를 구합니다.
     *
     * 회원 요청이면 null 을 돌려도 됩니다(회원은 회원 번호로 찾습니다). 위조할 수 있는 값은
     * 검증한 뒤에만 돌려줍니다 — 남의 동의를 빌릴 수 없어야 합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string|null 게스트 동의 식별자 (없으면 null)
     */
    public function resolveSubjectKey(Request $request): ?string;

    /**
     * 주체의 현재 동의 스냅샷을 반환합니다.
     *
     * @param  ConsentSubject  $subject  동의 주체 (회원 ID 또는 게스트 식별자)
     * @return ConsentSnapshot 동의 스냅샷
     */
    public function getSnapshot(ConsentSubject $subject): ConsentSnapshot;
}
