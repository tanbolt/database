<?php
namespace Tanbolt\Database;

use Exception;

class Sql
{
    /**
     * @var Connection
     */
    public $connection;

    /**
     * @var string
     */
    public $query = null;

    /**
     * @var array
     */
    public $bindings = [];

    /**
     * @var float
     */
    public $duration = 0;

    /**
     * @var bool
     */
    public $isSlave = false;

    /**
     * @var Exception
     */
    public $exception = null;

    /**
     * Sql constructor.
     * @param ?Connection $connection
     * @param ?string $query
     * @param array $bindings
     * @param int $duration
     * @param bool $slave
     * @param null $e
     */
    public function __construct(
        Connection $connection = null,
        string $query = null,
        array $bindings = [],
        int $duration = 0,
        bool $slave = false,
        $e = null
    ) {
        $this->connection = $connection;
        $this->query = $query;
        $this->bindings = $bindings;
        $this->duration = $duration;
        $this->isSlave = $slave;
        $this->exception = $e;
    }

    /**
     * @return string
     */
    public function executed()
    {
        $bindings = $this->bindings;
        return preg_replace_callback('/\?/', function() use (&$bindings) {
            $data = array_shift($bindings);
            return is_numeric($data) ? $data : '\'' . str_replace('\'', '\'\'', $data) . '\'';
        }, $this->query);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->executed();
    }
}
