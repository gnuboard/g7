<?php

namespace App\Extension\Helpers;

use Illuminate\Support\Facades\Log;

/**
 * Windows 파일 핸들(잠금) 감지 및 해제 헬퍼
 *
 * 확장 업데이트 시 디렉토리 이동(rename)이 실패하면,
 * 해당 디렉토리를 잠그고 있는 프로세스를 감지하고 종료할 수 있습니다.
 *
 * 탐지 전략 (우선순위 순):
 * 1. WMI CommandLine + 프로세스 모듈 검색 — 디렉토리 핸들 잠금 (Node.js 워처, IDE 등)
 * 2. Restart Manager API — 파일 수준 잠금
 * 3. handle.exe (Sysinternals) — 폴백
 */
class FileHandleHelper
{
    /**
     * 현재 PHP 프로세스의 PID (종료 대상에서 제외)
     *
     * @return int
     */
    private static function currentPid(): int
    {
        return getmypid() ?: 0;
    }

    /**
     * Windows 환경인지 확인합니다.
     *
     * @return bool
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * 디렉토리를 잠그고 있는 프로세스 목록을 반환합니다.
     *
     * 3단계 전략으로 탐지합니다:
     * 1. WMI CommandLine + 모듈 검색 (디렉토리 핸들 — Node.js, IDE 등)
     * 2. Restart Manager API (파일 수준 잠금)
     * 3. handle.exe 폴백
     *
     * @param  string  $directoryPath  잠금 확인할 디렉토리 경로
     * @param  int  $maxFiles  Restart Manager에서 검사할 최대 파일 수
     * @param  \Closure|null  $onOutput  진단 출력 콜백 (string $message)
     * @return array<int, array{pid: int, name: string, app: string}> 프로세스 목록
     */
    public static function findLockingProcesses(string $directoryPath, int $maxFiles = 30, ?\Closure $onOutput = null): array
    {
        $output = $onOutput ?? function () {};

        if (! self::isWindows()) {
            $output('[lock-detect] Windows 환경이 아니므로 잠금 감지를 건너뜁니다.');

            return [];
        }

        // 존재하지 않는 디렉토리는 잠금이 있을 수 없음 → WMI 전체 스캔 회피
        // (Get-Process 의 Modules 열거는 수백 프로세스에 접근 핸들을 열어 20초+ 소요 가능)
        if (! is_dir($directoryPath)) {
            $output('[lock-detect] 디렉토리가 존재하지 않으므로 잠금 감지를 건너뜁니다: '.$directoryPath);

            return [];
        }

        // 파일이 전혀 없는 디렉토리도 파일 수준 잠금이 있을 수 없음
        // 디렉토리 핸들 자체를 잠그는 드문 케이스는 Restart Manager 로는 감지 불가하고
        // WMI 모듈 검색의 비용이 압도적으로 크므로 빠른 경로로 처리한다.
        $files = self::sampleFiles($directoryPath, $maxFiles);
        if (empty($files)) {
            $output('[lock-detect] 디렉토리가 비어 있으므로 잠금 감지를 건너뜁니다: '.$directoryPath);

            return [];
        }

        $allProcesses = [];

        // 1순위: WMI CommandLine + 프로세스 모듈 검색
        $output('[lock-detect] 1단계: WMI 프로세스 CommandLine + 모듈 검색...');
        $wmiResults = self::detectViaWmi($directoryPath, $onOutput);
        foreach ($wmiResults as $proc) {
            $allProcesses[$proc['pid']] = $proc;
        }

        // 2순위: Restart Manager API (파일 수준 잠금)
        $output('[lock-detect] 2단계: Restart Manager API ('.count($files).'개 파일)...');
        $rmResults = self::detectViaRestartManager($files, $onOutput);
        foreach ($rmResults as $proc) {
            if (! isset($allProcesses[$proc['pid']])) {
                $allProcesses[$proc['pid']] = $proc;
            }
        }

        // 3순위: handle.exe 폴백
        // 빈 결과 = "잠금 없음" 이라는 정상 응답이므로 폴백을 호출하지 않는다.
        // (handle.exe 는 Sysinternals 외부 도구이고, Windows Store App Execution Alias 와
        //  충돌하는 경우 무한 대기하므로 명시적으로 사용 가능한 경우에만 호출.)

        // 현재 PHP 프로세스 제외
        $currentPid = self::currentPid();
        unset($allProcesses[$currentPid]);

        $filtered = array_values($allProcesses);

        if (! empty($filtered)) {
            $output('[lock-detect] 감지된 잠금 프로세스: '.count($filtered).'개');
            foreach ($filtered as $proc) {
                $output("[lock-detect]   PID {$proc['pid']}: {$proc['name']} ({$proc['app']})");
            }
        } else {
            $output('[lock-detect] 잠금 프로세스를 감지하지 못했습니다.');
        }

        return $filtered;
    }

    /**
     * 잠금 프로세스를 종료합니다.
     *
     * @param  array<int, array{pid: int, name: string, app: string}>  $processes  종료할 프로세스 목록
     * @return array{killed: array, failed: array} 종료 결과
     */
    public static function killProcesses(array $processes): array
    {
        $killed = [];
        $failed = [];

        foreach ($processes as $proc) {
            $pid = $proc['pid'];
            $name = $proc['name'];

            // 시스템 프로세스 보호
            if (self::isSystemProcess($name)) {
                $failed[] = array_merge($proc, ['reason' => '시스템 프로세스는 종료할 수 없습니다']);

                continue;
            }

            if (self::isWindows()) {
                $result = self::killWindowsProcess($pid);
            } else {
                $result = self::killUnixProcess($pid);
            }

            if ($result) {
                $killed[] = $proc;
            } else {
                $failed[] = array_merge($proc, ['reason' => '프로세스 종료 실패']);
            }
        }

        return ['killed' => $killed, 'failed' => $failed];
    }

    /**
     * 디렉토리 잠금을 감지하고 해제합니다.
     *
     * @param  string  $directoryPath  잠금 해제할 디렉토리 경로
     * @param  \Closure|null  $onOutput  출력 콜백 (string $message)
     * @return bool 잠금 해제 성공 여부 (잠금이 없었거나, 모두 해제된 경우 true)
     */
    public static function releaseLocks(string $directoryPath, ?\Closure $onOutput = null): bool
    {
        $output = $onOutput ?? function () {};

        $output('');
        $output('🔍 파일 잠금 감지 시작: '.$directoryPath);

        $processes = self::findLockingProcesses($directoryPath, 30, $onOutput);

        if (empty($processes)) {
            // 해제할 잠금이 없으므로 호출자는 안전하게 진행 가능
            return true;
        }

        $output('');
        $output('⚠️  파일 잠금 프로세스 감지됨 ('.count($processes).'개):');
        foreach ($processes as $proc) {
            $output("   - PID {$proc['pid']}: {$proc['name']} ({$proc['app']})");
        }
        $output('');
        $output('🔧 잠금 프로세스 종료 시도...');

        $result = self::killProcesses($processes);

        foreach ($result['killed'] as $proc) {
            $output("   ✅ PID {$proc['pid']} ({$proc['name']}) 종료 완료");
        }

        foreach ($result['failed'] as $proc) {
            $output("   ❌ PID {$proc['pid']} ({$proc['name']}) 종료 실패: {$proc['reason']}");
        }

        if (! empty($result['failed'])) {
            $output('');
            $output('❌ 일부 프로세스를 종료하지 못했습니다. 수동으로 종료 후 재시도하세요.');
            Log::warning('파일 잠금 해제 실패', [
                'directory' => $directoryPath,
                'failed' => $result['failed'],
            ]);

            return false;
        }

        // 프로세스 종료 후 핸들 해제 대기
        $output('');
        $output('⏳ 핸들 해제 대기 중 (500ms)...');
        usleep(500_000); // 500ms
        $output('✅ 잠금 해제 완료 — 디렉토리 이동 재시도합니다.');
        $output('');

        Log::info('파일 잠금 해제 완료', [
            'directory' => $directoryPath,
            'killed' => $result['killed'],
        ]);

        return true;
    }

    // ========================================================================
    // 탐지 전략 1: WMI CommandLine + 프로세스 모듈 검색
    // ========================================================================

    /**
     * WMI로 해당 디렉토리 경로를 참조하는 프로세스를 탐지합니다.
     *
     * Get-CimInstance Win32_Process의 CommandLine에 디렉토리 경로가 포함된 프로세스를 찾습니다.
     *
     * 과거 버전은 Get-Process 의 Modules 컬렉션(로드된 DLL) 까지 스캔했지만,
     * 이는 모든 프로세스에 접근 핸들을 열어 DLL 테이블을 열거하므로
     * 활성 프로세스가 수백 개인 Windows 환경에서 20초+ 블로킹을 유발합니다.
     * Modules 스캔은 Node.js native addon 등 드문 케이스만 감지하므로
     * 비용 대비 효과가 낮아 CommandLine 검색만 유지합니다.
     * (Node 워처, IDE, 파일 탐색기 등 실사용 케이스는 CommandLine 으로 충분히 감지됩니다.)
     *
     * @param  string  $directoryPath  디렉토리 경로
     * @param  \Closure|null  $onOutput  진단 출력 콜백
     * @return array<int, array{pid: int, name: string, app: string}> 프로세스 목록
     */
    private static function detectViaWmi(string $directoryPath, ?\Closure $onOutput = null): array
    {
        $output = $onOutput ?? function () {};

        if (! function_exists('exec')) {
            $output('[WMI] exec() 함수 사용 불가');

            return [];
        }

        $normalizedPath = str_replace('/', '\\', $directoryPath);
        // PowerShell에서 안전하게 사용할 수 있도록 이스케이프
        $escapedPath = str_replace("'", "''", $normalizedPath);

        // 간결한 인라인 PowerShell — 임시 파일 불필요
        // CommandLine 검색만 수행 (Modules 스캔은 수백 프로세스 핸들 오픈으로 인해 제거됨)
        $psCommand = implode('; ', [
            '$ErrorActionPreference = "SilentlyContinue"',
            '$p = "'.addcslashes($escapedPath, '"').'"',
            '$pl = $p.ToLower()',
            '$r = @{}',
            'Get-CimInstance Win32_Process | ForEach-Object { if ($_.CommandLine) { $cl = $_.CommandLine.ToLower().Replace("/","\\"); if ($cl.Contains($pl)) { $r[$_.ProcessId] = "$($_.ProcessId)|$($_.Name)|$($_.CommandLine.Substring(0,[Math]::Min(120,$_.CommandLine.Length)))" } } }',
            'foreach ($v in $r.Values) { Write-Output $v }',
        ]);

        // exec() 대신 proc_open 사용 — PHPUnit 환경에서 상속된 부모 파이프로 인한
        // PowerShell 하위 프로세스 블로킹을 회피한다.
        [$cmdOutput, $exitCode] = self::runCommandWithTimeout(
            'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '.escapeshellarg($psCommand),
            30
        );

        $output("[WMI] PowerShell exit code: {$exitCode}, 출력: ".count($cmdOutput).'줄');

        if (! empty($cmdOutput)) {
            foreach ($cmdOutput as $line) {
                $output("[WMI] → {$line}");
            }
        }

        return self::parseProcessOutput($cmdOutput);
    }

    // ========================================================================
    // 탐지 전략 2: Restart Manager API (파일 수준 잠금)
    // ========================================================================

    /**
     * Windows Restart Manager API를 사용하여 잠금 프로세스를 탐지합니다.
     *
     * PowerShell에서 인라인 C# 코드로 Restart Manager API를 호출합니다.
     * 외부 도구(handle.exe 등)가 필요 없으며, Windows Vista+ 내장 API입니다.
     * 파일 수준 잠금만 감지 가능 (디렉토리 핸들은 감지 불가).
     *
     * @param  string[]  $filePaths  검사할 파일 경로 목록
     * @param  \Closure|null  $onOutput  진단 출력 콜백
     * @return array<int, array{pid: int, name: string, app: string}> 프로세스 목록
     */
    private static function detectViaRestartManager(array $filePaths, ?\Closure $onOutput = null): array
    {
        $output = $onOutput ?? function () {};

        if (! function_exists('exec')) {
            $output('[RestartManager] exec() 함수 사용 불가');

            return [];
        }

        $tempScript = tempnam(sys_get_temp_dir(), 'g7lock_');
        $scriptPath = $tempScript.'.ps1';
        rename($tempScript, $scriptPath);

        $escapedFiles = array_map(
            fn ($f) => "'".str_replace("'", "''", str_replace('/', '\\', $f))."'",
            $filePaths
        );
        $fileListPs = '@('.implode(',', $escapedFiles).')';

        $psScript = self::getRestartManagerScript($fileListPs);
        file_put_contents($scriptPath, $psScript);

        $stderrPath = $scriptPath.'.err';

        try {
            [$cmdOutput, $exitCode, $stderrCaptured] = self::runCommandWithTimeout(
                'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '.escapeshellarg($scriptPath),
                30,
                true
            );

            if ($stderrCaptured !== '') {
                @file_put_contents($stderrPath, $stderrCaptured);
            }

            $output("[RestartManager] exit code: {$exitCode}, 출력: ".count($cmdOutput).'줄');

            if (file_exists($stderrPath)) {
                $stderr = trim(file_get_contents($stderrPath));
                if (! empty($stderr)) {
                    $lines = array_slice(explode("\n", $stderr), 0, 3);
                    foreach ($lines as $line) {
                        $output('[RestartManager] stderr: '.trim($line));
                    }
                }
            }

            return self::parseProcessOutput($cmdOutput);
        } finally {
            @unlink($scriptPath);
            @unlink($stderrPath);
        }
    }

    /**
     * Restart Manager API를 호출하는 PowerShell 스크립트를 생성합니다.
     *
     * @param  string  $fileListPs  PowerShell 배열 형식의 파일 목록
     * @return string PowerShell 스크립트 내용
     */
    private static function getRestartManagerScript(string $fileListPs): string
    {
        return <<<'PSHEADER'
$ErrorActionPreference = 'SilentlyContinue'

Add-Type -TypeDefinition @"
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.InteropServices.ComTypes;

public class RmLockDetector
{
    [StructLayout(LayoutKind.Sequential)]
    struct RM_UNIQUE_PROCESS
    {
        public int dwProcessId;
        public FILETIME ProcessStartTime;
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    struct RM_PROCESS_INFO
    {
        public RM_UNIQUE_PROCESS Process;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 256)]
        public string strAppName;
        [MarshalAs(UnmanagedType.ByValTStr, SizeConst = 64)]
        public string strServiceShortName;
        public int ApplicationType;
        public uint AppStatus;
        public uint TSSessionId;
        [MarshalAs(UnmanagedType.Bool)]
        public bool bRestartable;
    }

    [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)]
    static extern int RmStartSession(out uint pSessionHandle, int dwSessionFlags, string strSessionKey);

    [DllImport("rstrtmgr.dll")]
    static extern int RmEndSession(uint pSessionHandle);

    [DllImport("rstrtmgr.dll", CharSet = CharSet.Unicode)]
    static extern int RmRegisterResources(uint pSessionHandle, uint nFiles, string[] rgsFilenames, uint nApplications, [In] RM_UNIQUE_PROCESS[] rgApplications, uint nServices, string[] rgsServiceNames);

    [DllImport("rstrtmgr.dll")]
    static extern int RmGetList(uint dwSessionHandle, out uint pnProcInfoNeeded, ref uint pnProcInfo, [In, Out] RM_PROCESS_INFO[] rgAffectedApps, ref uint lpdwRebootReasons);

    public static string[] FindLockingProcesses(string[] filePaths)
    {
        uint handle;
        string key = Guid.NewGuid().ToString();
        int res = RmStartSession(out handle, 0, key);
        if (res != 0) return new string[0];

        try
        {
            res = RmRegisterResources(handle, (uint)filePaths.Length, filePaths, 0, null, 0, null);
            if (res != 0) return new string[0];

            uint pnProcInfoNeeded = 0;
            uint pnProcInfo = 0;
            uint lpdwRebootReasons = 0;
            res = RmGetList(handle, out pnProcInfoNeeded, ref pnProcInfo, null, ref lpdwRebootReasons);

            if (pnProcInfoNeeded == 0) return new string[0];

            RM_PROCESS_INFO[] processInfo = new RM_PROCESS_INFO[pnProcInfoNeeded];
            pnProcInfo = pnProcInfoNeeded;
            res = RmGetList(handle, out pnProcInfoNeeded, ref pnProcInfo, processInfo, ref lpdwRebootReasons);
            if (res != 0) return new string[0];

            var results = new List<string>();
            var seen = new HashSet<int>();
            for (int i = 0; i < pnProcInfo; i++)
            {
                int pid = processInfo[i].Process.dwProcessId;
                if (seen.Contains(pid)) continue;
                seen.Add(pid);
                try
                {
                    var proc = Process.GetProcessById(pid);
                    results.Add(pid + "|" + proc.ProcessName + "|" + processInfo[i].strAppName);
                }
                catch { }
            }
            return results.ToArray();
        }
        finally
        {
            RmEndSession(handle);
        }
    }
}
"@

PSHEADER
            ."\$files = {$fileListPs}\n"
            .<<<'PSFOOTER'
$results = [RmLockDetector]::FindLockingProcesses($files)
foreach ($r in $results) {
    Write-Output $r
}
PSFOOTER;
    }

    // ========================================================================
    // 탐지 전략 3: handle.exe (Sysinternals) 폴백
    // ========================================================================

    /**
     * handle.exe (Sysinternals)를 사용하여 잠금 프로세스를 탐지합니다.
     *
     * @param  string  $directoryPath  디렉토리 경로
     * @param  \Closure|null  $onOutput  진단 출력 콜백
     * @return array<int, array{pid: int, name: string, app: string}> 프로세스 목록
     */
    private static function detectViaHandle(string $directoryPath, ?\Closure $onOutput = null): array
    {
        $logOutput = $onOutput ?? function () {};

        if (! function_exists('exec')) {
            $logOutput('[handle.exe] exec() 함수 사용 불가');

            return [];
        }

        // PATH 에 handle.exe 가 있는지 사전 검사 (없는 경우 즉시 skip)
        // - handle.exe 미설치 시 cmd.exe 가 명령을 찾는 데 수십 초가 걸리거나
        //   EULA 다이얼로그로 인해 무한 대기에 빠질 수 있음.
        // - 결과는 정적 캐시하여 같은 프로세스에서 반복 검사 회피.
        static $handleAvailable = null;
        if ($handleAvailable === null) {
            $handleAvailable = self::isCommandAvailable('handle.exe');
        }

        if (! $handleAvailable) {
            $logOutput('[handle.exe] PATH 에서 찾을 수 없어 건너뜁니다.');

            return [];
        }

        $dirPath = str_replace('/', '\\', $directoryPath);
        [$cmdOutput, $exitCode] = self::runCommandWithTimeout(
            'handle.exe -nobanner -accepteula '.escapeshellarg($dirPath),
            5
        );

        if ($exitCode !== 0 || empty($cmdOutput)) {
            $logOutput("[handle.exe] 사용 불가 (exit code: {$exitCode})");

            return [];
        }

        $logOutput('[handle.exe] '.count($cmdOutput).'줄 출력 수신');

        return self::parseHandleOutput($cmdOutput);
    }

    /**
     * Windows PATH 에서 명령어 실행 파일이 발견되는지 빠르게 확인합니다.
     *
     * Windows Store App Execution Alias(`%LOCALAPPDATA%\Microsoft\WindowsApps\*.exe`)는
     * 실제 실행 파일이 아닌 0바이트 stub 으로, 호출 시 Microsoft Store 앱을 실행하려
     * 시도하면서 무한 대기를 유발합니다. PATH 검색 결과에서 이런 경로를 제외합니다.
     *
     * @param  string  $command  명령어 파일명 (예: 'handle.exe')
     */
    private static function isCommandAvailable(string $command): bool
    {
        if (! self::isWindows()) {
            return false;
        }

        // `where` 는 PATH 검색이 빠르며, 미발견 시 1초 이내에 exit code 1 반환
        [$out, $code] = self::runCommandWithTimeout('where '.escapeshellarg($command), 3);

        if ($code !== 0 || empty($out)) {
            return false;
        }

        // Windows Store App Execution Alias 경로 제외
        foreach ($out as $path) {
            $path = trim($path);
            if ($path === '' || stripos($path, '\\Microsoft\\WindowsApps\\') !== false) {
                continue;
            }
            // 0 바이트 파일은 stub 으로 간주
            if (@filesize($path) > 0) {
                return true;
            }
        }

        return false;
    }

    // ========================================================================
    // 파서/유틸리티
    // ========================================================================

    /**
     * 디렉토리 내 파일을 샘플링합니다.
     *
     * @param  string  $directoryPath  디렉토리 경로
     * @param  int  $maxFiles  최대 파일 수
     * @return string[] 파일 경로 목록
     */
    private static function sampleFiles(string $directoryPath, int $maxFiles): array
    {
        if (! is_dir($directoryPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directoryPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
                if (count($files) >= $maxFiles) {
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * "PID|ProcessName|AppName" 형식의 출력을 파싱합니다.
     *
     * @param  string[]  $output  프로세스 출력 라인
     * @return array<int, array{pid: int, name: string, app: string}> 프로세스 목록
     */
    private static function parseProcessOutput(array $output): array
    {
        $processes = [];
        $seen = [];

        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'ERROR:')) {
                continue;
            }

            $parts = explode('|', $line, 3);
            if (count($parts) < 2) {
                continue;
            }

            $pid = (int) $parts[0];
            if ($pid <= 0 || isset($seen[$pid])) {
                continue;
            }

            $seen[$pid] = true;
            $processes[] = [
                'pid' => $pid,
                'name' => $parts[1] ?? 'unknown',
                'app' => $parts[2] ?? $parts[1] ?? 'unknown',
            ];
        }

        return $processes;
    }

    /**
     * handle.exe 출력을 파싱합니다.
     *
     * @param  string[]  $output  handle.exe 출력
     * @return array<int, array{pid: int, name: string, app: string}> 프로세스 목록
     */
    private static function parseHandleOutput(array $output): array
    {
        $processes = [];
        $seen = [];

        foreach ($output as $line) {
            if (preg_match('/^(.+?)\s+pid:\s*(\d+)\s/', $line, $matches)) {
                $name = trim($matches[1]);
                $pid = (int) $matches[2];

                if ($pid <= 0 || isset($seen[$pid])) {
                    continue;
                }

                $seen[$pid] = true;
                $processes[] = [
                    'pid' => $pid,
                    'name' => $name,
                    'app' => $name,
                ];
            }
        }

        return $processes;
    }

    /**
     * 외부 명령을 타임아웃과 함께 실행합니다 (부모 파이프 비상속).
     *
     * exec()/shell_exec() 는 내부적으로 `sh -c` 또는 `cmd.exe /c` 를 통해 자식
     * 프로세스를 스폰하며, 부모 PHP 프로세스의 stdin/stdout/stderr 핸들을 그대로
     * 상속시킵니다. PHPUnit 과 같이 stdout 을 자체 파이프로 모니터링하는 환경에서는
     * 자식 프로세스가 상속된 파이프에 블로킹 쓰기를 수행할 때 무한 대기가 발생합니다.
     *
     * proc_open 으로 자체 파이프를 명시적으로 열면 상속을 차단하여 이 문제를 회피합니다.
     * 또한 poll 루프에서 deadline 을 초과하면 강제 종료(TerminateProcess)합니다.
     *
     * @param  string  $command  실행할 명령 문자열
     * @param  int  $timeoutSeconds  최대 실행 시간(초)
     * @param  bool  $returnStderr  true 면 세 번째 요소로 stderr 문자열을 함께 반환
     * @return array{0: string[], 1: int, 2?: string} [stdout 라인 배열, exit code, (stderr 문자열)]
     */
    private static function runCommandWithTimeout(string $command, int $timeoutSeconds, bool $returnStderr = false): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, null, [
            'bypass_shell' => false,
            'create_new_console' => false,
        ]);

        if (! is_resource($process)) {
            return $returnStderr ? [[], -1, ''] : [[], -1];
        }

        // stdin 즉시 닫기 (하위 프로세스가 입력 대기 방지)
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            // 100ms 씩 poll (Windows 는 select() 정확도 한계)
            $ready = @stream_select($read, $write, $except, 0, 100_000);

            if ($ready > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk !== false && $chunk !== '') {
                        if ($stream === $pipes[1]) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
            }
        }

        // 남은 출력 흡수
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($timedOut) {
            // Windows: taskkill /F /T 로 자식 트리까지 종료
            $status = proc_get_status($process);
            if (! empty($status['pid'])) {
                if (PHP_OS_FAMILY === 'Windows') {
                    @exec('taskkill /F /T /PID '.$status['pid'].' 2>NUL');
                } else {
                    @posix_kill($status['pid'], 9);
                }
            }
            proc_terminate($process, 9);
        }

        $exitCode = proc_close($process);

        $lines = $stdout === '' ? [] : preg_split("/\r\n|\n|\r/", rtrim($stdout, "\r\n"));
        if ($lines === false) {
            $lines = [];
        }

        if ($returnStderr) {
            return [$lines, $exitCode, $stderr];
        }

        return [$lines, $exitCode];
    }

    /**
     * 시스템 프로세스 여부를 확인합니다.
     *
     * @param  string  $processName  프로세스명
     * @return bool 시스템 프로세스이면 true
     */
    private static function isSystemProcess(string $processName): bool
    {
        $systemProcesses = [
            'System',
            'svchost',
            'csrss',
            'wininit',
            'winlogon',
            'services',
            'lsass',
            'smss',
            'explorer',
            'dwm',
            'RuntimeBroker',
            'SearchHost',
            'StartMenuExperienceHost',
        ];

        return in_array($processName, $systemProcesses, true);
    }

    /**
     * Windows 프로세스를 종료합니다.
     *
     * @param  int  $pid  프로세스 ID
     * @return bool 종료 성공 여부
     */
    private static function killWindowsProcess(int $pid): bool
    {
        if (! function_exists('exec')) {
            return false;
        }

        $output = [];
        $exitCode = 0;
        exec("taskkill /PID {$pid} /F 2>NUL", $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Unix 프로세스를 종료합니다.
     *
     * @param  int  $pid  프로세스 ID
     * @return bool 종료 성공 여부
     */
    private static function killUnixProcess(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 9); // SIGKILL
        }

        if (function_exists('exec')) {
            $output = [];
            $exitCode = 0;
            exec("kill -9 {$pid} 2>/dev/null", $output, $exitCode);

            return $exitCode === 0;
        }

        return false;
    }
}
