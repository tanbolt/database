<?php

use PHPUnit\Framework\TestCase;
use Tanbolt\Database\Model\Access;
use Tanbolt\Database\Model\Cast\TimeAble;
use Tanbolt\Database\Model\Cast\ArrayAble;

class ModelAccessTest extends TestCase
{
    public function testAttributesMethod()
    {
        $access = new Access();
        static::assertEquals([], $access->toArray());

        static::assertFalse(isset($access['foo']));
        static::assertFalse($access->hasAttribute('foo'));
        $access['foo'] = 'bar';
        static::assertEquals('bar', $access['foo']);
        static::assertEquals('bar', $access->foo);
        static::assertTrue(isset($access['foo']));
        static::assertTrue($access->hasAttribute('foo'));
        static::assertEquals(['foo' => 'bar'], $access->attributes());

        static::assertFalse(isset($access->bar));
        $access->bar = 'foo';
        static::assertEquals('foo', $access['bar']);
        static::assertEquals('foo', $access->bar);
        static::assertTrue(isset($access->bar));

        static::assertEquals(['foo' => 'bar', 'bar' => 'foo'], $access->toArray());
        static::assertCount(2, $access);
        static::assertEquals('{"foo":"bar","bar":"foo"}', json_encode($access));
        static::assertEquals('{"foo":"bar","bar":"foo"}', (string) $access);

        unset($access['foo']);
        static::assertFalse(isset($access['foo']));
        static::assertFalse(isset($access->foo));
        static::assertEquals(['bar' => 'foo'], $access->toArray());
        static::assertCount(1, $access);
        static::assertEquals('{"bar":"foo"}', json_encode($access));
        static::assertEquals('{"bar":"foo"}', (string) $access);

        $access = new Access(['foo' => 'bar', 'bar' => 'foo']);
        static::assertEquals(['foo' => 'bar', 'bar' => 'foo'], $access->toArray());
        static::assertTrue(isset($access['foo']));
        static::assertEquals('bar', $access->foo);
        static::assertTrue(isset($access->bar));
        static::assertEquals('foo', $access['bar']);
        static::assertEquals(['foo' => 'bar', 'bar' => 'foo'], $access->toArray());
        static::assertCount(2, $access);
        static::assertEquals('{"foo":"bar","bar":"foo"}', json_encode($access));
        static::assertEquals('{"foo":"bar","bar":"foo"}', (string) $access);
        static::assertSame($access, $access->removeAttribute('foo'));
        static::assertFalse($access->hasAttribute('foo'));
        static::assertTrue($access->hasAttribute('bar'));
        static::assertSame($access, $access->removeAttribute());
        static::assertEquals([], $access->attributes());

        static::assertSame($access, $access->setAttribute($z = [
            'a' => 'a',
            'b' => 'b',
        ]));
        static::assertEquals('a', $access->getAttribute('a'));
        static::assertEquals('b', $access->getAttribute('b'));
        static::assertEquals($z, $access->attributes());
    }

    public function testCastAttribute()
    {
        // cast object
        static::assertInstanceOf(ArrayAble::class, $cast = Access::castArray($z = ['foo' => 'bar']));
        static::assertEquals($z, $cast->__toScalar());

        static::assertInstanceOf(TimeAble::class, $cast = Access::castTime($z = '2021-06-17'));
        static::assertEquals($z.' 00:00:00', $cast->__toScalar());


        $castsArr = PHPUNIT_CastMode::CastsArr;
        $access = new PHPUNIT_CastMode();

        // set get cast
        static::assertEquals($castsArr, $access->getCasts());
        static::assertSame($access, $access->setCasts('diy', Access::JSON));

        $castsArr = array_merge($castsArr, ['diy' => Access::JSON]);
        static::assertEquals($castsArr, $access->getCasts());
        foreach ($castsArr as $key => $type) {
            static::assertEquals($type, $access->getCasts($key));
        }

        // int
        static::assertSame($access, $access->setAttribute('a', '1'));
        static::assertTrue(is_int($access['a']));
        static::assertEquals(1, $access['a']);

        // float
        $access->b = '1.2';
        static::assertTrue(is_float($access->getAttribute('b')));
        static::assertEquals(1.2, $access->getAttribute('b'));

        // bool
        $access['c'] = 2;
        static::assertTrue((bool) $access->c);
        static::assertEquals(1, $access->c);

        //string
        $access->d = 11;
        static::assertTrue(is_string($access->d));
        static::assertEquals('11', $access->d);

        // json
        $access->e = ['foo' => 'bar'];
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\ArrayAble', $access->e);
        static::assertSame($access->e, $access->getAttribute('e'));
        static::assertEquals('{"foo":"bar"}', (string) $access->e);
        static::assertEquals(['foo' => 'bar'], $access['e']);

        $access->e = '{"biz":"que"}';
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\ArrayAble', $access->e);
        static::assertEquals('{"biz":"que"}', (string) $access->e);
        static::assertEquals(['biz' => 'que'], $access['e']);

        $access->e = (new ArrayAble)->__config(true)->__setter(['bar' => 'foo']);
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\ArrayAble', $access->e);
        static::assertEquals('{"bar":"foo"}', (string) $access->e);
        static::assertEquals(['bar' => 'foo'], $access['e']);

        // serialize
        $access['f'] = ['foo' => 'bar'];
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\ArrayAble', $access->f);
        static::assertEquals('a:1:{s:3:"foo";s:3:"bar";}', (string) $access->f);
        static::assertEquals(['foo' => 'bar'], $access['f']);

        $access['f'] = 'a:1:{s:3:"foo";s:3:"bar";}';
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\ArrayAble', $access->f);
        static::assertEquals('a:1:{s:3:"foo";s:3:"bar";}', (string) $access->f);
        static::assertEquals(['foo' => 'bar'], $access['f']);

        $access['f'] = new ArrayAble(['bar' => 'foo']);
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\ArrayAble', $access->f);
        static::assertEquals('a:1:{s:3:"bar";s:3:"foo";}', (string) $access->f);
        static::assertEquals(['bar' => 'foo'], $access['f']);

        // timestamp
        $access->g = '2012-10-24 11:11';
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->g);
        static::assertEquals('1351077060', (string) $access->g);
        static::assertEquals(1351077060, $access['g']);
        static::assertEquals('2012-10-24 11:11', $access->g->format('Y-m-d H:i'));

        $access->g = (new TimeAble)->__config(TimeAble::TIMESTAMP)->__setter('1351077060');
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->g);
        static::assertEquals('1351077060', (string) $access->g);
        static::assertEquals(1351077060, $access['g']);
        static::assertEquals('2012-10-24 11:11', $access->g->format('Y-m-d H:i'));

        $access->g = 1351077060;
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->g);
        static::assertEquals('1351077060', (string) $access->g);
        static::assertEquals(1351077060, $access['g']);
        static::assertEquals('2012-10-24 11:11', $access->g->format('Y-m-d H:i'));

        // time
        $access->h = '2012-10-25';
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->h);
        static::assertEquals('2012-10-25 00:00:00', (string) $access->h);
        static::assertEquals('2012-10-25 00:00:00', $access['h']);

        $access->h = (new TimeAble)->__setter('2012-10-24 11:12');
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->h);
        static::assertEquals('2012-10-24 11:12:00', (string) $access->h);
        static::assertEquals('2012-10-24 11:12:00', $access['h']);

        $access->h = 1351077060;
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->h);
        static::assertEquals('2012-10-24 11:11:00', (string) $access->h);
        static::assertEquals('2012-10-24 11:11:00', $access['h']);

        // custom format time
        $access->k = '2012-10-25';
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->k);
        static::assertEquals('10-25', (string) $access->k);
        static::assertEquals('10-25', $access['k']);

        $access->k = (new TimeAble)->__setter('2012-10-24 11:12');
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->k);
        static::assertEquals('10-24', (string) $access->k);
        static::assertEquals('10-24', $access['k']);

        $access->k = 1351077060;
        static::assertInstanceOf('Tanbolt\Database\Model\Cast\TimeAble', $access->k);
        static::assertEquals('10-24', (string) $access->k);
        static::assertEquals('10-24', $access['k']);

        // custom castAble
        $access->m = 'foo';
        static::assertInstanceOf('CustomAble', $access->m);
        static::assertEquals('_foo_', (string) $access->m);
        static::assertEquals([null, 'foo', null], $access['m']);

        $access->p = 'foo';
        static::assertInstanceOf('CustomAble', $access->p);
        static::assertEquals('start_foo_', (string) $access->p);
        static::assertEquals(['start', 'foo', null], $access['p']);

        $access->x = 'foo';
        static::assertInstanceOf('CustomAble', $access->x);
        static::assertEquals('start_foo_end', (string) $access->x);
        static::assertEquals(['start', 'foo', 'end'], $access['x']);

        // 测试 getAttribute 和 attributes 的不同
        $access = new PHPUNIT_CastMode();
        $access->d = 11;
        $access->k = '2012-10-25';
        static::assertEquals([
            'd' => $access->d,
            'k' => $access->k,
        ], $access->getAttribute());

        static::assertEquals([
            'd' => '11',
            'k' => '10-25',
        ], $access->attributes());
    }

    public function testAttributeModify()
    {
        $access = new PHPUNIT_AttributeModifyModel();
        static::assertTrue(isset($access['foo']));
        static::assertFalse(isset($access['bar']));
        static::assertFalse(isset($access['biz']));
        static::assertNull($access->foo);

        $access->foo = 'FOO';
        static::assertEquals('foo', $access->foo);

        $access->bar = 'BAR';
        static::assertEquals('BAR', $access->bar);
        static::assertEquals('bar', $access->biz);

        static::assertEquals([
            'foo' => 'getFooAttribute',
            'foo2' => 'getFoo2Attribute'
        ], $access->modifyGetMethod());
        static::assertEquals('getFooAttribute', $access->modifyGetMethod('foo'));
        static::assertNull($access->modifyGetMethod('bar'));

        static::assertEquals([
            'bar' => 'setBarAttribute',
            'bar2' => 'setBar2Attribute'
        ], $access->modifySetMethod());
        static::assertEquals('setBarAttribute', $access->modifySetMethod('bar'));
        static::assertNull($access->modifySetMethod('foo'));
    }

    public function testOriginalMethod()
    {
        $access = new Access(['foo' => 'foo', 'bar' => 'bar', 'biz' => 'biz']);
        static::assertEquals([], $access->getOriginal());

        static::assertFalse($access->hasOriginal('foo'));
        static::assertFalse($access->hasOriginal('bar'));
        static::assertFalse($access->hasOriginal('biz'));
        static::assertFalse($access->hasOriginal('hello'));

        static::assertSame($access, $access->setOriginal('hello', 'world'));
        static::assertTrue($access->hasOriginal('hello'));
        static::assertEquals('world', $access->getOriginal('hello'));
        static::assertEquals(['hello' => 'world'], $access->getOriginal());
        static::assertFalse(isset($access->hello));
        static::assertFalse($access->hasAttribute('hello'));

        static::assertTrue($access->isChanged());
        static::assertTrue($access->isChanged('foo'));
        static::assertEquals(['foo' => 'foo', 'bar' => 'bar', 'biz' => 'biz'], $access->changed());

        static::assertSame($access, $access->syncOriginal('foo'));
        static::assertTrue($access->hasOriginal('foo'));
        static::assertEquals('foo', $access->getOriginal('foo'));
        static::assertTrue($access->isChanged());
        static::assertFalse($access->isChanged('foo'));
        static::assertTrue($access->isChanged('bar'));
        static::assertEquals(['bar' => 'bar', 'biz' => 'biz'], $access->changed());
        static::assertEquals(['hello' => 'world', 'foo' => 'foo'], $access->getOriginal());

        static::assertSame($access, $access->setOriginal('foo', 'foo2'));
        static::assertEquals('foo2', $access->getOriginal('foo'));
        static::assertTrue($access->isChanged('foo'));

        static::assertSame($access, $access->syncOriginal());
        static::assertFalse($access->isChanged());
        static::assertFalse($access->isChanged('foo'));
        static::assertEquals(['foo' => 'foo', 'bar' => 'bar', 'biz' => 'biz'], $access->getOriginal());

        static::assertSame($access, $access->removeOriginal('foo'));
        static::assertEquals(['bar' => 'bar', 'biz' => 'biz'], $access->getOriginal());
        static::assertSame($access, $access->removeOriginal());
        static::assertEquals([], $access->getOriginal());

        static::assertSame($access, $access->setOriginal($z = [
            'a' => 'a',
            'b' => 'b',
        ]));
        static::assertEquals('a', $access->getOriginal('a'));
        static::assertEquals('b', $access->getOriginal('b'));
        static::assertEquals($z, $access->getOriginal());
    }

    public function testRelationMethod()
    {
        $access = new Access();
        static::assertFalse($access->hasRelation('foo'));
        static::assertSame($access, $access->setRelation('foo', 'foo'));
        static::assertTrue($access->hasRelation('foo'));
        static::assertEquals('foo', $access->getRelation('foo'));

        static::assertTrue(isset($access->foo));
        static::assertEquals('foo', $access->foo);
        static::assertEquals($access->foo, $access['foo']);
        static::assertFalse($access->hasAttribute('foo'));

        static::assertSame($access, $access->removeRelation('foo'));
        static::assertFalse($access->hasOriginal('foo'));
        static::assertFalse(isset($access['foo']));

        static::assertSame($access, $access->setRelation($z = [
            'a' => 'a',
            'b' => 'b',
        ]));
        static::assertEquals('a', $access->getRelation('a'));
        static::assertEquals('b', $access->getRelation('b'));
        static::assertEquals($z, $access->getRelation());
        static::assertSame($access, $access->removeRelation());
        static::assertEquals([], $access->getRelation());
    }

    public function testHiddenProperty()
    {
        $access = new PHPUNIT_HiddenMode([
            'foo' => 'foo',
            'bar' => 'bar',
            'biz' => 'biz',
        ]);
        $access->setRelation([
            'hello' => 'hello',
            'world' => 'world',
        ]);

        static::assertEquals(['foo', 'hello'], $access->getHidden());
        static::assertEquals(['foo', 'bar', 'biz', 'hello', 'world'], $access->allColumn());
        static::assertEquals(['bar', 'biz', 'world'], $access->ableColumn());
        static::assertEquals(['bar', 'biz'], $access->ableAttribute());
        static::assertEquals(['world'], $access->ableRelation());
        static::assertEquals([
            'bar' => 'bar',
            'biz' => 'biz',
        ], $access->toArray());

        static::assertEquals([
            'bar' => 'bar',
            'biz' => 'biz',
            'world' => 'world'
        ], $access->toArray(true));

        static::assertEquals([
            'foo' => 'foo',
            'bar' => 'bar',
            'hello' => 'hello',
            'world' => 'world',
        ], $access->getArray('foo', 'bar', 'hello', 'world'));

        static::assertEquals([
            'foo' => 'foo',
            'biz' => 'biz',
            'hello' => 'hello',
        ], $access->getArrayExcept('bar', 'world'));

        static::assertEquals([
            'foo' => 'foo',
            'biz' => 'biz',
        ], $access->getAttributeExcept('bar', 'world'));
    }

    public function testVisibleProperty()
    {
        $access = new PHPUNIT_VisibleMode([
            'foo' => 'foo',
            'bar' => 'bar',
            'biz' => 'biz',
        ]);
        $access->setRelation([
            'hello' => 'hello',
            'world' => 'world',
        ]);

        static::assertEquals(['foo', 'hello'], $access->getVisible());
        static::assertEquals(['foo', 'bar', 'biz', 'hello', 'world'], $access->allColumn());
        static::assertEquals(['foo', 'hello'], $access->ableColumn());
        static::assertEquals(['foo'], $access->ableAttribute());
        static::assertEquals(['hello'], $access->ableRelation());

        static::assertEquals([
            'foo' => 'foo',
            'hello' => 'hello',
        ], $access->toArray(true));

        static::assertEquals([
            'foo' => 'foo',
            'bar' => 'bar',
            'hello' => 'hello',
            'world' => 'world',
        ], $access->getArray('foo', 'bar', 'hello', 'world'));

        static::assertEquals([
            'foo' => 'foo',
            'biz' => 'biz',
            'hello' => 'hello',
        ], $access->getArrayExcept('bar', 'world'));

        static::assertEquals([
            'foo' => 'foo',
            'biz' => 'biz',
        ], $access->getAttributeExcept('bar', 'world'));
    }

    public function testHiddenVisibleProperty()
    {
        $access = new PHPUNIT_HiddenVisibleMode([
            'foo' => 'foo',
            'bar' => 'bar',
            'biz' => 'biz',
        ]);
        $access->setRelation([
            'hello' => 'hello',
            'world' => 'world',
        ]);

        static::assertEquals(['foo', 'world'], $access->getHidden());
        static::assertEquals(['foo', 'bar', 'hello'], $access->getVisible());
        static::assertEquals(['foo', 'bar', 'biz', 'hello', 'world'], $access->allColumn());
        static::assertEquals(['bar', 'hello'], $access->ableColumn());
        static::assertEquals(['bar'], $access->ableAttribute());
        static::assertEquals(['hello'], $access->ableRelation());

        static::assertEquals([
            'bar' => 'bar',
            'hello' => 'hello',
        ], $access->toArray(true));

        static::assertEquals([
            'foo' => 'foo',
            'bar' => 'bar',
            'hello' => 'hello',
            'world' => 'world',
        ], $access->getArray('foo', 'bar', 'hello', 'world'));

        static::assertEquals([
            'foo' => 'foo',
            'biz' => 'biz',
            'hello' => 'hello',
        ], $access->getArrayExcept('bar', 'world'));

        static::assertEquals([
            'foo' => 'foo',
            'biz' => 'biz',
        ], $access->getAttributeExcept('bar', 'world'));
    }

    public function testSetHiddenVisibleMethod()
    {
        $access = new Access();

        static::assertEquals([], $access->getHidden());
        static::assertSame($access, $access->setHidden('foo', 'bar'));
        static::assertEquals(['foo', 'bar'], $access->getHidden());
        static::assertEquals($access, $access->addHidden('hello', 'world'));
        static::assertEquals(['foo', 'bar', 'hello', 'world'], $access->getHidden());
        static::assertEquals($access, $access->setHidden(['foo', 'bar']));
        static::assertEquals(['foo', 'bar'], $access->getHidden());
        static::assertEquals($access, $access->addHidden(['hello', 'world']));
        static::assertEquals(['foo', 'bar', 'hello', 'world'], $access->getHidden());

        static::assertEquals([], $access->getVisible());
        static::assertSame($access, $access->setVisible('foo', 'bar'));
        static::assertEquals(['foo', 'bar'], $access->getVisible());
        static::assertEquals($access, $access->addVisible('hello', 'world'));
        static::assertEquals(['foo', 'bar', 'hello', 'world'], $access->getVisible());
        static::assertEquals($access, $access->setVisible(['foo', 'bar']));
        static::assertEquals(['foo', 'bar'], $access->getVisible());
        static::assertEquals($access, $access->addVisible(['hello', 'world']));
        static::assertEquals(['foo', 'bar', 'hello', 'world'], $access->getVisible());
    }
}



class PHPUNIT_CastMode extends Access
{
    const CastsArr = [
        'a' => self::INT,
        'b' => self::FLOAT,
        'c' => self::BOOL,
        'd' => self::STRING,
        'e' => self::JSON,
        'f' => self::SERIALIZE,
        'g' => self::TIMESTAMP,
        'h' => self::TIME,
        'k' => 'time(m-d)',
        'm' => 'CustomAble',
        'p' => ['CustomAble', 'start'],
        'x' => ['CustomAble', 'start', 'end'],
    ];

    protected $casts = self::CastsArr;
}

class CustomAble implements \Tanbolt\Database\Model\CastAble
{
    protected $prepend;

    protected $append;

    protected $value;

    public function __config(...$config)
    {
        $this->prepend = $config[0] ?? null;
        $this->append = $config[1] ?? null;
    }

    public function __setter($value)
    {
        $this->value = $value;
        return $this;
    }

    public function __toScalar()
    {
        return [$this->prepend, $this->value, $this->append];
    }

    public function __toString()
    {
        return join('_', $this->__toScalar());
    }
}

class PHPUNIT_AttributeModifyModel extends Access
{

    public function getFooAttribute($value)
    {
        return $value ? strtolower($value) : $value;
    }
    public function getFoo2Attribute($value)
    {
        return $value ? strtolower($value) : $value;
    }

    public function setBarAttribute($value)
    {
        $this->setAttribute('biz', strtolower($value));
    }
    public function setBar2Attribute($value)
    {
        $this->setAttribute('biz', strtolower($value));
    }
}


class PHPUNIT_HiddenMode extends Access
{
    protected $hidden = [
        'foo', 'hello'
    ];
}


class PHPUNIT_VisibleMode extends Access
{
    protected $visible = [
        'foo', 'hello'
    ];
}

class PHPUNIT_HiddenVisibleMode extends Access
{
    protected $visible = [
        'foo', 'bar', 'hello'
    ];

    protected $hidden = [
        'foo', 'world'
    ];
}


