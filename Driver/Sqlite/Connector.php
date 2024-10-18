<?php
namespace Tanbolt\Database\Driver\Sqlite;

use PDO;
use Tanbolt\Database\Exception\DatabaseException;
use Tanbolt\Database\Driver\Connector as ConnectorDriver;

class Connector extends ConnectorDriver
{
    /**
     * @inheritDoc
     */
    public function preparedServer(array $config)
    {
        if (!isset($config['dbname']) || empty($config['dbname'])) {
            throw new DatabaseException('connect database path not set');
        }
        if ($config['dbname'] !== ':memory:') {
            if (($dir = realpath(dirname($config['dbname']))) === false) {
                throw new DatabaseException('connect database directory not exist');
            }
            $config['dbname'] = $dir . DIRECTORY_SEPARATOR . basename($config['dbname']);
        }
        $server['dsn'] = sprintf('sqlite:%s', (string) $config['dbname']);
        return $server;
    }

    /**
     * @inheritDoc
     */
    public function versionStatement()
    {
        return 'select sqlite_version()';
    }

    /**
     * @inheritDoc
     */
    public function checkForeign(PDO $pdo, bool $check)
    {
        $pdo->exec('PRAGMA foreign_keys = '.($check ? 'ON' : 'OFF'));
        return true;
    }

    /**
     * @inheritDoc
     */
    public function tablesStatement(string $prefix = null)
    {
        $query = "SELECT `name` FROM `sqlite_master` WHERE `type`='table'";
        if (!empty($prefix)) {
            return [$query . ' AND `name` LIKE ?', [$prefix.'%']];
        }
        return $query;
    }

    /**
     * @inheritDoc
     */
    public function isAbsoluteLastId()
    {
        return true;
    }
}
