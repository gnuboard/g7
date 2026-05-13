<?php

namespace App\Contracts\Extension;

use App\Extension\IdentityVerification\DTO\VerificationChallenge;
use App\Extension\IdentityVerification\DTO\VerificationResult;
use App\Models\User;

/**
 * 본인인증 프로바이더 인터페이스 (IdentityVerification / IDV)
 *
 * 이 계약을 구현하면 메일, KCP, 이니시스, SMS 등 어떤 본인인증 경로도
 * 동일한 방식으로 코어 Manager 에 붙일 수 있습니다. IDV 가 적용되는 목적은
 * purpose (signup / password_reset / self_update / sensitive_action / 플러그인 등록값) 로 구분합니다.
 */
interface IdentityVerificationInterface
{
    /**
     * 프로바이더 식별자.
     *
     * 코어 기본 프로바이더는 `g7:core.*` 접두사를 사용합니다 (예: `g7:core.mail`).
     * 플러그인 프로바이더는 자신의 벤더-slug 식별자를 사용합니다 (예: `kcp`, `inicis`).
     */
    public function getId(): string;

    /**
     * 관리자 UI 에 표시되는 라벨.
     */
    public function getLabel(): string;

    /**
     * 지원 채널 목록 (예: ['email', 'sms', 'ipin']).
     *
     * @return array<int, string>
     */
    public function getChannels(): array;

    /**
     * 프론트가 challenge 를 렌더하는 방법 힌트.
     *
     * - text_code        : 숫자 코드 입력 UI
     * - link             : 메일/SMS 링크 클릭 유도
     * - external_redirect: 외부 인증 페이지 이동
     */
    public function getRenderHint(): string;

    /**
     * 주어진 목적을 지원하는지 여부.
     *
     * purpose 는 최소 signup / password_reset / self_update / sensitive_action 을
     * 포함하며, 플러그인은 `core.identity.purposes` 필터 훅으로 커스텀 purpose 를 추가할 수 있습니다.
     */
    public function supportsPurpose(string $purpose): bool;

    /**
     * 런타임 설정(API 키/시크릿 등) 기준으로 실제 사용 가능한지 여부.
     */
    public function isAvailable(): bool;

    /**
     * Challenge 를 발행합니다.
     *
     * @param  User|array  $target  대상 사용자(로그인 상태) 또는 이메일·전화 배열(가입 전)
     * @param  array  $context  origin_type / origin_identifier / origin_policy_key / purpose / ip / user_agent 등
     */
    public function requestChallenge(User|array $target, array $context = []): VerificationChallenge;

    /**
     * Challenge 를 검증합니다.
     *
     * @param  string  $challengeId  requestChallenge 가 반환한 id
     * @param  array  $input  프로바이더별 입력 (코드, 토큰, 외부 CB 페이로드 등)
     * @param  array  $context  origin 정보, 요청 ip/ua 등
     */
    public function verify(string $challengeId, array $input, array $context = []): VerificationResult;

    /**
     * Challenge 를 취소합니다.
     */
    public function cancel(string $challengeId): bool;

    /**
     * 관리자 환경설정 UI 가 반복 렌더하기 위한 설정 스키마.
     *
     * @return array<string, array{label: string, type: string, default?: mixed, options?: array<mixed>, help?: string}>
     */
    public function getSettingsSchema(): array;

    /**
     * 설정값을 주입한 새 인스턴스를 반환합니다. (withStore/withDisk 불변 복제 패턴 준용)
     */
    public function withConfig(array $config): static;
}
