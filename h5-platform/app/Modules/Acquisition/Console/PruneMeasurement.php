<?php

namespace App\Modules\Acquisition\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneMeasurement extends Command
{
    protected $signature = 'acquisition:c-prune {--limit=1000}';

    protected $description = 'Bounded C-only retention; no billing, identity, legacy analytics or source changes';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        DB::transaction(function () use ($limit) {
            foreach (['events' => ['received_at', 'sequence'], 'event_keys' => ['created_at', 'id']] as $name => [$time, $pk]) {
                $q = DB::table('acquisition_c_'.$name);
                $ids = (clone $q)->where($time, '<=', now()->subDays(30))->orderBy($time)->limit($limit)->pluck($pk);
                $q->whereIn($pk, $ids)->delete();
            }
            $plays = DB::table('acquisition_c_plays')->where('updated_at', '<=', now()->subDays(30))->limit($limit)->get(['subject_id', 'play_instance_id']);
            foreach ($plays as $p) {
                DB::table('acquisition_c_plays')->where('subject_id', $p->subject_id)->where('play_instance_id', $p->play_instance_id)->delete();
            }
            $sessions = DB::table('acquisition_c_sessions')->where('last_seen_at', '<=', now()->subDays(30));
            foreach (['events', 'plays'] as $name) {
                $sessions->whereNotExists(fn ($q) => $q->selectRaw('1')->from('acquisition_c_'.$name)
                    ->whereColumn('acquisition_c_'.$name.'.measurement_session_id', 'acquisition_c_sessions.id'));
            }
            DB::table('acquisition_c_sessions')->whereIn('id', $sessions->limit($limit)->pluck('id'))->delete();
            $empty = DB::table('acquisition_c_subjects')->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhereNotNull('ended_at'));
            foreach (['events', 'event_keys', 'plays'] as $name) {
                $empty->whereNotExists(fn ($q) => $q->selectRaw('1')->from('acquisition_c_'.$name)
                    ->whereColumn('acquisition_c_'.$name.'.subject_id', 'acquisition_c_subjects.id'));
            }
            $ids = $empty->limit($limit)->pluck('id');
            DB::table('acquisition_c_subjects')->whereIn('id', $ids)->delete();
            $ids = DB::table('acquisition_c_subjects')->where('created_at', '<=', now()->subDays(37))->limit($limit)->pluck('id');
            DB::table('acquisition_c_subjects')->whereIn('id', $ids)->delete();
            $ids = DB::table('acquisition_c_preferences')->where('expires_at', '<=', now())->limit($limit)->pluck('id');
            DB::table('acquisition_c_preferences')->whereIn('id', $ids)->delete();
        });
        $this->info('C-only bounded retention completed; legacy and billing facts untouched.');

        return self::SUCCESS;
    }
}
