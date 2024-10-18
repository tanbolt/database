<?php
namespace Tanbolt\Database\Exception;

use Exception;
use PDOException;

/**
 * Class QueryException
 * @package Tanbolt\Database\Exception
 */
class QueryException extends PDOException implements ExceptionInterface
{
    /**
     * Sql query
     * @var string
     */
    public $query;

    /**
     * sql bindings
     * @var array
     */
    public $bindings;

    /**
     * QueryException constructor.
     * @param string $query
     * @param array $bindings
     * @param Exception $previous
     */
    public function __construct($query, $bindings, $previous)
    {
        parent::__construct('',  0, $previous);
        $this->query = $query;
        $this->bindings = $bindings;
        $this->code = $previous->getCode();
        $this->message = $previous->getMessage();
        if (is_array($bindings)) {
            $matchKey = 0;
            $query =  preg_replace_callback('/:([0-9a-z_]+)|\?/i', function($match) use ($bindings, &$matchKey) {
                if ('?' === $match[0]) {
                    $val = $bindings[$matchKey] ?? false;
                    $matchKey++;
                } else {
                    $val = $bindings[$match[0]] ?? false;
                }
                if (is_string($val)) {
                    return sprintf("'%s'", addslashes($val));
                }
                if (is_numeric($val)) {
                    return $val;
                }
                if (is_null($val)) {
                    return 'NULL';
                }
                return '';
            }, $query);
            $this->message .= sprintf(';  SQL execute Failed: %s', $query);
        } else {
            $this->message .= sprintf(';  SQL prepared Failed: %s', $query);
        }
    }
}
