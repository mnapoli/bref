<?php
/**
 * Created by PhpStorm.
 * User: bubba
 * Date: 2019-01-31
 * Time: 16:27
 */

namespace Bref\Bridge\Laravel\Console;

use Symfony\Component\Process\Process;
use Bref\Bridge\Laravel\Package\Archive;
use Illuminate\Console\Command;

class Package extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'bref:package';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Package (zip) the application in preparation for deployment, upload it to S3, and generate the .stack.yaml';

    public function handle(): int {
        if (env('BREF_S3_BUCKET', false) === false){
            $this->error('You must provide the S3 bucket to upload the package to in the BREF_S3_BUCKET environment variable.');
            exit(1);
        }

        $this->info('Creating Archive');
        $package = Archive::laravel();
        if (file_exists(storage_path('latest.zip'))){ unlink(storage_path('latest.zip'));}
        symlink($package, storage_path('latest.zip'));
        $this->info('Package at: ' . $package);

        $process = new Process('sam package --output-template-file .stack.yaml --s3-bucket ' . env('BREF_S3_BUCKET'));
        $process->setWorkingDirectory(base_path());
        $process->start();

        foreach ($process as $type => $data) {
                echo $data;
        }

        $process = new Process(sprintf('sam deploy --template-file .stack.yaml --capabilities CAPABILITY_IAM --stack-name %s', env('APP_NAME')));
        $process->setWorkingDirectory(base_path());
        $process->start();

        foreach ($process as $type => $data) {
                echo $data;
        }
        return 0;
    }
}
