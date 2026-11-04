<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('downloads:work')]
#[Description('Run as many queue workers as Settings → "Downloads at the same time" asks for')]
class RunWorkers extends Command
{
    public function handle(): int
    {
        $this->components->info('Running downloads. The number at a time follows the Settings page. Press Ctrl+C to stop.');

        /** @var Process[] $workers */
        $workers = [];

        while (true) {
            // Each worker runs one job and exits (or exits after 30 s with nothing to do), so a
            // changed setting applies within seconds and lowering it never stops a running job
            $workers = array_filter($workers, fn (Process $worker) => $worker->isRunning());

            for ($running = count($workers); $running < Setting::parallelJobs(); $running++) {
                $workers[] = $this->startWorker();
            }

            sleep(2);
        }
    }

    private function startWorker(): Process
    {
        $worker = new Process([PHP_BINARY, base_path('artisan'), 'queue:work', '--max-jobs=1', '--max-time=30', '--sleep=2', '--no-interaction']);
        $worker->setTimeout(null);
        $worker->start(fn (string $type, string $output) => $this->output->write($output));

        return $worker;
    }
}
