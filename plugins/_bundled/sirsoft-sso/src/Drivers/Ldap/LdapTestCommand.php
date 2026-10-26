<?php

namespace Plugins\Sirsoft\Sso\Drivers\Ldap;

use Illuminate\Console\Command;
use Plugins\Sirsoft\Sso\Repositories\Contracts\IdentityProviderRepositoryInterface;

/**
 * LDAP IdP 연결 시험과 「계정으로 시험」을 터미널에서 합니다 (운영자 진단 — 관리자 화면 진단 카드와 같은 결과).
 *
 * 계정 비밀번호는 옵션으로 받지 않는다(셸 기록에 남는다) — 환경변수 이름을 받아 그 값을 읽는다. 받은 신원은 가린 값만 출력한다.
 *
 * @since 1.0.0
 */
class LdapTestCommand extends Command
{
    /** @var string 명령 시그니처 */
    protected $signature = 'sirsoft-sso:ldap-test
        {idp : LDAP IdP 키}
        {--user= : 「계정으로 시험」할 아이디 (이메일 또는 사내 아이디)}
        {--password-env= : 그 계정 비밀번호가 든 환경변수 이름}';

    /** @var string 명령 설명 */
    protected $description = 'LDAP/AD IdP 연결 시험 (연결, TLS, 서비스 바인드, 기준 DN, 선택 시 계정 인증)';

    /**
     * 명령을 실행합니다.
     *
     * @param  IdentityProviderRepositoryInterface  $idps  IdP 저장소
     * @return int 종료 코드 (점검 하나라도 실패면 1)
     */
    public function handle(IdentityProviderRepositoryInterface $idps): int
    {
        $idp = $idps->findByKey((string) $this->argument('idp'));
        if ($idp === null || $idp->protocol !== LdapDriver::KEY) {
            $this->error(__('sirsoft-sso::ldap.command.not_ldap'));

            return self::FAILURE;
        }

        /** @var LdapDriver $driver */
        $driver = $idp->driver();
        $result = $driver->testConnection($idp);

        $user = $this->option('user');
        if (is_string($user) && $user !== '') {
            $env = (string) $this->option('password-env');
            $password = $env !== '' ? getenv($env) : false;
            if (! is_string($password) || $password === '') {
                $this->error(__('sirsoft-sso::ldap.command.password_env_missing'));

                return self::FAILURE;
            }

            $account = $driver->testAccount($idp, $user, $password);
            $checks = array_merge($result->checks, $account->checks);
        } else {
            $checks = $result->checks;
        }

        $ok = true;
        foreach ($checks as $check) {
            $ok = $ok && $check['ok'];
            $this->line(($check['ok'] ? '[OK] ' : '[NG] ').$check['key'].' — '.__($check['message_key']).($check['detail'] !== null ? ' ('.$check['detail'].')' : ''));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
