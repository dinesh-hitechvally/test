<?php

namespace App\GraphQL;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/**
 * Base for the resolver classes. Resolvers stay as thin as the controllers
 * were: validate, call a service, return.
 */
abstract class Resolver
{
    /**
     * What a resolver returns: the value exactly as the REST API serialized it
     * (model casts, date formats, appended attributes), as plain arrays.
     */
    protected function plain(mixed $value): mixed
    {
        return json_decode(json_encode($value), true);
    }

    protected function user(): User
    {
        return auth()->user();
    }

    /**
     * Validates GraphQL arguments with an existing FormRequest's rules — one
     * set of rules for both what used to be REST and what's now GraphQL.
     * Throws ValidationException (→ extensions.validation) on failure.
     *
     * @param  class-string<FormRequest>  $formRequest
     */
    protected function validated(string $formRequest, array $args): array
    {
        /** @var FormRequest $form */
        $form = $formRequest::createFrom(request(), new $formRequest);
        $form->replace($args);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->setUserResolver(fn () => auth()->user());
        $form->validateResolved();

        return $form->validated();
    }

    /** A Request carrying $args, for services that read query-style input. */
    protected function requestWith(array $args): Request
    {
        return Request::create('/', 'GET', $args);
    }
}
