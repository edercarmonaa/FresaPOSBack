<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Core\Contracts\ApiResponder;

Route::get('ping', fn (Request $request, ApiResponder $responses) => $responses->success($request, ['status' => 'ok']))->name('ping');
