<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ExampleScheduledCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:example-scheduled-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Example scheduled command that runs daily';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Running example scheduled command...');
        
        $this->info('Performing daily maintenance tasks...');
        
        $this->info('Example scheduled command completed successfully!');
        
        return Command::SUCCESS;
    }
}
