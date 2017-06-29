<?php
namespace App\Console\Commands\Install;

use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Class Build
 * @package App\Console\Commands\Install
 */
class Build extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'install:build release';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build release';

    /**
     * @var GitWrapper
     */
    protected $gitWrapper;

    private $libs = [
//        'BL',
        'testlib',
    ];

    /**
     * Build constructor.
     * @param GitWrapper $gitWrapper
     */
    public function __construct(GitWrapper $gitWrapper)
    {
        $this->gitWrapper = $gitWrapper;
        parent::__construct();
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $libVersions = [];

        $taskNumber = $this->ask('Enter task number:');

        if(!is_numeric($taskNumber)) {
            $this->alert('Invalid task number. Only numerics are allowed.');
            return;
        }

        $continue = true;
        $composerUpdateRequired = false;
        foreach ($this->libs as $libName) {
            if(false === $continue) {
                $continue = $this->confirm('Do you wish to continue?', true);
                if(!$continue) {
                    return;
                }
            }
            $libPath = realpath(App::basePath() . '/../' . $libName);

            if(!$libPath) {
                $this->alert('Invalid library: ' . $libName);
                return;
            }

            $this->warn('Working with path: ' . $libPath);
            $gitWrapper = $this->gitWrapper->workingCopy($libPath);

            try {
                $libVersions[$libName] = $this->getCurrentLibraryVersion($gitWrapper);
                $hasChanges = $this->releaseLibrary($gitWrapper, $taskNumber);
                if($hasChanges) {
                    $newVersion = $this->incrementTagVersion($libVersions[$libName]);
                    $tagUpdated = $this->updateLibraryVersionTag($gitWrapper, $newVersion);
                    if(false !== $tagUpdated) {
                        $this->updateLibraryVersion($libName, $newVersion);
                        $composerUpdateRequired = true;
                    }
                }
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                $continue = false;
            }
        }

        if($composerUpdateRequired) {
            $this->warn('Working with primary project: ' . App::basePath());
            $output = shell_exec('composer update');
            $this->line($output);
            $gitWrapper = $this->gitWrapper->workingCopy(App::basePath());

            $message = '#refs' . $taskNumber . ' Released';
            foreach ($libVersions as $lib => $version) {
                $message .= ' * ' . $lib . ' ' . $version;
            }
            $confirm = $this->confirm('Do you wish to create and push commit with message: "' . $message . '"', true);
            if(!$confirm) {
                return;
            }

            if(!in_array('release', $gitWrapper->getBranches()->all())) {
                $this->info('create release branch');
                $gitWrapper->branch('release');
                $gitWrapper->push('origin', 'release');
                $this->line($gitWrapper->getOutput());
            }

            $this->info('checkout release');
            $gitWrapper->checkout('release');
            $this->line($gitWrapper->getOutput());

            $this->info('pull');
            $gitWrapper->pull('origin', 'release');
            $this->line($gitWrapper->getOutput());

            $this->info('commit');
            $gitWrapper->commit($message);
            $this->line($gitWrapper->getOutput());

            $this->info('push');
            $gitWrapper->push('origin', 'release');
            $this->line($gitWrapper->getOutput());
        }

        return;
    }

    /**
     * @param GitWorkingCopy $gitWrapper
     * @param $taskNumber
     * @return bool
     * @throws \Exception
     */
    protected function releaseLibrary(GitWorkingCopy $gitWrapper, $taskNumber)
    {


        if($gitWrapper->hasChanges()) {
            throw new \Exception('Uncommited changes fount in library');
        }

        $this->info('checkout master');
        $gitWrapper->checkout('master');
        $this->line($gitWrapper->getOutput());

        $lastLocalCommit = trim($gitWrapper->run(['rev-parse','master'])->getOutput(), "\n");
        $this->line('Local commit hash: ' . $lastLocalCommit);

        $this->info('pull');
        $gitWrapper->pull();
        $this->line($gitWrapper->getOutput());

        $originLastCommit = trim($gitWrapper->run(['rev-parse','origin/master'])->getOutput(), "\n");
        $this->line('Origin Last commit hash: ' . $originLastCommit);

        if($lastLocalCommit === $originLastCommit) {
            $this->line('No new commits detected.');
            return false;
        }

        $this->info('push master');
        $gitWrapper->push('origin', 'master');
        $this->line($gitWrapper->getOutput());

        if(!in_array('release', $gitWrapper->getBranches()->all())) {
            $this->info('create release branch');
            $gitWrapper->branch('release');
            $gitWrapper->push('origin', 'release');
            $this->line($gitWrapper->getOutput());
        }
        $this->info('checkout release');
        $gitWrapper->checkout('release');
        $this->line($gitWrapper->getOutput());

        $this->info('merge master->release');
        $gitWrapper->merge('master', ['m' => 'refs #' . $taskNumber]);
        $this->line($gitWrapper->getOutput());

        $this->info('push release');
        $gitWrapper->push('origin', 'release');
        $this->line($gitWrapper->getOutput());

        return true;

    }

    /**
     * @param GitWorkingCopy $gitWrapper
     * @return string
     * @throws \Exception
     */
    protected function getCurrentLibraryVersion(GitWorkingCopy $gitWrapper)
    {
        $tags = $gitWrapper->tag()->getOutput();

        if(!is_string($tags) || empty($tags)) {
            return '0.0.00';
        }

        $tags = explode("\n", trim($tags, "\n"));

        $lastTag = end($tags);

        $matched = preg_match('/release-(\d.\d.\d+)/', $lastTag, $version);

        if(!$matched || 2 !== count($version)) {
            throw new \Exception('Can\'t parse tag: ' . $lastTag);
        }

        return $version[1];
    }

    /**
     * @param GitWorkingCopy $gitWrapper
     * @param $newVersion
     * @return bool
     */
    protected function updateLibraryVersionTag(GitWorkingCopy $gitWrapper, $newVersion)
    {
        $newTag = 'release-' . $newVersion;

        $confirm = $this->confirm('Create and push new tag: ' . $newTag, true);

        if(!$confirm) {
            return false;
        }
        $gitWrapper->tag($newTag);
        $gitWrapper->pushTag($newTag);

        return true;
    }

    /**
     * @param $currentVersion
     * @return string
     * @throws \Exception
     */
    private function incrementTagVersion($currentVersion)
    {
        $matched = preg_match('/(\d+).(\d+).(\d+)/', $currentVersion, $version);

        if(!$matched || 4 !== count($version)) {
            throw new \Exception('Can\'t parse version: ' . $currentVersion);
        }

        ++$version[3];
        if($version[3] > 99) {
            $version[3] = 0;
            ++$version[2];
            if($version[2]>9) {
                $version[2] = 0;
                ++$version[1];
            }
        }

        return $version[1] . '.' . $version[2] . '.' .  str_pad ($version[3], 2,"0",STR_PAD_LEFT);
    }

    /**
     * @param $libName
     * @param $newTag
     * @throws \Exception
     */
    private function updateLibraryVersion($libName, $newTag)
    {
        $composerFile = App::basePath() . '/composer.json';
        $input = file_get_contents($composerFile);
        $composerJson = json_decode($input, true);

        $composerLibName = 'paulchyrilov/'.$libName;

        if(!isset($composerJson['require'][$composerLibName])) {
            throw new \Exception('Could not find library ' . $libName . ' in composer.json');
        }

        $composerJson['require'][$composerLibName] = $newTag;

        file_put_contents($composerFile, json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

}