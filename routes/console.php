<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:compact')->hourly()->withoutOverlapping();
