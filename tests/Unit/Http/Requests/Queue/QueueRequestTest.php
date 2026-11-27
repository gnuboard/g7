<?php

namespace Tests\Unit\Http\Requests\Queue;

use App\Http\Requests\Queue\BulkDeleteFailedJobRequest;
use App\Http\Requests\Queue\BulkRetryFailedJobRequest;
use App\Http\Requests\Queue\FailedJobListRequest;
use App\Http\Requests\Queue\PendingJobListRequest;
use App\Http\Requests\Queue\QueueJobLogListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 큐 관리 FormRequest 검증 규칙 테스트 (— 목록 쿼리 키)
 */
class QueueRequestTest extends TestCase
{
    /**
     * 요청 규칙으로 입력을 검증해 통과 여부를 돌려줍니다.
     *
     * @param  class-string<FormRequest>  $class  FormRequest 클래스
     * @param  array<string, mixed>  $input  입력
     * @return bool 통과하면 true
     */
    private function passes(string $class, array $input): bool
    {
        $request = new $class;

        return Validator::make($input, $request->rules(), $request->messages(), $request->attributes())->passes();
    }

    /**
     * 목록 요청 3종은 큐 탭이 보내는 쿼리 키를 모두 규칙으로 가진다(게이트 정합 — 화면 params ⊆ rules).
     *
     * @scenario surface=form_request
     *
     * @effects list_request_keys_match_k8
     *
     * @return void 반환값 없음
     */
    public function test_목록_요청은_k8_쿼리키를_가진다(): void
    {
        $this->assertEqualsCanonicalizing(
            ['page', 'per_page', 'search_field', 'search_keyword', 'queue', 'connection', 'sort_by', 'sort_order'],
            array_keys((new PendingJobListRequest)->rules())
        );
        $this->assertEqualsCanonicalizing(
            ['page', 'per_page', 'search_field', 'search_keyword', 'queue', 'connection', 'failed_start_date', 'failed_end_date', 'sort_by', 'sort_order'],
            array_keys((new FailedJobListRequest)->rules())
        );
        $this->assertEqualsCanonicalizing(
            ['page', 'per_page', 'search_field', 'search_keyword', 'status', 'queue', 'connection', 'created_start_date', 'created_end_date', 'sort_by', 'sort_order'],
            array_keys((new QueueJobLogListRequest)->rules())
        );
    }

    /**
     * 검색 대상·페이지당 건수·날짜 범위·결과 값의 경계를 검사한다.
     *
     * @scenario field=search_field|per_page|date_range|status
     *
     * @effects list_request_bounds
     *
     * @return void 반환값 없음
     */
    public function test_목록_요청_경계값(): void
    {
        $this->assertTrue($this->passes(PendingJobListRequest::class, ['search_field' => 'uuid', 'per_page' => 100]));
        $this->assertFalse($this->passes(PendingJobListRequest::class, ['search_field' => 'error']));
        $this->assertFalse($this->passes(PendingJobListRequest::class, ['per_page' => 101]));
        $this->assertFalse($this->passes(PendingJobListRequest::class, ['per_page' => 0]));

        $this->assertTrue($this->passes(FailedJobListRequest::class, ['search_field' => 'error', 'failed_start_date' => '2026-10-01', 'failed_end_date' => '2026-10-01']));
        $this->assertFalse($this->passes(FailedJobListRequest::class, ['failed_start_date' => '2026-10-02', 'failed_end_date' => '2026-10-01']));
        $this->assertFalse($this->passes(FailedJobListRequest::class, ['search_keyword' => str_repeat('a', 256)]));

        $this->assertTrue($this->passes(QueueJobLogListRequest::class, ['status' => 'all']));
        $this->assertTrue($this->passes(QueueJobLogListRequest::class, ['status' => 'failed']));
        $this->assertFalse($this->passes(QueueJobLogListRequest::class, ['status' => 'released']));
    }

    /**
     * 정렬 기준은 목록마다 정한 열만, 정렬 순서는 asc·desc 만 받는다.
     *
     * @scenario field=sort_by|sort_order
     *
     * @effects list_sort_whitelist
     *
     * @return void 반환값 없음
     */
    public function test_목록_정렬_기준은_화이트리스트만_받는다(): void
    {
        $allowed = [
            PendingJobListRequest::class => ['created_at', 'available_at', 'attempts'],
            FailedJobListRequest::class => ['failed_at', 'id'],
            QueueJobLogListRequest::class => ['finished_at', 'duration_ms', 'attempts'],
        ];

        foreach ($allowed as $class => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue($this->passes($class, ['sort_by' => $column, 'sort_order' => 'asc']), $class.' '.$column);
            }
            $this->assertTrue($this->passes($class, ['sort_order' => 'desc']), $class);
            $this->assertFalse($this->passes($class, ['sort_by' => 'payload']), $class);
            $this->assertFalse($this->passes($class, ['sort_by' => 'id; drop table jobs']), $class);
            $this->assertFalse($this->passes($class, ['sort_order' => 'sideways']), $class);
        }

        $this->assertFalse($this->passes(PendingJobListRequest::class, ['sort_by' => 'failed_at']));
        $this->assertFalse($this->passes(FailedJobListRequest::class, ['sort_by' => 'duration_ms']));
        $this->assertFalse($this->passes(QueueJobLogListRequest::class, ['sort_by' => 'available_at']));

        $request = new QueueJobLogListRequest;
        $validator = Validator::make(['sort_by' => 'nope'], $request->rules(), $request->messages(), $request->attributes());
        $this->assertSame(__('queue.validation.sort_by_invalid'), $validator->errors()->first('sort_by'));
    }

    /**
     * 일괄 재시도·삭제는 1~100건의 서로 다른 UUID 만 받는다.
     *
     * @scenario size=0|1|100|101, item=invalid|duplicated
     *
     * @effects bulk_limit_100
     *
     * @return void 반환값 없음
     */
    public function test_일괄_요청은_1에서_100건의_uui_d만_받는다(): void
    {
        $uuids = fn (int $n) => array_map(fn () => (string) Str::uuid(), range(1, $n));

        foreach ([BulkRetryFailedJobRequest::class, BulkDeleteFailedJobRequest::class] as $class) {
            $this->assertTrue($this->passes($class, ['uuids' => $uuids(1)]), $class);
            $this->assertTrue($this->passes($class, ['uuids' => $uuids(100)]), $class);
            $this->assertFalse($this->passes($class, ['uuids' => $uuids(101)]), $class);
            $this->assertFalse($this->passes($class, ['uuids' => []]), $class);
            $this->assertFalse($this->passes($class, ['uuids' => ['nope']]), $class);
            $same = (string) Str::uuid();
            $this->assertFalse($this->passes($class, ['uuids' => [$same, $same]]), $class);
        }
    }

    /**
     * 검증 오류 문구는 필드 키 대신 라벨을 쓴다.
     *
     * @scenario surface=error_message
     *
     * @effects validation_messages_use_labels
     *
     * @return void 반환값 없음
     */
    public function test_검증_문구는_필드_라벨을_쓴다(): void
    {
        $request = new FailedJobListRequest;
        $validator = Validator::make(['failed_end_date' => 'x'], $request->rules(), $request->messages(), $request->attributes());

        $this->assertTrue($validator->fails());
        $this->assertSame(__('queue.validation.date_invalid'), $validator->errors()->first('failed_end_date'));
        $this->assertSame(__('queue.attributes.uuid'), (new BulkRetryFailedJobRequest)->attributes()['uuids.*']);
    }
}
