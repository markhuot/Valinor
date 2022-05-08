<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Cache\Compiled;

use CuyZ\Valinor\Cache\Exception\CacheDirectoryNotWritable;
use CuyZ\Valinor\Cache\Exception\CompiledPhpCacheFileNotWritten;
use CuyZ\Valinor\Cache\Exception\CorruptedCompiledPhpCacheFile;
use CuyZ\Valinor\Utility\Polyfill;
use DateInterval;
use DateTime;
use Error;
use FilesystemIterator;
use Psr\SimpleCache\CacheInterface;
use Traversable;

use function file_exists;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function rename;
use function sha1;
use function time;
use function uniqid;
use function unlink;

/**
 * @internal
 *
 * @template EntryType
 * @implements CacheInterface<EntryType>
 */
final class CompiledPhpFileCache implements CacheInterface
{
    private const TEMPORARY_DIR_PERMISSION = 510;

    private const GENERATED_MESSAGE = 'Generated by ' . self::class;

    private string $cacheDir;

    private CacheCompiler $compiler;

    /** @var array<PhpCacheFile<EntryType>> */
    private array $files = [];

    public function __construct(string $cacheDir, CacheCompiler $compiler)
    {
        $this->cacheDir = $cacheDir;
        $this->compiler = $compiler;
    }

    public function has($key): bool
    {
        $filename = $this->path($key);

        if (! file_exists($filename)) {
            return false;
        }

        return $this->getFile($filename)->isValid();
    }

    public function get($key, $default = null)
    {
        if (! $this->has($key)) {
            return $default;
        }

        $filename = $this->path($key);

        return $this->getFile($filename)->value();
    }

    public function set($key, $value, $ttl = null): bool
    {
        $code = $this->compile($value, $ttl);

        $tmpDir = $this->cacheDir . DIRECTORY_SEPARATOR . '.valinor.tmp';

        if (! is_dir($tmpDir) && ! @mkdir($tmpDir, self::TEMPORARY_DIR_PERMISSION, true)) {
            throw new CacheDirectoryNotWritable($this->cacheDir);
        }

        /** @infection-ignore-all */
        $tmpFilename = $tmpDir . DIRECTORY_SEPARATOR . uniqid('', true);
        $filename = $this->path($key);

        if (! @file_put_contents($tmpFilename, $code) || ! @rename($tmpFilename, $filename)) {
            throw new CompiledPhpCacheFileNotWritten($filename);
        }

        return true;
    }

    public function delete($key): bool
    {
        $filename = $this->path($key);

        if (file_exists($filename)) {
            return @unlink($filename);
        }

        return true;
    }

    public function clear(): bool
    {
        $success = true;

        /** @var FilesystemIterator $file */
        foreach (new FilesystemIterator($this->cacheDir) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $line = $file->openFile()->getCurrentLine();

            if (! $line || ! Polyfill::str_contains($line, self::GENERATED_MESSAGE)) {
                continue;
            }

            $success = @unlink($this->cacheDir . DIRECTORY_SEPARATOR . $file->getFilename()) && $success;
        }

        return $success;
    }

    /**
     * @return Traversable<string, EntryType>
     */
    public function getMultiple($keys, $default = null): Traversable
    {
        foreach ($keys as $key) {
            yield $key => $this->get($key, $default);
        }
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        $deleted = true;

        foreach ($keys as $key) {
            $deleted = $this->delete($key) && $deleted;
        }

        return $deleted;
    }

    /**
     * @param mixed $value
     * @param int|DateInterval|null $ttl
     */
    private function compile($value, $ttl = null): string
    {
        $validation = [];

        if ($ttl) {
            $time = $ttl instanceof DateInterval
                ? (new DateTime())->add($ttl)->getTimestamp()
                : time() + $ttl;

            $validation[] = "time() < $time";
        }

        if ($this->compiler instanceof CacheValidationCompiler) {
            $validation[] = $this->compiler->compileValidation($value);
        }

        $generatedMessage = self::GENERATED_MESSAGE;

        $code = $this->compiler->compile($value);
        $validationCode = empty($validation)
            ? 'true'
            : '(' . implode(' && ', $validation) . ')';

        return <<<PHP
        <?php // $generatedMessage
        return new class(\$this->compiler instanceof \CuyZ\Valinor\Cache\Compiled\HasArguments ? \$this->compiler->arguments() : []) implements \CuyZ\Valinor\Cache\Compiled\PhpCacheFile {
            /** @var array<string, mixed> */
            private array \$arguments;
            
            public function __construct(array \$arguments)
            {
                \$this->arguments = \$arguments;
            }

            public function value()
            {
                return $code;
            }

            public function isValid(): bool
            {
                return $validationCode;
            }
        };
        PHP;
    }

    /**
     * @return PhpCacheFile<EntryType>
     */
    private function getFile(string $filename): PhpCacheFile
    {
        if (! isset($this->files[$filename])) {
            try {
                $object = include $filename;
            } catch (Error $exception) {
                // @PHP8.0 remove variable
            }

            if (! isset($object) || ! $object instanceof PhpCacheFile) {
                throw new CorruptedCompiledPhpCacheFile($filename);
            }

            $this->files[$filename] = $object;
        }

        return $this->files[$filename];
    }

    private function path(string $key): string
    {
        /** @infection-ignore-all */
        return $this->cacheDir . DIRECTORY_SEPARATOR . sha1($key) . '.php';
    }
}
