<?php
namespace Robo\Task\Filesystem;

use Robo\Common\ResourceExistenceChecker;
use Robo\Result;
use Robo\Exception\TaskException;

/**
 * Copies one dir into another
 *
 * ``` php
 * <?php
 * $this->taskCopyDir(['dist/config' => 'config'])->run();
 * // as shortcut
 * $this->_copyDir('dist/config', 'config');
 * ?>
 * ```
 */
class CopyDir extends BaseDir
{
    use ResourceExistenceChecker;

    /**
     * @var int
     */
    protected $chmod = 0755;

    /**
     * Files to exclude on copying.
     *
     * @var string[]
     */
    protected $exclude = [];

    /**
     * Overwrite destination files newer than source files.
     */
    protected $overwrite = true;

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        if (!$this->checkResources($this->dirs, 'dir')) {
            return Result::error($this, 'Source directories are missing!');
        }
        foreach ($this->dirs as $src => $dst) {
            $this->copyDir($src, $dst);
            $this->printTaskInfo('Copied from {source} to {destination}', ['source' => $src, 'destination' => $dst]);
        }
        return Result::success($this);
    }

    /**
     * Sets the default folder permissions for the destination if it doesn't exist
     *
     * @link http://en.wikipedia.org/wiki/Chmod
     * @link http://php.net/manual/en/function.mkdir.php
     * @link http://php.net/manual/en/function.chmod.php
     *
     * @param int $value
     *
     * @return $this
     */
    public function dirPermissions($value)
    {
        $this->chmod = (int)$value;
        return $this;
    }

    /**
     * List files to exclude.
     *
     * @param string[] $exclude
     *
     * @return $this
     */
    public function exclude($exclude = [])
    {
        $this->exclude = $exclude;
        return $this;
    }

    /**
     * Destination files newer than source files are overwritten.
     *
     * @param bool $overwrite
     *
     * @return $this
     */
    public function overwrite($overwrite)
    {
        $this->overwrite = $overwrite;
        return $this;
    }

    /**
     * Copies a directory to another location.
     *
     * @param string $src Source directory
     * @param string $dst Destination directory
     * @param string $parent Parent directory
     *
     * @throws \Robo\Exception\TaskException
     */
    protected function copyDir($src, $dst, $parent = '')
    {
        $dir = @opendir($src);
        if (false === $dir) {
            throw new TaskException($this, "Cannot open source directory '" . $src . "'");
        }
        if (!is_dir($dst)) {
            mkdir($dst, $this->chmod, true);
        }
        while (false !== ($file = readdir($dir))) {
            // Support basename and full path exclusion.
            if (in_array($file, $this->exclude) || in_array($parent . $file, $this->exclude) || in_array($src . DIRECTORY_SEPARATOR . $file, $this->exclude)) {
                continue;
            }
            if (($file !== '.') && ($file !== '..')) {
                $srcFile = $src . '/' . $file;
                $destFile = $dst . '/' . $file;
                if (is_dir($srcFile)) {
                    $this->copyDir($srcFile, $destFile, $parent . $file . DIRECTORY_SEPARATOR);
                } else {
                    $this->fs->copy($srcFile, $destFile, $this->overwrite);
                }
            }
        }
        closedir($dir);
    }
}
