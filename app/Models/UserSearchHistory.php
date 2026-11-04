<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 회원 최근 검색어 모델
 *
 * 검색 범위(`scope`)마다 회원·검색어 한 행이고, 다시 검색하면 `searched_at` 만 새로 쓴다.
 * 범위별 보관 개수·보관 기한은 개인정보 설정(`privacy.search_history_scopes`)을 따른다.
 *
 * @property int $id 검색어 ID
 * @property int $user_id 회원 ID
 * @property string $scope 검색 범위 id
 * @property string $keyword 검색어
 * @property Carbon $searched_at 마지막으로 검색한 일시
 *
 * @since 7.1.5
 */
class UserSearchHistory extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'user_search_histories';

    /**
     * 생성·수정 일시 컬럼을 쓰지 않는다 (`searched_at` 하나).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'scope',
        'keyword',
        'searched_at',
    ];

    /**
     * 속성 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'searched_at' => 'datetime',
        ];
    }

    /**
     * 검색어의 회원
     *
     * @return BelongsTo 회원 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
