<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\Gate as AccessGate;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Users\Infrastructure\Persistence\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthPresentationTest extends TestCase
{
    public static function permissions(): array
    {
        return ['without permissions' => [false], 'with permissions' => [true]];
    }

    #[DataProvider('permissions')]
    public function test_forms_keep_the_legacy_security_contract(bool $allowed): void
    {
        Gate::swap(new AccessGate($this->app, fn () => new User));
        Gate::before(fn () => $allowed);
        foreach (['login', 'password', 'account', 'admin', 'livewire-login', 'livewire-sessions'] as $name) {
            $old = file_get_contents(base_path('tests/Fixtures/UI04/'.$name.'.blade.php'));
            $new = file_get_contents(base_path('Modules/Auth/resources/views/'.$name.'.blade.php'));
            // Child components are compared separately, without SQL or an authenticated session.
            $render = fn (string $source) => Blade::render(str_replace(['<livewire:auth.login />', '<livewire:auth.sessions />'], '', $source), $this->fixtureData());
            $this->assertSame($this->contracts($render($old)), $this->contracts($render($new)), $name);
        }
    }

    public function test_login_errors_are_associated_and_escaped(): void
    {
        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['username' => '<script>invalid()</script>']));
        $html = view('auth::livewire-login', ['errors' => $errors])->render();
        $this->assertStringContainsString('aria-describedby="ui-login-username-error"', $html);
        $this->assertStringContainsString('id="ui-login-username-error" role="alert"', $html);
        $this->assertStringContainsString('&lt;script&gt;invalid()&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>invalid()', $html);
        $dom = $this->dom($html);
        $this->assertSame('true', $dom->query('//input[@name="username"]')->item(0)->getAttribute('aria-invalid'));
        $this->assertSame('false', $dom->query('//input[@name="password"]')->item(0)->getAttribute('aria-invalid'));
        $this->assertSame(0, $dom->query('//input[@type="password"]/@value')->length);
    }

    public function test_session_table_has_accessible_scrolling_headers_and_empty_state(): void
    {
        Gate::swap(new AccessGate($this->app, fn () => new User));
        Gate::before(fn () => false);
        $data = $this->fixtureData();
        $data['sessions'] = [];
        $data['status'] = '<script>status()</script>';
        $html = view('auth::livewire-sessions', $data)->render();
        $dom = $this->dom($html);
        $this->assertSame(6, $dom->query('//th[@scope="col"]')->length);
        $this->assertSame(1, $dom->query('//div[@role="region"][@tabindex="0"]//caption')->length);
        $this->assertStringContainsString('No hay accesos para mostrar.', $html);
        $this->assertStringContainsString('&lt;script&gt;status()&lt;/script&gt;', $html);
        $this->assertStringContainsString('wire:confirm="¿Cerrar tus demás accesos?"', $html);
        $this->assertStringContainsString('wire:confirm="¿Revocar todos los accesos del usuario consultado? Deberá autenticarse nuevamente."', $html);
    }

    private function fixtureData(): array
    {
        return [
            'identity' => (object) ['name' => 'UI fixture', 'username' => 'ui_fixture', 'roles' => ['cashier']],
            'status' => '', 'errors' => new ViewErrorBag,
            'sessions' => array_map(fn (string $state) => [
                'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'channel' => 'web', 'client_name' => 'UI fixture',
                'created_at' => '2026-11-26 12:00:00', 'last_activity_at' => '2026-11-26 12:05:00',
                'expires_at' => '2026-11-26 20:00:00', 'state' => $state,
            ], ['active', 'expired', 'revoked']),
        ];
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function contracts(string $html): array
    {
        $result = [];
        foreach ($this->dom($html)->query('//form | //input | //button | //main//a') as $node) {
            $attributes = [];
            foreach ($node->attributes as $attribute) {
                if (in_array($attribute->name, ['href', 'name', 'action', 'method', 'value', 'autocomplete', 'inputmode', 'pattern', 'maxlength', 'required', 'disabled'], true) || str_starts_with($attribute->name, 'wire:')) {
                    $attributes[$attribute->name] = $attribute->value;
                }
            }
            if (in_array($node->nodeName, ['button', 'input'], true)) {
                $attributes['type'] = $node->getAttribute('type') ?: ($node->nodeName === 'input' ? 'text' : ($node->hasAttribute('wire:click') ? 'button' : 'submit'));
            }
            if ($node->nodeName === 'a') {
                $attributes['text'] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            }
            ksort($attributes);
            $result[] = [$node->nodeName, $attributes];
        }

        return $result;
    }
}
