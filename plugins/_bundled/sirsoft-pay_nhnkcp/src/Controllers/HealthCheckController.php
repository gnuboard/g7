<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Controllers;

use Illuminate\Http\JsonResponse;
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
class HealthCheckController
{
    private const STATUS_OK = 'ok';        // 정상

    private const STATUS_FIXED = 'fixed';  // 자동 복구됨

    private const STATUS_WARNING = 'warning';  // 동작 가능하나 권장 상태 아님

    private const STATUS_ERROR = 'error';  // 사용자 조치 필요

    private string $binDir;

    public function __construct()
    {
        $this->binDir = dirname(__DIR__, 2) . '/bin';
    }

    /**
     * GET /api/plugins/sirsoft-pay_nhnkcp/admin/health
     *
     * @return JsonResponse{success: true, data: {summary: array, checks: array}}
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

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'checks' => $checks,
            ],
        ]);
    }

    /**
     * 현재 PHP 런타임의 OS/아키텍처에 맞는 CLI 바이너리 정보 반환
     *
     * @return array{0: string, 1: string, 2: bool}  [filename, arch label, requireExec]
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
     * @param  array<int, array<string, mixed>>  $checks
     * @param  string  $cliFilename  현재 OS 의 CLI 바이너리 파일명 (cli_id 계산용)
     */
    private function summarize(array $checks, string $cliFilename): array
    {
        $byId = [];
        foreach ($checks as $c) {
            $byId[$c['id']] = $c;
        }

        $execOk = ! $this->isErroneous($byId['exec_function'] ?? null);

        // 현재 OS 의 CLI 바이너리 ID 동적 계산 (checkCliBinary 와 동일 규칙)
        $cliId = 'cli_' . preg_replace('/[^a-z0-9_]/i', '_', strtolower(pathinfo($cliFilename, PATHINFO_FILENAME)));
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
                'source_label' => '공인 송신 IP',
            ];
        }

        $serverIp = $this->detectServerVariableIp();
        if ($serverIp !== null) {
            return [
                'address' => $serverIp,
                'source' => 'server_variable',
                'source_label' => '서버 IP 후보',
            ];
        }

        return [
            'address' => null,
            'source' => 'unavailable',
            'source_label' => '확인 필요',
        ];
    }

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
     */
    private function checkExecFunction(): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $isDisabled = in_array('exec', $disabled, true) || ! function_exists('exec');

        if ($isDisabled) {
            return [
                'id' => 'exec_function',
                'category' => 'pc',
                'label' => 'PHP exec() 함수',
                'status' => self::STATUS_ERROR,
                'detail' => 'php.ini 의 disable_functions 에서 exec 가 차단되어 있습니다.',
                'remediation' => "php.ini 에서 disable_functions 목록에서 'exec' 를 제거한 뒤 PHP-FPM(또는 Apache) 을 재시작하세요.\n예: sudo sed -i 's/disable_functions = .*exec.*/disable_functions =/' /etc/php/8.x/fpm/php.ini && sudo systemctl restart php8.x-fpm",
            ];
        }

        return [
            'id' => 'exec_function',
            'category' => 'pc',
            'label' => 'PHP exec() 함수',
            'status' => self::STATUS_OK,
            'detail' => 'CLI 호출 가능',
        ];
    }

    /**
     * bin 디렉토리 존재 여부만 검사 — 쓰기 권한은 CLI 바이너리 단위로 처리
     */
    private function checkBinDirectory(): array
    {
        if (! is_dir($this->binDir)) {
            return [
                'id' => 'bin_directory',
                'category' => 'pc',
                'label' => 'CLI 바이너리 디렉토리',
                'status' => self::STATUS_ERROR,
                'detail' => "bin 디렉토리가 없습니다: {$this->binDir}",
                'remediation' => '플러그인을 재설치하거나, KCP 에서 제공한 CLI 바이너리를 bin/ 에 복사하세요.',
            ];
        }

        return [
            'id' => 'bin_directory',
            'category' => 'pc',
            'label' => 'CLI 바이너리 디렉토리',
            'status' => self::STATUS_OK,
            'detail' => '디렉토리 존재',
        ];
    }

    /**
     * CLI 바이너리: 존재 + (Linux는) 실행 권한 — 권한 없으면 자동 chmod +x 시도
     */
    private function checkCliBinary(string $filename, string $arch, bool $requireExec = true): array
    {
        $path = $this->binDir . '/' . $filename;
        $id = 'cli_' . preg_replace('/[^a-z0-9_]/i', '_', strtolower(pathinfo($filename, PATHINFO_FILENAME)));
        $label = "CLI 바이너리 ({$arch}) — {$filename}";

        if (! file_exists($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_ERROR,
                'detail' => "파일이 없습니다: {$path}",
                'remediation' => "현재 OS({$arch})에 해당하는 KCP CLI 바이너리를 bin/ 에 복사하세요. (테스트 환경은 플러그인 기본 제공)",
            ];
        }

        if (! $requireExec) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_OK,
                'detail' => 'Windows 바이너리 — 실행 권한 검사 생략',
            ];
        }

        if (is_executable($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_OK,
                'detail' => '실행 권한 OK (' . substr(sprintf('%o', fileperms($path)), -4) . ')',
            ];
        }

        // 자동 복구: chmod +x 시도
        $beforeMode = substr(sprintf('%o', fileperms($path)), -4);
        $chmodOk = @chmod($path, 0755);

        if ($chmodOk && is_executable($path)) {
            return [
                'id' => $id,
                'category' => 'pc',
                'label' => $label,
                'status' => self::STATUS_FIXED,
                'detail' => "실행 권한 자동 복구됨: {$beforeMode} → 0755",
            ];
        }

        return [
            'id' => $id,
            'category' => 'pc',
            'label' => $label,
            'status' => self::STATUS_ERROR,
            'detail' => "실행 권한 없음 ({$beforeMode}) — 자동 복구 실패",
            'remediation' => "이 파일에 실행 권한을 부여하세요:\n  chmod 755 {$path}\n파일 소유자가 아니면 앞에 sudo 를 붙이세요.",
        ];
    }

    /**
     * pub.key 파일 존재 — KCP CLI 가 결제 응답 복호화에 사용
     */
    private function checkPubKey(): array
    {
        $path = $this->binDir . '/pub.key';

        if (! file_exists($path)) {
            return [
                'id' => 'pub_key',
                'category' => 'pc',
                'label' => 'KCP 공개키 (pub.key)',
                'status' => self::STATUS_ERROR,
                'detail' => "pub.key 파일이 없습니다: {$path}",
                'remediation' => 'KCP 에서 제공받은 pub.key 파일을 bin/ 에 복사하세요. (테스트 환경은 플러그인 기본 제공)',
            ];
        }

        return [
            'id' => 'pub_key',
            'category' => 'pc',
            'label' => 'KCP 공개키 (pub.key)',
            'status' => self::STATUS_OK,
            'detail' => '파일 존재',
        ];
    }

    /**
     * PHP SOAP 확장 — 모바일 SmartPhone Pay 의 approval_key 획득에 필수
     */
    private function checkSoapExtension(): array
    {
        if (extension_loaded('soap') && class_exists(\SoapClient::class)) {
            return [
                'id' => 'soap_extension',
                'category' => 'mobile',
                'label' => 'PHP SOAP 확장',
                'status' => self::STATUS_OK,
                'detail' => 'SoapClient 사용 가능',
            ];
        }

        return [
            'id' => 'soap_extension',
            'category' => 'mobile',
            'label' => 'PHP SOAP 확장',
            'status' => self::STATUS_ERROR,
            'detail' => '모바일 결제 (SmartPhone Pay) 가 동작하지 않습니다.',
            'remediation' => "PHP SOAP 확장을 설치하고 PHP-FPM 을 재시작하세요:\n"
                . "Ubuntu/Debian: sudo apt install php8.x-soap && sudo systemctl restart php8.x-fpm\n"
                . "RHEL/CentOS:   sudo yum install php-soap && sudo systemctl restart php-fpm\n"
                . "확인: php -m | grep soap",
        ];
    }

    /**
     * WSDL 파일 — bin/ 에 KCPPaymentService.wsdl, real_KCPPaymentService.wsdl 필요
     */
    private function checkWsdlFile(): array
    {
        $wsdlFiles = ['KCPPaymentService.wsdl', 'real_KCPPaymentService.wsdl'];
        $missing = [];
        foreach ($wsdlFiles as $f) {
            if (! file_exists($this->binDir . '/' . $f)) {
                $missing[] = $f;
            }
        }

        if (! empty($missing)) {
            return [
                'id' => 'wsdl_file',
                'category' => 'mobile',
                'label' => 'WSDL 파일',
                'status' => self::STATUS_ERROR,
                'detail' => '누락: ' . implode(', ', $missing),
                'remediation' => 'KCP 에서 제공받은 WSDL 파일을 bin/ 에 복사하세요. (테스트/라이브 별로 두 개)',
            ];
        }

        return [
            'id' => 'wsdl_file',
            'category' => 'mobile',
            'label' => 'WSDL 파일',
            'status' => self::STATUS_OK,
            'detail' => '테스트/라이브 WSDL 모두 존재',
        ];
    }
}
