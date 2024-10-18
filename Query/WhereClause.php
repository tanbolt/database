<?php
namespace Tanbolt\Database\Query;

use BadMethodCallException;
use InvalidArgumentException;

/**
 * Class WhereClause
 * @package Tanbolt\Database\Query
 * 该类发生变化, 影响 Database\Query\Builder
 *
 * @method $this where($left, $operator = null, $right = null) 字段值与指定值比较
 * @method $this orWhere($left, $operator = null, $right = null)
 *
 * @method $this whereOn($left, $operator, $right = null) 两个字段值比较
 * @method $this orWhereOn($left, $operator, $right = null)
 *
 * @method $this whereTime($left, $operator, $right = null) 字段值与指定时间比较
 * @method $this orWhereTime($left, $operator, $right = null)
 * @method $this whereUnix($left, $operator, $right = null)
 * @method $this orWhereUnix($left, $operator, $right = null)
 *
 * @method $this whereNull($column) 字段值与 NULL 比较
 * @method $this orWhereNull($column)
 * @method $this whereNotNull($column)
 * @method $this orWhereNotNull($column)
 *
 * @method $this whereExists($builder) Exists子查询条件
 * @method $this orWhereExists($builder)
 * @method $this whereNotExists($builder)
 * @method $this orWhereNotExists($builder)
 */
class WhereClause extends Expression
{
    /**
     * @var BuilderInterface
     */
    private $builder;

    /**
     * @var string
     */
    private $table;

    /**
     * @var array
     */
    private $clauses = [];

    /**
     * @var array
     */
    private $bindings = null;

    /**
     * @var null
     */
    private static $types = [
        'on', 'time', 'unix', 'null', 'notnull', 'exists', 'notexists'
    ];

    /**
     * WhereClause constructor.
     * @param BuilderInterface $builder
     * @param ?string $table
     */
    public function __construct(BuilderInterface $builder, $table = null)
    {
        $this->builder = $builder;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * get clauses
     * @return array
     */
    public function clauses()
    {
        return $this->clauses;
    }

    /**
     * @inheritDoc
     */
    public function getBindings()
    {
        if ($this->bindings) {
            return $this->bindings;
        }
        $this->bindings = [];
        foreach ($this->clauses as $clause) {
            if (in_array($clause['type'], ['on', 'null', 'notnull'])) {
                continue;
            }
            $left = $clause['left'];
            if ($left instanceof ExpressionInterface) {
                $this->bindings = array_merge($this->bindings, $left->getBindings());
            } elseif (!in_array($clause['type'], ['exists', 'notexists'])) {
                $right = $clause['right'];
                if ($right instanceof ExpressionInterface) {
                    $this->bindings = array_merge($this->bindings, $right->getBindings());
                } else {
                    $this->bindings = array_merge($this->bindings, self::flattenArray($right, function($bind) {
                        if ($bind instanceof ExpressionInterface) {
                            return $bind->getBindings();
                        }
                        return $bind;
                    }));
                }
            }
        }
        return $this->bindings;
    }

    /**
     * add clauses
     * @param array $arguments
     * @param bool $bool
     * @param string|false $type
     * @return $this
     */
    protected function add(array $arguments, bool $bool = true, $type = false)
    {
        if (!count($arguments)) {
            return $this;
        }
        $left = $arguments[0];
        // [exists, notExists] method can use one argument
        if ('exists' === $type || 'notexists' === $type) {
            if (!is_callable($left) && !$left instanceof BuilderInterface) {
                throw new InvalidArgumentException('Argument 1 must be Callable or Builder instance.');
            }
            call_user_func_array([$this, 'addClause'], static::preparedClause([$left], $bool, $type) ?: $this);
            return $this;
        }
        // [where, orWhere] method can use argument 1 as array or callable
        if (false !== $type && (is_array($left) || $left instanceof self || is_callable($left))) {
            throw new InvalidArgumentException('Argument 1 must be string or Expression instance.');
        }
        // [null, notnull] method can use one argument
        if (is_string($left) && count($arguments) < 2 && !in_array($type, ['null', 'notnull'])) {
            throw new InvalidArgumentException('Argument 2 must be set.');
        }
        // [in, not in] can use multiple columns
        $isMultiple = is_array($left);
        if ($isMultiple && isset($arguments[1]) && isset($arguments[2]) && is_array($arguments[2])) {
            $operator = strtoupper(preg_replace('/\s+/', ' ', trim($arguments[1])));
            if ('IN' === $operator || 'NOT IN' === $operator) {
                $isMultiple = false;
            }
        }
        // multiple clause
        if ($isMultiple) {
            $where = new static($this->builder, $this->table);
            foreach ($arguments[0] as $item) {
                $where->__call('where', (array) $item);
            }
            $newBool = null;
            if (isset($arguments[1])) {
                if (false === $arguments[1] || true === $arguments[1]) {
                    $newBool = $arguments[1];
                }
                if (null === $newBool && is_string($arguments[1])) {
                    $arguments[1] = strtolower($arguments[1]);
                    if ('or' === $arguments[1] || 'and' === $arguments[1]) {
                        $newBool = 'and' === $arguments[1];
                    }
                }
            }
            $this->addClause($where, null, null, null === $newBool ? $bool : $newBool);
        } else {
            call_user_func_array([$this, 'addClause'], static::preparedClause($arguments, $bool, $type) ?: $this);
        }
        return $this;
    }

    /**
     * add a clause
     * @param self|callable|array|string $left
     * @param ?string $operator
     * @param mixed $right
     * @param bool $bool
     * @param string|bool $type
     * @return $this
     */
    protected function addClause($left, string $operator = null, $right = null, bool $bool = true, $type = false)
    {
        $ok = false;
        if ('on' !== $type) {
            if ($left instanceof self) {
                $ok = true;
            } elseif (is_callable($left)) {
                $ok = true;
                $callback = $left;
                if ('exists' === $type || 'notexists' === $type) {
                    call_user_func($callback, $left = $this->builder->createBuilder());
                } else {
                    call_user_func($callback, $left = new static($this->builder, $this->table));
                }
            } elseif ('null' === $type || 'notnull' === $type || 'exists' === $type || 'notexists' === $type) {
                $ok = true;
            }
        }
        if (!$ok && ($operator = strtoupper(preg_replace('/\s+/', ' ', trim($operator)))) ) {
            $ok = true;
            if ('on' !== $type && is_callable($right)) {
                $callback = $right;
                call_user_func($callback, $right = $this->builder->createBuilder());
            }
        }
        if ($ok) {
            if (is_string($left) || (is_array($left) && ('IN' === $operator || 'NOT IN' === $operator))) {
                $left = $this->preparedColumn($left);
            }
            $this->bindings = null;
            $this->clauses[] = compact('left', 'operator', 'right', 'bool', 'type');
        }
        return $this;
    }

    /**
     * @param array|string $columns
     * @return array|string
     */
    protected function preparedColumn($columns)
    {
        if (!$this->table) {
            return $columns;
        }
        if (is_array($columns)) {
            return array_map(function($column) {
                return (strpos($column, '.') ? '' : $this->table .'.') . $column;
            }, $columns);
        }
        return (strpos($columns, '.') ? '' : $this->table .'.') . $columns;
    }

    /**
     * prepared a clause
     * @param array $items
     * @param bool $bool
     * @param string|bool $type
     * @return array|false
     */
    protected static function preparedClause(array $items, bool $bool = true, $type = false)
    {
        if (!$count = count($items)) {
            return false;
        }
        $items = array_values($items);
        $left = $items[0];
        if (1 === $count) {
            $operator = null;
            $right = null;
        } elseif (2 === $count) {
            $operator = '=';
            $right = $items[1];
        } else {
            $operator = $items[1];
            $right = $items[2];
            // [where, orWhere] can reset [bool, type]
            if (false === $type && $count > 3) {
                $newType = null;
                $newBool = true === $items[3] ? true : (false === $items[3] ? false : null);
                if (null === $newBool) {
                    $newType = strtolower($items[3]);
                    if ('or' === $newType || 'and' === $newType) {
                        $newType = null;
                        $newBool = 'and' === $newType;
                    }
                }
                if (!$newType && $count > 4) {
                    $newType = strtolower($items[4]);
                }
                if (null !== $newBool) {
                    $bool = $newBool;
                }
                if ($newType && in_array($newType, self::$types)) {
                    $type = $newType;
                }
            }
        }
        return [$left, $operator, $right, $bool, $type];
    }

    /**
     * @param $method
     * @param $arguments
     * @return $this
     */
    public function __call($method, $arguments)
    {
        $type = null;
        $bool = false;
        $method = strtolower($method);
        $start = substr($method, 0, 3);
        // support where/having  orWhere/orHaving
        if (('orw' === $start && substr($method, 3, 4) === 'here') ||
            ('orh' === $start && substr($method, 3, 5) === 'aving') ) {
            $type = substr($method, 'orw' === $start ? 7 : 8);
        } else if (('whe' === $start && substr($method, 3, 2) === 're') ||
            ('hav' === $start && substr($method, 3, 3) === 'ing')) {
            $bool = true;
            $type = substr($method, 'whe' === $start ? 5 : 6);
        }
        // php7  $method='where' $type='' 而不是 false
        $type = !$type ? false : $type;
        if (false === $type || in_array($type, self::$types)) {
            return call_user_func([$this, 'add'], $arguments, $bool, $type);
        }
        throw new BadMethodCallException("Method $method does not exist.");
    }
}
