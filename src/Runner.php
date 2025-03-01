<?php

namespace Phpium;

use Composer\Factory;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RegexIterator;

class Runner
{

    private static $config = [];


    public static function run(array $argv)
    {
        $composerJsonPath = Factory::getComposerFile();
        $parentBasePath = dirname(realpath($composerJsonPath));
        $parentTestDirectory = $parentBasePath . '/tests';

        $configPath = $parentBasePath . '/phpium.xml';

        if (file_exists($configPath)) {
            $xml = simplexml_load_file($configPath);
            foreach ($xml->setting as $setting) {
                self::$config[(string)$setting['name']] = (string)$setting;
            }
        }

        $debugMode = self::get('debug') === 'true';
        $time = microtime(true);
        $testCount = 0;
        $errorCount = 0;
        $errors = [];

        echo 'PHPIUM 1.0 by Tvup' . PHP_EOL . PHP_EOL;

        $runningMarkerFilePath = $parentBasePath . '/.testsrunning';
        file_put_contents($runningMarkerFilePath, '');

        if (!is_dir($parentTestDirectory) || (is_dir($parentTestDirectory) && count(
                    scandir($parentTestDirectory)
                ) === 2)) {
            echo "No files found" . PHP_EOL;
            return 1;
        }

        $phpFiles = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($parentTestDirectory, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $phpFiles = new RegexIterator($phpFiles, '/\.php$/i');

        // Iterate over each PHP file in all subdirectories
        foreach ($phpFiles as $file) {
            // Get class name by file path
            $classPath = str_replace([$parentTestDirectory . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $className = str_replace(DIRECTORY_SEPARATOR, '\\', $classPath);

            // Check if class exists
            if (strpos($className, '\\') !== false) {
                $className = 'Tests\\' . $className;

                if (class_exists($className)) {
                    // Also only act on classes that are in the desired directory
                    $filterName = self::get('test-directory');
                    if ($filterName && str_contains($className, $filterName) !== false) {
                        $reflectionClass = new ReflectionClass($className);

                        // Make sure that classes for instantiation isn't abstract
                        if (!$reflectionClass->isAbstract()) {
                            // Instantiate class
                            $instance = $reflectionClass->newInstance();

                            // Get all public methods
                            $methods = $reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC);

                            $arr = [];
                            foreach ($methods as $method) {
                                // Call each public method, if it belongs to working class
                                if ($method->class === $className) {
                                    $arr[] = $method;
                                }
                            }
                            $setupMethod = null;
                            $tearDownMethod = null;

                            foreach ($arr as $key => $method) {
                                if ($method->name == 'setUp') {
                                    unset($arr[$key]);
                                    $setupMethod = $method;
                                }
                                if ($method->name == 'tearDown') {
                                    unset($arr[$key]);
                                    $tearDownMethod = $method;
                                }
                            }

                            try {
                                if ($setupMethod) {
                                    $setupMethod->invoke($instance);
                                } else {
                                    $parentClass = $reflectionClass->getParentClass();
                                    if ($parentClass) {
                                        foreach ($parentClass->getMethods() as $parentMethod) {
                                            if ($parentMethod->name == 'setUp') {
                                                $parentMethod->invoke($instance);
                                            }
                                        }
                                    }
                                }
                            } catch (Exception $e) {
                                echo "E"; // Mark error in setup
                                $errorCount++;
                                $errors[] = self::formatError($className, $method, $e);
                            }

                            if ($debugMode) {
                                echo "\n🔍 Debug: Running test for $className...\n";
                            }
                            foreach ($arr as $method) {
                                try {
                                    $method->invoke($instance);
                                    $testCount++;
                                    echo '.';
                                } catch (Exception $e) {
                                    $errorCount++;
                                    $errors[] = self::formatError($className, $method, $e);
                                    echo 'E';
                                }
                            }
                            if ($tearDownMethod) {
                                $tearDownMethod->invoke($instance);
                            } else {
                                $parentClass = $reflectionClass->getParentClass();
                                if ($parentClass) {
                                    foreach ($parentClass->getMethods() as $parentMethod) {
                                        if ($parentMethod->name == 'tearDown') {
                                            $parentMethod->invoke($instance);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        self::printResults($testCount, $errorCount, $errors, $time, $debugMode);

        return $errorCount > 0 ? 1 : 0;
    }

    public static function generate()
    {
        $composerJsonPath = Factory::getComposerFile();
        $parentBasePath = dirname(realpath($composerJsonPath));
        $configPath = $parentBasePath . '/phpium.xml';

        if (file_exists($configPath)) {
            echo "✅ phpium.xml already present.\n";
            return;
        }

        $defaultConfig = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpium>
    <setting name="debug">false</setting>
    <setting name="test-directory">uimtests</setting>
</phpium>
XML;

        file_put_contents($configPath, $defaultConfig);
        echo "✅ phpium.xml created in base path.\n";
    }

    private static function get($key, $default = null)
    {
        return self::$config[$key] ?? $default;
    }

    private static function formatError($className, $method, Exception $e)
    {
        return [
            'class' => $className,
            'method' => $method->getName(),
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
    }

    private static function printResults($testCount, $errorCount, $errors, $time, $debugMode)
    {
        echo PHP_EOL . PHP_EOL . 'Time: ' . (microtime(true) - $time) . ' ms' . PHP_EOL;
        $total = $testCount + $errorCount;
        $txt = $total == 1 ? 'test' : 'tests';
        if ($errorCount > 0) {
            echo "Errors: $errorCount\n";
            echo $errorCount == 1 ? 'There was 1 error' . PHP_EOL : 'There were ' . $errorCount . ' errors:' . PHP_EOL;
            $i = 0;
            foreach ($errors as $i => $error) {
                $i++;
                echo $i . ') ' . $error['class'] . '::' . $error['method'] . ': ' . $error['type'] . ': ' . $error['message'] . PHP_EOL;
                if ($debugMode) {
                    echo "{$error['trace']}\n";
                }
            }

            $txtText = ($total == 1) ? 'Test' : 'Tests';
            if ($errorCount == 1) {
                echo PHP_EOL . "\033[41mERROR!\033[0m\n";
                echo "\033[41m$txtText $total, Error: $errorCount\033[0m\n";
            } else {
                echo PHP_EOL . "\033[41mERRORS!\033[0m\n";
                echo "\033[41m$txtText $total, Errors: $errorCount\033[0m\n";
            }
        } else {
            echo PHP_EOL . "\033[42mOK ($total $txt)\033[0m\n";
        }
        echo PHP_EOL;
    }
}
