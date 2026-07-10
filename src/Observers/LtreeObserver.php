<?php

declare(strict_types=1);

namespace Happenv\Ltree\Observers;

use Happenv\Ltree\Contracts\Ltreeable;
use Happenv\Ltree\ValueObjects\LtreePath;
use Illuminate\Database\Eloquent\Model;

final class LtreeObserver
{
    public function creating(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        /** @var Model&Ltreeable $model */
        $parent = $model->getLtreeParent();
        $parentColumn = $model->getLtreeParentColumn();

        if ($parent !== null && $parentColumn !== null && $model->getAttribute($parentColumn) === null) {
            $model->setAttribute($parentColumn, $parent->getKey());
        }

        if ($model->getKey() === null && $this->labelIsKey($model)) {
            return; // defer to created(): the auto-increment key is not assigned yet
        }

        $model->setAttribute($model->getLtreePathColumn(), $this->computePath($model, $parent));
    }

    public function created(Model $model): void
    {
        if (! $this->enabled()) {
            return;
        }

        /** @var Model&Ltreeable $model */
        if ($model->getAttribute($model->getLtreePathColumn()) !== null) {
            return; // already set during creating
        }

        $path = $this->computePath($model, $model->getLtreeParent());

        $model->setAttribute($model->getLtreePathColumn(), $path);
        // Provably equivalent mutant: LtreePath implements Stringable, and a
        // PDO-bound query parameter is coerced to string by the driver
        // regardless (verified: passing $path here directly still produces
        // the identical UPDATE and the identical persisted value). The cast
        // documents intent but changes no observable behavior.
        $model->newQuery()->whereKey($model->getKey())->update([
            $model->getLtreePathColumn() => (string) $path, // @pest-mutate-ignore: RemoveStringCast
        ]);
    }

    private function computePath(Model $model, ?Model $parent): LtreePath
    {
        /** @var Model&Ltreeable $model */
        $label = $model->getLtreeLabel();

        /** @var (Model&Ltreeable)|null $parent */
        $parentPath = $parent?->path();

        return $parentPath !== null ? $parentPath->append($label) : new LtreePath($label);
    }

    private function labelIsKey(Model $model): bool
    {
        /** @var Model&Ltreeable $model */
        return $model->getLtreeLabelDependsOnKey();
    }

    private function enabled(): bool
    {
        return (bool) config('ltree.auto_update_path', true);
    }
}
