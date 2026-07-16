<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Services;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\PayNhnkcp\Exceptions\NhnKcpApiException;

class NhnKcpApiService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

    private const PA_URL_TEST = 'testpaygw.kcp.co.kr';

    private const PA_URL_LIVE = 'paygw.kcp.co.kr';

    private const PA_PORT = '8090';

    private const TX_APPROVE = '00100000';

    private const TX_CANCEL = '00200000';

    private const JS_URL_TEST = 'https://testpay.kcp.co.kr/plugin/payplus_web.jsp';

    private const JS_URL_LIVE = 'https://pay.kcp.co.kr/plugin/payplus_web.jsp';

    private const API_URL_TEST = 'https://stgapi.kcp.co.kr';

    private const API_URL_LIVE = 'https://api.kcp.co.kr';

    private const LIVE_SITE_CD_PREFIX = 'SR';

    /**
     * KCP CLI log_level — '1' (오류만).
     *
     * '3' (verbose) 은 TX_ENDED 라인에 PG 응답 페이로드 전체(card_no 평문 등) 를
     * 기록하므로 PCI DSS 위반 위험. LOG_DISABLED_PATH 와 함께 이중 안전장치.
     */
    private const LOG_LEVEL = '1';

    /**
     * KCP CLI log_path — 의도적으로 존재하지 않는 경로.
     *
     * gnuboard5 의 settle_kcp.inc.php 가 검증된 패턴 (`$g_conf_log_dir = '/home100/kcp'`).
     * CLI 가 디렉토리 생성을 시도하지만 실패하므로 로그 작성 단계가 silent skip 된다.
     * 결과:
     *  - bin/log/YYYYMM/*.log 자체가 생성되지 않음
     *  - 카드번호 / 사업자번호 등 결제 메타가 디스크에 잔존하지 않음 (PCI DSS 3.4)
     *  - 비표준 배포(DocumentRoot=프로젝트 루트)에서 직접 다운로드 위험 자체가 부재
     *
     * 디버깅 필요 시 임시로 실제 경로를 지정해 활성화 가능하지만, 그 경우 결제
     * 메타 평문 잔존을 운영자가 책임져야 함 (PCI 운영자 동의 필요).
     */
    private const LOG_DISABLED_PATH = '/home100/kcp';

    private bool $isTest;

    private string $siteCd;

    private string $escrowSiteCd;

    private string $siteKey;

    private string $binDir;

    public function __construct(
        private readonly PluginSettingsService $pluginSettingsService,
    )
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->applyCredentials($settings, (bool) ($settings['is_test_mode'] ?? true));
        $this->binDir = dirname(__DIR__, 2) . '/bin';
    }

    /**
     * 활성 site_cd 반환 (테스트/라이브 자동 분기)
     *
     * @return string KCP site_cd
     */
    public function getSiteCd(): string
    {
        return $this->siteCd;
    }

    /**
     * 현재 서비스 인스턴스가 테스트 모드인지 반환한다.
     *
     * @return bool 테스트 모드 여부
     */
    public function isTestMode(): bool
    {
        return $this->isTest;
    }

    /**
     * 결제 당시 저장된 KCP 상점 코드/모드를 환불·조회 호출 전에 복원한다.
     *
     * 사이트 키는 DB 결제 메타에 저장하지 않고 현재 플러그인 설정의 해당 모드 키를 사용한다.
     *
     * @param  bool  $isTestMode  결제 당시 테스트 모드 여부
     * @param  string  $siteCd  결제 당시 KCP site_cd
     * @return void
     */
    public function useStoredCredentials(bool $isTestMode, string $siteCd): void
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->applyCredentials($settings, $isTestMode, $siteCd);
    }

    /**
     * Standard Pay JS SDK URL 반환 (테스트/라이브 자동 분기)
     *
     * @return string SDK URL
     */
    public function getJsUrl(): string
    {
        return $this->isTest ? self::JS_URL_TEST : self::JS_URL_LIVE;
    }

    /**
     * KCP 거래 조회 (HTTP API 방식)
     *
     * 결제 승인/취소 hot path 는 KCP CLI 를 사용하지만, 거래 상태 조회처럼
     * 부수효과가 없는 요청은 HTTP API 로 제공한다.
     *
     * @param string $tno      KCP 거래번호
     * @param string $ordrIdxx 주문번호
     * @return array KCP 거래 조회 응답
     */
    public function getTransaction(string $tno, string $ordrIdxx): array
    {
        $this->assertSafeCliValue($tno, 'tno');
        $this->assertSafeCliValue($ordrIdxx, 'ordr_idxx');

        $timestamp = now()->format('YmdHis');
        $signature = hash_hmac(
            'sha256',
            implode('|', [$this->siteCd, $tno, $ordrIdxx, $timestamp]),
            $this->siteKey
        );

        $url = $this->apiBaseUrl() . '/v1/payment/trade/' . rawurlencode($tno);

        $response = Http::withBasicAuth($this->siteCd, $this->siteKey)
            ->withHeaders([
                'X-Kcp-Site-Code' => $this->siteCd,
                'X-Kcp-Timestamp' => $timestamp,
                'X-Kcp-Signature' => $signature,
            ])
            ->asJson()
            ->post($url, [
                'tno' => $tno,
                'ordr_idxx' => $ordrIdxx,
            ]);

        if ($response->failed()) {
            throw new NhnKcpApiException('KCP transaction query HTTP ' . $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * KCP 결제 승인 (CLI 방식)
     *
     * Standard Pay 결제창 완료 후 받은 enc_data / enc_info 로 KCP CLI 를 통해
     * 서버 승인 요청. 응답 res_cd = '0000' 이면 성공.
     *
     * @param  string  $encData  KCP 암호화 결제 데이터
     * @param  string  $encInfo  KCP 암호화 결제 정보
     * @param  string  $ordrIdxx  주문번호
     * @param  string  $custIp  고객 IP
     * @return array 파싱된 KCP 응답 (res_cd, res_msg, tno, app_no, card_no, quota 등)
     */
    public function approvePayment(string $encData, string $encInfo, string $ordrIdxx, string $custIp): array
    {
        return $this->executeCli(
            txCd: self::TX_APPROVE,
            ordrIdxx: $ordrIdxx,
            encData: $encData,
            encInfo: $encInfo,
            custIp: $custIp,
        );
    }

    /**
     * KCP 결제 취소 (CLI 방식)
     *
     * @param string $tno       KCP 원거래 거래번호
     * @param string $ordrIdxx  주문번호
     * @param int    $cancelAmt 취소 금액
     * @param string $cancelMsg 취소 사유
     * @param bool   $isPartial 부분 취소 여부
     * @param int    $totalAmt  현재 부분취소 가능 금액 (KCP rem_mny)
     * @return array 파싱된 KCP 응답
     */
    public function cancelPayment(
        string $tno,
        string $ordrIdxx,
        int $cancelAmt,
        string $cancelMsg,
        bool $isPartial = false,
        int $totalAmt = 0,
    ): array {
        $modType = $isPartial ? 'RN07' : 'STSC';

        $fields = [
            'tno' => $tno,
            'mod_type' => $modType,
            'mod_desc' => $cancelMsg,
        ];

        if ($isPartial && $totalAmt > 0) {
            // KCP rem_mny는 취소 후 잔액이 아니라 현재 부분취소 가능한 금액이다.
            // 첫 부분취소는 원거래금액, 이후에는 이전 부분취소 후 남은 잔액을 전달한다.
            $fields['rem_mny'] = (string) $totalAmt;
            $fields['mod_mny'] = (string) $cancelAmt;
        }

        $modxData = $this->buildModData($fields);

        // 훅: 결제 취소 전 (본인인증 등 확장 지점)
        HookManager::doAction('sirsoft-pay_nhnkcp.payment.before_cancel', $tno, $ordrIdxx, $cancelAmt, $cancelMsg);

        $result = $this->executeCli(
            txCd: self::TX_CANCEL,
            ordrIdxx: $ordrIdxx,
            encData: '',
            encInfo: '',
            custIp: '127.0.0.1',
            modxData: $modxData,
        );

        if (($result['res_cd'] ?? '') !== '0000') {
            Log::error('KCP CLI cancel failed', [
                'res_cd' => $result['res_cd'] ?? '',
                'res_msg' => $result['res_msg'] ?? '',
                'tno' => $tno,
            ]);
            throw new NhnKcpApiException($result['res_msg'] ?? 'KCP cancel failed');
        }

        // 훅: 결제 취소 완료 후 (외부 소비자 후처리 확장 지점)
        HookManager::doAction('sirsoft-pay_nhnkcp.payment.after_cancel', $tno, $result);

        return $result;
    }

    /**
     * KCP 에스크로 배송 등록 (CLI 방식, mod_type=STE1)
     *
     * 에스크로 결제 후 상품을 발송할 때 KCP에 운송장번호를 등록합니다.
     * 에스크로 테스트 결제는 별도 설정값이 있으면 그 site_cd를, 없으면 일반 테스트 site_cd를 사용합니다.
     *
     * @param  string  $tno       KCP 에스크로 거래번호
     * @param  string  $ordrIdxx  주문번호
     * @param  string  $deliNumb  운송장번호
     * @param  string  $deliCorp  택배사코드 (KCP 코드: '04'=CJ대한통운, '05'=한진택배 등)
     * @return array 파싱된 KCP 응답
     */
    public function registerEscrowDelivery(
        string $tno,
        string $ordrIdxx,
        string $deliNumb,
        string $deliCorp,
    ): array {
        $modxData = $this->buildModData([
            'tno' => $tno,
            'mod_type' => 'STE1',
            'deli_numb' => $deliNumb,
            'deli_corp' => $deliCorp,
        ]);

        $result = $this->executeCli(
            txCd: self::TX_CANCEL,
            ordrIdxx: $ordrIdxx,
            encData: '',
            encInfo: '',
            custIp: '127.0.0.1',
            modxData: $modxData,
            siteCdOverride: $this->escrowSiteCd,
        );

        if (($result['res_cd'] ?? '') !== '0000') {
            Log::error('KCP CLI escrow delivery register failed', [
                'res_cd' => $result['res_cd'] ?? '',
                'res_msg' => $result['res_msg'] ?? '',
                'tno' => $tno,
            ]);
            throw new NhnKcpApiException($result['res_msg'] ?? 'KCP escrow delivery registration failed');
        }

        return $result;
    }

    private function executeCli(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $modxData = '',
        string $siteCdOverride = '',
    ): array {
        $paUrl = $this->isTest ? self::PA_URL_TEST : self::PA_URL_LIVE;
        $siteCd = $siteCdOverride !== '' ? $siteCdOverride : $this->siteCd;

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $resData = $this->executeCliWindows($txCd, $ordrIdxx, $encData, $encInfo, $custIp, $paUrl, $modxData, $siteCd);
        } else {
            $resData = $this->executeCliLinux($txCd, $ordrIdxx, $encData, $encInfo, $custIp, $paUrl, $modxData, $siteCd);
        }

        if ($resData === '') {
            $resData = 'res_cd=9502' . chr(31) . 'res_msg=연동 모듈 호출 오류';
        }

        // KCP Windows CLI는 CP949(EUC-KR)로 한글을 출력 — MySQL UTF-8 저장을 위해 변환
        if (! mb_check_encoding($resData, 'UTF-8')) {
            $converted = mb_convert_encoding($resData, 'UTF-8', 'CP949');
            if ($converted !== false) {
                $resData = $converted;
            }
        }

        parse_str(str_replace(chr(31), '&', $resData), $result);

        Log::debug('KCP CLI response parsed', [
            'tx_cd' => $txCd,
            'ordr_idxx' => $ordrIdxx,
            'res_cd' => $result['res_cd'] ?? null,
        ]);

        return $result;
    }

    private function executeCliWindows(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $paUrl,
        string $modxData,
        string $siteCd = '',
    ): string {
        $siteCd = $siteCd !== '' ? $siteCd : $this->siteCd;
        $keyPath = str_replace('/', DIRECTORY_SEPARATOR, $this->binDir . '/pub.key');
        $binPath = str_replace('/', DIRECTORY_SEPARATOR, $this->binDir . '/pp_cli_exe.exe');

        // 실행 권한 자가 복구: plugin:update 후 _bundled 의 0664 권한이 활성 디렉토리에 그대로
        // 회귀해 9502 ("연동 모듈 호출 오류") 가 발생하던 회귀를 결제 hot path 에서 차단.
        // Windows 에서는 chmod 자체는 no-op 에 가깝지만, is_file 가드는 의미 있음.
        $this->ensureCliExecutable($binPath);
        $planData = 'payx_data=' . ($modxData !== '' ? 'mod_data=' . $modxData : '');

        // Shell injection 방어: CLI args 의 각 값을 사전 검증 후 안전한 값만 사용.
        // 위험 문자(`"`, 제어문자, 개행) 가 포함되면 KCP CLI 인터페이스가 깨지고 cmd.exe
        // 인수 파싱이 조작될 수 있어 NhnKcpApiException 으로 즉시 거부.
        $this->assertSafeCliValue($siteCd, 'site_cd');
        $this->assertSafeCliValue($this->siteKey, 'site_key');
        $this->assertSafeCliValue($txCd, 'tx_cd');
        $this->assertSafeCliValue($paUrl, 'pa_url');
        $this->assertSafeCliValue($ordrIdxx, 'ordr_idxx');
        $this->assertSafeCliValue($encData, 'enc_data');
        $this->assertSafeCliValue($encInfo, 'enc_info');
        $this->assertSafeCliValue($custIp, 'cust_ip');
        $this->assertSafeCliValue($keyPath, 'key_path');
        $this->assertSafeCliValue($planData, 'plan_data', allowKcpFieldSeparator: true);

        $args = 'site_cd=' . $siteCd . ','
            . 'site_key=' . $this->siteKey . ','
            . 'tx_cd=' . $txCd . ','
            . 'pa_url=' . $paUrl . ','
            . 'pa_port=' . self::PA_PORT . ','
            . 'ordr_idxx=' . $ordrIdxx . ','
            . 'enc_data=' . $encData . ','
            . 'enc_info=' . $encInfo . ','
            . 'trace_no=,'
            . 'cust_ip=' . $custIp . ','
            . 'key_path=' . $keyPath . ','
            . 'log_path=' . self::LOG_DISABLED_PATH . ','
            . 'log_level=' . self::LOG_LEVEL . ','
            . 'plan_data=' . $planData;

        // escapeshellarg 로 binPath / args 각각을 안전하게 quoting (Windows 는 `"` 제거 + 큰따옴표 래핑).
        $command = escapeshellarg($binPath) . ' ' . escapeshellarg($args);

        Log::debug('KCP CLI command prepared (Windows)', [
            'bin' => basename($binPath),
            'tx_cd' => $txCd,
            'ordr_idxx' => $ordrIdxx,
            'site_cd' => $siteCd,
        ]);

        exec($command, $output, $returnCode);

        Log::debug('KCP CLI exec result', [
            'return_code' => $returnCode,
            'output_count' => count($output),
        ]);

        // Windows 코드페이지 변경 메시지('Active code page: ...') 등 비-KCP 라인 제거
        $kcpLines = array_filter($output, static fn (string $line) => str_contains($line, 'res_cd='));

        if (empty($kcpLines)) {
            return '';
        }

        return (string) array_values($kcpLines)[0];
    }

    private function executeCliLinux(
        string $txCd,
        string $ordrIdxx,
        string $encData,
        string $encInfo,
        string $custIp,
        string $paUrl,
        string $modxData,
        string $siteCd = '',
    ): string {
        $siteCd = $siteCd !== '' ? $siteCd : $this->siteCd;
        $binExe = PHP_INT_MAX === 2147483647
            ? $this->binDir . '/pp_cli'
            : $this->binDir . '/pp_cli_x64';

        // 실행 권한 자가 복구: plugin:update 후 _bundled 의 0664 권한이 활성 디렉토리에 그대로
        // 회귀해 exec() 가 빈 결과를 반환 → 9502 ("연동 모듈 호출 오류") 가 발생하던 회귀를
        // 결제 hot path 에서 차단. HealthCheckController 의 admin UI 진입 시점 복구와는 별개로
        // 사용자 결제 시점 안전망 역할.
        $this->ensureCliExecutable($binExe);

        $modxArg = $modxData !== '' ? 'mod_data=' . $modxData : '';

        // CLI args 사전 검증 — Linux 도 별도 sanitization 적용해 OS 간 동일한 가드.
        $this->assertSafeCliValue($siteCd, 'site_cd');
        $this->assertSafeCliValue($this->siteKey, 'site_key');
        $this->assertSafeCliValue($txCd, 'tx_cd');
        $this->assertSafeCliValue($paUrl, 'pa_url');
        $this->assertSafeCliValue($ordrIdxx, 'ordr_idxx');
        $this->assertSafeCliValue($encData, 'enc_data');
        $this->assertSafeCliValue($encInfo, 'enc_info');
        $this->assertSafeCliValue($custIp, 'cust_ip');
        $this->assertSafeCliValue($modxArg, 'modx_data', allowKcpFieldSeparator: true);

        $args = 'home=' . $this->binDir . ','
            . 'site_cd=' . $siteCd . ','
            . 'site_key=' . $this->siteKey . ','
            . 'tx_cd=' . $txCd . ','
            . 'pa_url=' . $paUrl . ','
            . 'pa_port=' . self::PA_PORT . ','
            . 'ordr_idxx=' . $ordrIdxx . ','
            . 'enc_data=' . $encData . ','
            . 'enc_info=' . $encInfo . ','
            . 'trace_no=,'
            . 'cust_ip=' . $custIp . ','
            . 'modx_data=' . $modxArg . ','
            . 'log_path=' . self::LOG_DISABLED_PATH . ','
            . 'log_level=' . self::LOG_LEVEL . ','
            . 'opt=';

        $command = $binExe . ' ' . escapeshellarg('-h') . ' ' . escapeshellarg($args);

        return (string) exec($command);
    }

    /**
     * KCP CLI 바이너리의 실행 권한 자가 복구.
     *
     * plugin:update 가 _bundled 의 0664 권한을 활성 디렉토리로 그대로 복사해
     * 실행 권한이 사라지면 exec() 가 빈 결과를 반환 → executeCli() 가 res_cd=9502
     * fallback 으로 떨어진다. 결제 진입 직전 호출하여 권한이 부족하면 chmod 0755
     * 로 복구, stat 캐시 무효화 후 재검증한다.
     *
     * 복구 실패 시 (PHP-FPM 이 파일 소유자가 아닌 sudo 환경 등) NhnKcpApiException
     * 으로 fail-fast — exec() 가 9502 로 끝나기 전에 운영자가 원인 (sudo chmod 필요)
     * 을 명확히 알 수 있다.
     *
     * @param  string  $binPath  KCP CLI 바이너리 절대 경로
     * @return void
     *
     * @throws NhnKcpApiException 바이너리 누락 또는 실행 권한 복구 실패 시
     */
    private function ensureCliExecutable(string $binPath): void
    {
        if (! is_file($binPath)) {
            throw new NhnKcpApiException(__('sirsoft-pay_nhnkcp::messages.errors.cli_binary_missing', [
                'path' => $binPath,
            ]));
        }

        clearstatcache(true, $binPath);
        if (is_executable($binPath)) {
            return;
        }

        $beforeMode = substr(sprintf('%o', fileperms($binPath)), -4);
        $chmodOk = @chmod($binPath, 0755);
        clearstatcache(true, $binPath);

        if ($chmodOk && is_executable($binPath)) {
            Log::info('KCP: CLI 바이너리 실행 권한 자가 복구', [
                'path' => $binPath,
                'before' => $beforeMode,
                'after' => '0755',
            ]);

            return;
        }

        Log::error('KCP: CLI 실행 권한 자가 복구 실패 — sudo chmod 필요', [
            'path' => $binPath,
            'mode' => $beforeMode,
            'owner' => fileowner($binPath),
            'php_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
        ]);

        throw new NhnKcpApiException(
            __('sirsoft-pay_nhnkcp::messages.errors.cli_binary_not_executable', ['path' => $binPath])
        );
    }

    /**
     * CLI args 의 단일 값에 shell injection 위험 문자가 없는지 검증.
     *
     * 거부 대상:
     *  - 큰따옴표 (`"`) — Windows cmd.exe 의 args quoting 깨짐
     *  - 제어문자 (개행 / 캐리지리턴 / NUL / TAB) — argv 분리/명령 종료 위험
     *  - 백틱 (`` ` ``) — 일부 셸에서 명령 치환
     *
     * KCP CLI 의 정상 입력 (alphanumeric, base64, IP, URL, Windows path 등) 은
     * 모두 통과하며, 비정상 페이로드만 차단한다.
     *
     * @param  string  $value  검증할 값
     * @param  string  $key  필드 이름 (예외 메시지용)
     *
     * @throws NhnKcpApiException 위험 문자 발견 시
     */
    private function assertSafeCliValue(string $value, string $key, bool $allowKcpFieldSeparator = false): void
    {
        // 큰따옴표 / 백틱 — 명시적 위험
        if (preg_match('/["`]/', $value) === 1) {
            throw new NhnKcpApiException(
                "KCP CLI rejected unsafe value for {$key} (contains quote/backtick)."
            );
        }

        // 제어문자 (NUL / LF / CR / TAB 등 0x00-0x1F + 0x7F).
        // KCP CLI mod_data 자체에는 필드 구분자 chr(31)가 필요하므로 내부에서
        // buildModData()로 개별 값 검증을 마친 조립 문자열에 한해 chr(31)만 허용한다.
        $controlPattern = $allowKcpFieldSeparator ? '/[\x00-\x1E\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($controlPattern, $value) === 1) {
            throw new NhnKcpApiException(
                "KCP CLI rejected unsafe value for {$key} (contains control character)."
            );
        }
    }

    /**
     * KCP CLI mod_data 문자열을 안전하게 조립한다.
     *
     * 개별 value 에 KCP 필드 구분자(chr(31))나 쉘 인자 위험 문자가 들어가면 먼저
     * 거부한 뒤, 내부 구분자만 포함한 mod_data 를 만든다.
     *
     * @param array<string, string|int> $fields
     */
    private function buildModData(array $fields): string
    {
        $parts = [];

        foreach ($fields as $key => $value) {
            $value = (string) $value;
            $this->assertSafeCliValue($key, 'mod_data_key');
            $this->assertSafeCliValue($value, $key);
            $parts[] = $key . '=' . $value;
        }

        return implode(chr(31), $parts) . chr(31);
    }

    private function apiBaseUrl(): string
    {
        return $this->isTest ? self::API_URL_TEST : self::API_URL_LIVE;
    }

    /**
     * 플러그인 설정과 선택 모드 기준으로 KCP 인증 정보를 적용한다.
     */
    private function applyCredentials(array $settings, bool $isTestMode, string $siteCdOverride = ''): void
    {
        $this->isTest = $isTestMode;

        if ($this->isTest) {
            $this->siteCd = $siteCdOverride !== '' ? $siteCdOverride : ($settings['test_site_cd'] ?? 'T0000');
            $escrowTestSiteCd = trim((string) ($settings['escrow_test_site_cd'] ?? ''));
            $this->escrowSiteCd = $escrowTestSiteCd !== '' ? $escrowTestSiteCd : $this->siteCd;
            $this->siteKey = $settings['test_site_key'] ?? '';

            return;
        }

        $liveSiteCd = $siteCdOverride !== '' ? $siteCdOverride : ($settings['live_site_cd'] ?? '');
        $this->siteCd = $this->buildLiveSiteCd($liveSiteCd);
        $this->escrowSiteCd = $this->siteCd;
        $this->siteKey = $settings['live_site_key'] ?? '';
    }

    private function buildLiveSiteCd(string $suffix): string
    {
        return str_starts_with($suffix, self::LIVE_SITE_CD_PREFIX) ? $suffix : self::LIVE_SITE_CD_PREFIX . $suffix;
    }
}
