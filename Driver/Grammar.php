<?php
namespace Tanbolt\Database\Driver;

use Tanbolt\Database\Query\JoinClause;
use Tanbolt\Database\Query\WhereClause;
use Tanbolt\Database\Query\BuilderInterface;
use Tanbolt\Database\Query\ExpressionInterface;

/**
 * Class Grammar: 数据库语句构造器
 * @package Tanbolt\Database\Driver
 */
abstract class Grammar
{
    /**
     * 数据表前缀
     * @var string
     */
    protected $prefix = null;

    /**
     * tables 临时存储 (放置子查询 别名 共用)
     * @var array
     */
    protected $aliasTableStore = [];

    /**
     * 当前语句使用过的 tables 别名集合
     * @var array
     */
    protected $aliasTable = [];

    /**
     * 设置/获取数据表通用前缀
     * @param callable|string|null $prefix
     * @return $this|string
     */
    public function prefix($prefix = null)
    {
        if (func_num_args()) {
            $this->prefix = $prefix;
            return $this;
        }
        return (string) (is_callable($this->prefix) ? call_user_func($this->prefix) : $this->prefix);
    }

    /**
     * 格式化数据表名
     * @param ExpressionInterface|string $table
     * @return string
     */
    protected function quoteTable($table)
    {
        if ($table instanceof ExpressionInterface) {
            return $table->query();
        }
        $table = trim($table, '`');
        if (empty($table)) {
            return '';
        }
        if ($this->prefix() && !in_array($table, $this->aliasTable) && strpos($table, $this->prefix()) !== 0) {
            $table = $this->prefix() . $table;
        }
        return '`'.$table.'`';
    }

    /**
     * 格式化字段名
     * @param ExpressionInterface|string $column
     * @return string
     */
    protected function quoteColumn($column)
    {
        if ('*' === $column) {
            return '*';
        }
        if ($column instanceof ExpressionInterface) {
            return $column->query();
        }
        $column = trim((string) $column, '`');
        return $column ? '`'.$column.'`' : '';
    }

    /**
     * 格式化查询字段名
     * @param ExpressionInterface|string $column
     * @param string|bool $table
     * @return string
     */
    protected function quoteTableColumn($column, $table = false)
    {
        if (true === $table) {
            return $this->quoteTable($column);
        }
        if ($column instanceof BuilderInterface) {
            return '('. $column->query() .')';
        }
        if ($column instanceof ExpressionInterface) {
            return $column->query();
        }
        $column = (string) $column;
        if ($column !== '*' && !strpos($column, '.*') && static::hasSpecialChar($column)) {
            return $column;
        }
        $quoted = [];
        // builder prefix table
        if (!empty($table) && false === strpos($column, '.')) {
            $column = $table . '.' . $column;
        }
        $items = explode('.', $column);
        foreach ($items as $key => $item) {
            if (($item = $key == 0 && count($items) > 1 ? $this->quoteTable($item) : $this->quoteColumn($item))) {
                $quoted[] = $item;
            }
        }
        return implode('.', $quoted);
    }

    /**
     * 特殊字符检测
     * @param string $value
     * @return bool
     */
    protected static function hasSpecialChar(string $value)
    {
        foreach ([')','(','+','-','*','/','%','=','&','^','|',':','>','<','!','~'] as $needle) {
            if (strpos($value, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 格式化一个或多个(通过数组指定)查询字段名
     * @param mixed $items
     * @param string|bool $table
     * @return string|null
     */
    protected function quoteColumns($items, $table = false)
    {
        if (!is_array($items)) {
            $items = [$items];
        }
        $items = array_map(function($item) use ($table) {
            return $this->quoteTableColumn($item, $table);
        }, $items);
        return count($items) ? implode(', ', $items) : null;
    }

    /**
     * 格式化子查询
     * @param BuilderInterface $builder
     * @param null $alias
     * @return string
     */
    protected function quoteSubQuery(BuilderInterface $builder, &$alias = null)
    {
        $this->aliasTableStore[] = $this->aliasTable;
        $this->aliasTable = [];
        $alias = $builder->getAlias();
        $query = '(' . $builder->query() . ')';
        $this->aliasTable = array_pop($this->aliasTableStore);
        return $query;
    }

    /**
     * 格式化别名
     * @param mixed $value
     * @param null $query
     * @return null|string
     */
    protected function quoteAlias($value, &$query = null)
    {
        $value = (string) $value;
        if (false !== $pos = strrpos(strtoupper($value), ' AS ')) {
            $query = substr($value, 0, $pos);
            $alias = substr($value, ($pos + 4));
        } else {
            $query = $value;
            $alias = null;
        }
        return $alias;
    }

    /**
     * get select column or table quote
     * @param ExpressionInterface|string $value
     * @param string|bool $table
     * @param null $alias
     * @return string
     */
    protected function getQuote($value, $table = false, &$alias = null)
    {
        if ($value instanceof BuilderInterface) {
            $query = $this->quoteSubQuery($value, $alias);
        } elseif ($value instanceof WhereClause) {
            $alias = null;
            $query = '(' . $value->query() . ')';
        } elseif ($value instanceof ExpressionInterface) {
            $alias = $value->getAlias();
            $query = $value->query();
        } else {
            $alias = $this->quoteAlias($value, $query);
            $query = $this->quoteTableColumn($query, $table);
        }
        return $query;
    }

    /**
     * quote select column or table
     * @param ExpressionInterface|string $value
     * @param string|bool $table
     * @return string
     */
    protected function quote($value, $table = false)
    {
        $alias = null;
        $query = $this->getQuote($value, $table, $alias);
        if ($table && $alias) {
            $this->aliasTable[] = $alias;
        }
        return $query ? $query . ($alias ? ' AS `' . trim($alias,'`') . '`' : '') : '';
    }

    /**
     * @param $parameters
     * @return string
     */
    protected function parameter($parameters)
    {
        if (!is_array($parameters)) {
            $parameters = [$parameters];
        }
        $query = [];
        foreach ($parameters as $parameter) {
            $query[] = $parameter instanceof ExpressionInterface ? $parameter->query()  : '?';
        }
        return implode(',', $query);
    }

    /**
     * @param array $queries
     * @return string
     */
    protected function mergeQuery(array $queries)
    {
        return implode(' ', array_filter($queries, function ($query) {
            return (string) $query !== '';
        }));
    }

    /**
     * builder select query
     * @param BuilderInterface $builder
     * @return string
     */
    public function builderSelect(BuilderInterface $builder)
    {
        $query = $this->preparedSelect($builder);
        return $this->mergeQuery([
            ($query['columns'] ? 'SELECT '.$query['columns'] : ''),
            ($query['from'] ? 'FROM '.$query['from'] : ''),
            $query['joins'],
            ($query['wheres'] ? 'WHERE '.$query['wheres'] : ''),
            $query['groups'],
            ($query['having'] ? 'HAVING '.$query['having'] : ''),
            $query['orders'],
            $query['limit'],
            $query['offset'],
            $query['unions'],
            $query['lock'],
        ]);
    }

    /**
     * prepared select builder
     * @param BuilderInterface $builder
     * @return array
     */
    protected function preparedSelect(BuilderInterface $builder)
    {
        $query = [];
        $this->aliasTable = [];
        $query['from'] = $this->builderFromTable($builder, $builder->parameter('from'));
        $query['joins'] = $this->builderJoins($builder, $builder->parameter('joins'));
        if ($builder->parameter('aggregate')) {
            $query['columns'] = $this->builderAggregate($builder, $builder->parameter('aggregate'));
        } else {
            $query['columns'] = $this->builderColumns($builder, $builder->parameter('selects'));
        }
        $query['wheres'] = $this->builderWheres($builder, $builder->parameter('wheres'));
        $query['groups'] = $this->builderGroups($builder, $builder->parameter('groups'));
        $query['having'] = $query['groups'] ? $this->builderWheres($builder, $builder->parameter('having')) : null;
        $query['orders'] = $this->builderOrders($builder, $builder->parameter('orders'));
        $query['limit'] =  $this->builderLimit($builder->parameter('limit'));
        $query['offset'] = $this->builderOffset($builder->parameter('offset'));
        $query['unions'] = $this->builderUnions($builder, $builder->parameter('unions'));
        $query['lock'] = $this->builderLock($builder, $builder->parameter('lock'));
        return $query;
    }

    /**
     * builder from
     * @param BuilderInterface $builder
     * @param $table
     * @return string
     */
    protected function builderFromTable(BuilderInterface $builder, $table)
    {
        return $this->quote($table, true);
    }

    /**
     * builder joins
     * @param BuilderInterface $builder
     * @param JoinClause[] $joins
     * @return string
     */
    protected function builderJoins(BuilderInterface $builder, array $joins)
    {
        $query = [];
        foreach ($joins as $join) {
            $table = $this->quote($join->table, true);
            $clauses = $join->using ? $this->builderJoinUsing($builder, $join) :
                ($join->on ? $this->builderJoinOn($builder, $join) : $this->builderJoinWhere($builder, $join));
            $query[] = sprintf(
                '%s JOIN %s%s', ($join->joinType ?: 'INNER'), $table, ($clauses ? ' '.$clauses : '')
            );
        }
        return implode(' ', $query);
    }

    /**
     * builder join using
     * @param BuilderInterface $builder
     * @param JoinClause $join
     * @return null|string
     */
    protected function builderJoinUsing(BuilderInterface $builder, JoinClause $join)
    {
        $columns = $this->quoteColumns($join->using);
        return $columns ? 'USING('.$columns.')' : null;
    }

    /**
     * builder join on
     * @param BuilderInterface $builder
     * @param JoinClause $join
     * @return null|string
     */
    protected function builderJoinOn(BuilderInterface $builder, JoinClause $join)
    {
        if ($join->on && ($left = $this->quoteTableColumn($join->on['left'])) && $join->on['operator'] &&
            ($right = $this->quoteTableColumn($join->on['right']))) {
            return 'ON '.$left. ' '.$join->on['operator'].' '.$right;
        }
        return null;
    }

    /**
     * builder join where
     * @param BuilderInterface $builder
     * @param JoinClause $join
     * @return null|string
     */
    protected function builderJoinWhere(BuilderInterface $builder, JoinClause $join)
    {
        if (!$join->where instanceof WhereClause) {
            return null;
        }
        $where = $this->builderWheres($builder, $join->where);
        return $where ? 'ON '.$where : null;
    }

    /**
     * builder aggregate
     * @param BuilderInterface $builder
     * @param $aggregate
     * @return string
     */
    protected function builderAggregate(BuilderInterface $builder, $aggregate)
    {
        $function = is_array($aggregate) && $aggregate['function'] ? $aggregate['function'] : null;
        if (!$function) {
            return null;
        }
        $columns = is_string($aggregate['columns']) ? [$aggregate['columns']] : (array) $aggregate['columns'];
        return $columns ? $function.'('.$this->builderColumns($builder, $columns).') AS `aggregate`' : '';
    }

    /**
     * builder columns
     * @param BuilderInterface $builder
     * @param $columns
     * @return string
     */
    protected function builderColumns(BuilderInterface $builder, $columns)
    {
        if (null === $columns) {
            return null;
        }
        if (!is_array($columns)) {
            $columns = [$columns];
        }
        if ($columns !== ['*']) {
            $columns = array_map(function($column) use ($builder){
                return $this->quote($column, $builder->parameter('prefixTable'));
            }, $columns);
            $columns = array_unique(array_filter($columns));
        }
        $columns = count($columns) ? implode(', ', $columns) : '*';
        return ($builder->parameter('distinct') ? 'DISTINCT ' : '') . $columns;
    }

    /**
     * builder where clause
     * @param BuilderInterface $builder
     * @param WhereClause|string|null $where
     * @param bool $subQuery
     * @return array|string
     */
    protected function builderWheres(BuilderInterface $builder, $where, bool $subQuery = false)
    {
        if (!$where instanceof WhereClause) {
            return null;
        }
        $query = [];
        foreach ($where->clauses() as $key => $clause) {
            $left = $clause['left'];
            $operator = $clause['operator'];
            $right = $clause['right'];
            $bool = $clause['bool'];
            $type = $clause['type'];
            $term = null;
            if ($left instanceof WhereClause) {
                if (count($subWhere = $this->builderWheres($builder, $left, true))) {
                    $term = $key || $subQuery ? array_merge(['('], $subWhere, [')']) : $subWhere;
                }
            } elseif ('on' === $type) {
                if (($left = $this->quoteTableColumn($left)) && $operator && ($right = $this->quoteTableColumn($right))) {
                    $term = $left. ' '.$operator.' '.$right;
                }
            } elseif ('null' === $type || 'notnull' === $type) {
                if (($left = $this->quoteTableColumn($left))) {
                    $term = $left . ('null' === $type ? ' IS NULL' : ' IS NOT NULL');
                }
            } elseif ('exists' === $type || 'notexists' === $type) {
                $term = $this->builderWhereExists($builder, $clause);
            } elseif ('IN' === $operator || 'NOT IN' === $operator) {
                $term = $this->builderWhereIn($builder, $clause);
            } else {
                $term = $this->builderWhereMethod($builder, $clause);
            }
            if ($term) {
                if (count($query)) {
                    $query[] = $bool ? 'AND' : 'OR';
                }
                is_array($term) ? $query = array_merge($query, $term) : $query[] = $term;
            }
        }
        return $subQuery ? $query : (count($query) ? $this->mergeQuery($query) : '');
    }

    /**
     * @param BuilderInterface $builder
     * @param array $clause
     * @return string
     */
    protected function builderWhereExists(BuilderInterface $builder, array $clause)
    {
        /** @var BuilderInterface $query */
        $query = $clause['left'];
        return ('exists' === $clause['type'] ? '' : 'NOT '). 'EXISTS (' . $query->select('*')->query() .')';
    }

    /**
     * builder where in not in
     * @param BuilderInterface $builder
     * @param array $clause
     * @return null|string
     */
    protected function builderWhereIn(BuilderInterface $builder, array $clause)
    {
        if (!($left = $this->quoteColumns($clause['left']))) {
            return null;
        }
        if (is_array($clause['left'])) {
            $left = '('.$left.')';
            $right = array_map(function($item) {
                return $this->parameter($item);
            }, $clause['right']);
            $right = '('.implode('),(', $right).')';
        } else {
            $right = $this->parameter($clause['right']);
        }
        return $left.' '.$clause['operator'].' ('.$right.')';
    }

    /**
     * builder where clause
     * @param BuilderInterface $builder
     * @param array $clause
     * @return null|string
     */
    protected function builderWhereMethod(BuilderInterface $builder, array $clause)
    {
        if ('time' === $clause['type'] || 'unix' === $clause['type']) {
            $operators = explode(' ', $clause['operator']);
            $function = array_pop($operators);
            $support = [
                'TIMESTAMP', 'DATETIME', 'DATE', 'TIME', 'YEAR', 'QUARTER',
                'MONTH', 'WEEK', 'DAY', 'HOUR', 'MINUTE', 'SECOND'
            ];
            if (in_array($function, $support)) {
                $clause['operator'] = implode(' ', $operators);
                return 'time' === $clause['type'] ? $this->builderWhereTime($builder, $clause, $function) :
                       $this->builderWhereUnix($builder, $clause, $function);
            }
        }
        if (!($left = $this->quoteTableColumn($clause['left']))) {
            return null;
        }
        return $this->builderWhereAuto($left, $clause);
    }

    /**
     * builder where time clause
     * @param BuilderInterface $builder
     * @param array $clause
     * @param string $function
     * @return null|string
     */
    protected function builderWhereTime(BuilderInterface $builder, array $clause, string $function)
    {
        if (!($left = $this->quoteTableColumn($clause['left']))) {
            return null;
        }
        if ('DATETIME' === $function) {
            return $this->builderWhereAuto($left, $clause);
        }
        if ('TIMESTAMP' === $function) {
            $function = 'UNIX_TIMESTAMP';
        }
        return $this->builderWhereAuto($function.'('.$left.')', $clause);
    }

    /**
     * builder where timestamp clause
     * @param BuilderInterface $builder
     * @param array $clause
     * @param string $function
     * @return null|string
     */
    protected function builderWhereUnix(BuilderInterface $builder, array $clause, string $function)
    {
        if (!($left = $this->quoteTableColumn($clause['left']))) {
            return null;
        }
        if ('TIMESTAMP' === $function) {
            return $this->builderWhereAuto($left, $clause);
        }
        if ('DATETIME' === $function) {
            return $this->builderWhereAuto('FROM_UNIXTIME('.$left.')', $clause);
        }
        if ('QUARTER' === $function) {
            return $this->builderWhereAuto('QUARTER(FROM_UNIXTIME('.$left.'))', $clause);
        }
        $convert = [
            'DATE' => '%Y-%m-%d',
            'TIME' => '%H:%i:%s',
            'YEAR' => '%Y',
            'MONTH' => '%c',
            'WEEK' => '%w',
            'DAY' => '%e',
            'HOUR' => '%k',
            'MINUTE' => '%i',
            'SECOND' => '%s'
        ];
        if (isset($convert[$function])) {
            $left = 'FROM_UNIXTIME('.$left.', \''.$convert[$function].'\')';
            if ('MINUTE' === $function || 'SECOND' === $function) {
                $left = 'CONVERT('.$left.', SIGNED)';
            }
            return $this->builderWhereAuto($left, $clause);
        }
        return null;
    }

    /**
     * builder where clause
     * @param string $left
     * @param array $clause
     * @return string
     */
    protected function builderWhereAuto(string $left, array $clause)
    {
        if (!($operator = $clause['operator'])) {
            return null;
        }
        $right = $clause['right'];
        if ($right instanceof BuilderInterface && ($rightQuery = $this->quoteSubQuery($right))) {
            return $left.' '.$operator.' '.$rightQuery;
        }
        if (empty($clause['right']) && ('IN' === $operator || 'NOT IN' === $operator)) {
            return 'IN' === $operator ? '1 = 0' : '1 = 1';
        }
        if ('BETWEEN' === $operator || 'NOT BETWEEN' === $operator) {
            return $left . ' '. $operator .' ? AND ?';
        }
        if (!($right = $this->parameter($clause['right']))) {
            return null;
        }
        if ('IN' === $operator || 'NOT IN' === $operator) {
            $right = '('.$right.')';
        }
        return $left . ' '. $operator .' '.$right;
    }

    /**
     * builder group by
     * @param BuilderInterface $builder
     * @param $groups
     * @return string
     */
    protected function builderGroups(BuilderInterface $builder, $groups)
    {
        $groups = $this->quoteColumns($groups, $builder->parameter('prefixTable'));
        return $groups ? 'GROUP BY '.$groups : null;
    }

    /**
     * builder unions
     * @param BuilderInterface $builder
     * @param $unions
     * @return null
     */
    protected function builderUnions(BuilderInterface $builder, $unions)
    {
        if (empty($unions)) {
            return null;
        }
        $query = [];
        foreach ($unions as $union) {
            if ( ($unionBuilder = $union['builder']) && $builder instanceof BuilderInterface) {
                $query[] = $this->builderUnion($unionBuilder, $union['all']);
            }
        }
        if (!empty($query)) {
            $query['orders'] = $this->builderOrders($builder, $builder->parameter('unionOrders'));
            $query['limit'] =  $this->builderLimit($builder->parameter('unionLimit'));
            $query['offset'] = $this->builderOffset($builder->parameter('unionOffset'));
            return $this->mergeQuery($query);
        }
        return null;
    }

    /**
     * builder union
     * @param BuilderInterface $union
     * @param bool $all
     * @return string
     */
    protected function builderUnion(BuilderInterface $union, bool $all = false)
    {
        $query = $union->query();
        return $query ? ($all ? 'UNION ALL ' : 'UNION ') . $query : '';
    }

    /**
     * builder order by
     * @param BuilderInterface $builder
     * @param $orders
     * @return null|string
     */
    protected function builderOrders(BuilderInterface $builder, $orders)
    {
        $orderBy = '';
        $table  = $builder->parameter('prefixTable');
        foreach ($orders as $order) {
            if (isset($order['column']) && ($column = $this->quoteColumns($order['column'], $table))) {
                $orderBy .= ('' === $orderBy ? '' : ', ') . $column.' '.($order['asc'] ? 'ASC' : 'DESC');
            }
        }
        return $orderBy ? 'ORDER BY '.$orderBy : null;
    }

    /**
     * builder limit
     * @param ?int $limit
     * @return string
     */
    protected function builderLimit(?int $limit)
    {
        return null === $limit ? null : 'LIMIT '.$limit;
    }

    /**
     * builder offset
     * @param ?int $offset
     * @return string
     */
    protected function builderOffset(?int $offset)
    {
        return null === $offset ? null : 'OFFSET '.$offset;
    }

    /**
     * builder select lock
     * @param BuilderInterface $builder
     * @param $value
     * @return string
     */
    protected function builderLock(BuilderInterface $builder, $value)
    {
        return is_string($value) ? $value : '';
    }

    /**
     * builder exists query
     * @param BuilderInterface $builder
     * @return null|string
     */
    public function builderExists(BuilderInterface $builder)
    {
        return 'SELECT EXISTS('.$this->builderSelect($builder).') AS `exists`';
    }

    /**
     * builder insert query
     * @param BuilderInterface $builder
     * @param array $inserts
     * @param bool $replace
     * @return null|string
     */
    public function builderInsert(BuilderInterface $builder, array $inserts, bool $replace = false)
    {
        $query = $this->builderIntoQuery($builder, $inserts);
        return $query ? 'INSERT INTO '.$query : null;
    }

    /**
     * builder into query
     * @param BuilderInterface $builder
     * @param array $inserts
     * @return null|string
     */
    protected function builderIntoQuery(BuilderInterface $builder, array $inserts)
    {
        if (empty($inserts)) {
            return null;
        }
        if (!($table = $this->quoteTable($builder->parameter('from')))) {
            return null;
        }
        if (!($columns = $this->quoteColumns(array_keys(reset($inserts))))) {
            return null;
        }
        $parameters = [];
        foreach ($inserts as $insert) {
            $parameters[] = '('.$this->parameter($insert).')';
        }
        return sprintf('%s (%s) VALUES %s', $table, $columns, implode(', ', $parameters));
    }

    /**
     * builder insert from sub query
     * @param BuilderInterface $builder
     * @param BuilderInterface $select
     * @param null $columns
     * @return null|string
     */
    public function builderInsertFrom(BuilderInterface $builder, BuilderInterface $select, $columns = null)
    {
        if (!($table = $this->quoteTable($builder->parameter('from')))) {
            return null;
        }
        if ($columns) {
            if (!is_array($columns)) {
                $columns = [$columns];
            }
            $columns = array_map([$this, 'quoteTableColumn'], $columns);
        } else {
            $columns = $this->builderInsertColumns($select->parameter('selects'));
        }
        if (count($columns) !== count($select->parameter('selects'))) {
            return null;
        }
        return sprintf('INSERT INTO %s (%s) %s', $table, implode(', ', $columns), $select->query());
    }

    /**
     * builder insert columns
     * @param array $values
     * @return array
     */
    protected function builderInsertColumns(array $values)
    {
        $columns = [];
        foreach ($values as $value) {
            if ($value instanceof ExpressionInterface) {
                if ($alias = $value->getAlias()) {
                    $column = $this->quoteColumn($alias);
                } else {
                    $column = $value->query();
                }
            } else {
                $value = (string) $value;
                $alias = $this->quoteAlias($value, $query);
                $column = $alias ? $this->quoteColumn($alias) : $this->quoteTableColumn($query);
            }
            if ($column) {
                $columns[] = $column;
            }
        }
        return $columns;
    }

    /**
     * builder update query
     * @param BuilderInterface $builder
     * @param array $update
     * @param ?array $bindings
     * @return string|null
     */
    public function builderUpdate(BuilderInterface $builder, array $update, array &$bindings = null)
    {
        if (!($update = $this->preparedUpdate($builder, $update)) ) {
            return null;
        }
        $bindings = $update->bindings;
        return sprintf(
            'UPDATE %s%s SET %s%s',
            $update->table,
            ($update->joins ? ' '.$update->joins : ''),
            $update->columns,
            ($update->wheres ? ' WHERE '.$update->wheres : '')
        );
    }

    /**
     * prepared update dates
     * @param BuilderInterface $builder
     * @param array $update
     * @param bool $includeJoins
     * @return null|object
     */
    protected function preparedUpdate(BuilderInterface $builder, array $update, bool $includeJoins = false)
    {
        if (!($table = $this->builderFromTable($builder, $builder->parameter('from')))) {
            return null;
        }
        $joins = $includeJoins ? $this->builderJoins($builder, $builder->parameter('joins')) : null;
        $columns = [];
        $bindings = [];
        foreach ($update as $key => $value) {
            if ($value instanceof ExpressionInterface) {
                $bindings = array_merge($bindings, $value->getBindings());
                $value = $value instanceof BuilderInterface ? '('. $value->query() .')' : $value->query();
            } else {
                $bindings[] = $value;
                $value = '?';
            }
            $increment = substr($key, -1);
            if ('+' === $increment || '-' === $increment) {
                $key = $this->quoteTableColumn(substr($key, 0, -1));
                $value = $key .' '. $increment .' '. $value;
            } else {
                $key = $this->quoteTableColumn($key);
            }
            $columns[] = $key . ' = ' . $value;
        }
        $bindings = array_merge($bindings, $builder->mergeBindings([
            $builder->parameter('from'),
            $includeJoins ? $builder->parameter('joins') : null,
            $builder->parameter('wheres')
        ]));
        if (empty($columns)) {
            return null;
        }
        return (object) [
            'table'    => $table,
            'joins'   => $joins,
            'columns' => implode(', ', $columns),
            'wheres'  => $this->builderWheres($builder, $builder->parameter('wheres')),
            'bindings'=> $bindings,
        ];
    }

    /**
     * prepared delete query
     * @param BuilderInterface $builder
     * @return null|string
     */
    public function builderDelete(BuilderInterface $builder)
    {
        $table = $this->builderFromTable($builder, $builder->parameter('from'));
        if (!$table) {
            return null;
        }
        $where = $this->builderWheres($builder, $builder->parameter('wheres'));
        $where = $where ? ' WHERE '.$where : '';
        return sprintf('DELETE FROM %s%s', $table, $where);
    }
}
