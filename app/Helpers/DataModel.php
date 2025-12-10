<?php

namespace App\Helpers;

trait DataModel
{
    use \Zerotoprod\DataModel\DataModel;

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}