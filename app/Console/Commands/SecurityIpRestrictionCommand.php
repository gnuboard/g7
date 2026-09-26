<?php

namespace App\Console\Commands;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Enums\ActivityLogType;
use App\Services\SettingsService;
use App\Support\IpAccessMatcher;
use App\Support\TrustedProxyDiagnostic;
use Illuminate\Console\Command;

/**
 * IP 접근 제한 상태 점검·비상 해제 커맨드.
 *
 * 허용 목록을 잘못 저장해 관리자 화면에 들어갈 수 없게 되면 화면으로는 되돌릴 수 없다 — 그
 * 상태에서 쓰는 유일한 탈출구다. 서버 셸 접근 자체가 권한이므로 추가 확인을 요구하지 않는다
 * (`totp:reset` 과 같은 결). 해제는 마스터 토글만 끄고 목록·규칙은 남긴다 — 무엇이 잘못됐는지
 * 운영자가 화면에서 보고 고친 뒤 다시 켜야 하기 때문이다.
 *
 * 저장은 환경설정 화면과 같은 `SettingsService::setSetting()` 을 거친다. 설정 파일만 고치면
 * 상주 프로세스의 설정 미러와 config 캐시가 옛 값을 계속 읽는다.
 *
 * @since 7.1.0
 */
class SecurityIpRestrictionCommand extends Command
{
    use ResolvesActivityLogType;

    /**
     * 커맨드 시그니처.
     *
     * @var string
     */
    protected $signature = 'security:ip-restriction
        {--status : 상태만 보고 아무것도 바꾸지 않습니다 (옵션을 주지 않아도 상태는 표시합니다)}
        {--disable : IP 접근 제한을 끕니다 (관리자 잠금 구제)}';

    /**
     * 커맨드 설명.
     *
     * @var string
     */
    protected $description = 'IP 접근 제한 설정을 점검하거나 비상 해제합니다 (관리자 잠금 구제)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  ConfigRepositoryInterface  $configRepository  설정 저장소 (JSON 직접 조회)
     * @param  SettingsService  $settingsService  설정 서비스 (저장·미러 재채움)
     * @return int 종료 코드
     */
    public function handle(ConfigRepositoryInterface $configRepository, SettingsService $settingsService): int
    {
        // --status 는 "보기만 한다" 는 뜻이다 — 같이 주면 무엇을 의도했는지 알 수 없으므로 끄지 않고 멈춘다.
        if ($this->option('status') && $this->option('disable')) {
            $this->error('--status 와 --disable 은 함께 쓸 수 없습니다 — 상태만 보려면 --status, 끄려면 --disable 만 주세요.');

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            if (! IpAccessMatcher::isEnabled($configRepository->getCategory('security'))) {
                $this->info('IP 접근 제한은 이미 꺼져 있습니다.');
            } elseif (! $this->turnOff($settingsService)) {
                return self::FAILURE;
            } else {
                $this->logActivity('security.ip_restriction_disabled', [
                    'log_type' => ActivityLogType::System,
                    'description_key' => 'activity_log.description.security_ip_restriction_disabled',
                    'properties' => ['via' => 'console'],
                ]);

                $this->info('IP 접근 제한을 껐습니다.');
                $this->line('허용 목록과 규칙은 지우지 않았습니다 — 환경설정 > 보안 > 접근 제한 에서 목록을 고친 뒤 다시 켜세요.');
            }

            $this->line('');
        }

        $this->renderStatus($configRepository->getCategory('security'));

        return self::SUCCESS;
    }

    /**
     * 마스터 토글만 끕니다.
     *
     * 저장 실패를 예외로 흘려보내지 않는다 — 이 커맨드를 실행하는 사람은 이미 화면에서 잠긴
     * 상태이고, 그 자리에서 스택트레이스를 읽게 하면 구제 수단이 아니라 또 하나의 막다른 길이 된다.
     *
     * @param  SettingsService  $settingsService  설정 서비스
     * @return bool 껐으면 true, 저장에 실패했으면 false
     */
    private function turnOff(SettingsService $settingsService): bool
    {
        try {
            if ($settingsService->setSetting('security.ip_restriction_enabled', false)) {
                return true;
            }
        } catch (\Throwable $e) {
            $this->error('설정을 저장하지 못했습니다 — '.$e->getMessage());
            $this->line('storage/app/settings/security.json 의 "ip_restriction_enabled" 를 false 로 고치면 같은 효과입니다.');

            return false;
        }

        $this->error('설정을 저장하지 못했습니다 — storage/app/settings/security.json 의 쓰기 권한을 확인하세요.');

        return false;
    }

    /**
     * 현재 설정을 출력합니다.
     *
     * 형식이 잘못된 항목은 따로 표시한다 — 판정에서는 조용히 무시되므로, 운영자가 "목록에 넣었는데
     * 통과가 안 된다" 를 겪을 때 여기서만 원인이 보인다.
     *
     * @param  array<string, mixed>  $security  security 카테고리 설정
     * @return void 콘솔에 직접 출력한다
     */
    private function renderStatus(array $security): void
    {
        $enabled = IpAccessMatcher::isEnabled($security);
        $adminAllowedIps = is_array($security['admin_allowed_ips'] ?? null) ? $security['admin_allowed_ips'] : [];
        $rules = is_array($security['ip_restriction_rules'] ?? null) ? array_values($security['ip_restriction_rules']) : [];

        $this->line('<options=bold>IP 접근 제한 상태</>');
        $this->line('');
        $this->line('  사용 여부          : '.($enabled ? '<fg=green>켜짐</>' : '<comment>꺼짐</comment>'));

        if ($adminAllowedIps === []) {
            $this->line('  관리자 영역 허용 IP : <comment>비어 있음 (이 축은 적용되지 않음)</comment>');
        } else {
            $this->line('  관리자 영역 허용 IP : '.implode(', ', array_map('strval', array_filter($adminAllowedIps, 'is_scalar'))));
        }

        $this->line('  라우트별 규칙      : '.count($rules).'건 (사용 중인 첫 매칭 규칙만 적용)');

        if ($rules !== []) {
            $this->table(
                ['#', 'URI 패턴', '허용 IP', '메모', '사용'],
                array_map(function ($rule, $index) {
                    $rule = is_array($rule) ? $rule : [];

                    return [
                        $index + 1,
                        (string) ($rule['pattern'] ?? ''),
                        implode(', ', array_map('strval', array_filter((array) ($rule['ips'] ?? []), 'is_scalar'))),
                        (string) ($rule['memo'] ?? ''),
                        IpAccessMatcher::isRuleEnabled($rule) ? '예' : '아니오',
                    ];
                }, $rules, array_keys($rules))
            );
        }

        $invalid = $this->invalidEntries($adminAllowedIps, $rules);

        if ($invalid !== []) {
            $this->warn('  형식이 잘못된 항목 (판정에서 무시됨): '.implode(', ', $invalid));
        }

        $this->line('  신뢰 프록시        : '.(TrustedProxyDiagnostic::isConfigured()
            ? (string) TrustedProxyDiagnostic::configuredProxies()
            : '<comment>미설정 — 프록시 뒤에서는 전원이 프록시 주소 하나로 보입니다 (trusted-proxy:status)</comment>'));
        $this->line('  설정 파일          : storage/app/settings/security.json');
        $this->line('  비상 해제          : php artisan security:ip-restriction --disable');
        $this->line('');
    }

    /**
     * 허용 목록·규칙에서 IP/CIDR 형식이 아닌 항목을 모읍니다.
     *
     * @param  array<int|string, mixed>  $adminAllowedIps  관리자 영역 허용 목록
     * @param  array<int|string, mixed>  $rules  규칙 목록
     * @return array<int, string> 잘못된 항목 (표시용 문자열)
     */
    private function invalidEntries(array $adminAllowedIps, array $rules): array
    {
        $entries = $adminAllowedIps;

        foreach ($rules as $rule) {
            if (is_array($rule) && is_array($rule['ips'] ?? null)) {
                $entries = [...$entries, ...$rule['ips']];
            }
        }

        $invalid = [];

        foreach ($entries as $entry) {
            if (! IpAccessMatcher::isValidEntry($entry)) {
                $invalid[] = is_scalar($entry) ? (string) $entry : gettype($entry);
            }
        }

        return array_values(array_unique($invalid));
    }
}
