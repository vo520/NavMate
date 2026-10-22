<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

class CategoryTreeService
{
    public const TREE_CACHE_KEY = 'categories:public_tree';

    protected const TREE_CACHE_TTL = 300;

    /**
     * Build the category tree with sites for the public frontend.
     *
     * Cached as a plain array: the database cache store disables object
     * unserialization (serializable_classes = false), so models/collections
     * would come back unusable. Cleared by ClearsDashboardCache on
     * category/site mutations.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPublicTree(): array
    {
        return Cache::remember(self::TREE_CACHE_KEY, self::TREE_CACHE_TTL, function () {
            return $this->buildPublicTree();
        });
    }

    protected function buildPublicTree(): array
    {
        $sitesByCategory = Site::active()
            ->public()
            ->ordered()
            ->get()
            ->groupBy('category_id');

        $allCategories = Category::active()
            ->ordered()
            ->get()
            ->keyBy('id');

        $childCategories = $allCategories->filter(fn ($c) => $c->parent_id !== null);
        $parentCategories = $allCategories->filter(fn ($c) => $c->parent_id === null);

        return $parentCategories
            ->sortBy(fn ($category) => $category->name === '导入书签' ? -1 : $category->sort_order)
            ->map(function ($parent) use ($childCategories, $sitesByCategory) {
            $sites = $sitesByCategory->get($parent->id, collect())->values()->toArray();
            $children = $childCategories
                ->where('parent_id', $parent->id)
                ->values()
                ->map(function ($child) use ($sitesByCategory) {
                    $childArray = $child->toArray();
                    $childArray['sites'] = $sitesByCategory->get($child->id, collect())->values()->toArray();
                    $childArray['children'] = [];

                    return $childArray;
                })
                ->all();

            if (empty($sites) && empty($children)) {
                return null;
            }

            $parentArray = $parent->toArray();
            $parentArray['sites'] = $sites;
            $parentArray['children'] = $children;

            return $parentArray;
        })->filter()
            ->values()
            ->all();
    }
}
