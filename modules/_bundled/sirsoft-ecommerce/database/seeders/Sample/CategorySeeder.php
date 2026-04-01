<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 더미 데이터 시더
 */
class CategorySeeder extends Seeder
{
    /**
     * 카테고리 더미 데이터 정의
     */
    private array $categories = [
        [
            'name' => ['ko' => '의류', 'en' => 'Clothing'],
            'description' => ['ko' => '다양한 스타일의 의류 제품', 'en' => 'Various styles of clothing products'],
            'slug' => 'clothing',
            'children' => [
                [
                    'name' => ['ko' => '남성', 'en' => 'Men'],
                    'description' => ['ko' => '남성용 패션 아이템', 'en' => 'Men\'s fashion items'],
                    'slug' => 'men',
                    'children' => [
                        ['name' => ['ko' => '티셔츠', 'en' => 'T-Shirts'], 'description' => ['ko' => '편안한 캐주얼 티셔츠', 'en' => 'Comfortable casual t-shirts'], 'slug' => 'men-tshirts'],
                        ['name' => ['ko' => '바지', 'en' => 'Pants'], 'description' => ['ko' => '다양한 스타일의 바지', 'en' => 'Various styles of pants'], 'slug' => 'men-pants'],
                        ['name' => ['ko' => '아우터', 'en' => 'Outerwear'], 'description' => ['ko' => '겨울용 아우터웨어', 'en' => 'Winter outerwear'], 'slug' => 'men-outerwear'],
                    ],
                ],
                [
                    'name' => ['ko' => '여성', 'en' => 'Women'],
                    'description' => ['ko' => '여성용 패션 제품', 'en' => 'Women\'s fashion products'],
                    'slug' => 'women',
                    'children' => [
                        ['name' => ['ko' => '원피스', 'en' => 'Dresses'], 'description' => ['ko' => '다양한 디자인의 원피스', 'en' => 'Various designs of dresses'], 'slug' => 'women-dresses'],
                        ['name' => ['ko' => '스커트', 'en' => 'Skirts'], 'description' => ['ko' => '여성용 스커트', 'en' => 'Women\'s skirts'], 'slug' => 'women-skirts'],
                        ['name' => ['ko' => '블라우스', 'en' => 'Blouses'], 'description' => ['ko' => '세련된 블라우스', 'en' => 'Stylish blouses'], 'slug' => 'women-blouses'],
                    ],
                ],
            ],
        ],
        [
            'name' => ['ko' => '전자기기', 'en' => 'Electronics'],
            'description' => ['ko' => '최신 전자제품과 기기', 'en' => 'Latest electronic products and devices'],
            'slug' => 'electronics',
            'children' => [
                [
                    'name' => ['ko' => '스마트폰', 'en' => 'Smartphones'],
                    'description' => ['ko' => '스마트폰과 액세서리', 'en' => 'Smartphones and accessories'],
                    'slug' => 'smartphones',
                    'children' => [
                        ['name' => ['ko' => '애플', 'en' => 'Apple'], 'description' => ['ko' => '애플 스마트폰', 'en' => 'Apple smartphones'], 'slug' => 'apple-phones'],
                        ['name' => ['ko' => '삼성', 'en' => 'Samsung'], 'description' => ['ko' => '삼성 스마트폰', 'en' => 'Samsung smartphones'], 'slug' => 'samsung-phones'],
                    ],
                ],
                [
                    'name' => ['ko' => '노트북', 'en' => 'Laptops'],
                    'description' => ['ko' => '노트북 컴퓨터', 'en' => 'Laptop computers'],
                    'slug' => 'laptops',
                    'children' => [
                        ['name' => ['ko' => '게이밍', 'en' => 'Gaming'], 'description' => ['ko' => '게이밍용 노트북', 'en' => 'Gaming laptops'], 'slug' => 'gaming-laptops'],
                        ['name' => ['ko' => '비즈니스', 'en' => 'Business'], 'description' => ['ko' => '비즈니스용 노트북', 'en' => 'Business laptops'], 'slug' => 'business-laptops'],
                    ],
                ],
                [
                    'name' => ['ko' => '태블릿', 'en' => 'Tablets'],
                    'description' => ['ko' => '태블릿 기기', 'en' => 'Tablet devices'],
                    'slug' => 'tablets',
                ],
            ],
        ],
        [
            'name' => ['ko' => '가구', 'en' => 'Furniture'],
            'description' => ['ko' => '집과 사무실 가구', 'en' => 'Home and office furniture'],
            'slug' => 'furniture',
            'children' => [
                ['name' => ['ko' => '소파', 'en' => 'Sofas'], 'description' => ['ko' => '편안한 소파', 'en' => 'Comfortable sofas'], 'slug' => 'sofas'],
                ['name' => ['ko' => '침대', 'en' => 'Beds'], 'description' => ['ko' => '침대와 매트리스', 'en' => 'Beds and mattresses'], 'slug' => 'beds'],
                ['name' => ['ko' => '책상', 'en' => 'Desks'], 'description' => ['ko' => '책상과 작업대', 'en' => 'Desks and worktables'], 'slug' => 'desks'],
                ['name' => ['ko' => '의자', 'en' => 'Chairs'], 'description' => ['ko' => '다양한 의자', 'en' => 'Various chairs'], 'slug' => 'chairs'],
            ],
        ],
        [
            'name' => ['ko' => '식품', 'en' => 'Food'],
            'description' => ['ko' => '신선한 식품과 음료', 'en' => 'Fresh food and beverages'],
            'slug' => 'food',
            'children' => [
                ['name' => ['ko' => '과일', 'en' => 'Fruits'], 'description' => ['ko' => '신선한 과일', 'en' => 'Fresh fruits'], 'slug' => 'fruits'],
                ['name' => ['ko' => '채소', 'en' => 'Vegetables'], 'description' => ['ko' => '다양한 채소', 'en' => 'Various vegetables'], 'slug' => 'vegetables'],
                ['name' => ['ko' => '육류', 'en' => 'Meat'], 'description' => ['ko' => '신선한 육류', 'en' => 'Fresh meat'], 'slug' => 'meat'],
                ['name' => ['ko' => '해산물', 'en' => 'Seafood'], 'description' => ['ko' => '신선한 해산물', 'en' => 'Fresh seafood'], 'slug' => 'seafood'],
            ],
        ],
        [
            'name' => ['ko' => '스포츠', 'en' => 'Sports'],
            'description' => ['ko' => '스포츠 용품과 의류', 'en' => 'Sports equipment and apparel'],
            'slug' => 'sports',
            'children' => [
                ['name' => ['ko' => '축구', 'en' => 'Soccer'], 'description' => ['ko' => '축구 용품', 'en' => 'Soccer equipment'], 'slug' => 'soccer'],
                ['name' => ['ko' => '농구', 'en' => 'Basketball'], 'description' => ['ko' => '농구 용품', 'en' => 'Basketball equipment'], 'slug' => 'basketball'],
                ['name' => ['ko' => '테니스', 'en' => 'Tennis'], 'description' => ['ko' => '테니스 용품', 'en' => 'Tennis equipment'], 'slug' => 'tennis'],
            ],
        ],
        [
            'name' => ['ko' => '책', 'en' => 'Books'],
            'description' => ['ko' => '다양한 장르의 책과 출판물', 'en' => 'Books and publications of various genres'],
            'slug' => 'books',
            'children' => [
                ['name' => ['ko' => '소설', 'en' => 'Fiction'], 'description' => ['ko' => '재미있는 소설책', 'en' => 'Enjoyable fiction books'], 'slug' => 'fiction'],
                ['name' => ['ko' => '비소설', 'en' => 'Non-fiction'], 'description' => ['ko' => '유익한 비소설책', 'en' => 'Informative non-fiction books'], 'slug' => 'non-fiction'],
                ['name' => ['ko' => '교육', 'en' => 'Education'], 'description' => ['ko' => '교육 관련 책', 'en' => 'Education-related books'], 'slug' => 'education'],
            ],
        ],
        [
            'name' => ['ko' => '가전제품', 'en' => 'Home Appliances'],
            'description' => ['ko' => '집에서 사용하는 가전제품', 'en' => 'Home appliances for household use'],
            'slug' => 'home-appliances',
            'children' => [
                ['name' => ['ko' => '냉장고', 'en' => 'Refrigerators'], 'description' => ['ko' => '냉장고 제품', 'en' => 'Refrigerator products'], 'slug' => 'refrigerators'],
                ['name' => ['ko' => '세탁기', 'en' => 'Washing Machines'], 'description' => ['ko' => '세탁기 제품', 'en' => 'Washing machine products'], 'slug' => 'washing-machines'],
                ['name' => ['ko' => '에어컨', 'en' => 'Air Conditioners'], 'description' => ['ko' => '에어컨 제품', 'en' => 'Air conditioner products'], 'slug' => 'air-conditioners'],
            ],
        ],
        [
            'name' => ['ko' => '건강/뷰티', 'en' => 'Health/Beauty'],
            'description' => ['ko' => '건강과 미용 제품', 'en' => 'Health and beauty products'],
            'slug' => 'health-beauty',
            'children' => [
                ['name' => ['ko' => '스킨케어', 'en' => 'Skincare'], 'description' => ['ko' => '피부 관리 제품', 'en' => 'Skincare products'], 'slug' => 'skincare'],
                ['name' => ['ko' => '헤어케어', 'en' => 'Hair Care'], 'description' => ['ko' => '머리 관리 제품', 'en' => 'Hair care products'], 'slug' => 'hair-care'],
                ['name' => ['ko' => '영양제', 'en' => 'Supplements'], 'description' => ['ko' => '건강 영양제', 'en' => 'Health supplements'], 'slug' => 'supplements'],
            ],
        ],
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('카테고리 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingCategories();
        $this->createCategories($this->categories);

        $count = Category::count();
        $this->command->info("카테고리 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 카테고리 삭제
     */
    private function deleteExistingCategories(): void
    {
        $deletedCount = Category::count();

        if ($deletedCount > 0) {
            // 자식 먼저 삭제하기 위해 depth 역순으로 삭제
            Category::orderBy('depth', 'desc')->delete();
            $this->command->warn("기존 카테고리 {$deletedCount}건을 삭제했습니다.");
        }
    }

    /**
     * 카테고리 생성 (재귀)
     *
     * @param  array  $categories  카테고리 데이터 배열
     * @param  int|null  $parentId  부모 카테고리 ID
     * @param  int  $depth  깊이
     * @param  string  $parentPath  부모 경로
     */
    private function createCategories(array $categories, ?int $parentId = null, int $depth = 0, string $parentPath = ''): void
    {
        $sortOrder = 0;

        foreach ($categories as $categoryData) {
            $children = $categoryData['children'] ?? [];
            unset($categoryData['children']);

            $category = Category::create([
                'name' => $categoryData['name'],
                'description' => $categoryData['description'] ?? null,
                'slug' => $categoryData['slug'],
                'parent_id' => $parentId,
                'depth' => $depth,
                'sort_order' => $sortOrder++,
                'is_active' => true,
                'path' => '', // 임시값, 생성 후 업데이트
            ]);

            // path 업데이트
            $path = $parentPath ? $parentPath.'/'.$category->id : (string) $category->id;
            $category->update(['path' => $path]);

            $this->command->line("  - 카테고리 생성: {$category->getLocalizedName()} (depth: {$depth})");

            // 자식 카테고리 재귀 생성
            if (! empty($children)) {
                $this->createCategories($children, $category->id, $depth + 1, $path);
            }
        }
    }
}
