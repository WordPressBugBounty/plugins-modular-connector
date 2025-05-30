<?php

namespace Modular\ConnectorDependencies\Illuminate\Session;

use Modular\ConnectorDependencies\Illuminate\Filesystem\Filesystem;
use Modular\ConnectorDependencies\Illuminate\Support\Carbon;
use SessionHandlerInterface;
use Modular\ConnectorDependencies\Symfony\Component\Finder\Finder;
class FileSessionHandler implements SessionHandlerInterface
{
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;
    /**
     * The path where sessions should be stored.
     *
     * @var string
     */
    protected $path;
    /**
     * The number of minutes the session should be valid.
     *
     * @var int
     */
    protected $minutes;
    /**
     * Create a new file driven handler instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @param  string  $path
     * @param  int  $minutes
     * @return void
     */
    public function __construct(Filesystem $files, $path, $minutes)
    {
        $this->path = $path;
        $this->files = $files;
        $this->minutes = $minutes;
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function open($savePath, $sessionName)
    {
        return \true;
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function close()
    {
        return \true;
    }
    /**
     * {@inheritdoc}
     *
     * @return string|false
     */
    #[\ReturnTypeWillChange]
    public function read($sessionId)
    {
        if ($this->files->isFile($path = $this->path . '/' . $sessionId)) {
            if ($this->files->lastModified($path) >= Carbon::now()->subMinutes($this->minutes)->getTimestamp()) {
                return $this->files->sharedGet($path);
            }
        }
        return '';
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function write($sessionId, $data)
    {
        $this->files->put($this->path . '/' . $sessionId, $data, \true);
        return \true;
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function destroy($sessionId)
    {
        $this->files->delete($this->path . '/' . $sessionId);
        return \true;
    }
    /**
     * {@inheritdoc}
     *
     * @return int|false
     */
    #[\ReturnTypeWillChange]
    public function gc($lifetime)
    {
        $files = Finder::create()->in($this->path)->files()->ignoreDotFiles(\true)->date('<= now - ' . $lifetime . ' seconds');
        foreach ($files as $file) {
            $this->files->delete($file->getRealPath());
        }
    }
}
