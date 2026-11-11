<?php

return [
    'required' => 'El campo :attribute es obligatorio.',
    'array' => 'El campo :attribute debe ser un arreglo.',
    'string' => 'El campo :attribute debe ser texto.',
    'integer' => 'El campo :attribute debe ser un entero.',
    'numeric' => 'El campo :attribute debe ser numérico.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'ulid' => 'El campo :attribute debe ser un ULID válido.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'in' => 'El valor de :attribute no es válido.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'min' => [
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El archivo :attribute debe tener al menos :min kilobytes.',
    ],
    'max' => [
        'string' => 'El campo :attribute no debe superar :max caracteres.',
        'numeric' => 'El campo :attribute no debe superar :max.',
        'array' => 'El campo :attribute no debe superar :max elementos.',
        'file' => 'El archivo :attribute no debe superar :max kilobytes.',
    ],
];
