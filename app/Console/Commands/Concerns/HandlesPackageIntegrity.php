<?php

namespace App\Console\Commands\Concerns;

use App\Exceptions\PackageIntegrityException;
use App\Extension\Integrity\PackageIntegrityOptions;
use App\Extension\Integrity\PackageSignatureFormat;
use App\Extension\Integrity\PackageVerificationStatus;

/**
 * 설치·업데이트 Artisan 명령의 패키지 무결성 처리 (7.2.0).
 *
 * 명령 서명에 `{--allow-unsigned}` 와 `{--signature=}` 를 두고 이 트레이트를 쓴다(확인 질문은 `HasUnifiedConfirm`).
 *
 * - 미서명(검증 불가 포함): 대화형이면 묻고, 답할 수 없는 실행(cron·CI·`--no-interaction`)이면 `--allow-unsigned` 없이는
 *   사유와 안내를 남기고 실패(exit 1)로 끝난다. `--force`·`--yes` 는 이 확인을 대신하지 않는다.
 * - 환경설정 「미서명 패키지 차단」이 켜져 있으면 `--allow-unsigned` 로도 진행하지 않는다.
 * - 서명 불일치·미신뢰 키는 늘 실패다.
 * - `--signature=` 를 비우면 ZIP 옆 `{zip}.sig.json` 을 자동으로 쓴다.
 */
trait HandlesPackageIntegrity
{
    /**
     * 입력 옵션으로 무결성 선택을 만듭니다.
     *
     * @return PackageIntegrityOptions|null 무결성 선택 (`--signature` 파일이 없으면 오류를 출력하고 null)
     */
    protected function packageIntegrityOptionsFromInput(): ?PackageIntegrityOptions
    {
        $signature = $this->hasOption('signature') ? $this->option('signature') : null;
        $signaturePath = null;

        if (is_string($signature) && $signature !== '') {
            $resolved = realpath($signature);
            if ($resolved === false || ! is_file($resolved)) {
                $this->error('❌ '.__('extension_integrity.commands.signature_not_found', ['path' => $signature]));

                return null;
            }
            $signaturePath = $resolved;
        }

        return new PackageIntegrityOptions(
            allowUnsigned: $this->hasOption('allow-unsigned') && (bool) $this->option('allow-unsigned'),
            signaturePath: $signaturePath,
        );
    }

    /**
     * 작업을 실행하고, 미서명으로 멈추면 운영자 확인을 받아 한 번 더 실행합니다.
     *
     * 작업(`$run`)은 무결성 거절을 아무것도 바꾸기 전에 던져야 한다(설치·업데이트 매니저는 백업 전에 검증한다).
     * 거절 문장 자체는 출력하지 않는다 — 다시 던진 예외를 받는 명령의 실패 처리가 낸다. 여기서는 안내 줄만 더한다.
     * 다시 던지는 예외는 명령줄 문장(`PackageIntegrityException::forConsole()`)이다 — 화면 응답 문장의 「미서명으로 계속」
     * 단추 안내가 명령줄 출력에 섞이지 않게 한다.
     *
     * @template T
     *
     * @param  \Closure(PackageIntegrityOptions): T  $run  실행할 작업
     * @param  PackageIntegrityOptions  $options  처음 선택
     * @return T 작업 결과
     *
     * @throws PackageIntegrityException 계속할 수 없거나 운영자가 거절했을 때 (안내를 출력한 뒤)
     */
    protected function runWithIntegrityConsent(\Closure $run, PackageIntegrityOptions $options): mixed
    {
        try {
            return $run($options);
        } catch (PackageIntegrityException $e) {
            if (! $e->canContinue || $options->allowUnsigned) {
                if ($e->blockedByPolicy) {
                    $this->line(__('extension_integrity.commands.unsigned_blocked_hint'));
                }
                throw $e->forConsole();
            }

            // `--yes` 는 진행 확인에만 「예」로 답한다 — 미서명 동의는 `--allow-unsigned` 하나로만 한다.
            // 그래서 `canAnswerConfirmation()`·`unifiedConfirm()`(둘 다 `--yes` 를 「답함」으로 봄) 대신 질문을 실제로 띄울 수
            // 있는지(`canShowPrompt()`)로 가르고 `optionalConfirm()` 으로 묻는다. 종전에는 `-y` 만 준 비대화형 실행이 미서명
            // 패키지를 묻지도 않고 받아들였다.
            if (! $this->canShowPrompt()) {
                $this->line(__('extension_integrity.commands.unsigned_noninteractive_hint'));
                throw $e->forConsole();
            }

            if (! $this->optionalConfirm(__('extension_integrity.commands.unsigned_confirm', ['reason' => (string) $e->verification->reasonMessage()]), false)) {
                throw $e->forConsole();
            }

            return $run($options->allowingUnsigned());
        }
    }

    /**
     * 받은 패키지의 검증 결과 한 줄을 출력합니다.
     *
     * @param  array<string, mixed>|null  $integrity  `PackageVerification::toArray()` 결과 (없으면 출력 안 함)
     * @return void 반환값 없음
     */
    protected function renderPackageIntegrity(?array $integrity): void
    {
        if (! is_array($integrity) || ! is_string($integrity['status'] ?? null)) {
            return;
        }

        if ($integrity['status'] === PackageVerificationStatus::Verified->value) {
            $this->info('🔏 '.__('extension_integrity.commands.verified_line', [
                'label' => (string) ($integrity['signer_label'] ?? '-'),
                'key' => (string) ($integrity['key_id'] ?? '-'),
            ]));

            return;
        }

        $this->line(__('extension_integrity.commands.status_line', [
            'status' => __('extension_integrity.statuses.'.$integrity['status']),
        ]));
    }

    /**
     * key_id(`{label}:{hex}`) 에서 서명자 이름을 뗍니다.
     *
     * @param  string|null  $keyId  key_id
     * @return string|null 서명자 이름 (없으면 null)
     */
    protected function signerLabelOf(?string $keyId): ?string
    {
        if ($keyId === null || ! str_contains($keyId, ':')) {
            return null;
        }

        return strstr($keyId, ':', true) ?: null;
    }

    /**
     * 계속할 수 없는 무결성 거절을 출력합니다 (차단 스위치·불일치·미신뢰).
     *
     * @param  PackageIntegrityException  $e  거절 예외
     * @return void 반환값 없음
     */
    protected function reportPackageIntegrityRefusal(PackageIntegrityException $e): void
    {
        $this->error('❌ '.$e->forConsole()->getMessage());

        if ($e->blockedByPolicy) {
            $this->line(__('extension_integrity.commands.unsigned_blocked_hint'));
        }
    }

    /**
     * ZIP 옆 서명 파일 경로를 돌려줍니다 (안내용).
     *
     * @param  string  $zipPath  ZIP 경로
     * @return string 서명 파일 경로
     */
    protected function sidecarSignaturePath(string $zipPath): string
    {
        return $zipPath.PackageSignatureFormat::FILE_SUFFIX;
    }
}
