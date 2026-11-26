<?php

namespace App\Models\Concerns;

use App\Support\Area;
use Illuminate\Database\Eloquent\Builder;

/**
 * Separa o registro entre oficina e locadora (coluna "area").
 *
 * Nas telas, só aparece o que é da área atual e o que for criado
 * recebe a área atual. Sem área (comandos, portal, webhooks) nada é filtrado.
 */
trait SeparadoPorArea
{
    public static function bootSeparadoPorArea(): void
    {
        static::addGlobalScope('area', function (Builder $query) {
            if ($area = Area::atual()) {
                $query->where($query->getModel()->qualifyColumn('area'), $area);
            }
        });

        static::creating(function ($model) {
            $model->area ??= Area::atual() ?? Area::OFICINA;
        });
    }

    public function daLocadora(): bool
    {
        return $this->area === Area::LOCADORA;
    }
}
