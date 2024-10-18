<?php
namespace Tanbolt\Database\Driver\Mysql;

use PDO;
use DateTime;
use Tanbolt\Database\Exception\DatabaseException;
use Tanbolt\Database\Driver\Connector as ConnectorDriver;

class Connector extends ConnectorDriver
{
    /**
     * @inheritDoc
     */
    public function preparedServer(array $config)
    {
        $server = [];
        // 可以去掉 dbname, 没有指定数据库的情况下也可以连接上, 比如可以来创建数据库
        // 但考虑到数据库本事有很多安全方面的配置, 这里强制指定数据库
        // 让使用者提前配置好数据库
        foreach (['dbname', 'username', 'password'] as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                throw new DatabaseException('connect '.$field.' not set');
            }
        }
        $server['username'] = $config['username'];
        $server['password'] = $config['password'];
        $dbName = isset($config['dbname']) && !empty($config['dbname']) ? ';dbname='.$config['dbname'] : '';
        if (isset($config['unix_socket']) && !empty($config['unix_socket'])) {
            $server['dsn'] = sprintf('mysql:unix_socket=%s%s', $config['unix_socket'], $dbName);
        } else {
            if (!isset($config['host'])) {
                throw new DatabaseException('connect host or unix_socket must be set');
            }
            if (isset($config['port']) && !empty($config['port'])) {
                $server['dsn'] = sprintf('mysql:host=%s;port=%s%s', $config['host'], $config['port'], $dbName);
            } else {
                $server['dsn'] = sprintf('mysql:host=%s%s', $config['host'], $dbName);
            }
        }
        foreach (['charset', 'collation', 'timezone', 'strict'] as $field) {
            if (isset($config[$field])) {
                $server[$field] = $config[$field];
            }
        }
        return $server;
    }

    /**
     * @inheritDoc
     */
    public function preparedOption(array $option)
    {
        // 可使用该方法为当前驱动设置 缺省的 非通用选项
        // 比如 Mysql 可通过 PDO::MYSQL_ATTR_USE_BUFFERED_QUERY 开启缓存查询
        $driverOption = [
            //PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ];
        $option = array_diff_key($this->options, $option, $driverOption) + $option + $driverOption;
        if (!isset($option[PDO::ATTR_DEFAULT_FETCH_MODE])) {
            $option[PDO::ATTR_DEFAULT_FETCH_MODE] = $this->defaultFetchMode;
        }
        return $option;
    }

    /**
     * @inheritDoc
     */
    public function afterConnect(PDO $pdo, array $config, array $options)
    {
        $query = [];
        if (isset($config['charset'])) {
            $query[] = sprintf(
                'set names %s %s',
                $config['charset'],
                (isset($config['collation']) ? 'collate '.$config['collation'] : '')
            );
        }
        $timezone = $config['timezone'] ?? static::getTimezone();
        $query[] = sprintf("set time_zone='%s'", $timezone);
        if (isset($config['strict'])) {
            $query[] = sprintf("set session sql_mode='%s'", $config['strict'] ? 'STRICT_ALL_TABLES' : '');
        }
        if (count($query)) {
            $pdo->exec(join(';', $query));
        }
        return true;
    }

    /**
     * get time zone for mysql
     * @return string
     */
    protected static function getTimezone()
    {
        $now = new DateTime();
        $minute = $now->getOffset() / 60;
        $sign = ($minute < 0 ? -1 : 1);
        $minute = abs($minute);
        $hour = floor($minute / 60);
        $minute -= $hour * 60;
        return sprintf('%+d:%02d', $hour * $sign, $minute);
    }

    /**
     * @inheritDoc
     */
    public function versionStatement()
    {
        return 'SELECT VERSION()';
    }

    /**
     * @inheritDoc
     */
    public function checkForeign(PDO $pdo, bool $check)
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = '.($check ? 'ON' : 'OFF'));
        return true;
    }

    /**
     * @inheritDoc
     */
    public function tablesStatement(string $prefix = null)
    {
        $query = 'SHOW TABLES';
        if (!empty($prefix)) {
            $prefix = str_replace(
                ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
                ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
                $prefix
            );
            return sprintf($query . " LIKE '%s'", $prefix.'%');
        }
        return $query;
    }

    /**
     * @inheritDoc
     */
    public function isAbsoluteLastId()
    {
        return false;
    }
}
