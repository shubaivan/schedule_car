<?php

namespace App\Warehouse\Exception;

use RuntimeException;

/** Помилка, яку можна показати людині як є: текст пишемо саме для неї. */
class WarehouseException extends RuntimeException
{
}
