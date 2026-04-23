<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 업그레이드 스텝이 자신의 실행을 중단하고, 사용자가 새 PHP 프로세스로 재실행할
 * 것을 요청할 때 던지는 예외.
 *
 * 사용 사례: 현재 실행 중인 PHP 프로세스의 opcache / autoloader 가 이전 버전의
 * 코드를 들고 있어, 디스크에 덮여진 새 버전 코드를 참조할 수 없는 경우.
 *
 * 스텝 A 실행은 성공했지만, 스텝 B 부터는 현재 프로세스에서 안전하게 실행할 수
 * 없을 때 사용한다. 이 경우 스텝 B 파일 안에서 `throw new UpgradeHandoffException(...)`
 * 을 호출하면 상위 계층(CoreUpdateService → ExecuteUpgradeStepsCommand 또는
 * CoreUpdateCommand) 이 이를 감지해 다음을 수행한다:
 *
 *   1. 스텝 A 까지의 상태는 보존 (파일 교체·migration·composer 결과)
 *   2. `.env` 의 APP_VERSION 을 `$afterVersion` 으로 갱신 (다음 `core:update`
 *      재실행 시 해당 버전에서 다시 시작)
 *   3. maintenance 모드 해제
 *   4. 사용자에게 재실행 안내 메시지 출력
 *   5. 명령어는 성공 종료 (Command::SUCCESS) — spawn 체인에서는 exit code 75
 *
 * 이 예외를 받을 수 있는 코어 레벨은 7.0.0-beta.3 이후에 도입되었다.
 * 그 이전 코어 (beta.1 / beta.2) 는 이 클래스 자체를 알지 못하므로, 구 코어에서
 * 본 예외를 in-process 로 던져도 일반 uncaught exception 취급된다. 따라서
 * beta.1 에서 beta.3 직행 같은 하위 호환성이 필요한 스텝은 throw 대신 graceful
 * skip (`method_exists` 검사 등) 을 병행해야 한다.
 *
 * @see docs/extension/upgrade-step-guide.md 섹션 "업그레이드 핸드오프"
 */
class UpgradeHandoffException extends RuntimeException
{
    /**
     * spawn 체인에서 핸드오프 신호로 사용하는 exit code (sysexits.h EX_TEMPFAIL).
     */
    public const EXIT_CODE = 75;

    /**
     * @param  string       $afterVersion  여기까지의 스텝은 완료되었다고 간주할 버전
     *                                     (다음 재실행은 이 버전 기준으로 나머지 스텝만 실행)
     * @param  string       $reason        핸드오프가 필요한 이유 (로그·사용자 메시지)
     * @param  string|null  $resumeCommand 사용자에게 안내할 재실행 명령어.
     *                                     null 이면 `CoreUpdateCommand` 가 catch 시점에
     *                                     `php artisan core:execute-upgrade-steps --from=<afterVersion> --to=<toVersion> --force`
     *                                     형식으로 자동 생성한다.
     *                                     대부분의 step 작성자는 null 기본값 사용 권장 —
     *                                     전체 `core:update` 재다운로드를 피하고 스텝만 재실행.
     */
    public function __construct(
        public readonly string $afterVersion,
        public readonly string $reason,
        public readonly ?string $resumeCommand = null,
    ) {
        parent::__construct(__('exceptions.core_update.handoff', [
            'after_version' => $afterVersion,
            'reason' => $reason,
            'resume_command' => $resumeCommand ?? '(auto)',
        ]));
    }
}
