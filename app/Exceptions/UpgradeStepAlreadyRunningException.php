<?php

namespace App\Exceptions;

use App\Extension\Upgrade\UpgradeStepRunLedger;
use RuntimeException;

/**
 * 같은 업그레이드 스텝을 다른 실행(같은 DB 를 쓰는 다른 서버 등)이 선점하고 있을 때 던집니다.
 *
 * 콘솔 업데이트와 웹 업데이트 경로가 함께 던지므로 메시지는 콘솔 전용 문구가 아닌 예외 문구를 쓴다.
 *
 * @since 7.2.0
 */
class UpgradeStepAlreadyRunningException extends RuntimeException
{
    /** 메시지 키 */
    public const MESSAGE_KEY = 'exceptions.upgrade_step_already_running';

    /**
     * @param  string  $scope  범위 (core|module|plugin|template)
     * @param  string  $identifier  확장 식별자 (코어는 빈 문자열)
     * @param  string  $version  스텝 판 번호
     * @param  string  $step  스텝 식별
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $identifier,
        public readonly string $version,
        public readonly string $step,
    ) {
        parent::__construct((string) __(self::MESSAGE_KEY, $this->getMessageParams()));
    }

    /**
     * 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array{target: string, version: string, step: string, hours: int} 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return [
            'target' => trim($this->scope.' '.$this->identifier),
            'version' => $this->version,
            'step' => $this->step,
            'hours' => UpgradeStepRunLedger::STALE_RUNNING_HOURS,
        ];
    }
}
