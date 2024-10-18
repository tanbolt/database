<?php
namespace Tanbolt\Database\Exception;

use PDOException;

/**
 * Class ModelNotFoundException
 * @package Tanbolt\Database\Exception
 */
class ModelNotFoundException extends PDOException implements ExceptionInterface
{
    /**
     * @var mixed
     */
    protected $model;

    /**
     * @param $model
     * @return $this
     */
    public function setModel($model)
    {
        $this->model = $model;
        return $this;
    }

    /**
     * @return mixed
     */
    public function model()
    {
        return $this->model;
    }
}
