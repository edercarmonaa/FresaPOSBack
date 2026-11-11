<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;

abstract class ApiFormRequest extends FormRequest
{
    protected function createDefaultValidator(Factory $factory)
    {
        $validator = parent::createDefaultValidator($factory);
        if (! $validator instanceof Validator) {
            throw new \LogicException('ApiFormRequest requiere el validador nativo de Laravel.');
        }
        // Copia aislada: las traducciones de la API no alteran validación web/Livewire.
        $translator = clone $validator->getTranslator();
        if (! $translator instanceof Translator) {
            throw new \LogicException('ApiFormRequest requiere el traductor nativo de Laravel.');
        }
        $locale = $translator->getLocale();
        $native = $translator->get('validation', [], $locale);
        $api = $translator->get('core::validation', [], $locale);
        $messages = array_replace_recursive(is_array($native) ? $native : [], is_array($api) ? $api : []);
        $translator->addLines(Arr::dot(['validation' => $messages]), $locale);
        $validator->setTranslator($translator);

        return $validator;
    }
}
