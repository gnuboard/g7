<?php

namespace Plugins\Sirsoft\Totp\Repositories\Contracts;

use Plugins\Sirsoft\Totp\Models\TotpCredential;

/**
 * 인증 앱 자격증명 저장소 계약.
 *
 * Service·Listener·Provider 는 모델을 직접 만지지 않고 이 계약만 쓴다 — 저장 경로를
 * 한 곳에 모아야 "어디서 시크릿이 쓰이는가" 를 한 파일로 답할 수 있다.
 *
 * @since 1.0.0
 */
interface TotpCredentialRepositoryInterface
{
    /**
     * 사용자의 자격증명을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return TotpCredential|null 자격증명, 없으면 null
     */
    public function findByUserId(int $userId): ?TotpCredential;

    /**
     * 사용자에게 확정된 자격증명이 있는지 확인합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return bool 확정 자격증명 보유 여부
     */
    public function hasConfirmed(int $userId): bool;

    /**
     * 사용자의 확정된 자격증명을 조회합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return TotpCredential|null 확정 자격증명, 없으면 null
     */
    public function findConfirmedByUserId(int $userId): ?TotpCredential;

    /**
     * 사용자의 자격증명을 새 시크릿으로 발급(또는 재발급)합니다.
     *
     * @param  int  $userId  사용자 ID
     * @param  string  $secret  Base32 시크릿
     * @return TotpCredential 저장된 자격증명
     */
    public function issue(int $userId, string $secret): TotpCredential;

    /**
     * 자격증명의 속성을 갱신합니다.
     *
     * @param  TotpCredential  $credential  대상 자격증명
     * @param  array<string, mixed>  $attributes  갱신할 속성
     * @return TotpCredential 갱신된 자격증명
     */
    public function update(TotpCredential $credential, array $attributes): TotpCredential;

    /**
     * 사용자의 자격증명을 삭제합니다.
     *
     * @param  int  $userId  사용자 ID
     * @return int 삭제된 행 수
     */
    public function deleteByUserId(int $userId): int;

    /**
     * 확정 자격증명의 마지막 사용 timestep 을 「더 클 때만」 올립니다.
     *
     * 같은 코드를 동시에 낸 두 요청 중 한쪽만 true 를 받는다(KVE-2026-3193).
     *
     * @param  int  $credentialId  자격증명 ID
     * @param  int  $step  이번에 맞은 timestep
     * @return bool 이번 호출이 올렸으면 true
     *
     * @since 1.0.3
     */
    public function advanceTimestep(int $credentialId, int $step): bool;

    /**
     * 백업 코드 하나를 행 잠금 안에서 대조·소진합니다.
     *
     * @param  int  $credentialId  자격증명 ID
     * @param  string  $plain  입력한 백업 코드
     * @return bool 맞고 이번 호출이 소진했으면 true
     *
     * @since 1.0.3
     */
    public function consumeBackupCode(int $credentialId, string $plain): bool;

    /**
     * 미확정 자격증명을 「시크릿이 그대로일 때만」 확정합니다.
     *
     * 동시 확정·시크릿 재발급과 겹치면 false — 화면에 보인 백업 코드와 저장 코드가 어긋나지 않게 한다(KVE 동형 L3).
     *
     * @param  TotpCredential  $credential  확정할 때 읽은 자격증명
     * @param  int  $step  확정에 쓴 timestep
     * @param  array<int, string>  $hashes  저장할 백업 코드 해시
     * @return bool 이번 호출이 확정했으면 true
     *
     * @since 1.0.3
     */
    public function confirmIfPending(TotpCredential $credential, int $step, array $hashes): bool;

    /**
     * 확정 자격증명의 백업 코드를 통째로 바꿉니다.
     *
     * @param  int  $credentialId  자격증명 ID
     * @param  array<int, string>  $hashes  새 백업 코드 해시
     * @return bool 행이 있어 바꿨으면 true
     *
     * @since 1.0.3
     */
    public function replaceBackupCodes(int $credentialId, array $hashes): bool;
}
