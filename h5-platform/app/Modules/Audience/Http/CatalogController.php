<?php

namespace App\Modules\Audience\Http;

use App\Contracts\CatalogReader;
use App\Support\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CatalogController
{
    public function __construct(private CatalogReader $catalog) {}

    private function page(callable $read)
    {
        try {
            return $read();
        } catch (ApiProblem $e) {
            return response()->view('audience.unavailable', ['code' => $e->errorCode], $e->status);
        }
    }

    public function home()
    {
        return $this->page(fn () => view('audience.home', ['home' => $this->catalog->homepage(app()->getLocale())]));
    }

    public function catalog(Request $request)
    {
        $data = $request->validate(['category_id' => 'nullable|uuid', 'cursor' => 'nullable|string|max:2048']);

        return $this->page(function () use ($data) {
            $categories = $this->catalog->categories(app()->getLocale());
            $reset = false;
            try {
                $page = $this->catalog->catalog($data['cursor'] ?? null, 20, app()->getLocale(), $data['category_id'] ?? null);
            } catch (ValidationException $e) {
                if (empty($data['cursor']) || ! array_key_exists('cursor', $e->errors())) {
                    throw $e;
                }
                $page = $this->catalog->catalog(null, 20, app()->getLocale(), $data['category_id'] ?? null);
                $reset = true;
            }

            return view('audience.catalog', compact('categories', 'page', 'reset') + ['categoryId' => $data['category_id'] ?? null]);
        });
    }

    public function drama(string $id)
    {
        return $this->page(fn () => view('audience.drama', ['drama' => $this->catalog->drama($id, app()->getLocale())]));
    }

    public function watch(string $id, string $episode)
    {
        return $this->page(function () use ($id, $episode) {
            $drama = $this->catalog->drama($id, app()->getLocale());
            $selected = collect($drama->episodes)->first(fn ($item) => $item->id === $episode);
            if (! $selected) {
                throw new ApiProblem('NOT_FOUND', 404, 'Episode unavailable');
            }

            return view('audience.watch', ['drama' => $drama, 'episode' => $selected]);
        });
    }
}
