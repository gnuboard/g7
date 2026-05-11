<?php

namespace Modules\Gnuboard7\HelloModule\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Gnuboard7\HelloModule\Database\Factories\MemoFactory;

/**
 * 메모 모델
 *
 * Hello 학습용 샘플 엔티티입니다.
 */
class Memo extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'gnuboard7_hello_module_memos';

    /**
     * 대량 할당 가능한 속성
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'title',
        'content',
    ];

    /**
     * UUID 가 적용될 컬럼 목록을 반환합니다.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * 팩토리 클래스를 반환합니다.
     *
     * @return MemoFactory
     */
    protected static function newFactory(): MemoFactory
    {
        return MemoFactory::new();
    }
}
