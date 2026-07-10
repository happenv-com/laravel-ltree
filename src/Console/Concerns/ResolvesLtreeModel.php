<?php

declare(strict_types=1);

namespace Happenv\Ltree\Console\Concerns;

use Happenv\Ltree\Exceptions\LtreeException;
use Illuminate\Database\Eloquent\Model;
use Throwable;

trait ResolvesLtreeModel
{
    /** Resolve the {model} argument to an HasLtree model instance, or throw. */
    protected function resolveLtreeModel(string $class): Model
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new LtreeException("[{$class}] is not an Eloquent model.");
        }

        try {
            $model = new $class;
        } catch (Throwable $exception) {
            // A model with a required constructor throws ArgumentCountError, not
            // LtreeException — surface it as a clean command failure, not a trace.
            throw new LtreeException("[{$class}] could not be instantiated: {$exception->getMessage()}", $exception->getCode(), $exception);
        }

        if (! method_exists($model, 'getLtreePathColumn')) {
            throw new LtreeException("[{$class}] does not use the HasLtree trait.");
        }

        return $model;
    }
}
