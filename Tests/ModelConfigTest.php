<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model;
use Tanbolt\Database\Database;

class ModelConfigTest extends TestCase
{

    public function testModelConfigMethod()
    {
        $defaultConfig = [
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ];
        Database::putNode(['default' => $defaultConfig], true);

        $model = new ModelBasic(['id' => 1, 'sid' => 2]);

        static::assertTrue($model->isNewRecord());
        static::assertNull($model->getConnectConfig());
        static::assertNull($model->getConnection(true));
        static::assertEquals('model_basic', $model->getTable());
        static::assertEquals('id', $model->getPrimaryColumn());
        static::assertEquals('model_basic.id', $model->getTablePrimary());
        static::assertEquals(['*'], $model->getSelectColumns());
        static::assertNull($model->getCreateTimeColumn());
        static::assertNull($model->getUpdateTimeColumn());
        static::assertEquals(1, $model->primaryValue());
        static::assertFalse($model->equal((new ModelBasic())));
        static::assertTrue($model->equal((new ModelBasic(['id' => 1]))));

        $connection = $model->connection();
        static::assertInstanceOf('Tanbolt\Database\Connection', $connection);
        static::assertEquals($defaultConfig, $connection->config);
        static::assertSame($connection, $model->getConnection());

        $config = [
            'driver' => 'sqlite',
            'dbname' => 'model.db',
        ];
        static::assertSame($model, $model->setConnectConfig($config));
        static::assertEquals($config, $model->getConnectConfig());

        static::assertSame($model, $model->setTable('table'));
        static::assertEquals('table', $model->getTable());

        static::assertSame($model, $model->setSelectColumns(['id', 'sid']));
        static::assertEquals(['id', 'sid'], $model->getSelectColumns());

        static::assertSame($model, $model->setCreateTimeColumn('create'));
        static::assertEquals('create', $model->getCreateTimeColumn());

        static::assertSame($model, $model->setUpdateTimeColumn('update'));
        static::assertEquals('update', $model->getUpdateTimeColumn());


        static::assertSame($model, $model->setPrimaryColumn('sid'));
        static::assertEquals('sid', $model->getPrimaryColumn());
        static::assertEquals('table.sid', $model->getTablePrimary());
        static::assertEquals('table.id', $model->getTablePrimary('id'));

        static::assertEquals(2, $model->primaryValue());
        static::assertFalse($model->equal((new ModelBasic())));
        static::assertFalse($model->equal((new ModelBasic(['id' => 1]))));
        static::assertFalse($model->equal((new ModelBasic(['sid' => 2]))));
        static::assertFalse(
            $model->equal((new ModelBasic(['sid' => 2]))->setTable('table')->setPrimaryColumn('sid'))
        );

        static::assertSame($model, $model->setCreateTime(10));
        static::assertEquals(10, $model->create);

        static::assertSame($model, $model->setUpdateTime(20));
        static::assertEquals(20, $model->update);

        // add
        static::assertSame($model, $model->syncOriginal());
        static::assertSame($model, $model->setPrimaryValue(1));
        static::assertEquals(2, $model->primaryValue());

        static::assertSame($model, $model->syncOriginal());
        static::assertEquals(1, $model->primaryValue());

        static::assertSame($model, $model->setPrimaryColumn(['id', 'sid']));
        static::assertEquals(['id', 'sid'], $model->getPrimaryColumn());
        static::assertEquals(['table.id', 'table.sid'], $model->getTablePrimary());
        static::assertEquals([
            'id' => 1,
            'sid' => 1
        ], $model->primaryValue());

        static::assertSame($model, $model->setPrimaryValue([
            'id' => 3,
            'sid' => 2,
            'foo' => 4
        ]));
        static::assertEquals([
            'id' => 1,
            'sid' => 1
        ], $model->primaryValue());
        static::assertSame($model, $model->syncOriginal());
        static::assertEquals([
            'id' => 3,
            'sid' => 2
        ], $model->primaryValue());

        static::assertSame($model, $model->setPrimaryValue([
            6,4,5
        ]));
        static::assertEquals([
            'id' => 3,
            'sid' => 2
        ], $model->primaryValue());
        static::assertSame($model, $model->syncOriginal());
        static::assertEquals([
            'id' => 6,
            'sid' => 4
        ], $model->primaryValue());

        $connection = $model->connection();
        static::assertInstanceOf('Tanbolt\Database\Connection', $connection);
        static::assertEquals($config, $connection->config);
        static::assertSame($connection, $model->getConnection());

        $activeRecord = $model->activeRecord();
        static::assertInstanceOf('Tanbolt\Database\Model\ActiveRecord', $activeRecord);
        static::assertSame($model, $activeRecord->getModel());

        $modelConnection = new ModelConnection();
        static::assertSame($model, $model->setConnection($modelConnection));
        static::assertInstanceOf('ModelConnection', $model->getConnection());

        $customConfig = [
            'driver' => 'sqlite',
            'dbname' => 'custom.db',
        ];
        $customConnection = $model->getConnection($customConfig);
        static::assertInstanceOf('Tanbolt\Database\Connection', $customConnection);
        static::assertEquals($customConfig, $customConnection->config);

        Database::getNode()->disconnect();
        Database::clearNode();
    }

    protected function modelPropertyCheck(Model $model)
    {
        static::assertEquals('customTable', $model->getTable());
        static::assertEquals(['id', 'sid', 'foo', 'bar'], $model->getSelectColumns());
        static::assertEquals(['id', 'sid'], $model->getPrimaryColumn());
        static::assertEquals('createTime', $model->getCreateTimeColumn());
        static::assertEquals('updateTime', $model->getUpdateTimeColumn());
        static::assertEquals(['sid'], $model->getHidden());
        static::assertEquals(['id', 'sid', 'foo', 'bar'], $model->getVisible());
    }

    public function testModelCustomConfig()
    {
        Database::putNode(['default' => [
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]], true);

        $customConfig = [
            'driver' => 'sqlite',
            'dbname' => 'modelCustom',
        ];
        Database::putNode(['custom' => $customConfig]);

        $model = new ModelCustom();
        static::assertEquals('custom', $model->getConnectConfig());
        $this->modelPropertyCheck($model);

        $connection = $model->connection();
        static::assertInstanceOf('Tanbolt\Database\Connection', $connection);
        static::assertEquals($customConfig, $connection->config);

        $model->setConnectConfig(NULL);
        static::assertNull($model->getConnectConfig());
        Database::clearNode();
    }

    public function testModelCustomConfigByArray()
    {
        $model = new ModelCustomConfig();
        $config = [
            'driver' => 'sqlite',
            'dbname' => 'modelCustom.db',
        ];

        static::assertEquals($config, $model->getConnectConfig());
        $this->modelPropertyCheck($model);

        $connection = $model->connection();
        static::assertInstanceOf('Tanbolt\Database\Connection', $connection);
        static::assertEquals($config, $connection->config);

        $model->setConnectConfig(NULL);
        static::assertNull($model->getConnectConfig());
        Database::clearNode();
    }
}


class ModelConnection extends \Tanbolt\Database\Connection
{
}

class ModelBasic extends Model
{
}

class ModelCustom extends Model
{
    protected $connectConfig = 'custom';

    protected $tableName = 'customTable';

    protected $selectColumns = ['id', 'sid', 'foo', 'bar'];

    protected $primaryColumn = ['id', 'sid'];

    protected $incrementing = false;

    protected $createTimeColumn = 'createTime';

    protected $updateTimeColumn = 'updateTime';

    protected $hidden = ['sid'];

    protected $visible = ['id', 'sid', 'foo', 'bar'];
}


class ModelCustomConfig extends ModelCustom
{
    protected $connectConfig = [
        'driver' => 'sqlite',
        'dbname' => 'modelCustom.db',
    ];
}



