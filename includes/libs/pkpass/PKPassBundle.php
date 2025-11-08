<?php

namespace PKPass;

use ZipArchive;

/**
 * A bundle of multiple passes, which can be output as a `.pkpasses` file.
 */
class PKPassBundle
{
    /**
     * @var PKPass[]
     */
    private $passes = [];

    /**
     * @var string|null
     */
    private $tempFile = null;

    /**
     * @var string
     */
    private $tempPath;

    public function __construct()
    {
        $this->tempPath = sys_get_temp_dir();
    }

    /**
     * Set the path to the temporary directory.
     *
     * @param string $path Path to temporary directory
     */
    public function setTempPath($path)
    {
        $this->tempPath = $path;
    }

    /**
     * Add a pass to the bundle.
     *
     * @param PKPass $pass
     */
    public function add(PKPass $pass)
    {
        $this->passes[] = $pass;
    }

    private function createZip(): ZipArchive
    {
        if (empty($this->passes)) {
            throw new \RuntimeException('Cannot create bundle with no passes. Add at least one pass before creating the bundle.');
        }

        $zip = new ZipArchive();
        $this->tempFile = tempnam($this->tempPath, 'pkpasses');

        if ($zip->open($this->tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create zip archive.');
        }

        $counter = 1;
        foreach ($this->passes as $pass) {
            $zip->addFromString("pass{$counter}.pkpass", $pass->create(false));
            $counter++;
        }

        if ($zip->close() === false) {
            throw new \RuntimeException('Could not close zip archive.');
        }

        // Re-open the zip to read it
        if ($zip->open($this->tempFile) !== true) {
            throw new \RuntimeException('Could not reopen zip archive.');
        }

        return $zip;
    }

    /**
     * Save the bundle as a `.pkpasses` file to the filesystem.
     *
     * @param string $path
     */
    public function save(string $path)
    {
        $zip = $this->createZip();
        $zip->close();

        if (@copy($this->tempFile, $path) === false) {
            $error = error_get_last();
            unlink($this->tempFile);
            throw new \RuntimeException('Could not write zip archive to file. Error: ' . ($error['message'] ?? 'Unknown error'));
        }

        unlink($this->tempFile);
    }

    /**
     * Output the bundle as a `.pkpasses` file to the browser.
     */
    public function output()
    {
        $zip = $this->createZip();
        $zip->close();

        header('Content-Type: application/vnd.apple.pkpasses');
        header('Content-Disposition: attachment; filename="passes.pkpasses"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');

        readfile($this->tempFile);
        unlink($this->tempFile);
        exit;
    }
}