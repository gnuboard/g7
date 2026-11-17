<?php

namespace App\Extension\Upgrade;

use App\Contracts\Repositories\UpgradeStepRunRepositoryInterface;
use App\Enums\UpgradeStepRunStatus;
use App\Exceptions\UpgradeStepAlreadyRunningException;
use App\Extension\AbstractUpgradeStep;
use App\Support\RuntimeRole;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 업그레이드 스텝 실행 대장 — DB 를 함께 쓰는 서버가 같은 스텝을 두 번 돌리지 않게 한다.
 *
 * 코어 판 번호는 서버마다 `.env` 에만 있어 두 번째 서버의 `core:update`·단독 스텝 실행이 이미 끝난 스텝을 다시 돌렸다.
 * 스텝마다 실행 전에 대장을 본다.
 * - `done` 이면 건너뛰고 「이미 실행됨」 로그를 남긴다(`--force` 재실행은 대장을 무시하고 다시 돌린다).
 * - 끝낸 스텝은 내용 지문({@see self::fingerprint()} — 스텝 클래스 파일 + 그 스텝의 `data/<판>/` 아래 파일, sha256)을 함께 적는다.
 *   검수 서버(stage)의 `--force` 는 이미 끝났고 지문이 같으면 건너뛰고(「같은 내용 이미 실행됨」), 다르면(같은 판 번호로 스텝을
 *   고쳐 다시 배포했으면) 다시 돌고 지문을 갱신한다 — 검수 서버는 운영 서버와 DB 를 함께 쓰므로 같은 판 재배포가 같은 변환을
 *   공유 DB 에서 두 번 돌리면 안 되고, 고친 스텝은 다시 돌아야 한다. 운영 서버의 `--force` 는 운영자의 명시 재실행이라 종전대로
 *   다시 돈다. 지문이 없는 행(클로저 스텝·지문 도입 전 행)은 같은 내용인지 알 수 없어 `--force` 에서 다시 돈다.
 * - 새 스텝은 유니크 삽입으로 「실행 중」 선점, `failed` 행은 조건부 갱신으로 다시 선점한다. 선점에 실패하면 다른 서버가
 *   실행 중이므로 {@see UpgradeStepAlreadyRunningException} 을 던진다(업데이트 실패).
 * - 비정상 종료로 남은 「실행 중」 행은 시작한 지 {@see self::STALE_RUNNING_HOURS} 시간이 지나면 다시 선점하고,
 *   `--force` 재실행은 경과 시간과 무관하게 다시 선점한다.
 * - 대장 테이블이 아직 없으면(옛 판에서 올라오는 첫 업데이트 — 마이그레이션 전) 대장 없이 종전처럼 돈다.
 *
 * 기존 설치는 백필하지 않는다 — 지난 판의 스텝은 판 범위 판정으로 원래 돌지 않는다.
 *
 * @since 7.2.0
 */
class UpgradeStepRunLedger
{
    /**
     * 단위 테스트에서 대장을 쓰는지 — 기본은 끈다.
     *
     * 스텝을 돌리는 기존 테스트 다수가 트랜잭션 없이 실행돼, 대장을 켜 두면 남은 행 때문에 다음 실행에서 스텝이
     * 건너뛰어진다(실행 순서에 따라 결과가 갈린다). 대장 자체를 재는 테스트만 `enableForTests()` 로 켠다.
     */
    private static bool $enabledInTests = false;

    /**
     * 「실행 중」 행을 비정상 종료로 남은 것으로 보는 경과 시간(시간).
     *
     * 실행이 도중에 죽으면(프로세스 강제 종료·서버 재시작) 행이 「실행 중」으로 남아 그 스텝이 영영 선점되지 않았다.
     * 시작한 지 이만큼 지난 「실행 중」 행은 다음 실행이 다시 선점한다. 정상 스텝이 이 시간을 넘길 일은 없고,
     * 더 빨리 풀어야 하면 `--force` 재실행이 경과 시간과 무관하게 선점한다.
     */
    public const STALE_RUNNING_HOURS = 6;

    /**
     * @param  UpgradeStepRunRepositoryInterface  $runs  실행 대장 저장소
     */
    public function __construct(
        private readonly UpgradeStepRunRepositoryInterface $runs,
    ) {}

    /**
     * 스텝 하나를 대장 확인과 함께 실행합니다.
     *
     * @param  string  $scope  범위 (core|module|plugin|template)
     * @param  string  $identifier  확장 식별자 (코어는 빈 문자열)
     * @param  string  $version  스텝 판 번호
     * @param  string  $step  스텝 식별 (클래스명 등)
     * @param  bool  $force  `--force` 재실행이면 이미 끝난 스텝·「실행 중」으로 남은 스텝도 다시 돌린다 (검수 서버는 지문이 같은 끝난 스텝을 건너뛴다)
     * @param  \Closure(): void  $run  스텝 본문
     * @param  string  $logChannel  로그 채널
     * @param  string|null  $fingerprint  스텝 내용 지문 ({@see self::fingerprint()}, 잴 수 없으면 null)
     * @return bool 실행했으면 true, 이미 끝나 건너뛰었으면 false
     *
     * @throws UpgradeStepAlreadyRunningException 다른 실행이 같은 스텝을 선점했을 때
     */
    public function run(string $scope, string $identifier, string $version, string $step, bool $force, \Closure $run, string $logChannel = 'upgrade', ?string $fingerprint = null): bool
    {
        if ((app()->runningUnitTests() && ! self::$enabledInTests) || ! $this->runs->available()) {
            $run();

            return true;
        }

        $existing = $this->runs->find($scope, $identifier, $version, $step);
        if ($existing !== null && $existing->status === UpgradeStepRunStatus::Done && ! $force) {
            Log::channel($logChannel)->info("업그레이드 스텝 건너뜀 — 이미 실행됨(같은 DB 의 다른 실행): {$scope}:{$identifier} {$version} {$step}", [
                'host' => $existing->host,
                'finished_at' => (string) $existing->finished_at,
            ]);

            return false;
        }

        // 검수 서버의 같은 판 재배포(`--force`) — 같은 내용으로 이미 끝난 스텝은 공유 DB 에서 다시 돌리지 않는다
        if ($existing !== null
            && $existing->status === UpgradeStepRunStatus::Done
            && $force
            && RuntimeRole::isStage()
            && $fingerprint !== null
            && $existing->fingerprint === $fingerprint
        ) {
            Log::channel($logChannel)->info("업그레이드 스텝 건너뜀 — 같은 내용 이미 실행됨(검수 서버 --force, 지문 일치): {$scope}:{$identifier} {$version} {$step}", [
                'host' => $existing->host,
                'finished_at' => (string) $existing->finished_at,
                'fingerprint' => $fingerprint,
            ]);

            return false;
        }

        $host = $this->host();
        $claimed = $existing === null
            ? $this->runs->insertRunning($scope, $identifier, $version, $step, $host)
            : $this->runs->reclaim(
                $existing,
                $force
                    ? [UpgradeStepRunStatus::Done, UpgradeStepRunStatus::Failed, UpgradeStepRunStatus::Running]
                    : [UpgradeStepRunStatus::Failed],
                $host,
                now()->subHours(self::STALE_RUNNING_HOURS),
            );

        if (! $claimed) {
            throw new UpgradeStepAlreadyRunningException($scope, $identifier, $version, $step);
        }

        if ($existing !== null && $existing->status === UpgradeStepRunStatus::Running) {
            Log::channel($logChannel)->warning("업그레이드 스텝 실행 중 표시 회수 — 비정상 종료로 남은 행을 다시 선점함: {$scope}:{$identifier} {$version} {$step}", [
                'previous_host' => $existing->host,
                'previous_started_at' => (string) $existing->started_at,
                'force' => $force,
            ]);
        }

        try {
            $run();
        } catch (\Throwable $e) {
            $this->runs->finish($scope, $identifier, $version, $step, UpgradeStepRunStatus::Failed);

            throw $e;
        }

        $this->runs->finish($scope, $identifier, $version, $step, UpgradeStepRunStatus::Done, $fingerprint);

        return true;
    }

    /**
     * 단위 테스트에서 대장을 켜거나 끕니다 — 테스트 전용.
     *
     * @param  bool  $enabled  켜면 true
     * @return void 반환값 없음
     */
    public static function enableForTests(bool $enabled = true): void
    {
        self::$enabledInTests = $enabled;
    }

    /**
     * 스텝 식별 문자열 — 클래스 인스턴스는 클래스명, 클로저는 판 번호 기반 이름.
     *
     * @param  mixed  $step  스텝 (UpgradeStepInterface 인스턴스 또는 callable)
     * @param  string  $version  스텝 판 번호
     * @return string 스텝 식별
     */
    public static function stepKey(mixed $step, string $version): string
    {
        if (is_object($step) && ! $step instanceof \Closure) {
            return $step::class;
        }

        return 'callable@'.$version;
    }

    /**
     * 스텝 내용 지문(sha256) — 검수 서버의 같은 판 `--force` 재배포가 같은 내용의 스텝을 건너뛸지 정한다.
     *
     * 잰다: 스텝 클래스 파일, 그리고 {@see AbstractUpgradeStep} 을 상속한 스텝이면 그 스텝이 실행하는 데이터 디렉터리
     * (`스텝 파일 위치/data/<판>/` — manifest·appliers·migrations 등 아래 모든 파일, {@see AbstractUpgradeStep::dataDir()} 와 같은 위치).
     * 코어(`upgrades/`)와 확장(`<확장>/upgrades/`)이 같은 규칙이다. 파일은 데이터 디렉터리 기준 상대경로(구분자 `/`)로 정렬하고
     * 줄바꿈은 LF 로 맞춰 잰다 — 체크아웃 줄바꿈 설정이 달라도 내용이 같으면 같은 지문이다.
     *
     * 클로저 스텝·파일을 찾거나 읽을 수 없는 스텝은 null(「모름」) — 대장은 `--force` 에서 다시 돌린다.
     *
     * @param  mixed  $step  스텝 (UpgradeStepInterface 인스턴스 또는 callable)
     * @param  string  $version  스텝 판 번호
     * @return string|null 64자 16진 지문 (잴 수 없으면 null)
     */
    public static function fingerprint(mixed $step, string $version): ?string
    {
        if (! is_object($step) || $step instanceof \Closure) {
            return null;
        }

        $stepFile = (new \ReflectionClass($step))->getFileName();
        if ($stepFile === false || ! is_file($stepFile)) {
            return null;
        }

        $parts = ['step' => $stepFile];

        $dataDir = dirname($stepFile).DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.$version;
        if ($step instanceof AbstractUpgradeStep && is_dir($dataDir)) {
            $dataFiles = [];
            foreach (File::allFiles($dataDir, true) as $file) {
                $dataFiles['data/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
            }
            uksort($dataFiles, 'strcmp');
            $parts += $dataFiles;
        }

        $context = hash_init('sha256');
        foreach ($parts as $label => $path) {
            $content = @file_get_contents($path);
            if ($content === false) {
                return null;
            }

            hash_update($context, $label."\0".str_replace("\r\n", "\n", $content)."\0");
        }

        return hash_final($context);
    }

    /**
     * 실행 서버 이름(진단용).
     *
     * @return string|null 이름 (구할 수 없으면 null)
     */
    private function host(): ?string
    {
        $name = function_exists('gethostname') ? @gethostname() : false;

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 191) : null;
    }
}
