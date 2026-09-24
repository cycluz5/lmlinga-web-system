<?php

namespace App\Support\Offline;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Runs an existing FormRequest against an inner offline payload array.
 */
final class OfflineInnerRequestValidator
{
    /**
     * @param  class-string<FormRequest>  $formRequestClass
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>
     */
    public static function validate(string $formRequestClass, array $input, array $routeParameters = []): array
    {
        return self::request($formRequestClass, $input, $routeParameters)->validated();
    }

    /**
     * @param  class-string<TFormRequest>  $formRequestClass
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $routeParameters
     * @return TFormRequest
     *
     * @template TFormRequest of FormRequest
     */
    public static function request(string $formRequestClass, array $input, array $routeParameters = []): FormRequest
    {
        /** @var TFormRequest $formRequest */
        $formRequest = $formRequestClass::create('/', 'POST', $input);
        $formRequest->headers->set('Accept', 'application/json');
        $formRequest->replace($input);
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->setUserResolver(static fn () => Auth::user());

        if ($routeParameters !== []) {
            $segments = [];
            foreach (array_keys($routeParameters) as $key) {
                $segments[] = '{'.$key.'}';
            }
            $route = new Route(['POST'], '/'.implode('/', $segments), static fn () => null);
            $route->bind($formRequest);
            foreach ($routeParameters as $key => $value) {
                $route->setParameter((string) $key, $value);
            }
            $formRequest->setRouteResolver(static fn () => $route);
        }

        try {
            $formRequest->validateResolved();
        } catch (AuthorizationException $e) {
            $message = trim($e->getMessage());
            if ($message === '' || strcasecmp($message, 'This action is unauthorized.') === 0) {
                $message = 'This action is unauthorized.';
            }

            throw OfflineSyncException::validationFailed([
                '_authorization' => [$message],
            ], $message);
        } catch (HttpResponseException $e) {
            $decoded = json_decode((string) $e->getResponse()->getContent(), true);
            $errors = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
            $message = is_string($decoded['message'] ?? null) ? $decoded['message'] : null;

            throw OfflineSyncException::validationFailed($errors, $message);
        } catch (ValidationException $e) {
            throw OfflineSyncException::validationFailed($e->errors(), $e->getMessage());
        }

        return $formRequest;
    }
}
