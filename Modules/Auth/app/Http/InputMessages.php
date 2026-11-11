<?php

namespace Modules\Auth\Http;

final class InputMessages
{
    /** @return array<string,string> */
    public static function get(): array
    {
        $messages = trans('auth::validation');
        if (! is_array($messages)) {
            throw new \LogicException('Faltan las traducciones de validación de Auth.');
        }

        return $messages;
    }
}
