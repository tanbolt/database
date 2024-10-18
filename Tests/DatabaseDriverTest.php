<?php

use PHPUnit\Framework\TestSuite;

class DatabaseDriverTest extends TestSuite
{
    private static function getTestDriverFiles()
    {
        $drivers = [];
        $dir = realpath(__DIR__.'/Config');
        if (function_exists('glob')) {
            $drivers = glob($dir. DIRECTORY_SEPARATOR.'*.php');
        } elseif (function_exists('scandir')) {
            foreach (scandir($dir) as $file) {
                if (substr($file, -4) === '.php') {
                    $drivers[] = $dir.  DIRECTORY_SEPARATOR .$file;
                }
            }
        } elseif (function_exists('opendir') && function_exists('readdir')) {
            $handle = opendir($dir);
            while(($file = readdir($handle)) !== FALSE) {
                if (substr($file, -4) === '.php') {
                    $drivers[] = $dir . DIRECTORY_SEPARATOR . $file;
                }
            }
            closedir($handle);
        }

        $testFiles = [];
        $testDir = __DIR__ . DIRECTORY_SEPARATOR . 'Driver' . DIRECTORY_SEPARATOR;
        foreach ($drivers as $driver) {
            $config = include $driver;
            if (is_array($config) && array_key_exists('test', $config) && $config['test']) {
                $driver = substr(basename($driver), 0, -4);

                // connection
                if (array_key_exists('connection', $config) && $config['connection'] &&
                    is_file($testDir . 'DatabaseConnection' . $driver. '.php')
                ) {
                    $testFiles[] = $testDir . 'DatabaseConnection' . $driver. '.php';
                }

                // schema
                if (array_key_exists('schema', $config) && $config['schema'] &&
                    is_file($testDir . 'DatabaseSchema' . $driver. '.php')
                ) {
                    $testFiles[] = $testDir . 'DatabaseSchema' . $driver. '.php';
                }

                // query
                if (array_key_exists('builder', $config) && $config['builder'] &&
                    is_file($testDir . 'DatabaseBuilder' . $driver. '.php')
                ) {
                   $testFiles[] = $testDir . 'DatabaseBuilder' . $driver. '.php';
                }
            }
        }
        return  $testFiles;
    }

    public static function suite()
    {
        $suite = new self(__NAMESPACE__);
        $suite->addTestFiles(self::getTestDriverFiles());
        return $suite;
    }
}
