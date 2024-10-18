<?php
namespace Tanbolt\Database;

use PDO;
use Tanbolt\Database\Exception\BadPropertyException;
use Throwable;
use Exception;
use Tanbolt\Database\Model\Access;
use Tanbolt\Database\Model\Helper;
use Tanbolt\Database\Model\Relation;
use Tanbolt\Database\Model\ActiveRecord;
use Tanbolt\Database\Exception\ModelNotFoundException;

/**
 * Class Model: 数据表 Model 抽象类，主要实现两种功能
 * - 通过魔术方法调用 ActiveRecord 实现针对 Model 的无状态查询器
 * - 映射数据库记录到有状态的 Model 对象, 对数据记录使用 OOP 方式 CURD
 *
 * 对于魔术方法调用 ActiveRecord 的处理:
 * 期待达成的使用方式 `Model::where()->...->method()->find()`
 * 等同于 `Builder()->from(Model->table())->where()....->method()`
 *
 * 配合 IDE 自动提示有以下几种方案
 *
 * 方式一：
 *      IDE（如 phpstorm）提供了 mixin 注释的方式引入另外一个类的方法，所以可通过 mixin ActiveRecord 引入方法，
 *      通过 (new Model())->where()->method() 调用, 可以获得自动提示功能
 *      Model 实现了静态函数的魔术方法，所以也可 Model::where()->method() 形式调用，IDE 默认有警告提示, 但可取消
 *      [静态调用默认不会自动提示, phpstorm 也可以通过 ^ + Space 键获得自动提示, 使用略麻烦]
 *
 *      优势：动态调用, 可获得自动提示
 *           不需要额外创建 注释 文件, 仅 mixin ActiveRecord 即可
 *           Ctrl+点击 可以跳转并定位到实际的执行函数
 *      劣势：静态调用默认无法自动完成，需先实例化再调用的方式, 语法略显啰嗦
 * 方式二：
 *      额外创建一个 class 文件，提取 ActiveRecord 的 public 方法并改为 static 模式, 并 mixin 引入这个 class
 *      这样就可以通过 Model::where()->method() 调用 ActiveRecord 方法，并获得自动提示功能
 *      但对于 (new Model())->where()->method() 形式，静态方法默认不会自动提示
 *      [当然, phpstorm 仍可以通过 ^ + Space 键获显示所有方法, 从而获得自动提示]
 *
 *      优势：静态调用方式语法简洁
 *      劣势：需要额外创建 class 文件且保持与 ActiveRecord 的一致,
 *           Ctrl+点击 无法定位到实际的执行函数
 *           动态调用无法直接提示(不过查询操作很少会直接动态调用的方式)
 *           Model 子类有自定义 scope 函数, 每个都需通过注释方式定义 static 方法才能获得 IDE 提示
 *      备注：Laravel 使用的是这种方式
 * 方式三：
 *      在方式一基础上，增加一个 Model:instance() 函数返回 Model 实例
 *      使用时通过 Model::instance()->where()->method() 避免 new 方式的啰嗦，同时获得自动提示
 *      优劣势与[方式一]完全相同
 *
 * 最终决定
 *      采用方式三, 创建一个 instance() 静态方法, 方便链式调用, IDE 可获得提示
 *      同时也实现了静态函数的魔术方法，所有 ActiveRecord 动态方法都可以使用 Model::method() 静态方式调用
 *
 * 另外，使用 instance() 方式，还额外有了一个好处：
 *      Model 映射往往要单独创建一个类, 比如通过 User extends Model{}, 这样在使用时就可以
 *      $user = User::instance()->find() 获得一个对象, 之后可通过 $user->filed 修改字段并 $user->save() 完成更新
 *
 *      但有些时候，有些无关紧要、使用很少的表创建的一个单独的类显的很累赘，那么有了 instance() 就可以
 *      $model = Model::instance('table')->find() 获得一个对象，之后便可使用 OOP 方式对该记录进行修改更新
 *
 * @package Tanbolt\Database
 * @mixin ActiveRecord
 * @property Model|object|null $pivot 中间表
 */
class Model extends Access
{
    const EVENT_STARTUP = 'startup';
    const EVENT_BOOT = 'boot';

    const EVENT_SAVING = 'saving';
    const EVENT_SAVED = 'saved';

    const EVENT_CREATING = 'creating';
    const EVENT_CREATED = 'created';

    const EVENT_UPDATING = 'updating';
    const EVENT_UPDATED = 'updated';

    const EVENT_DROPPING = 'dropping';
    const EVENT_DROPPED = 'dropped';

    /**
     * 数据库配置信息
     * @var array|string|null
     */
    protected $connectConfig;

    /**
     * 数据表 名称
     * @var string
     */
    protected $tableName;

    /**
     * 主键
     * @var string|array
     */
    protected $primaryColumn = 'id';

    /**
     * 缺省查询字段
     * @var string
     */
    protected $selectColumns = ['*'];

    /**
     * 入库时间 自动维护字段名
     * @var string
     */
    protected $createTimeColumn;

    /**
     * 修改时间 自动维护字段名
     * @var string
     */
    protected $updateTimeColumn;

    /**
     * 数据库连接器
     * @var Connection
     */
    private $connection;

    /**
     * 当前模型是否已存在于数据库中
     * @var bool
     */
    private $newRecord;

    /**
     * relation 缓存
     * @var Relation[]
     */
    private $related = [];

    /**
     * 调用自定义 scope 筛选函数时的临时 ActiveRecord 对象
     * @var ActiveRecord
     */
    private $scopeActiveRecord;

    /**
     * @var array
     */
    private static $startupRecord = [];

    /**
     * 实例化一个 Model 对象
     * @param string|null $table
     * @param array|string|null $primary
     * @return static
     */
    public static function instance(string $table = null, $primary = null)
    {
        $model = new static();
        if ($table) {
            $model->setTable($table);
        }
        if ($primary) {
            $model->setPrimaryColumn($primary);
        }
        return $model;
    }

    /**
     * 创建 Model 对象
     * @param array $attributes
     * @param bool $newRecord
     */
    public function __construct(array $attributes = [], bool $newRecord = true)
    {
        if (!in_array(($class = get_called_class()), self::$startupRecord)) {
            $this->fireListener(static::EVENT_STARTUP);
            self::$startupRecord[] = $class;
        }
        parent::__construct($attributes);
        $this->newRecord = $newRecord;
        if (!$this->newRecord) {
            $this->syncOriginal();
        }
        $this->fireListener(static::EVENT_BOOT);
    }

    /**
     * 标记当前 Model 为已入库数据
     * @param bool $new
     * @return $this
     */
    public function setNewRecord(bool $new = true)
    {
        $this->newRecord = $new;
        return $this;
    }

    /**
     * 判断当前 Model 是否已经入库
     * @return bool
     */
    public function isNewRecord()
    {
        return $this->newRecord;
    }

    /**
     * 设置数据库连接配置信息
     * @param array|string|null $config
     * @return $this
     */
    public function setConnectConfig($config)
    {
        $this->connectConfig = $config;
        return $this;
    }

    /**
     * 获取数据库连接配置信息
     * @return array|string|null
     */
    public function getConnectConfig()
    {
        return $this->connectConfig;
    }

    /**
     * 手工设置 Connection 对象，默认会根据 setConnectConfig() 设置的连接信息创建 Connection 对象
     * @param ?Connection $connection
     * @return $this
     */
    public function setConnection(?Connection $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 获取 Model Connection，根据 $config 参数，返回值有以下几种情况
     * - true: 返回 setConnection 设置的值
     * - array: 直接根据 array 配置连接数据库并返回
     * - string: 返回 Database 预置的数据库连接
     * - null: 根据 Model 的 connectConfig 返回数据库连接，若未设置，则返回默认连接
     * @param array|string|true|null $config
     * @return Connection
     */
    public function getConnection($config = null)
    {
        if (true === $config) {
            return $this->connection;
        }
        if ($config) {
            return Database::getNode($config);
        }
        return $this->connection ?: Database::getNode($this->getConnectConfig());
    }

    /**
     * 设置映射数据表名称
     * @param ?string $table
     * @return $this
     */
    public function setTable(?string $table)
    {
        $this->tableName = $table;
        return $this;
    }

    /**
     * 获取映射数据表名称
     * @return string
     */
    public function getTable()
    {
        if (empty($this->tableName)) {
            $this->tableName = implode('_', array_map(function($w) {
                return strtolower($w);
            }, preg_split(
                '/(?=[A-Z])/',
                basename(str_replace('\\', '/', get_class($this))),
                -1,
                PREG_SPLIT_NO_EMPTY
            )));
        }
        return $this->tableName;
    }

    /**
     * 设置数据表主键字段
     * @param array|string $column
     * @return $this
     */
    public function setPrimaryColumn($column)
    {
        $this->primaryColumn = $column;
        return $this;
    }

    /**
     * 获取数据表主键字段
     * @return array|string
     */
    public function getPrimaryColumn()
    {
        return $this->primaryColumn;
    }

    /**
     * 获取数据表绝对字段:
     * 比如数据表名为 foo, 字段为 id, 该函数返回 foo.id, 若不指定 $columns, 默认使用主键
     * @param array|string|null $columns
     * @return array|string
     */
    public function getTablePrimary($columns = null)
    {
        if (empty($columns)) {
            $columns = $this->getPrimaryColumn();
        }
        $table = $this->getTable();
        if (is_array($columns)) {
            $primary = [];
            foreach ($columns as $column) {
                $primary[] = $table . '.' . $column;
            }
            return $primary;
        }
        return $table.'.'. $columns;
    }

    /**
     * 设置当前 Model 的主键值
     * @param array|string|mixed $primary
     * @return $this
     */
    public function setPrimaryValue($primary)
    {
        $columns = $this->getPrimaryColumn();
        if (!is_array($columns)) {
            if (is_array($primary)) {
                if(isset($primary[$columns])) {
                    $primary = $primary[$columns];
                } else {
                    throw new ModelNotFoundException('Primary value not match');
                }
            }
            return $this->setAttribute($columns, $primary);
        }
        if (!is_array($primary) || count($primary) < ($columnCount = count($columns))) {
            throw new ModelNotFoundException('Primary value not match');
        }
        $attributes = [];
        foreach ($columns as $column) {
            if (isset($primary[$column])) {
                $attributes[$column] = $primary[$column];
            }
        }
        if (count($attributes) !== $columnCount) {
            $attributes = [];
            $primary = array_values($primary);
            foreach ($columns as $key => $column) {
                $attributes[$column] = $primary[$key];
            }
        }
        return $this->setAttribute($attributes);
    }

    /**
     * 获取当前 Model 主键字段的值
     * @return mixed
     */
    public function primaryValue()
    {
        $columns = $this->getPrimaryColumn();
        if (!is_array($columns)) {
            // 先从原始值获取, 万一修改了主键值呢
            return $columns ? (
                $this->hasOriginal($columns) ? $this->getOriginal($columns) : $this->getAttribute($columns)
            ) : null;
        }
        $values = [];
        foreach ($columns as $column) {
            $values[$column] = $this->hasOriginal($column) ? $this->getOriginal($column) : $this->getAttribute($column);
        }
        return $values;
    }

    /**
     * 设置缺省情况下查询的字段名
     * @param mixed $columns
     * @return $this
     */
    public function setSelectColumns(...$columns)
    {
        $this->selectColumns = self::flatten($columns);
        return $this;
    }

    /**
     * 获取缺省情况下查询的字段名
     * @return array
     */
    public function getSelectColumns()
    {
        return (array) $this->selectColumns;
    }

    /**
     * 设置记录入库时间的字段名
     * @param ?string $column
     * @return $this
     */
    public function setCreateTimeColumn(?string $column)
    {
        $this->createTimeColumn = $column;
        return $this;
    }

    /**
     * 获取记录入库时间的字段名
     * @return string
     */
    public function getCreateTimeColumn()
    {
        return $this->createTimeColumn;
    }

    /**
     * 手工强制设置入库时间的值, 默认为 insert 语句执行时的时间
     * @param $value
     * @return $this
     */
    public function setCreateTime($value)
    {
        if ($column = $this->getCreateTimeColumn()) {
            $this->{$column} = $value;
        }
        return $this;
    }

    /**
     * 获取记录更新时间的字段名
     * @param ?string $column
     * @return $this
     */
    public function setUpdateTimeColumn(?string $column)
    {
        $this->updateTimeColumn = $column;
        return $this;
    }

    /**
     * 设置记录更新时间的字段名
     * @return string
     */
    public function getUpdateTimeColumn()
    {
        return $this->updateTimeColumn;
    }

    /**
     * 手工强制设置更新时间的值, 默认为 update 语句执行时的时间
     * @param $value
     * @return $this
     */
    public function setUpdateTime($value)
    {
        if ($column = $this->getUpdateTimeColumn()) {
            $this->{$column} = $value;
        }
        return $this;
    }

    /**
     * 设置 入库时间/更新时间 为当前时间
     * - 若为新纪录，且未手动设置过，设置当期时间为入库时间
     * - 无论是否为新纪录，未手动设置过，设置当前时间为更新时间
     * @return $this
     */
    public function syncTimeColumn()
    {
        $time = '@'.time();
        if (($column = $this->getCreateTimeColumn()) && $this->isNewRecord() && !$this->isChanged($column)) {
            $this->setCreateTime($time);
        }
        if (($column = $this->getUpdateTimeColumn()) && !$this->isChanged($column)) {
            $this->setUpdateTime($time);
        }
        return $this;
    }

    /**
     * 返回 一对一 关联模型
     * @param string $related
     * @param $foreignKey
     * @param $localKey
     * @param ?callable $where
     * @return Relation|mixed
     */
    public function oneToOne(string $related, $foreignKey, $localKey, callable $where = null)
    {
        return $this->getRelationObject('Relation', $related, $foreignKey, $localKey, $where);
    }

    /**
     * 返回 一对多 关联模型
     * @param string $related
     * @param $foreignKey
     * @param $localKey
     * @param ?callable $where
     * @return Relation|mixed
     */
    public function oneToMany(string $related, $foreignKey, $localKey, callable $where = null)
    {
        return $this->getRelationObject('Relation', $related, $foreignKey, $localKey, $where, true);
    }

    /**
     * 返回一个 hasOne 类型的关联模型
     * @param string $related
     * @param $foreignKey
     * @param $localKey
     * @param ?callable $where
     * @return Relation\HasOne|mixed
     */
    public function hasOne(string $related, $foreignKey, $localKey, callable $where = null)
    {
        return $this->getRelationObject(__FUNCTION__, $related, $foreignKey, $localKey, $where);
    }

    /**
     * 返回一个 HasMany 类型的关联模型
     * @param string $related
     * @param $foreignKey
     * @param $localKey
     * @param ?callable $where
     * @return Relation\HasMany|mixed
     */
    public function hasMany(string $related, $foreignKey, $localKey, callable $where = null)
    {
        return $this->getRelationObject(__FUNCTION__, $related, $foreignKey, $localKey, $where, true);
    }

    /**
     * 返回一个 BelongsTo 类型的关联模型
     * @param $related
     * @param $foreignKey
     * @param $localKey
     * @param ?callable $where
     * @return Relation\BelongsTo|mixed
     */
    public function belongsTo($related, $foreignKey, $localKey, callable $where = null)
    {
        return $this->getRelationObject(__FUNCTION__, $related, $foreignKey, $localKey, $where);
    }

    /**
     * @param string $type
     * @param string $related
     * @param $foreignKey
     * @param $localKey
     * @param ?callable $where
     * @param bool $many
     * @param null $name
     * @return Relation|mixed
     */
    protected function getRelationObject(
        string $type,
        string $related,
        $foreignKey,
        $localKey,
        callable $where = null,
        bool $many = false,
        $name = null
    ) {
        $hash = md5(serialize([$type, $related, $foreignKey, $localKey]));
        if (isset($this->related[$hash])) {
            $relation = $this->related[$hash];
        } else {
            $relation = __NAMESPACE__.'\\Model\\Relation';
            if ('Relation' !== $type) {
                $relation = __NAMESPACE__.'\\Model\\Relation\\'.ucfirst($type);
            }
            $relation = new $relation((new $related()), $this, $foreignKey, $localKey, $many);
            $this->related[$hash] = $relation;
        }
        if (empty($name)) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = array_pop($caller);
            $name = $caller['function'];
        }
        $relation->resetRelationBuilder($where, $many, $name);
        return $relation;
    }

    /**
     * 手动加载指定的关联模型,默认情况下,直接以属性方式访问关联模型,自动加载并获取结果。
     * > 手动加载则可以进行有条件的加载,比如符合某种条件下,在关联模型设置的基础上再添加一些验证条件
     * ```
     * loadRelation('foo', 'bar', 'biz.que')
     * loadRelation([
     *    'foo' => function() {
     *       //Scope
     *    },
     *   'bar' => function() {
     *       //Scope
     *    },
     * ])
     * ```
     * @param mixed $relation 指定要加载的关联模型
     * @return $this
     * @throws Exception
     */
    public function loadRelation(...$relation)
    {
        Helper::loadModelRelations([$this], $relation);
        return $this;
    }

    /**
     * 保存当前 Model 数据
     * @return $this|false
     * @throws Throwable
     */
    public function save()
    {
        if ($this->fireListener(static::EVENT_SAVING) === false) {
            return false;
        }
        // insert
        if ($this->isNewRecord()) {
            if ($this->fireListener(static::EVENT_CREATING) === false) {
                return false;
            }
            if (!$this->count()) {
                return $this;
            }
            $dates = $this->syncTimeColumn()->attributes();
            // 如果主键只有一个, 且已设置的 model 数据不包含主键, 则认为主键是自增字段
            $activeRecord = new ActiveRecord($this);
            $primaryColumn = $this->getPrimaryColumn();
            if ($primaryColumn && !is_array($primaryColumn) && !array_key_exists($primaryColumn, $dates)) {
                if ($result = $activeRecord->setIncrementColumn($primaryColumn)->insert($dates)) {
                    $this->{$primaryColumn} = $activeRecord->lastId();
                }
            } else {
                $result = $activeRecord->insert($dates);
            }
            if ($result) {
                $this->fireListener(static::EVENT_CREATED);
                $this->afterSaved();
            }
            return $this;
        }
        // update
        if ($this->fireListener(static::EVENT_UPDATING) === false) {
            return false;
        }
        if (!$this->isChanged()) {
            return $this;
        }
        $primary = $this->primaryValue();
        if (empty($primary)) {
            throw new Exception('No primary key defined on model.');
        }
        $this->syncTimeColumn();
        $updates = $this->changed();
        if (!count($updates)) {
            return $this;
        }
        if ((new ActiveRecord($this))->wherePrimary($primary)->update($updates)) {
            $this->fireListener(static::EVENT_UPDATED);
            $this->afterSaved();
        }
        return $this;
    }

    /**
     * 保存当前 Model 数据并保存与 Model 关联的其他 Model 数据
     * @return $this
     * @throws Throwable
     */
    public function saveWithRelation()
    {
        foreach (Helper::flattenModels($this) as $collection) {
            $collection->save();
        }
        return $this;
    }

    /**
     * 触发保存后的监听函数，save() / saveWithRelation() 函数执行后会自动调用该函数，无需手工再次调用。
     * 如果通过其他方式保存 Model，则需要手动调用该函数，一般情况下是用不到的
     * @return $this
     */
    public function afterSaved()
    {
        $this->newRecord = false;
        $this->fireListener(static::EVENT_SAVED);
        $this->syncOriginal();
        return $this;
    }

    /**
     * 从数据库中删除当前 Model 对应的记录
     * @return $this|false
     * @throws Exception
     */
    public function drop()
    {
        $primary = $this->getPrimaryColumn();
        $primary = empty($primary) ? null : $this->primaryValue();
        if (empty($primary)) {
            throw new Exception('No primary key defined on model.');
        }
        if ($this->fireListener(static::EVENT_DROPPING) === false) {
            return false;
        }
        if ((new ActiveRecord($this))->wherePrimary($primary)->delete()) {
            $this->setNewRecord()->fireListener(static::EVENT_DROPPED);
        }
        return $this;
    }

    /**
     * 删除当前 Model 对应的记录，同时删除关联模型的数据
     * @return $this
     * @throws Exception
     */
    public function dropWithRelation()
    {
        foreach (Helper::flattenModels($this) as $collection) {
            $collection->drop();
        }
        return $this;
    }

    /**
     * 获取一个新对象
     * - 1.若当前是未入库对象, 则返回一个克隆当前对象的新对象
     * - 2.若为已入库对象, 则是通过主键从数据库重新查询得到的新对象, 同时可使用类似 fresh('foo', 'bar') 指定同时要加载的关联 model
     * @param mixed $relation 设置同步刷新的关联模型
     * @return $this|object
     */
    public function fresh(...$relation)
    {
        if ($this->isNewRecord()) {
            return clone $this;
        }
        return call_user_func_array([$this, 'with'], $relation)->wherePrimary($this->primaryValue())->find();
    }

    /**
     * 刷新当前对象(所有数据重新从数据库查询)，如果是未入库数据，什么都不做，直接返回
     * @return $this
     * @throws Exception
     */
    public function refresh()
    {
        if ($this->isNewRecord()) {
            return $this;
        }
        $this->setAttribute(
            (new ActiveRecord($this))->wherePrimary($this->primaryValue())
                ->modelBuilder()->getOne(PDO::FETCH_ASSOC)
        );
        $relations = array_filter(array_keys($this->getRelation()), function($relation) {
            if ('pivot' === $relation) {
                $this->pivot->refresh();
                return false;
            }
            return true;
        });
        $this->loadRelation($relations)->syncOriginal();
        return $this;
    }

    /**
     * 触发 Model 事件监听函数
     * @param string $event
     * @return mixed|null
     */
    public function fireListener(string $event)
    {
        $method = 'on'.ucfirst($event);
        if (method_exists($this, $method)) {
            $this->$method();
        }
        if (!($listener = Database::getModelListener())) {
            return null;
        }
        return call_user_func($listener, $event, $this);
    }

    /**
     * 获取当前 Model 的唯一符：由 连接器/表名/主键值 构成，
     * 若两个 Model 的唯一符相同，可认为映射的是同一条数据库记录。
     * @return string
     */
    public function uniqueSymbol()
    {
        $unique = [];
        if ($this->connection) {
            $unique[] = $this->connection->name;
        } elseif ($this->connectConfig) {
            $unique[] = md5(serialize($this->connectConfig));
        } else {
            $unique[] = '';
        }
        $unique[] = $this->getTable();
        try {
            $primary = $this->primaryValue();
        } catch (BadPropertyException $e) {
            $primary = '';
        }
        if (is_array($primary)) {
            ksort($primary);
            $unique[] = implode('_', $primary);
        } else {
            $unique[] = $primary;
        }
        return implode('#', $unique);
    }

    /**
     * 判断当前 Model 是否和另外一个 Model 等价，即 uniqueSymbol 相同
     * @param Model $model
     * @return bool
     * @see uniqueSymbol
     */
    public function equal(Model $model)
    {
        return $model->uniqueSymbol() === $this->uniqueSymbol();
    }

    /**
     * 设置当前 Model 的全局限定条件
     */
    public function globalScope()
    {
        // ex:  $builder->where('id', '>', 2);
    }

    /**
     * 获取以当前对象作为 Model 源的 ActiveRecord 对象。
     * 由于当前 Model 使用了模式函数映射了 ActiveRecord 的所有方法, 可直接调用 ActiveRecord 方法，一般情况该函数也用不到
     * @return ActiveRecord
     */
    public function activeRecord()
    {
        return new ActiveRecord($this);
    }

    /**
     * 调用自定义 scope 筛选函数时的临时 ActiveRecord 对象
     * @param ActiveRecord|null $activeRecord
     * @return $this
     */
    public function __setScopeActiveRecord(?ActiveRecord $activeRecord)
    {
        $this->scopeActiveRecord = $activeRecord;
        return $this;
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        // 根据调用时机, 使用不同的 ActiveRecord 对象
        // Model::instance()->where()->orWhere()->myScope()->find()  在第一步调用 where() 时创建新的 ActiveRecord 对象
        // 后续链式调用(如: orWhere()) 是在这个 ActiveRecord 对象上执行的，与 Model 已无关联
        // 在调用 myScope() 时并为执行, 而只是记录了函数名 和 调用参数
        // 在最后执行 find() 时, 即要执行 SQL query 时, ActiveRecord 对象内部会先通过 __setScopeActiveRecord 设置其自身
        // 然后调用 Model 的 myScope() 函数，在 myScope() 内部通过 $this->where() 设置自定义限制条件
        // 此内部的 where() 函数触发 __call 魔术函数, 实际执行的 ActiveRecord 对象与 Model::instance() 为同一个
        $activeRecord = $this->scopeActiveRecord ?: new ActiveRecord($this);
        return call_user_func_array([$activeRecord, $name], $arguments);
    }

    /**
     * 静态魔术方法也实现一下，支持直接 Model::where()->find().
     * 但不是很推荐使用这种方式，具体愿意参考当前 Class 头部注释
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return call_user_func_array([new static, $name], $arguments);
    }
}
