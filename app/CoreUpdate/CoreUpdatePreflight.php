<?php

namespace App\CoreUpdate;

use App\Extension\Helpers\StorageLinkHelper;
use App\Services\CoreUpdateService;
use App\Support\ExecutionEnvironment;
use Illuminate\Support\Facades\File;

/**
 * 웹 코어 업데이트의 사전 진단 (D26 — 쓰기 가능성 진단 분기).
 *
 * "웹 업데이트가 되려면 777 이나 전면 소유자 변경이 필요한 것 아닌가" 에 대한 답이
 * 이 클래스다. **권한을 맞추지 않고 진단으로 분기한다** — 쓰기가 되면 진행하고,
 * 안 되면 **적용을 시작하지 않고** 어떤 경로가 막혔는지와 CLI 절차를 안내한다.
 * 777 · 자동 chown 은 하지 않는다 (§7 R39 · R41 과 정면 충돌한다).
 *
 * 검사 대상이 `ensureWritableDirectories()` 의 기본 목록보다 넓은 것이 이 진단의
 * 핵심이다 — 그 목록에는 `storage/*`·`bootstrap/cache`·`vendor`·확장 디렉토리만 있고
 * **코어 코드 경로(`app/`·`config/`·`routes/`·`public/` …)가 없다.** 웹이 실제로
 * 덮어쓰는 것은 그 경로들이므로, 그것을 재지 않는 진단은 「통과했는데 적용에서
 * 실패」를 만든다.
 *
 * @since 7.1.0
 */
class CoreUpdatePreflight
{
    /**
     * 백업 + 전개본이 함께 놓일 여유 공간의 안전 계수.
     *
     * 백업(현재 트리 사본) · `_pending`(신 버전) 둘이 동시에 존재하는 구간이 있으므로
     * 현재 트리 크기의 배수를 요구한다. 정확한 예측이 목적이 아니라 **명백히 모자란
     * 상태에서 착수하지 않는 것**이 목적이다.
     */
    public const DISK_SAFETY_FACTOR = 2.5;

    /** 크기 추정을 위해 훑는 최대 항목 수 (대형 트리에서 진단이 요청 시간을 먹지 않도록). */
    public const SIZE_SCAN_LIMIT = 40000;

    /** 쓰기 시험 파일 이름 접두사 (판정 직후 지운다). */
    public const WRITE_PROBE_PREFIX = '.g7-write-probe-';

    /** 하위 항목 훑기 시간 가운데 쓰기 가능성 훑기가 쓰는 비율 (나머지는 크기 추정). */
    public const WRITABLE_SCAN_SHARE = 0.6;

    /** 훑기 시간을 정한 시각 (몫을 나누는 기준). null 이면 시간 제한 없음. */
    private ?float $scanStartedAt = null;

    /**
     * 하위 항목 훑기에 쓸 시간(초). null 이면 항목 수 상한만 적용한다.
     *
     * 항목 수 상한(`SIZE_SCAN_LIMIT`)만으로는 요청 시간을 지키지 못한다 — 항목 하나를
     * 재는 비용이 서버마다 수십 배 다르다(Windows 에서는 같은 상한을 훑는 데 수 분이
     * 걸리기도 한다). 이 훑기의 목적은 전수 확인이 아니라 명백한 차단을 착수 전에
     * 드러내는 것이므로, 시간이 다하면 거기까지의 판정으로 멈춘다.
     */
    private ?float $scanSeconds = null;

    /** @var bool 직전 진단에서 훑기가 시간 제한으로 중간에 멈췄는지 */
    private bool $scanTruncated = false;

    /**
     * @param  CoreUpdateService  $service  코어 업데이트 서비스 (요구사항·경로 검증 소유)
     * @param  \Closure|null  $clock  현재 시각(초, 부동소수) 공급자 — 테스트 주입용
     */
    public function __construct(
        private readonly CoreUpdateService $service,
        private readonly ?\Closure $clock = null,
    ) {}

    /**
     * 하위 항목 훑기에 쓸 시간을 정합니다 (지금부터 센다).
     *
     * 쓰기 가능성 훑기가 앞쪽 6할, 크기 추정이 나머지를 쓴다 — 한쪽이 시간을 다 쓰면
     * 다른 쪽이 0 으로 판정해 여유 공간 검사가 사실상 꺼진다.
     *
     * @param  float|null  $seconds  쓸 시간(초). null 이면 시간 제한 없이 항목 수 상한만 쓴다
     * @return static 같은 인스턴스
     */
    public function limitScanTime(?float $seconds): static
    {
        $this->scanStartedAt = $seconds === null ? null : $this->now();
        $this->scanSeconds = $seconds === null ? null : max(0.0, $seconds);

        return $this;
    }

    /**
     * 직전 진단의 하위 항목 훑기가 시간 제한으로 중간에 멈췄는지.
     *
     * @return bool 멈췄으면 true
     */
    public function scanWasTruncated(): bool
    {
        return $this->scanTruncated;
    }

    /**
     * 사전 진단을 수행합니다.
     *
     * @param  bool  $requiresArchiveExtraction  아카이브 추출이 필요한지 (수동 소스 디렉토리면 false)
     * @param  bool  $deep  하위 항목까지 훑어 쓰기 가능성과 필요 용량을 잴지 (false 면 얕게 본다)
     * @return array{
     *     ok: bool,
     *     requirements: array<string, mixed>,
     *     pending: array<string, mixed>,
     *     writable: array{ok: bool, blocked: list<string>, checked: int},
     *     disk: array{measurable: bool, free_bytes: int|null, required_bytes: int|null, ok: bool},
     *     errors: list<string>,
     * } 진단 결과
     */
    public function run(bool $requiresArchiveExtraction = true, bool $deep = true): array
    {
        $errors = [];
        $this->scanTruncated = false;

        $requirements = $requiresArchiveExtraction
            ? $this->service->checkSystemRequirements()
            : ['valid' => true, 'errors' => [], 'available_methods' => []];

        if (! ($requirements['valid'] ?? false)) {
            foreach ((array) ($requirements['errors'] ?? []) as $error) {
                $errors[] = (string) $error;
            }
        }

        $pending = $this->service->validatePendingPath();

        if (! ($pending['valid'] ?? false)) {
            foreach ((array) ($pending['errors'] ?? []) as $error) {
                $errors[] = (string) $error;
            }
        }

        $writable = $this->checkWritableTargets($deep);

        if (! $writable['ok']) {
            $errors[] = (string) __('settings.core_update.web.preflight_not_writable', [
                'paths' => implode(', ', array_slice($writable['blocked'], 0, 10)),
            ]);
        }

        $disk = $this->checkDiskSpace($deep);

        if (! $disk['ok']) {
            $errors[] = (string) __('settings.core_update.web.preflight_low_disk', [
                'free' => $this->humanBytes($disk['free_bytes'] ?? 0),
                'required' => $this->humanBytes($disk['required_bytes'] ?? 0),
            ]);
        }

        return [
            'ok' => $errors === [],
            'requirements' => $requirements,
            'pending' => $pending,
            'writable' => $writable,
            'disk' => $disk,
            'errors' => $errors,
        ];
    }

    /**
     * 적용 대상 전 경로의 쓰기 가능성을 확인합니다 (D26 ①).
     *
     * 대상은 `update.targets` 전부와 백업·전개가 쓰는 런타임 디렉토리다. 존재하지 않는
     * 항목은 **부모 디렉토리**의 쓰기 가능성으로 판정한다 — 신 버전이 새로 만드는 파일이
     * 그 자리에 놓이기 때문이다.
     *
     * 링크·정션은 검사하지 않는다 — 업데이트·백업·되돌리기는 링크를 따라가 덮지 않고
     * 링크째 다루므로, 링크 대상의 쓰기 가능성은 이 진단의 질문이 아니다.
     *
     * @param  bool  $deep  하위 항목까지 훑을지 (착수 화면 요약은 얕게 본다 — 아래 사유)
     * @return array{ok: bool, blocked: list<string>, checked: int, truncated: bool} 판정 결과
     */
    public function checkWritableTargets(bool $deep = true): array
    {
        $blocked = [];
        $checked = 0;

        // 순회 예산은 **후보 전체가 공유한다.** 후보마다 새로 세면 상한이 후보 수만큼
        // 곱해져(현재 40여 개) 「진단이 요청 시간을 먹지 않게 한다」는 상한의 목적이
        // 사라진다 — 이 진단은 착수 화면이 열릴 때마다 도는 경로다.
        $budget = self::SIZE_SCAN_LIMIT;
        $deadline = $this->deadlineAt(self::WRITABLE_SCAN_SHARE);

        foreach ($this->writableCandidates() as $relative) {
            $checked++;
            $path = base_path($relative);

            if (StorageLinkHelper::isLinkOrJunction($path)) {
                continue;
            }

            if (! file_exists($path)) {
                // 신 버전이 새로 만드는 항목 — 그 자리를 만들 수 있는지는 부모가 정한다.
                $parent = dirname($path);

                if (is_dir($parent) && ! $this->isWritablePath($parent)) {
                    $blocked[] = $relative;
                }

                continue;
            }

            if (! $this->isWritablePath($path)) {
                $blocked[] = $relative;

                continue;
            }

            // 디렉토리는 그 안의 항목이 교체되므로 자기 자신의 쓰기 비트만으로는 부족하다 —
            // 3-way 적용이 실제로 건드리는 것은 **하위 파일**이다.
            if ($deep && is_dir($path)) {
                $unwritableChild = $this->firstUnwritableChild($path, $budget, $deadline);

                if ($unwritableChild !== null) {
                    $blocked[] = $unwritableChild;
                }
            }
        }

        return [
            'ok' => $blocked === [],
            'blocked' => array_values(array_unique($blocked)),
            'checked' => $checked,
            'truncated' => $this->scanTruncated,
        ];
    }

    /**
     * 여유 공간을 확인합니다.
     *
     * `disk_free_space` 는 하드닝 프로필의 표준 차단 대상이라 막힐 수 있다. 그때는
     * **측정 불가**로 두고 통과시킨다 — 잴 수 없는 것을 실패로 접으면 그 호스팅에서는
     * 업데이트가 영영 시작되지 않는다 (0% 로 표시하지 않는 A-8 규율과 같은 판단).
     *
     * @param  bool  $deep  현재 트리 크기를 훑어 필요 용량까지 잴지 (false 면 여유 공간만 잰다)
     * @return array{measurable: bool, free_bytes: int|null, required_bytes: int|null, ok: bool} 판정 결과
     */
    public function checkDiskSpace(bool $deep = true): array
    {
        if (! ExecutionEnvironment::hasFunction('disk_free_space')) {
            return ['measurable' => false, 'free_bytes' => null, 'required_bytes' => null, 'ok' => true];
        }

        $free = @disk_free_space(storage_path());

        if ($free === false) {
            return ['measurable' => false, 'free_bytes' => null, 'required_bytes' => null, 'ok' => true];
        }

        if (! $deep) {
            // 요구량은 현재 트리를 훑어야 나온다 — 얕은 모드에서는 재지 않고 여유 공간만
            // 알린다. 실제 차단은 착수 1단계가 한다.
            return ['measurable' => true, 'free_bytes' => (int) $free, 'required_bytes' => null, 'ok' => true];
        }

        $required = (int) ceil($this->estimateTreeSize($this->deadlineAt(1.0)) * self::DISK_SAFETY_FACTOR);

        return [
            'measurable' => true,
            'free_bytes' => (int) $free,
            'required_bytes' => $required,
            'ok' => $required === 0 || (int) $free >= $required,
        ];
    }

    /**
     * 쓰기 가능성을 검사할 경로 목록.
     *
     * @return list<string> base_path() 상대 경로
     */
    protected function writableCandidates(): array
    {
        $targets = array_values(array_filter(array_map(
            static fn ($t): string => trim((string) $t),
            (array) config('app.update.targets', []),
        )));

        // 백업·전개·캐시가 쓰는 런타임 경로. 적용 대상은 아니지만 여기에 쓰지 못하면
        // 되돌리기용 백업 자체를 만들 수 없어 D10(자동 되돌리기)이 성립하지 않는다.
        //
        // `vendor` 는 `update.targets` 에 없지만(별도 파이프라인 산출물이라 3-way 적용
        // 대상이 아니다) **웹 흐름이 실제로 쓰는 경로**다 — `stepVendorCopy` 가 통째로
        // 덮어쓰고, 되돌리기도 `backup_only` 목록을 그리로 복원한다. 빠뜨리면 D26 이
        // 막으려던 「진단은 통과했는데 적용에서 실패」가 그대로 재현되고, 그때는 이미
        // 파일 적용이 끝난 뒤라 되돌리기까지 부분 실패한다.
        $runtime = array_merge(
            ['storage', 'bootstrap/cache'],
            array_values(array_filter(array_map(
                static fn ($t): string => trim((string) $t),
                (array) config('app.update.backup_only', []),
            ))),
        );

        return array_values(array_unique(array_merge($targets, $runtime)));
    }

    /**
     * 디렉토리 하위에서 쓰기 불가한 첫 항목을 찾습니다.
     *
     * 전수 순회는 대형 트리(`public/build`·`resources`)에서 요청 시간을 먹으므로 항목 수와
     * 시간 두 가지 상한을 둔다. 목적은 「전부 확인했다」가 아니라 **명백한 차단을 착수
     * 전에 드러내는 것**이다.
     *
     * @param  string  $directory  검사할 디렉토리 절대 경로
     * @param  int  $budget  남은 순회 예산 (후보 전체가 공유 — 참조로 감소한다)
     * @param  float|null  $deadline  훑기 마감 시각 (null 이면 항목 수 상한만)
     * @return string|null 쓰기 불가 항목의 상대 경로 (없으면 null)
     */
    private function firstUnwritableChild(string $directory, int &$budget, ?float $deadline = null): ?string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        foreach ($this->walk($directory) as $path) {
            if (--$budget < 0) {
                return null;
            }

            if ($deadline !== null && $this->now() >= $deadline) {
                // 남은 후보도 훑지 않는다 — 예산을 비워 다음 후보가 곧바로 멈추게 한다.
                $this->scanTruncated = true;
                $budget = 0;

                return null;
            }

            if (! $this->isWritablePath($path)) {
                return str_replace($base, '', $path);
            }
        }

        return null;
    }

    /**
     * 현재 트리의 대략적인 크기를 잽니다 (여유 공간 요구량 산정용).
     *
     * @param  float|null  $deadline  훑기 마감 시각 (null 이면 항목 수 상한만)
     * @return int 바이트 (상한까지만 훑은 근사값)
     */
    private function estimateTreeSize(?float $deadline = null): int
    {
        $total = 0;
        $scanned = 0;

        foreach ($this->writableCandidates() as $relative) {
            $path = base_path($relative);

            if (StorageLinkHelper::isLinkOrJunction($path)) {
                continue;
            }

            if (is_file($path)) {
                $total += (int) @filesize($path);

                continue;
            }

            if (! is_dir($path)) {
                continue;
            }

            foreach ($this->walk($path) as $child) {
                if (++$scanned > self::SIZE_SCAN_LIMIT) {
                    return $total;
                }

                if ($deadline !== null && $this->now() >= $deadline) {
                    $this->scanTruncated = true;

                    return $total;
                }

                if (is_file($child)) {
                    $total += (int) @filesize($child);
                }
            }
        }

        return $total;
    }

    /**
     * 디렉토리를 재귀 순회합니다 (제외 목록 존중, 링크·정션 제외).
     *
     * 링크·정션은 내놓지도 따라 들어가지도 않는다. 업데이트·백업·되돌리기는 링크를 링크째
     * 다루므로 그 대상의 쓰기 가능성은 이 진단의 질문이 아니다. 대상까지 재면 Windows 에서
     * 설치가 만든 `public/storage` 정션(`is_writable()` 이 거짓으로 나온다)을 「쓰기 권한
     * 없음」으로 막고, 백업 안에 링크째 보존된 같은 정션까지 함께 지목한다 — 운영자가
     * 권한을 바꿔도 풀리지 않는 차단이다.
     *
     * @param  string  $directory  시작 디렉토리
     * @return \Generator<int, string> 절대 경로
     */
    private function walk(string $directory): \Generator
    {
        $excludes = array_values(array_filter(array_map(
            static fn ($e): string => trim((string) $e),
            (array) config('app.update.excludes', []),
        )));

        $entries = @scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;

            if (in_array($entry, $excludes, true)) {
                continue;
            }

            if (StorageLinkHelper::isLinkOrJunction($path)) {
                continue;
            }

            yield $path;

            if (is_dir($path)) {
                yield from $this->walk($path);
            }
        }
    }

    /**
     * 경로에 쓸 수 있는지 판정합니다.
     *
     * 디렉토리는 `is_writable()` 이 거짓이면 **실제로 파일을 하나 만들어 본다.** Windows 의
     * `is_writable()` 은 읽기 전용 속성이 붙은 폴더에서 거짓을 내지만 그 안에 파일을 만드는
     * 데는 지장이 없다 — 거짓 판정을 그대로 믿으면 운영자에게 「권한을 바꾸라」는 풀리지
     * 않는 조치를 준다. 시험은 거짓 판정이 난 디렉토리에서만 하므로 정상 서버에는 추가
     * 비용이 없다. 파일은 시험하지 않는다 — 덮어쓰기를 시험하려면 내용을 건드려야 한다.
     *
     * @param  string  $path  절대 경로
     * @return bool 쓸 수 있으면 true
     */
    private function isWritablePath(string $path): bool
    {
        if (is_writable($path)) {
            return true;
        }

        if (! is_dir($path)) {
            return false;
        }

        $probe = rtrim($path, '/\\').DIRECTORY_SEPARATOR.self::WRITE_PROBE_PREFIX.bin2hex(random_bytes(4));
        $handle = @fopen($probe, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);
        @unlink($probe);

        return true;
    }

    /**
     * 훑기 시간 가운데 지정 비율까지 쓴 시점.
     *
     * @param  float  $share  비율 (0~1)
     * @return float|null 마감 시각 (시간 제한이 없으면 null)
     */
    private function deadlineAt(float $share): ?float
    {
        if ($this->scanStartedAt === null || $this->scanSeconds === null) {
            return null;
        }

        return $this->scanStartedAt + $this->scanSeconds * max(0.0, min(1.0, $share));
    }

    /**
     * 현재 시각(초).
     *
     * @return float 시계 값
     */
    private function now(): float
    {
        return $this->clock !== null ? (float) ($this->clock)() : microtime(true);
    }

    /**
     * 바이트를 사람이 읽는 표기로 바꿉니다.
     *
     * @param  int  $bytes  바이트
     * @return string 표기 (예: `1.2 GB`)
     */
    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $value, $units[$index]);
    }

    /**
     * 수동 ZIP 소스가 놓이는 디렉토리를 보장하고 그 경로를 돌려줍니다 (㉯ — D18).
     *
     * @return string 절대 경로
     */
    public function manualSourceDirectory(): string
    {
        $path = (string) config('app.update.pending_path');

        if ($path === '') {
            $path = storage_path('app'.DIRECTORY_SEPARATOR.'core_pending');
        }

        File::ensureDirectoryExists($path, 0770, true);

        return $path;
    }
}
