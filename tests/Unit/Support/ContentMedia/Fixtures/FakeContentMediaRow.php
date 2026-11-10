<?php

namespace Tests\Unit\Support\ContentMedia\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * 시험용 본문 미디어 행 — DB 없이 속성만 담는 모델 (#887)
 *
 * 골격은 행을 `Model` 로만 다루고 속성(`hash`·`disk`·`mime_type`…)을 읽는다. 저장은 가짜 어댑터의 메모리 목록이 맡는다.
 */
class FakeContentMediaRow extends Model
{
    /** @var array<int, string> 대량 할당 차단 열 없음 */
    protected $guarded = [];

    /** @var bool 시각 열 없음 */
    public $timestamps = false;

    /** @var array<string, string> 형변환 */
    protected $casts = ['meta' => 'array'];
}
