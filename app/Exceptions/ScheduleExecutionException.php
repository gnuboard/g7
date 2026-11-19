<?php

namespace App\Exceptions;

use App\Rules\AllowedArtisanCommand;
use Exception;

/**
 * 스케줄 실행 실패 예외
 *
 * 저장된 스케줄을 실행하는 시점에 차단 목록에 걸리거나(artisan/shell 화이트리스트, 내부망 URL),
 * 실행 자체가 실패했을 때 발생합니다. `ScheduleExecutor` 가 이 예외를 잡아 실행 이력에 기록합니다.
 * 소유 확장 비활성·수동 실행 중복은 실행 전에 던져 호출자(수동 실행 API)가 사유를 보여 준다.
 */
class ScheduleExecutionException extends Exception
{
    /**
     * 거부 사유 코드 (`ScheduleCommandValidator::ARTISAN_REASON_*`).
     *
     * 관리자에게 보이는 메시지는 사유와 무관하게 동일하지만, 운영 진단 로그에는
     * 어느 규칙에 걸렸는지가 남아야 한다 — 업그레이드 점검 스텝을 두지 않기로 했으므로
     * 실행 이력과 로그가 유일한 통로다.
     */
    public ?string $reasonCode = null;

    /**
     * 허용되지 않은 Artisan 명령
     *
     * @param  string|null  $reasonCode  거부 사유 코드 (`ScheduleCommandValidator::ARTISAN_REASON_*`)
     * @return self 생성된 예외
     */
    public static function artisanNotAllowed(?string $reasonCode = null): self
    {
        // 사유를 알면 저장 검증과 같은 사유별 문구(금지 명령만 「보안상」), 모르면 기존 일반 문구.
        $exception = new self(__($reasonCode === null
            ? 'schedule.artisan_not_allowed'
            : AllowedArtisanCommand::messageKey($reasonCode)));
        $exception->reasonCode = $reasonCode;

        return $exception;
    }

    /**
     * 허용되지 않은 쉘 명령
     *
     * @return self 생성된 예외
     */
    public static function shellNotAllowed(): self
    {
        return new self(__('schedule.shell_not_allowed'));
    }

    /**
     * 쉘 명령 실행 실패
     *
     * @param  string  $errorOutput  프로세스 표준 에러 출력
     * @param  int  $exitCode  프로세스 종료 코드
     * @return self 생성된 예외
     */
    public static function shellCommandFailed(string $errorOutput, int $exitCode): self
    {
        return new self($errorOutput !== '' ? $errorOutput : __('schedule.shell_command_failed'), $exitCode);
    }

    /**
     * 공개되지 않은(내부망) URL 호출 차단
     *
     * @return self 생성된 예외
     */
    public static function urlNotPublic(): self
    {
        return new self(__('schedule.url_not_public'));
    }

    /**
     * HTTP 요청 실패
     *
     * @param  int  $status  응답 상태 코드
     * @return self 생성된 예외
     */
    public static function httpRequestFailed(int $status): self
    {
        return new self(__('schedule.http_request_failed', ['status' => $status]), $status);
    }

    /**
     * 소유 확장이 비활성이라 실행 거부
     *
     * @return self 생성된 예외 (reasonCode = owner_inactive)
     */
    public static function ownerInactive(): self
    {
        $exception = new self(__('schedule.owner_inactive'));
        $exception->reasonCode = 'owner_inactive';

        return $exception;
    }

    /**
     * 앞선 실행이 중복 방지 잠금을 잡고 있어 수동 실행 거부
     *
     * @return self 생성된 예외 (reasonCode = already_running)
     */
    public static function alreadyRunning(): self
    {
        $exception = new self(__('schedule.already_running'));
        $exception->reasonCode = 'already_running';

        return $exception;
    }

    /**
     * 허용목록에 없는 Job 클래스
     *
     * @return self 생성된 예외 (reasonCode = job_not_allowed)
     */
    public static function jobNotAllowed(): self
    {
        $exception = new self(__('schedule.job_not_allowed'));
        $exception->reasonCode = 'job_not_allowed';

        return $exception;
    }

    /**
     * 허용목록에는 있지만 존재하지 않는 Job 클래스
     *
     * @param  string  $class  Job 클래스명
     * @return self 생성된 예외
     */
    public static function jobNotFound(string $class): self
    {
        return new self(__('schedule.job_not_found', ['class' => $class]));
    }

    /**
     * Artisan 명령이 0 이 아닌 종료 코드로 끝남
     *
     * @param  int  $exitCode  종료 코드
     * @param  string  $output  명령 출력 (있으면 메시지로 쓴다)
     * @return self 생성된 예외
     */
    public static function artisanCommandFailed(int $exitCode, string $output = ''): self
    {
        $message = trim($output) !== ''
            ? trim($output)
            : __('schedule.artisan_command_failed', ['code' => $exitCode]);

        return new self($message, $exitCode);
    }
}
