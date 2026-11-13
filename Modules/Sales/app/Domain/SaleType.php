<?php

namespace Modules\Sales\Domain;

enum SaleType: string
{
    case Counter = 'counter';
    case Table = 'table';
}
