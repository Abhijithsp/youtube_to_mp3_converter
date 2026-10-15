<?php

namespace App\Console\Commands;

use App\Models\Download;
use App\Services\WaveformService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('downloads:waveforms {--force : Recreate waveforms that already exist}')]
#[Description('Create player waveforms for finished downloads that do not have one yet')]
class GenerateWaveforms extends Command
{
    public function handle(WaveformService $waveformService): int
    {
        $query = Download::completed()->whereNotNull('file_path');

        if (!$this->option('force')) {
            $query->whereNull('waveform');
        }

        $downloads = $query->get()->filter(fn (Download $download) => file_exists($download->file_path));

        if ($downloads->isEmpty()) {
            $this->components->info('Every finished download already has a waveform.');

            return self::SUCCESS;
        }

        $failed = 0;

        $this->withProgressBar($downloads, function (Download $download) use ($waveformService, &$failed) {
            $waveform = $waveformService->generate($download->file_path);

            $waveform ? $download->update(['waveform' => $waveform]) : $failed++;
        });

        $this->newLine(2);
        $this->components->info(($downloads->count() - $failed) . ' waveform(s) created' . ($failed ? ", {$failed} file(s) could not be read" : '') . '.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
