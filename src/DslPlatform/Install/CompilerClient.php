<?php
namespace DslPlatform\Install;
use Symfony\Component\Process\Process;

/**
 * Wrapper around dsl-compiler-client
 * @package PhpDslAdmin\Install
 */
class CompilerClient
{
    const CLC_LATEST = 'https://github.com/ngs-doo/dsl-compiler-client/releases/latest';
    const CLC_RELEASE = 'https://github.com/ngs-doo/dsl-compiler-client/releases/download/{version}/dsl-clc.jar';

    private $context;

    private $jarPath;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->jarPath = $context->get(Config::CLC_PATH);
    }

    protected function assertJvmVersion()
    {
        static $requiredJvmExists;
        if (!isset($requiredJvmExists)) {
            $proc = new Process('java -version');
            $proc->run();
            $output = $proc->getOutput();
            if ($output === null)
                $output = $proc->getErrorOutput();
            preg_match('/java version "1\.([0-9]+).*"/', $output, $matches);
            $requiredJvmExists = (count($matches) === 2 && (int)$matches[1] > 6);
        }

        if (!$requiredJvmExists)
            throw new \ErrorException('No required JVM version found');
    }

    protected function assertJarExists()
    {
        if ($this->jarPath === null || !file_exists($this->jarPath))
            $this->downloadClc($this->jarPath);
    }

    protected function downloadClc($path)
    {
        $this->context->write("Downloading latest dsl-compiler-client from github.com\n");
        $ch = curl_init(self::CLC_LATEST);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!curl_exec($ch))
            throw new \ErrorException('Error connecting to github.com');
        $res = curl_getinfo($ch);
        if (!isset($res['http_code']) || $res['http_code'] !== 302)
            throw new \ErrorException('Cannot download dsl-clc.jar, unexpected HTTP code received');
        if (!isset($res['redirect_url']))
            throw new \ErrorException('Cannot download dsl-clc.jar, no Location header received');
        $chunks = explode('/', $res['redirect_url']);
        $version = array_pop($chunks);
        $releaseUrl = str_replace('{version}', $version, self::CLC_RELEASE);
        if (($clcJar = file_get_contents($releaseUrl)) === false)
            throw new \ErrorException('Cannot download dsl-clc.jar from ' . $releaseUrl);
        if (file_put_contents($path, $clcJar) === false)
            throw new \ErrorException('Cannot write dsl-clc.jar to ' . $path);
    }

    protected function run($args, $login = true)
    {
        $this->assertJvmVersion();
        $this->assertJarExists();

        $dslPath = $this->context->get(Config::DSL_PATH);
        $command = 'java -jar '.$this->jarPath.($login ? ' -compiler ' : '').' -dsl='.$dslPath.' '. $args;
        $this->context->write('Running: ' . $command);

        $process = new Process($command);
        $process->run();
        $this->context->write($process->getOutput() ?: $process->getErrorOutput());
        return true;
    }

    public function compile($targets)
    {
        if (is_array($targets))
            $targets = implode(',', $targets);
        return $this->run('-target='.$targets);
    }

    public function downloadRevenj()
    {
        return $this->run('-target=revenj -download');// -dependencies:revenj='.$this->context->get(Config::REVENJ_PATH));
    }

    public function applyMigration()
    {
        $db = $this->context->getDb();
        $connString = sprintf('%s:%s/%s?user=%s&password=%s', $db['server'], $db['port'], $db['database'], $db['user'], $db['password']);
        return $this->run('-migration -apply -force -postgres="' . $connString . '"');
    }
}
