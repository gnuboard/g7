<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use App\Helpers\PermissionHelper;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * NHN KCP 플러그인 시스템 점검 컨트롤러
 *
 * 관리자 설정 페이지에서 호출되어:
 *   - PC 결제 (Standard Pay CLI 바이너리) 실행 환경
 *   - 모바일 결제 (SmartPhone Pay SOAP 호출) 실행 환경
 * 두 가지 사전조건을 진단하고, 자동 복구 가능한 항목(예: chmod +x)은
 * 즉시 수정한 뒤 결과를 반환한다.
 *
 * 자동 복구 불가능한 항목(php-soap 미설치, exec() disabled 등)은
 * "사용자 조치 안내" 메시지(remediation)를 함께 반환한다.
 */
class HealthCheckController extends AdminBaseController
{
    private const STATUS_OK = 'ok';        // 정상

    private const STATUS_FIXED = 'fixed';  // 자동 복구됨

    private const STATUS_WARNING = 'warning';  // 동작 가능하나 권장 상태 아님

    private const STATUS_ERROR = 'error';  // 사용자 조치 필요

    private string $binDir;

    /**
     * 플러그인 루트의 bin 디렉토리 경로를 점검 대상으로 정합니다.
     *
     * CLI 바이너리·pub.key·WSDL 파일이 모두 이 디렉토리 아래에 있어야 한다.
     */
    public function __construct()
    {
        parent::__construct();

        $this->binDir = dirname(__DIR__, 2).'/bin';
    }

    /**
     * GET /api/plugins/sirsoft-pay_nhnkcp/admin/health
     *
     * @return JsonResponse{success: true, message: string, data: {summary: array, checks: array}}
     */
    public function check(): JsonResponse
    {
        // 현재 OS/아키텍처에 해당하는 CLI 바이너리만 체크 — NhnKcpApiService::executeCli() 와 동일 기준
        [$cliFilename, $cliArch, $cliRequireExec] = $this->resolveCliForCurrentOs();

        $checks = [
            $this->checkExecFunction(),
            $this->checkBinDirectory(),
            $this->checkCliBinary($cliFilename, $cliArch, $cliRequireExec),
            $this->checkPubKey(),
            $this->checkSoapExtension(),
            $this->checkWsdlFile(),
        ];

        $summary = array_merge(
            $this->summarize($checks, $cliFilename),
            ['cancel_server_ip' => $this->resolveCancelServerIp()]
        );

        return ResponseHelper::success('sirsoft-pay_nhnkcp::messages.health.checked', [
            'summary' => $summary,
            'checks' => $checks,
        ]);
    }

    /**
     * 현재 PHP 런타임의 OS/아키텍처에 맞는 CLI 바이너리 정보 반환
     *
     * @return array{0: string, 1: string, 2: bool} [filename, arch label, requireExec]
     */
    private function resolveCliForCurrentOs(): array
    {
        // Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows 는 ACL 기반이라 ELF 의 +x 비트 검사를 적용하지 않음
            return ['pp_cli_exe.exe', 'Windows', false];
        }

        // Linux 32-bit (PHP_INT_MAX == 2^31-1)
        if (PHP_INT_MAX === 2147483647) {
            return ['pp_cli', 'Linux 32-bit', true];
        }

        // Linux 64-bit (기본)
        return ['pp_cli_x64', 'Linux 64-bit', true];
    }

    /**
     * 상태 통계: 카테고리별로 PC/모바일 결제 가능 여부 판단
     *
     * @param  array<int, array<string, mixed>>  $checks  개별 점검 항목 결과 목록
     * @param  string  $cliFilename  현재 OS 의 CLI 바이너리 파일명 (cli_id 계산용)
     * @return array<string, bool|int> PC/모바일 결제 가능 여부와 상태별 건수(error·warning·fixed)
     */
    private function summarize(array $checks, string $cliFilename): array
    {
        $byId = [];
        foreach ($checks as $c) {
            $byId[$c['id']] = $c;
        }

        $execOk = ! $this->isErroneous($byId['exec_function'] ?? null);

        // 현재 OS 의 CLI 바이너리 ID 동적 계산 (checkCliBinary 와 동일 규칙)
        $cliId = 'cli_'.preg_replace('/[^a-z0-9_]/i', '_', strtolower(pathinfo($cliFilename, PATHINFO_FILENAME)));
        $cliOk = ! $this->isErroneous($byId[$cliId] ?? null);

        $pubKeyOk = ! $this->isErroneous($byId['pub_key'] ?? null);
        $soapOk = ! $this->isErroneous($byId['soap_extension'] ?? null);
        $wsdlOk = ! $this->isErroneous($byId['wsdl_file'] ?? null);

        // PC 표준결제: CLI 바이너리로 enc_data 복호화 필수
        $pcReady = $execOk && $cliOk && $pubKeyOk;

        // 모바일 카드/일반결제: KCP 가 콜백에 enc_data 를 동봉 → 서버가 CLI 로 복호화 필요.
        // SOAP 만으로는 부족. PC 와 동일한 CLI 요건 + 모바일 전용 SOAP/WSDL 모두 필요.
        $mobileCardReady = $execOk && $cliOk && $pubKeyOk && $soapOk && $wsdlOk;

        // 모바일 가상계좌 전용: KCP 가 평문 필드(bankname/account/depositor)로 콜백 → CLI 불필요.
        // SOAP/WSDL 만 OK 여도 가상계좌는 동작 가능.
        $mobileVbankOnlyReady = $soapOk && $wsdlOk;

        $errorCount = 0;
        $warningCount = 0;
        $fixedCount = 0;
        foreach ($checks as $c) {
            match ($c['status']) {
                self::STATUS_ERROR => $errorCount++,
                self::STATUS_WARNING => $warningCount++,
                self::STATUS_FIXED => $fixedCount++,
                default => null,
            };
        }

        return [
            'pc_payment_ready' => $pcReady,
            // 'mobile_payment_ready' 는 카드/일반결제 기준(가장 흔한 케이스). 가상계좌는 별도 필드.
            'mobile_payment_ready' => $mobileCardReady,
            'mobile_vbank_only_ready' => $mobileVbankOnlyReady,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'fixed_count' => $fixedCount,
        ];
    }

    /**
     * 점검 항목이 사용자 조치가 필요한 오류 상태인지 판정합니다.
     *
     * 항목이 없거나 상태가 비어 있으면 오류로 간주한다.
     *
     * @param  array<string, mixed>|null  $check  개별 점검 항목 결과 (없으면 null)
     * @return bool 오류 상태이면 true
     */
    private function isErroneous(?array $check): bool
    {
        return ($check['status'] ?? self::STATUS_ERROR) === self::STATUS_ERROR;
    }

    /**
     * KCP 상점관리자 결제서버 IP 설정에 등록할 서버 IP 후보를 반환한다.
     *
     * 실제 취소 요청은 서버에서 KCP로 나가므로 공인 outbound IP가 가장 정확하다.
     * 외부 조회가 실패하면 PHP 서버 변수/호스트명에서 확인 가능한 IP를 fallback으로 제공한다.
     *
     * @return array{address: string|null, source: string, source_label: string}
     */
    private function resolveCancelServerIp(): array
    {
        $publicIp = $this->detectPublicOutboundIp();
        if ($publicIp !== null) {
            return [
                'address' => $publicIp,
                'source' => 'public_outbound',
                'source_label' => $this->t('source.public_outbound'),
            ];
        }

        $serverIp = $this->detectServerVariableIp();
        if ($serverIp !== null) {
            return [
                'address' => $serverIp,
                'source' => 'server_variable',
                'source_label' => $this->t('source.server_variable'),
            ];
        }

        return [
            'address' => null,
            'source' => 'unavailable',
            'source_label' => $this->t('source.unavailable'),
        ];
    }

    /**
     * 외부 IP 조회 서비스(ipify)로 서버의 공인 outbound IP 를 조회합니다.
     *
     * 2초 안에 응답이 없거나 실패·형식 오류이면 null 을 돌려준다.
     *
     * @return string|null 공인 outbound IP (조회 실패 시 null)
     */
    private function detectPublicOutboundIp(): ?string
    {
        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get('https://api.ipify.org', ['format' => 'json']);

            if (! $response->ok()) {
                return null;
            }

            $ip = trim((string) ($response->json('ip') ?? ''));

            return $this->normalizeIp($ip);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * PHP 서버 변수(SERVER_ADDR·LOCAL_ADDR)와 호스트명에서 첫 번째 유효 IP 를 찾습니다.
     *
     * @return string|null 유효한 서버 IP (찾지 못하면 null)
     */
    private function detectServerVariableIp(): ?string
    {
        $candidates = [
            (string) request()->server('SERVER_ADDR', ''),
            (string) ($_SERVER['SERVER_ADDR'] ?? ''),
            (string) request()->server('LOCAL_ADDR', ''),
            (string) ($_SERVER['LOCAL_ADDR'] ?? ''),
            gethostbyname((string) gethostname()),
        ];

        foreach ($candidates as $candidate) {
            $ip = $this->normalizeIp($candidate);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * 앞뒤 공백을 제거한 값이 유효한 IP 주소일 때만 돌려줍니다.
     *
     * @param  string|null  $ip  검사할 IP 후보 문자열
     * @return string|null 유효한 IP 주소 (비었거나 형식 오류이면 null)
     */
    private function normalizeIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return null;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /**
     * PHP `exec()` 함수 활성화 여부 — disable_functions 에서 차단되면 CLI 호출 불가
     *
     * @return array<string, string> 점검 항목 결과 (id·category·label·status·detail, 오류 시 remediation)
     */
    private function checkExecFunction(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $isDisabled = in_array('exec', $disabled, true) || ! function_exists('exec');

        if ($isDisabled) {
            return [
                'id' => 'exec_function',
                'category' => 'pc',
                'label' => $this->t('exec.label'),
                'status' => self::STATUS_ERROR,
                'detail' => $this->t('exec.disabled'),
                'remediation' => $this->t('exec.disabled_remediation'),
            ];
        }

        return [
            'id' => 'exec_function',
            'category' => 'pc',
            'label' => $this->t('exec.label'),
            'status' => self::STATUS_OK,
            'detail' => $this->t('exec.ok'),
        ];
    }

    /**
     * bin 디렉토리 존재 여부만 검사 — 쓰기 권한은 CLI 바이너리 단위로 처리
     *
     * @return array<string, string> 점검 항목 결과 (id·category·label·status·detail, 오류 시 remediation)
     */
    private function checkBinDirectory(): array
    {
        if (! is_dir($this->binDir)) {
            return [
                'id' => 'bin_directory',
                'category' => 'pc',
                'label' => $this->t('bin_dir.label'),
                'status' => self::STATUS_ERROR,
                'detail' => $this->t('bin_dir.missing', ['path' => $this->binDir]),
                'remediation' => $this->t('bin_dir.missing_remediation'),
            ];
        }

        return [
            'id' => 'bin_directory',
            'category' => 'pc',
            'label' => $this->t('bin_dir.label'),
            'status' => self::STATUS_OK,
            'detail' => $this->t('bin_dir.ok'),
        ];
    }

    /**
     * CLI 바이너리: 존재 + (Linux는) 실행 권한 — 권한 없으면 자동 chmod +x 시도
     *
     * @param  string  $filename  bin 디렉토리 안의 CLI 바이너리 파일명
     * @param  string  $arch  화면에 표시할 OS/아키텍처 이름
     * @param  bool  $requireExec  실행 권한(+x) 검사 여부 (Windows 는 false)
     * @return array<string, string> 점검 항목 결과 (id·category·label·status·detail, 오류 시 remediation)
     */
    private function checkCliBinary(string $filename, string $arch, bool $requireExec = true): array
    {
        $path = $this->binDir.'/'.$filename;
        $id = 'cli_'.preg_replace('/[^a-z0-9_]/i', '_', strtolower(pathinfo($filename, PATHINFO_FILENAME)));
        $label = $this->t('cli.label', ['arch' => $arch, 'filename' => $filename]);

        if (! file_exists($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_ERROR,
                'detail' => $this->t('cli.missing', ['path' => $path]),
                'remediation' => $this->t('cli.missing_remediation', ['arch' => $arch]),
            ];
        }

        if (! $requireExec) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_OK,
                'detail' => $this->t('cli.windows'),
            ];
        }

        if (is_executable($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_OK,
                'detail' => $this->t('cli.executable', ['mode' => substr(sprintf('%o', fileperms($path)), -4)]),
            ];
        }

        $beforeMode = substr(sprintf('%o', fileperms($path)), -4);

        // 조회 요청이 서버 파일 권한을 바꾸므로 자동 복구는 플러그인 설정 수정 권한자만 — 그 밖에는 진단만 하고 조치를 안내한다
        if (! PermissionHelper::check('core.plugins.update', Auth::user())) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_ERROR,
                'detail' => $this->t('cli.not_executable_no_permission', ['before' => $beforeMode]),
                'remediation' => $this->t('cli.not_executable_remediation', ['path' => $path]),
            ];
        }

        // 자동 복구: chmod +x 시도
        $chmodOk = @chmod($path, 0755);

        if ($chmodOk && is_executable($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_FIXED,
                'detail' => $this->t('cli.fixed', ['before' => $beforeMode]),
            ];
        }

        return [
            'id' => $id,
            'category' => 'pc',
            'label' => $label,
            'status' => self::STATUS_ERROR,
            'detail' => $this->t('cli.not_executable', ['before' => $beforeMode]),
            'remediation' => $this->t('cli.not_executable_remediation', ['path' => $path]),
        ];
    }

    /**
     * pub.key 파일 존재 — KCP CLI 가 결제 응답 복호화에 사용
     *
     * @return array<string, string> 점검 항목 결과 (id·category·label·status·detail, 오류 시 remediation)
     */
    private function checkPubKey(): array
    {
        $path = $this->binDir.'/pub.key';

        if (! file_exists($path)) {
            return [
                'id' => 'pub_key',
                'category' => 'pc',
                'label' => $this->t('pub_key.label'),
                'status' => self::STATUS_ERROR,
                'detail' => $this->t('pub_key.missing', ['path' => $path]),
                'remediation' => $this->t('pub_key.missing_remediation'),
            ];
        }

        return [
            'id' => 'pub_key',
            'category' => 'pc',
            'label' => $this->t('pub_key.label'),
            'status' => self::STATUS_OK,
            'detail' => $this->t('pub_key.ok'),
        ];
    }

    /**
     * PHP SOAP 확장 — 모바일 SmartPhone Pay 의 approval_key 획득에 필수
     *
     * @return array<string, string> 점검 항목 결과 (id·category·label·status·detail, 오류 시 remediation)
     */
    private function checkSoapExtension(): array
    {
        if (extension_loaded('soap') && class_exists(\SoapClient::class)) {
            return [
                'id' => 'soap_extension',
                'category' => 'mobile',
                'label' => $this->t('soap.label'),
                'status' => self::STATUS_OK,
                'detail' => $this->t('soap.ok'),
            ];
        }

        return [
            'id' => 'soap_extension',
            'category' => 'mobile',
            'label' => $this->t('soap.label'),
            'status' => self::STATUS_ERROR,
            'detail' => $this->t('soap.missing'),
            'remediation' => $this->t('soap.missing_remediation'),
        ];
    }

    /**
     * WSDL 파일 — bin/ 에 KCPPaymentService.wsdl, real_KCPPaymentService.wsdl 필요
     *
     * @return array<string, string> 점검 항목 결과 (id·category·label·status·detail, 오류 시 remediation)
     */
    private function checkWsdlFile(): array
    {
        $wsdlFiles = ['KCPPaymentService.wsdl', 'real_KCPPaymentService.wsdl'];
        $missing = [];
        foreach ($wsdlFiles as $f) {
            if (! file_exists($this->binDir.'/'.$f)) {
                $missing[] = $f;
            }
        }

        if (! empty($missing)) {
            return [
                'id' => 'wsdl_file',
                'category' => 'mobile',
                'label' => $this->t('wsdl.label'),
                'status' => self::STATUS_ERROR,
                'detail' => $this->t('wsdl.missing', ['files' => implode(', ', $missing)]),
                'remediation' => $this->t('wsdl.missing_remediation'),
            ];
        }

        return [
            'id' => 'wsdl_file',
            'category' => 'mobile',
            'label' => $this->t('wsdl.label'),
            'status' => self::STATUS_OK,
            'detail' => $this->t('wsdl.ok'),
        ];
    }

    /**
     * 시스템 점검 문구를 요청 언어로 옮깁니다(`messages.health.*`).
     *
     * 카드 제목·건수·결제 경로 줄은 화면 번역 키로 바뀌지만 항목 줄은 서버가 문구를 만들어 보내므로,
     * 여기서 번역을 거치지 않으면 다른 언어 화면에 한국어 줄이 섞인다.
     *
     * @param  string  $key  `messages.health.` 아래 키
     * @param  array<string, string>  $replace  치환 값
     * @return string 번역된 문구
     */
    private function t(string $key, array $replace = []): string
    {
        return (string) __('sirsoft-pay_nhnkcp::messages.health.'.$key, $replace);
    }
}
