<?php

namespace App\Http\Requests\Public\Csp;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CSP 위반 보고 요청 (#158)
 *
 * 브라우저가 보내는 두 본문 형식을 기록용 필드로 정리한다 — 검증 실패로 4xx 를 돌려주지 않는다
 * (보고에 4xx 를 주면 방문자 콘솔에 오류가 쌓인다). 형식이 맞지 않으면 {@see reports()} 가 빈 배열을 돌려주고
 * 컨트롤러가 기록 없이 204 로 끝낸다.
 *
 *   - `application/csp-report` — `{"csp-report": {"document-uri": …, "blocked-uri": …}}` (report-uri, 대부분 브라우저)
 *   - `application/reports+json` — `[{"type": "csp-violation", "body": {"documentURL": …}}]` (Reporting API)
 *
 * 주소(문서·차단·원본 파일)는 쿼리스트링·조각을 걷어내고 경로까지만 남긴다 — 쿼리에는 토큰·검색어 같은
 * 개인 값이 실릴 수 있다. 요청 IP 는 싣지 않는다.
 */
class CspReportRequest extends FormRequest
{
    /** 받는 본문 상한 (바이트) */
    public const MAX_BODY_BYTES = 16384;

    /** 샘플 기록 길이 */
    private const SAMPLE_LENGTH = 80;

    /** 한 요청에서 기록하는 보고 수 상한 (Reporting API 묶음) */
    private const MAX_REPORTS_PER_REQUEST = 20;

    /**
     * 공개 경로라 누구나 보낼 수 있다(호출 제한은 라우트 미들웨어).
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 본문 형식은 {@see reports()} 가 판정하므로 선언 규칙은 없다.
     *
     * @return array<string, mixed> 빈 규칙
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * 본문이 상한을 넘는지 판정합니다 (선언 길이 또는 실제 길이).
     *
     * @return bool 16KB 를 넘으면 true
     */
    public function isOversized(): bool
    {
        if ((int) $this->headers->get('Content-Length', '0') > self::MAX_BODY_BYTES) {
            return true;
        }

        return strlen((string) $this->getContent()) > self::MAX_BODY_BYTES;
    }

    /**
     * 기록할 위반 보고 목록을 돌려줍니다.
     *
     * @return array<int, array<string, mixed>> 정리된 보고 (해석할 수 없으면 빈 배열, 최대 20건)
     */
    public function reports(): array
    {
        return array_slice($this->extractReports((string) $this->getContent()), 0, self::MAX_REPORTS_PER_REQUEST);
    }

    /**
     * 본문에서 위반 보고를 꺼내 기록용 필드로 정리합니다.
     *
     * @param  string  $raw  요청 본문
     * @return array<int, array<string, mixed>> 정리된 보고 목록 (해석할 수 없으면 빈 배열)
     */
    private function extractReports(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        // report-uri 형식
        if (isset($decoded['csp-report']) && is_array($decoded['csp-report'])) {
            $body = $decoded['csp-report'];

            return [$this->present([
                'document_uri' => $body['document-uri'] ?? null,
                'blocked_uri' => $body['blocked-uri'] ?? null,
                'effective_directive' => $body['effective-directive'] ?? ($body['violated-directive'] ?? null),
                'disposition' => $body['disposition'] ?? null,
                'line_number' => $body['line-number'] ?? null,
                'source_file' => $body['source-file'] ?? null,
                'sample' => $body['script-sample'] ?? null,
            ])];
        }

        // Reporting API 형식 (배열)
        if (! array_is_list($decoded)) {
            return [];
        }

        $reports = [];

        foreach ($decoded as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'csp-violation' || ! is_array($item['body'] ?? null)) {
                continue;
            }

            $body = $item['body'];
            $reports[] = $this->present([
                'document_uri' => $body['documentURL'] ?? null,
                'blocked_uri' => $body['blockedURL'] ?? null,
                'effective_directive' => $body['effectiveDirective'] ?? null,
                'disposition' => $body['disposition'] ?? null,
                'line_number' => $body['lineNumber'] ?? null,
                'source_file' => $body['sourceFile'] ?? null,
                'sample' => $body['sample'] ?? null,
            ]);
        }

        return $reports;
    }

    /**
     * 기록용으로 값을 다듬습니다 (주소 쿼리 제거·문자열 길이 제한·타입 정리).
     *
     * @param  array<string, mixed>  $fields  원래 필드
     * @return array<string, mixed> 기록용 필드
     */
    private function present(array $fields): array
    {
        foreach (['document_uri', 'blocked_uri', 'source_file'] as $key) {
            $fields[$key] = $this->stripQuery($fields[$key]);
        }

        foreach (['effective_directive', 'disposition'] as $key) {
            $fields[$key] = is_string($fields[$key]) ? mb_substr($fields[$key], 0, 64) : null;
        }

        $fields['line_number'] = is_numeric($fields['line_number']) ? (int) $fields['line_number'] : null;
        $fields['sample'] = is_string($fields['sample']) ? mb_substr($fields['sample'], 0, self::SAMPLE_LENGTH) : null;

        return $fields;
    }

    /**
     * 주소에서 쿼리스트링·조각을 걷어내고 경로까지만 남깁니다.
     *
     * `inline`·`eval`·`data` 처럼 주소가 아닌 값은 그대로 둔다(길이만 제한).
     *
     * @param  mixed  $value  원래 값
     * @return string|null 정리된 값
     */
    private function stripQuery(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = mb_substr($value, 0, 2048);
        $cut = strcspn($value, '?#');

        return mb_substr(substr($value, 0, $cut), 0, 512);
    }
}
