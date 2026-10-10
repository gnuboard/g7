<?php

namespace App\Services\Analytics;

use App\Analytics\ActionNameHasher;
use App\Enums\Analytics\VisitorType;
use Illuminate\Support\Facades\Log;

/**
 * 확장 집계 수집기
 *
 * 집계 배치가 하루치 원장을 다 읽은 뒤, 확장이 자기 기준으로 만든 차원 행을 받아
 * 둡니다. 배치는 이 수집기가 모아 둔 행을 자기 행과 함께 한 트랜잭션으로 넣습니다.
 *
 * 확장이 집계 표에 직접 쓰지 않고 이 수집기를 거치는 이유는, 집계가 「지우고 넣기」라
 * 중간에 확장이 따로 써 넣은 행은 배치가 지워 버리기 때문입니다.
 *
 * **이 수집기를 받는 훅(`core.analytics.archive.day`)은 동기로 구독해야 합니다**
 * (`'sync' => true`). 큐로 넘어가면 수집기가 직렬화돼 워커에 사본이 가고, 확장이 채운 행은
 * 집계 배치에 돌아오지 않습니다. 그 순간을 조용히 넘기지 않도록 직렬화될 때 경고를 남깁니다.
 */
class ArchiveCollector
{
    /**
     * 차원 이름 최대 길이 — 집계 표 `dimension` 열의 폭과 같다.
     *
     * `ext:` + 확장 식별자(최대 255자) + `:` + 선언 키(최대 50자)가 들어가는 폭이다.
     * 이보다 긴 이름은 데이터베이스가 거부해 그날 집계 전체가 실패하므로 받지 않는다.
     */
    public const MAX_DIMENSION_LENGTH = 310;

    /**
     * 지표 열별 최댓값 — 집계 표 정수 열이 담을 수 있는 가장 큰 값.
     *
     * 부호 없는 정수 열은 4,294,967,295 를, 부호 없는 큰 정수 열은 PHP 정수 최댓값을 넘는 수를
     * 거부하고, 그 한 행 때문에 그날 집계 전체가 실패한다. 넘는 값은 이 값으로 맞춰 넣는다.
     *
     * @var array<string, int>
     */
    public const MAX_COUNTS = [
        'visits' => 4294967295,
        'hits' => 4294967295,
        'entries' => 4294967295,
        'exits' => 4294967295,
        'bounces' => 4294967295,
        'total_time_seconds' => PHP_INT_MAX,
    ];

    /**
     * 모인 차원 행 목록 (방문자 유형·차원·라벨 해시로 묶음)
     *
     * @var array<string, array<string, mixed>>
     */
    private array $rows = [];

    /**
     * 집계 대상 일자를 지닌 수집기를 만듭니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d, 사이트 표준시 기준)
     */
    public function __construct(
        public readonly string $date,
    ) {}

    /**
     * 확장 차원 행을 하나 더합니다.
     *
     * 차원 이름은 ext:{확장 식별자}:{키} 형태여야 합니다 — 코어 차원과 섞이면
     * 확장을 지울 때 코어 집계까지 함께 사라집니다.
     *
     * @param  string  $dimension  차원 이름 (ext:식별자:키)
     * @param  string  $label  라벨
     * @param  string  $visitorType  방문자 유형
     * @param  array<string, mixed>  $metrics  지표 값 (visits, hits, entries, exits, bounces, total_time_seconds, extra)
     * @return void 없음
     */
    public function addDimensionRow(string $dimension, string $label, string $visitorType, array $metrics = []): void
    {
        // 집계 표의 차원 열은 대소문자·끝 공백을 가리지 않고 같다고 본다 — 표기만 다른 두 이름을
        // 다른 행으로 넘기면 유일키에 걸려 그날 집계 전체가 실패한다. 식별자·선언 키는 소문자뿐이라
        // 소문자로 맞춰도 정상 이름은 그대로다.
        $dimension = mb_strtolower(trim($dimension));

        if (! str_starts_with($dimension, 'ext:')) {
            // 코어 차원 이름을 쓰면 확장을 지울 때 코어 집계까지 함께 지워진다 — 받지 않는다.
            Log::warning('확장 집계 행의 차원 이름이 ext: 로 시작하지 않아 버렸습니다.', [
                'dimension' => $dimension,
                'date' => $this->date,
            ]);

            return;
        }

        if (mb_strlen($dimension) > self::MAX_DIMENSION_LENGTH) {
            // 열 폭을 넘는 이름은 데이터베이스가 거부하고, 그 한 행 때문에 그날 집계가 통째로 실패한다.
            Log::warning('확장 집계 행의 차원 이름이 너무 길어 버렸습니다.', [
                'dimension' => mb_substr($dimension, 0, 80),
                'length' => mb_strlen($dimension),
                'max' => self::MAX_DIMENSION_LENGTH,
                'date' => $this->date,
            ]);

            return;
        }

        if (VisitorType::tryFrom($visitorType) === null) {
            // 방문자 유형 열은 네 값만 담는다 — 다른 값은 표가 거부하거나 어느 화면에도 잡히지 않는다.
            Log::warning('확장 집계 행의 방문자 유형이 올바르지 않아 버렸습니다.', [
                'dimension' => mb_substr($dimension, 0, 80),
                'visitor_type' => mb_substr($visitorType, 0, 40),
                'date' => $this->date,
            ]);

            return;
        }

        $labelHash = ActionNameHasher::labelHash($label);
        $key = $visitorType."\0".$dimension."\0".$labelHash;
        // 지표 열은 부호 없는 정수라 음수도, 열 최댓값을 넘는 수도 받지 않는다 — 그 값 하나가
        // 그날 집계를 실패시키지 않게 음수는 0, 넘는 수는 최댓값으로 맞춘다.
        $counts = [];
        foreach (self::MAX_COUNTS as $column => $max) {
            $counts[$column] = $this->boundedCount($metrics[$column] ?? 0, $max, $column, $dimension);
        }
        $extra = $metrics['extra'] ?? null;

        if (isset($this->rows[$key])) {
            // 같은 라벨이 두 번 오면(이름이 같은 분류 둘 등) 한 행으로 합친다 — 두 행을 그대로
            // 넣으면 집계 표 유일키에 걸려 그날 집계 전체가 실패한다. 합이 열 최댓값을 넘지 않게 맞춘다.
            foreach ($counts as $column => $value) {
                $current = $this->rows[$key][$column];
                $this->rows[$key][$column] = $value > self::MAX_COUNTS[$column] - $current
                    ? self::MAX_COUNTS[$column]
                    : $current + $value;
            }
            $this->rows[$key]['extra'] = self::mergeExtra($this->rows[$key]['extra'], $extra);

            return;
        }

        $this->rows[$key] = [
            'date' => $this->date,
            'visitor_type' => $visitorType,
            'dimension' => $dimension,
            'label_hash' => $labelHash,
            'label' => mb_substr($label, 0, 512),
            'action_name_id' => filter_var($metrics['action_name_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
            ...$counts,
            'extra' => $extra,
        ];
    }

    /**
     * 모인 행을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 차원 집계 행 목록
     */
    public function rows(): array
    {
        return array_values(array_map(function (array $row): array {
            $row['extra'] = $row['extra'] === null ? null : $this->encodeExtra($row['extra'], $row['dimension']);

            return $row;
        }, $this->rows));
    }

    /**
     * 지표 값을 열이 담을 수 있는 범위의 정수로 맞춥니다.
     *
     * @param  mixed  $value  확장이 넘긴 값
     * @param  int  $max  열 최댓값
     * @param  string  $column  열 이름
     * @param  string  $dimension  차원 이름 (경고용)
     * @return int 0 이상 최댓값 이하의 정수
     */
    private function boundedCount(mixed $value, int $max, string $column, string $dimension): int
    {
        // 정수 범위를 넘는 실수를 (int) 로 바꾸면 값이 정해지지 않는다 — 최댓값 비교는 캐스트 전에 한다.
        $number = is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : null);

        if ($number !== null && $number > $max) {
            Log::warning('확장 집계 행의 지표 값이 열 최댓값을 넘어 최댓값으로 맞췄습니다.', [
                'dimension' => mb_substr($dimension, 0, 80),
                'column' => $column,
                'max' => $max,
                'date' => $this->date,
            ]);

            return $max;
        }

        return max(0, (int) $value);
    }

    /**
     * 추가 값을 JSON 으로 바꿉니다.
     *
     * 깨진 UTF-8(한글을 바이트로 자른 문자열 등)은 대체 문자로 바꿔 넣는다 — 그대로 두면
     * 인코딩이 실패해 빈 값이 JSON 열에 들어가고 그날 집계 전체가 실패한다. 그래도 바꿀 수
     * 없는 값(무한대·NaN 등)이면 그 칸만 비운다.
     *
     * @param  mixed  $extra  추가 값
     * @param  string  $dimension  차원 이름 (경고용)
     * @return string|null JSON 문자열 (바꿀 수 없으면 null)
     */
    private function encodeExtra(mixed $extra, string $dimension): ?string
    {
        $json = json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            Log::warning('확장 집계 행의 추가 값을 JSON 으로 바꿀 수 없어 비웠습니다.', [
                'dimension' => mb_substr($dimension, 0, 80),
                'error' => json_last_error_msg(),
                'date' => $this->date,
            ]);

            return null;
        }

        return $json;
    }

    /**
     * 같은 라벨 행의 추가 값을 합칩니다 — 숫자는 더하고 그 밖의 값은 먼저 온 것을 둡니다.
     *
     * @param  mixed  $current  이미 모인 추가 값
     * @param  mixed  $incoming  새로 온 추가 값
     * @return mixed 합친 추가 값
     */
    private static function mergeExtra(mixed $current, mixed $incoming): mixed
    {
        if ($incoming === null) {
            return $current;
        }

        if ($current === null) {
            return $incoming;
        }

        if (! is_array($current) || ! is_array($incoming)) {
            return $current;
        }

        $merged = $current;

        foreach ($incoming as $name => $value) {
            if (! array_key_exists($name, $merged)) {
                $merged[$name] = $value;
            } elseif (is_int($merged[$name]) || is_float($merged[$name])) {
                if (is_int($value) || is_float($value)) {
                    $merged[$name] += $value;
                }
            }
        }

        return $merged;
    }

    /**
     * 직렬화될 때(큐로 넘어갈 때) 경고를 남깁니다.
     *
     * @return array<string, mixed> 직렬화할 상태
     */
    public function __serialize(): array
    {
        Log::warning('방문자 통계 집계 수집기가 큐로 넘어갔습니다 — core.analytics.archive.day 는 sync 로 구독해야 확장 행이 집계에 들어갑니다.', [
            'date' => $this->date,
        ]);

        return ['date' => $this->date, 'rows' => $this->rows];
    }

    /**
     * 직렬화된 상태에서 되돌립니다.
     *
     * @param  array<string, mixed>  $data  직렬화된 상태
     * @return void 없음
     */
    public function __unserialize(array $data): void
    {
        $this->date = (string) ($data['date'] ?? '');
        $this->rows = (array) ($data['rows'] ?? []);
    }

    /**
     * 모인 행이 있는지 확인합니다.
     *
     * @return bool 행이 하나라도 있으면 true
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
