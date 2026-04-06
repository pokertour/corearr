<?php
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/resources/views/livewire'));
foreach ($dir as $file) {
    if (!$file->isDir() && str_ends_with($file->getFilename(), '.wire.php')) {
        $oldName = $file->getRealPath();
        $newName = str_replace('.wire.php', '.blade.php', $oldName);
        rename($oldName, $newName);
        $content = file_get_contents($newName);
        $content = str_replace('Livewire\Volt\Component', 'Livewire\Component', $content);
        file_put_contents($newName, $content);
    }
}
echo "Done.";
