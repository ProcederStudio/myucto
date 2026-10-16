<?php

declare(strict_types=1);

namespace MyUctoRegression;

final class Instrumentation
{
    private const SOURCES = [
        'Connection' => 'b7d1044875fad222611fc313bde683c8aeb7a4fddb5e15e46022de64a17750fb',
        'LoggingPdo' => 'd10edbbbab05bdf41e29675ed131e9a64c5d61258bf16990472f69f06ea5cd81',
        'LoggingPdoStatement' => '73585baf5a9f597f445e93ee9a0ffc1796e0cfc128722549318c6b3f674b904e',
    ];

    public static function register(string $codeRoot, bool $preserveConfig = false): void
    {
        $sources = [];
        foreach (self::SOURCES as $name => $hash) {
            $class = 'MyInvoice\\Infrastructure\\Database\\' . $name;
            if (class_exists($class, false)) {
                throw new \RuntimeException('instrumentation_loaded_too_late');
            }
            $source = str_replace("\r\n", "\n", (string) file_get_contents($codeRoot . '/api/src/Infrastructure/Database/' . $name . '.php'));
            if (!hash_equals($hash, hash('sha256', $source))) {
                throw new \RuntimeException('unsupported_database_source_signature');
            }
            $sources[$class] = self::transform($name, $source);
        }
        if ($preserveConfig) {
            if (class_exists('MyInvoice\\Bootstrap', false)) {
                throw new \RuntimeException('instrumentation_loaded_too_late');
            }
            $source = str_replace("\r\n", "\n", (string) file_get_contents($codeRoot . '/api/src/Bootstrap.php'));
            if (!hash_equals('05ee1762e9e867c9ea721c5975edfbcb9eab6c3bfdf498dc4b74dd8dd64332f1', hash('sha256', $source))) {
                throw new \RuntimeException('unsupported_bootstrap_source_signature');
            }
            $source = self::replace($source, '$config  = Config::load($rootDir);', '$config  = \\MyUctoRegression\\ConfigSnapshot::get();', 1);
            $sources['MyInvoice\\Bootstrap'] = self::replace($source, 'return dirname(__DIR__, 2);', 'return ' . var_export($codeRoot, true) . ';', 1);
        }
        spl_autoload_register(static function (string $class) use ($sources): void {
            if (isset($sources[$class])) {
                eval(substr($sources[$class], 5));
            }
        }, true, true);
    }

    private static function transform(string $name, string $source): string
    {
        $guard = '\\MyUctoRegression\\Tripwire::';
        if ($name === 'Connection') {
            $start = strpos($source, '    public function pdo(): PDO');
            $end = strpos($source, "\n    /**", $start);
            $method = substr($source, $start, $end - $start);
            $method = self::replace($method, "public function pdo(): PDO\n    {", "public function pdo(): PDO\n    {\n        " . $guard . 'verifyConfig($this->config);', 1);
            $method = self::replace($method, 'return $this->pdo;', $guard . 'activate($this->pdo); return $this->pdo;', 2);
            $method = self::replace($method, 'return $this->pdo = $pdo;', $guard . 'activate($pdo); return $this->pdo = $pdo;', 1);
            return substr($source, 0, $start) . $method . substr($source, $end);
        }
        $signatures = $name === 'LoggingPdo' ? [
            'public function prepare(string $query, array $options = []): PDOStatement|false' => '$query',
            'public function exec(string $statement): int|false' => '$statement',
            'public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false' => '$query',
        ] : ['public function execute(?array $params = null): bool' => '(string) $this->queryString'];
        foreach ($signatures as $signature => $argument) {
            $source = self::replace($source, $signature . "\n    {", $signature . "\n    {\n        " . $guard . 'sql(' . $argument . ');', 1);
        }
        $source = self::replace($source, 'catch (PDOException $e) {', 'catch (PDOException $e) { ' . $guard . 'databaseError();', $name === 'LoggingPdo' ? 3 : 1);
        return $source;
    }

    private static function replace(string $source, string $find, string $replacement, int $expected): string
    {
        $result = str_replace($find, $replacement, $source, $count);
        if ($count !== $expected) {
            throw new \RuntimeException('unsupported_database_method_signature');
        }
        return $result;
    }
}
