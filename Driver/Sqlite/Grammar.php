<?php
namespace Tanbolt\Database\Driver\Sqlite;

use DateTime;
use Tanbolt\Database\Query\BuilderInterface;
use Tanbolt\Database\Query\ExpressionInterface;
use Tanbolt\Database\Driver\Grammar as StructureGrammar;

class Grammar extends StructureGrammar
{
    /**
     * @var int
     */
    private $timeOffset = null;

    /**
     * @inheritDoc
     */
    public function builderSelect(BuilderInterface $builder)
    {
        $query = $this->preparedSelect($builder);
        $select = $this->mergeQuery([
            ($query['columns'] ? 'SELECT '.$query['columns'] : ''),
            ($query['from'] ? 'FROM '.$query['from'] : ''),
            $query['joins'],
            ($query['wheres'] ? 'WHERE '.$query['wheres'] : ''),
            $query['groups'],
            ($query['having'] ? 'HAVING '.$query['having'] : ''),
            $query['orders'],
            $query['limit'],
            $query['offset'],
        ]);
        if (!$query['unions']) {
            return $select;
        }
        if ($builder->parameter('orders') || $builder->parameter('limit')) {
            $select = 'SELECT * FROM ('.$select.')';
        }
        return $this->mergeQuery([$select, $query['unions']]);
    }

    /**
     * @inheritDoc
     */
    protected function builderUnion(BuilderInterface $union, bool $all = false)
    {
        $query = $union->query();
        if ($query) {
            if ($union->parameter('orders') || $union->parameter('limit')) {
                $query = 'SELECT * FROM ('.$query.')';
            }
            return ($all ? 'UNION ALL ' : 'UNION ') . $query;
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    protected function builderWhereIn(BuilderInterface $builder, array $clause)
    {
        if (!($left = $this->quoteColumns($clause['left']))) {
            return null;
        }
        if (!is_array($clause['left'])) {
            $right = $this->parameter($clause['right']);
            return $left.' '.$clause['operator'].' ('.$right.')';
        }
        if (!is_array($clause['right'])) {
            return null;
        }
        $alias = null;
        $table = $this->getQuote($builder->parameter('from'), true, $alias);
        if ($alias) {
            $table = '`'.$alias.'`';
        }
        $select = '';
        $clauseOn = '';
        foreach ($clause['left'] as $column) {
            $select .= ('' === $select ? '' : ',').' ? AS `'.$column.'`';
            $clauseOn .= ('' === $clauseOn ? '' : ' AND '). '`tanbolt_tempin`.`'.$column.'` ='. $table.'.`'.$column.'`';
        }
        $select = ' UNION ALL SELECT'.$select;
        $select = str_repeat($select, count($clause['right']));
        $select = substr($select, 11);
        $select = '(SELECT NULL FROM ('.$select.') AS `tanbolt_tempin` WHERE '.$clauseOn.')';
        return ('IN' === $clause['operator'] ? 'EXISTS ' : 'NOT EXISTS ').$select;
    }

    /**
     * @inheritDoc
     */
    protected function builderWhereTime(BuilderInterface $builder, array $clause, string $function)
    {
        return $this->builderWhereUnixTime($clause, $function);
    }

    /**
     * @inheritDoc
     */
    protected function builderWhereUnix(BuilderInterface $builder, array $clause, string $function)
    {
        return $this->builderWhereUnixTime($clause, $function, true);
    }

    /**
     * builder where time or where unix
     * @param array $clause
     * @param string $function
     * @param bool $unix
     * @return null|string
     */
    protected function builderWhereUnixTime(array $clause, string $function, bool $unix = false)
    {
        if (!($left = $this->quoteTableColumn($clause['left']))) {
            return null;
        }
        if (!$unix && 'DATETIME' === $function) {
            return $this->builderWhereAuto($left, $clause);
        }
        if ($unix && 'TIMESTAMP' === $function) {
            return $this->builderWhereAuto($left, $clause);
        }
        $timeOffset = $unixTime = '';
        if ($unix || 'TIMESTAMP' === $function) {
            $timeOffset = $this->getTimeOffset() . ' seconds';
            $unixTime = ', \'unixepoch\', \''.$timeOffset.'\'';
        }
        $convert = [
            'MONTH' => 'm',
            'DAY' => 'd',
            'HOUR' => 'H',
            'MINUTE' => 'M',
            'SECOND' => 'S'
        ];
        $column = null;
        if (isset($convert[$function])) {
            $column = 'cast(strftime(\'%'.$convert[$function].'\', '.$left.$unixTime.') as integer)';
        } elseif ('YEAR' === $function || 'WEEK' === $function) {
            $column = 'strftime(\'%'.('YEAR' === $function ? 'Y' : 'w').'\', '.$left.$unixTime.')';
        } elseif ('DATE' === $function || 'TIME' === $function) {
            $column = $function.'('.$left.$unixTime.')';
        } elseif ('QUARTER' === $function) {
            $column = 'cast((cast(strftime(\'%m\', '.$left.$unixTime.') as integer) + 2) / 3 as integer)';
        } elseif ('TIMESTAMP' === $function) {
            $column = 'strftime(\'%s\', '.$left.', \''.$timeOffset.'\')';
        } elseif ('DATETIME' === $function) {
            $column = 'datetime('.$left.$unixTime.')';
        }
        return $column ? $this->builderWhereAuto($column, $clause) : null;
    }

    /**
     * get time zone offset
     * @return int
     */
    protected function getTimeOffset()
    {
        if (!$this->timeOffset) {
            $this->timeOffset = (new DateTime())->getOffset();
        }
        return $this->timeOffset;
    }

    /**
     * @inheritDoc
     */
    public function builderInsert(BuilderInterface $builder, array $inserts, bool $replace = false)
    {
        if (empty($inserts)) {
            return null;
        }
        if (count($inserts) < 2) {
            $query = $this->builderIntoQuery($builder, $inserts);
            return $query ? ($replace ? 'REPLACE' : 'INSERT').' INTO '.$query : null;
        }
        if (!($table = $this->quoteTable($builder->parameter('from')))) {
            return null;
        }
        $columns = [];
        $query = '';
        foreach (reset($inserts) as $key => $val) {
            $key = $this->quoteTableColumn($key);
            $columns[] = $key;
            $query .= ('' === $query ? '' : ', ') . '%s AS '.$key;
        }
        if (!count($columns)) {
            return null;
        }
        $columns = implode(', ', $columns);
        $query = 'SELECT '.$query;
        $parameters = [];
        foreach ($inserts as $value) {
            $parameter = [];
            foreach ($value as $val) {
                $parameter[] = $val instanceof ExpressionInterface ? $val->query()  : '?';
            }
            $parameters[] = vsprintf($query, $parameter);
        }
        return sprintf('%s INTO %s (%s) %s', ($replace ? 'REPLACE' : 'INSERT'), $table, $columns, implode(' UNION ALL ', $parameters));
    }
}
