<?php

namespace Modules\Sirsoft\Board\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CategoryInUseRule implements ValidationRule
{
    /**
     * 생성자
     */
    public function __construct(
        private string $boardSlug,
        private array $existingCategories,
        private array $newCategories
    ) {}

    /**
     * 검증 수행
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  검증할 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 제거될 분류 찾기
        $removedCategories = array_diff($this->existingCategories, $this->newCategories);

        if (empty($removedCategories)) {
            return;
        }

        // 제거될 분류가 게시글에서 사용 중인지 확인
        $tableName = "board_posts_{$this->boardSlug}";

        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ($removedCategories as $category) {
            $count = DB::table($tableName)
                ->where('category', $category)
                ->whereNull('deleted_at')
                ->count();

            if ($count > 0) {
                $fail(__('sirsoft-board::validation.category_in_use', [
                    'category' => $category,
                    'count' => $count,
                ]));

                return;
            }
        }
    }
}
