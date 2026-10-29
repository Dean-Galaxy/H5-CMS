<?php

namespace App\Modules\Acquisition\Analytics;

use App\Enums\Permission;
use App\Modules\Acquisition\Services\AssetAccess;
use App\Support\ApiProblem;
use Illuminate\Support\Str;

final class LinkFilter
{
    public function page(string $search = '', int $page = 1, ?string $selected = null): array
    {
        $search = trim($search);
        if (mb_strlen($search) > 100) {
            throw new \InvalidArgumentException('链接名称搜索不能超过100个字符');
        }
        $access = app(AssetAccess::class);
        $query = $access->query('link');
        if ($search !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("name ILIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        $total = (clone $query)->count();
        $last = max(1, (int) ceil($total / 50));
        $page = max(1, min($page, $last));
        $items = $query->orderBy('name')->orderBy('id')->forPage($page, 50)->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name, 'unavailable' => false])->all();
        $unavailable = false;
        if ($selected !== null && $selected !== '') {
            try {
                // Always recheck the selected asset, even when it is outside the current search/page.
                $row = $access->get('link', $selected, permission: Permission::AcquisitionSummary);
                if (! in_array($row->id, array_column($items, 'id'), true)) {
                    $items[] = ['id' => $row->id, 'name' => $row->name.'（已选）', 'unavailable' => false];
                }
            } catch (ApiProblem $e) {
                if ($e->errorCode !== 'NOT_FOUND') {
                    throw $e;
                }
                $unavailable = true;
                if (Str::isUuid($selected)) {
                    $items[] = ['id' => $selected, 'name' => '已选链接当前不可用或无权查看', 'unavailable' => true];
                }
            }
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'last_page' => $last, 'selected_unavailable' => $unavailable];
    }
}
