<?php

namespace App\Support;

use App\Extension\CoreVersionChecker;

/**
 * 코어 업데이트 트리 안에서 새 코드가 띄우는 자식 프로세스의 env 를 조립합니다.
 *
 * 자식(번들 일괄 업데이트 `core:execute-bundled-updates`, 그 안의 언어팩 단계 새 프로세스, 훅 캐시 새 프로세스)은
 * 부팅하면서 config/hooks 캐시를 다시 굽는다. 상속 env 를 그대로 넘기면 부모 env 의 APP_VERSION 이 그대로 캐시에
 * 구워지므로(Laravel env 저장소는 immutable 이라 `.env` 가 덮지 못한다), 업그레이드 스텝 자식
 * (`CoreUpdateCommand::spawnUpgradeStepsProcess()`)과 같이 현재 코어 버전과 업데이트 플래그를 명시한다(#145 F10).
 *
 * 업데이트 트리 밖(확장 롤백의 훅 캐시 복구 등)에서는 상속 env 를 그대로 넘긴다. 그때 고정할 수 있는 값은 config 뿐이라,
 * config 캐시가 오염돼 있으면 그 값을 자식에게 퍼뜨릴 뿐이다.
 */
final class UpdateChildEnv
{
    /**
     * 자식 프로세스에 넘길 env 배열을 만듭니다.
     *
     * `getenv()` 는 putenv 로 세운 값까지 담은 프로세스 env 테이블이고, `$_ENV` 로 보완한다(`variables_order` 에 E 가
     * 없으면 `$_ENV` 가 비어 있을 수 있다).
     *
     * @return array<string, mixed> proc_open 에 넘길 env
     */
    public static function build(): array
    {
        $env = array_merge(getenv(), $_ENV);

        if (CoreUpdateContext::isInProgress()) {
            $env['APP_VERSION'] = CoreVersionChecker::getCoreVersion();
            $env['G7_UPDATE_IN_PROGRESS'] = '1';
        }

        return $env;
    }
}
