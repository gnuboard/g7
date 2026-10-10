<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;

/** 공식 현재환율 API. 예외 원문과 요청 URL은 외부로 반환하지 않습니다. */
class KoreaeximApiClient
{
    public const ENDPOINT = 'https://oapi.koreaexim.go.kr/site/program/financial/exchangeJSON';

    private const MIN_HTTP_ATTEMPTS = 1;

    private const MAX_HTTP_ATTEMPTS = 2;

    /**
     * 공식 API의 결과와 실제 호출 횟수를 반환합니다.
     *
     * @param  string  $key  key 입력값
     * @param  string  $date  date 입력값
     * @param  int  $maxAttempts  허용할 최대 HTTP 전송 횟수
     * @return array 처리 결과
     */
    public function fetch(string $key, string $date, int $maxAttempts = self::MAX_HTTP_ATTEMPTS): array
    {
        $count = 0;
        // HTTP 전송 횟수를 직접 세어 재시도까지 호출 예산에 반영합니다.
        $maxAttempts = max(self::MIN_HTTP_ATTEMPTS, min(self::MAX_HTTP_ATTEMPTS, $maxAttempts));
        while ($count < $maxAttempts) {
            $count++;
            try {
                $response = Http::connectTimeout(5)->timeout(20)->get(self::ENDPOINT, [
                    'authkey' => $key, 'searchdate' => $date, 'data' => 'AP01',
                ]);
                if (! $response->successful()) {
                    if ($count < $maxAttempts && $response->serverError()) {
                        usleep(500000);

                        continue;
                    }

                    return $this->result(RunStatus::NetworkError, [], $count);
                }
                try {
                    $rows = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return $this->result(RunStatus::BadResponse, [], $count);
                }
                if ($rows === null || $rows === []) {
                    if (trim($response->body()) !== 'null' && ! str_starts_with(ltrim($response->body()), '[')) {
                        return $this->result(RunStatus::BadResponse, [], $count);
                    }

                    return $this->result(RunStatus::NoData, [], $count);
                }
                if (! is_array($rows) || ! array_is_list($rows)) {
                    return $this->result(RunStatus::BadResponse, [], $count);
                }
                $accepted = [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        return $this->result(RunStatus::BadResponse, [], $count);
                    }
                    $status = match ((int) ($row['result'] ?? 0)) {
                        1 => RunStatus::Success, 2 => RunStatus::BadRequest,
                        3 => RunStatus::AuthError, 4 => RunStatus::QuotaExceeded,
                        default => RunStatus::BadResponse,
                    };
                    if ($status !== RunStatus::Success) {
                        return $this->result($status, [], $count);
                    }
                    // API 명세 필드만 보존하며 비밀값이 응답에 반사되어도 저장하지 않습니다.
                    $safe = array_intersect_key($row, array_flip(['result', 'cur_unit', 'cur_nm', 'ttb', 'tts', 'deal_bas_r', 'bkpr', 'yy_efee_r', 'ten_dd_efee_r', 'kftc_bkpr', 'kftc_deal_bas_r']));
                    foreach ($safe as &$value) {
                        if (is_string($value) && $key !== '') {
                            $value = str_replace([$key, rawurlencode($key)], '[redacted]', $value);
                        }
                    }
                    unset($value);
                    $accepted[] = $safe;
                }

                return $this->result(RunStatus::Success, $accepted, $count);
            } catch (ConnectionException) {
                if ($count < $maxAttempts) {
                    usleep(500000);

                    continue;
                }
            }
        }

        return $this->result(RunStatus::NetworkError, [], $count);
    }

    /**
     * 외부 API 결과의 공통 구조를 구성합니다.
     *
     * @param  RunStatus  $status  status 입력값
     * @param  array  $rows  rows 입력값
     * @param  int  $count  count 입력값
     * @return array 처리 결과
     */
    private function result(RunStatus $status, array $rows, int $count): array
    {
        return ['status' => $status, 'rows' => $rows, 'request_count' => $count, 'message' => $status->label()];
    }
}
