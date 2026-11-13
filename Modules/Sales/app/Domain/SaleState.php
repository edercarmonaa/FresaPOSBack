<?php

namespace Modules\Sales\Domain;

enum SaleState: string
{
    case Open = 'open';
    case Paused = 'paused';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Reopened = 'reopened';
}
