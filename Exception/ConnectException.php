<?php
namespace Tanbolt\Database\Exception;

use PDOException;

/**
 * Class ConnectException
 * @package Tanbolt\Database\Exception
 */
class ConnectException extends PDOException implements ExceptionInterface
{

}
