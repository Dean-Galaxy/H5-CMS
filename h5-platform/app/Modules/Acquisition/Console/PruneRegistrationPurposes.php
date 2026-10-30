<?php

namespace App\Modules\Acquisition\Console;

use App\Modules\Acquisition\Analytics\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneRegistrationPurposes extends Command
{
    protected $signature = 'acquisition:r1-prune {--limit=1000}';

    protected $description = 'Bounded R1 operation receipts and necessary preference proof retention; no billing/source deletion';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            return self::FAILURE;
        }
        $limit = max(1, min(5000, (int) $this->option('limit')));
        DB::transaction(function () use ($limit) {
            $ops = DB::table('acquisition_r1_operations')->where('expires_at', '<=', Clock::stamp())->limit($limit)->pluck('id');
            DB::table('acquisition_r1_operations')->whereIn('id', $ops)->delete();
            $proofs = DB::table('acquisition_r1_proofs')->where('expires_at', '<=', Clock::stamp())->limit($limit)->pluck('id');
            DB::table('acquisition_r1_proofs')->whereIn('id', $proofs)->delete();
        });
        $this->info('R1有界清理完成；原账务、来源、拒绝证明与C轨迹未改写');

        return self::SUCCESS;
    }
}
