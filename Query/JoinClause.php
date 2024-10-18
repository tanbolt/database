<?php
namespace Tanbolt\Database\Query;

/**
 * Class JoinClause: JOIN 语句表达式
 * @package Tanbolt\Database\Query
 */
class JoinClause extends Expression
{
    /**
     * @var ExpressionInterface|string
     */
    public $table = null;

    /**
     * @var string
     */
    public $joinType = null;

    /**
     * @var array|bool
     */
    public $using = null;

    /**
     * @var array
     */
    public $on = null;

    /**
     * @var ?WhereClause
     */
    public $where = null;

    /**
     * JoinClause constructor.
     * @param ExpressionInterface|string $table
     * @param ?string $joinType
     */
    public function __construct($table, string $joinType = null)
    {
        $this->table = $table;
        if ($joinType) {
            $this->joinType($joinType);
        }
        parent::__construct();
    }

    /**
     * set join type
     * @param ?string $type
     * @return $this
     */
    public function joinType(?string $type)
    {
        $this->joinType = strtoupper($type);
        return $this;
    }

    /**
     * set join using columns
     * @param array|string $columns
     * @return $this
     */
    public function using($columns)
    {
        if (is_string($columns)) {
            $columns = [$columns];
        }
        $this->using = count((array) $columns) ? $columns : null;
        $this->on = null;
        $this->where = null;
        return $this;
    }

    /**
     * set join on clause
     * @param array $clause
     * @return $this
     */
    public function on(array $clause)
    {
        $on = null;
        if (array_key_exists('left', $clause) && array_key_exists('right', $clause)) {
            $on = [
                'left' => $clause['left'],
                'operator' => array_key_exists('operator', $clause) ? $clause['operator'] : '=',
                'right' => $clause['right'],
            ];
        }
        $this->using = null;
        $this->on = $on;
        $this->where = null;
        return $this;
    }

    /**
     * set join where clause
     * @param WhereClause|null $where
     * @return $this
     */
    public function where(?WhereClause $where)
    {
        $this->using = null;
        $this->on = null;
        $this->where = $where;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        $bindings = [];
        if ($this->table instanceof ExpressionInterface) {
            $bindings = $this->table->getBindings();
        }
        if ($this->where) {
            return array_merge($bindings, $this->where->getBindings());
        }
        return $bindings;
    }
}
