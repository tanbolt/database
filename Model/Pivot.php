<?php
namespace Tanbolt\Database\Model;

use Tanbolt\Database\Model;

class Pivot extends Model
{
    /**
     * @var string
     */
    private $relationKey;

    /**
     * @var string
     */
    private $parentKey;

    /**
     * @param string $relationKey
     * @param string $parentKey
     * @return $this
     */
    public function setPivotKeys(string $relationKey, string $parentKey)
    {
        $this->relationKey = $relationKey;
        $this->parentKey = $parentKey;
        return $this;
    }

    /**
     * @return string
     */
    public function relationKey()
    {
        return $this->relationKey;
    }

    /**
     * @return string
     */
    public function parentKey()
    {
        return $this->parentKey;
    }
}
