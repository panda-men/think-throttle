<?php
declare(strict_types=1);

// 跨平台语法检查，不依赖 shell 的 find、xargs 或路径转义。
$root = dirname(__DIR__);
foreach (['src', 'config', 'tests', 'scripts'] as $folder) {
    if (!is_dir($root.'/'.$folder)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') continue;
        $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (proc_close($process) !== 0) exit(1);
    }
}
