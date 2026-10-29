<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Analytics\BehaviorRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneAnalytics extends Command
{
    protected $signature = 'acquisition:analytics-prune {--limit=1000}';

    protected $description = 'Bounded raw behavior retention; never removes billing, source, mapping, or pending delivery facts';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $cutoff = now()->subDays(90);
        DB::transaction(function () use ($limit, $cutoff) {
            $ids = DB::table('acquisition_behaviors')->where('received_at', '<', $cutoff)->whereNotIn('event_name', BehaviorRecorder::FINANCIAL)->limit($limit)->pluck('id');
            DB::table('acquisition_behaviors')->whereIn('id', $ids)->delete();
            $keys = DB::table('acquisition_event_keys')->where('created_at', '<', $cutoff)->limit($limit)->pluck('id');
            DB::table('acquisition_event_keys')->whereIn('id', $keys)->delete();
            $plays = DB::table('acquisition_play_states')->where('updated_at', '<', $cutoff)->limit($limit)->get(['subject_id', 'play_instance_id']);
            foreach ($plays as $play) {
                DB::table('acquisition_play_states')->where('subject_id', $play->subject_id)->where('play_instance_id', $play->play_instance_id)->delete();
            }
            // Retain pseudonymous identities referenced by frozen orders, sources or financial facts.
            $subjects = DB::table('acquisition_subjects')->where('updated_at', '<', $cutoff);
            foreach (['acquisition_behaviors', 'acquisition_sources', 'acquisition_analytics_orders', 'acquisition_play_states'] as $table) {
                $subjects->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn($table.'.subject_id', 'acquisition_subjects.id'));
            }
            DB::table('acquisition_subjects')->whereIn('id', $subjects->limit($limit)->pluck('id'))->delete();
            $privacy = DB::table('acquisition_privacy')->where('updated_at', '<', $cutoff);
            foreach (['acquisition_subjects', 'acquisition_sources', 'acquisition_analytics_orders', 'acquisition_behaviors'] as $table) {
                $privacy->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn($table.'.privacy_id', 'acquisition_privacy.id'));
            }
            DB::table('acquisition_privacy')->whereIn('id', $privacy->limit($limit)->pluck('id'))->delete();
        });
        $this->info('原始行为有界清理完成；账务、来源、财务行为和待发送事实保留');

        return self::SUCCESS;
    }
}
