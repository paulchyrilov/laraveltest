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
        'testlib',
        'testlib3',
    ];

    /**
     * @var string
     */
    private $basePath;

    /**
     * Build constructor.
     */
    public function __construct()
    {
        $this->basePath = App::basePath();
        parent::__construct();
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $gitWrapper = new GitWrapper();

        $currentLibraryVersions = [];
        $actualLibraryVersions = [];

        $taskNumber = $this->ask('Enter task number:');

        if(!is_numeric($taskNumber)) {
            $this->error('Invalid task number. Only numeric is allowed.');
            return;
        }

        $continue = true;
        $updatePrimaryProject = false;
        foreach ($this->libs as $libName) {
            $libPath = realpath($this->basePath . '/../' . $libName);

            if(!$libPath) {
                $this->error('Invalid library: ' . $libName);
                return;
            }

            $this->warn('Working with path: ' . $libPath);
            $gitWorkingCopy = $gitWrapper->workingCopy($libPath);

            try {
                $actualLibraryVersions[$libName] = $currentLibraryVersions[$libName] = $this->getCurrentLibraryVersion($gitWorkingCopy);
                $hasChanges = $this->releaseLibrary($gitWorkingCopy, $taskNumber);
                if($hasChanges) {
                    $newVersion = $this->incrementTagVersion($currentLibraryVersions[$libName]);
                    $tagUpdated = $this->updateLibraryVersionTag($gitWorkingCopy, $newVersion);
                    if(false !== $tagUpdated) {
                        $actualLibraryVersions[$libName] = $newVersion;
                        $updatePrimaryProject = true;
                    }
                }
            } catch (\Exception $e) {
                $this->error($e->getMessage());
                $continue = $updatePrimaryProject = false;
            }
            if(false === $continue) {
                $continue = $this->confirm('Do you wish to continue?', true);
                if(!$continue) {
                    return;
                }
            }
        }

        if($updatePrimaryProject) {

            $this->warn('Working with primary project: ' . $this->basePath);
            $gitWorkingCopy = $gitWrapper->workingCopy($this->basePath);

            if(!in_array('release', $gitWorkingCopy->getBranches()->all())) {
                $this->info('create release branch');
                $gitWorkingCopy->branch('release');
                $gitWorkingCopy->push('origin', 'release');
                $this->line($gitWorkingCopy->getOutput());
            }

            $this->info('checkout release');
            $gitWorkingCopy->checkout('release');
            $this->line($gitWorkingCopy->getOutput());

            $this->info('pulling release');
            $gitWorkingCopy->pull('origin', 'release');
            $this->line($gitWorkingCopy->getOutput());

            $this->info('Updating library versions in composer.json');
            foreach ($actualLibraryVersions as $libName => $version) {
                $this->updateLibraryVersion($libName, $version);
            }

            $this->info('Updating dependencies');
            $output = shell_exec('composer update');
            $this->line($output);

            $message = 'refs #' . $taskNumber . ' Released';
            foreach ($actualLibraryVersions as $lib => $version) {
                $message .= ' * ' . $lib . ' ' . $version;
            }
            $confirm = $this->confirm('Do you wish commit and push changes with message: "' . $message . '"', true);
            if(!$confirm) {
                return;
            }

            $this->info('committing');
            $gitWorkingCopy->commit($message);
            $this->line($gitWorkingCopy->getOutput());

            $this->info('pushing release');
            $gitWorkingCopy->push('origin', 'release');
            $this->line($gitWorkingCopy->getOutput());
        } else {
            $this->info('No changes detected in libraries, nothing to release.');
        }

        return;
    }

    /**
     * Detects new commits in library(covered by gitWorkingCopy), merges and pushes them in "release" branch + creates tag.
     *
     * @param GitWorkingCopy $gitWorkingCopy
     * @param string $taskNumber
     * @return bool
     * @throws \Exception
     */
    protected function releaseLibrary(GitWorkingCopy $gitWorkingCopy, $taskNumber)
    {
        if($gitWorkingCopy->hasChanges()) {
            throw new \Exception('Uncommitted changes fount in library');
        }

        $this->info('checkout master');
        $gitWorkingCopy->checkout('master');
        $this->line($gitWorkingCopy->getOutput());

        $this->info('pulling master');
        $gitWorkingCopy->pull('origin', 'master');
        $this->line($gitWorkingCopy->getOutput());

        $this->info('pushing master');
        $gitWorkingCopy->push('origin', 'master');
        $this->line($gitWorkingCopy->getOutput());

        if(!in_array('release', $gitWorkingCopy->getBranches()->all())) {
            $this->info('create release branch');
            $gitWorkingCopy->branch('release');
            $gitWorkingCopy->push('origin', 'release');
            $this->line($gitWorkingCopy->getOutput());
        }

        $this->info('checkout release');
        $gitWorkingCopy->checkout('release');
        $this->line($gitWorkingCopy->getOutput());

        $this->info('pulling release');
        $gitWorkingCopy->pull('origin', 'release');
        $this->line($gitWorkingCopy->getOutput());

        $output = $gitWorkingCopy->diff('release..master')->getOutput();

        if(empty($output)) {
            $this->info('No diff in "master" and "release" detected.');
            return false;
        }

        $this->info('Diff in "master" and "release" detected.');
        $this->info('merge master->release');
        $gitWorkingCopy->merge('master', ['m' => 'refs #' . $taskNumber]);
        $this->line($gitWorkingCopy->getOutput());

        $this->info('pushing release');
        $gitWorkingCopy->push('origin', 'release');
        $this->line($gitWorkingCopy->getOutput());

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