<?php

declare(strict_types=1);

namespace Rosua\Migrations;

use RuntimeException;
use WP_CLI;
use wpdb;

class Migrator
{
    private Configuration $configuration;

    private string $commandNamespace;

    /**
     * Stores the instance, implementing a Singleton pattern.
     */
    private static self $instance;

    /**
     * The Singleton's constructor should always be private to prevent direct
     * construction calls with the `new` operator.
     */
    private function __construct()
    {
    }

    /**
     * Singletons should not be cloneable.
     */
    private function __clone()
    {
    }

    /**
     * Singletons should not be restorable from strings.
     *
     * @throws RuntimeException Cannot unserialize a singleton.
     */
    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize a singleton.');
    }

    /**
     * This is the static method that controls the access to the singleton
     * instance. On the first run, it creates a singleton object and places it
     * into the static property. On subsequent runs, it returns the client existing
     * object stored in the static property.
     */
    public static function getInstance(?array $configs = []): self
    {
        $cls = static::class;
        if (! isset(self::$instance)) {
            self::$instance = new self();
            self::$instance->setConfig($configs);
        }

        return self::$instance;
    }

    public function setConfig(array $configs): void
    {
        $configs = array_merge(
            [
                'tableName' => 'rosua_migrations',
                'namespace' => 'RosuaMigrations',
                'directory' => 'migrations',
                'basePath' => dirname(__DIR__),
                'commandNamespace' => 'migrations',
            ],
            $configs
        );

        $this->configuration = new Configuration([
            'tableName' => $configs['tableName'],
            'namespace' => $configs['namespace'],
            'directory' => $configs['directory'],
            'basePath' => $configs['basePath'],
        ]);

        $this->commandNamespace = $configs['commandNamespace'];
    }

    public function boot(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->registerCommands();
    }

    public function registerCommands(): void
    {
        if (! class_exists('WP_CLI')) {
            return;
        }

        WP_CLI::add_command($this->commandNamespace, Command::class);
    }

    public function install(): void
    {
        /** @var wpdb $wpdb */
        global $wpdb;

        $tableName = $wpdb->prefix . $this->configuration->getTableName();

        $find_table = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($tableName)
            )
        );

        if ($find_table === $tableName) {
            return;
        }

        $collation = $wpdb->has_cap('collation') ? $wpdb->get_charset_collate() : '';

        $sql = "CREATE TABLE `{$tableName}` (
            `version` VARCHAR(191) NOT NULL,
            `executed_at` DATETIME DEFAULT NULL,
            `execution_time` INT DEFAULT NULL,
            PRIMARY KEY (`version`)
        ) {$collation};";

        dbDelta($sql);
    }

    public function generate(?string $up = null, ?string $down = null)
    {
        $generator = new Generator($this->configuration);

        $generator->generateMigration($up, $down);
    }

    public function list()
    {
        $migrationRepository = new MigrationRepository($this->configuration);

        return $migrationRepository->getMigrationVersions();
    }

    public function execute(): array
    {
        $list = $this->list();

        $executed = [];

        foreach ($list as $version) {
            if ($version['executed']) {
                continue;
            }

            $start_time = microtime(true);

            /** @var AbstractMigration $m */
            $m = new $version['version']();
            $m->up();

            $end_time = microtime(true);

            $executed[] = [
                'version' => $version['version'],
                'executed_at' => date('Y-m-d H:i:s'),
                'execution_time' => ($end_time - $start_time) * 1000,
            ];
        }

        if (empty($executed)) {
            return [];
        }

        /** @var wpdb $wpdb */
        global $wpdb;

        $tableName = $wpdb->prefix . $this->configuration->getTableName();

        $wpdb->query(sprintf('LOCK TABLES `%s` WRITE', $tableName));

        foreach ($executed as $version) {
            $wpdb->insert(
                $tableName,
                [
                    'version' => $version['version'],
                    'executed_at' => $version['executed_at'],
                    'execution_time' => $version['execution_time'],
                ]
            );
        }

        $wpdb->query('UNLOCK TABLES');

        return $executed;
    }
}
