<?php

namespace App\Modules\Acquisition\Probe;

use App\Modules\Acquisition\Analytics\TrackingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PageTestController
{
    public function show(PageTestProbe $probe)
    {
        $probe->owner();
        $response = response()->view('acquisition.page-test', [
            'assets' => DB::table('acquisition_pixels')->where('platform', 'meta')->where('environment', 'test')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'granted' => app(TrackingContext::class)->privacy()?->advertising === 'granted',
            'intent' => session('acquisition.page_test.intent'),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        $response->getContent();
        $probe->pageProof();

        return $response;
    }

    public function create(Request $request, PageTestProbe $probe)
    {
        abort_if(array_diff(array_keys($request->all()), ['_token', 'pixel_id', 'agree']), 422);
        $values = $request->validate(['pixel_id' => ['required', 'uuid'], 'agree' => ['required', 'accepted']]);
        $probe->create($values['pixel_id'], true);

        return redirect(PageTestProbe::PATH)->header('Cache-Control', 'no-store, private');
    }

    public function withdraw(PageTestProbe $probe)
    {
        $probe->withdraw();

        return redirect(PageTestProbe::PATH)->header('Cache-Control', 'no-store, private');
    }
}
