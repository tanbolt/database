<?php
namespace Tanbolt\Database\Driver\Mysql;

use Tanbolt\Database\Query\BuilderInterface;
use Tanbolt\Database\Driver\Grammar as StructureGrammar;

class Grammar extends StructureGrammar
{
    /**
     * @inheritDoc
     */
    protected function builderUnion(BuilderInterface $union, bool $all = false)
    {
        $query = $union->query();
        return $query ? ($all ? 'UNION ALL (' : 'UNION (') . $query . ')' : '';
    }

    /**
     * @inheritDoc
     */
    protected function builderLock(BuilderInterface $builder, $value)
    {
        return null === $value ? null : ($value ? 'FOR UPDATE' : 'LOCK IN SHARE MODE');
    }

    /**
     * @inheritDoc
     */
    public function builderInsert(BuilderInterface $builder, array $inserts, bool $replace = false)
    {
        $query = $this->builderIntoQuery($builder, $inserts);
        return $query ? ($replace ? 'REPLACE' : 'INSERT').' INTO '.$query : null;
    }

    /**
     * @inheritDoc
     */
    public function builderUpdate(BuilderInterface $builder, array $update, array &$bindings = null)
    {
        if (!($update = $this->preparedUpdate($builder, $update, true)) ) {
            return null;
        }
        $bindings = $update->bindings;
        $limit = $update->joins ? '' : $this->mergeQuery([
            $this->builderOrders($builder, $builder->parameter('orders')),
            $this->builderLimit($builder->parameter('limit'))
        ]);
        return sprintf(
            'UPDATE %s%s SET %s%s%s',
            $update->table,
            ($update->joins ? ' '.$update->joins : ''),
            $update->columns,
            ($update->wheres ? ' WHERE '.$update->wheres : ''),
            ($limit ? ' '.$limit : '')
        );
    }
}
