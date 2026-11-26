<?php

namespace App\Support;

use App\Models\User;

/**
 * Área do sistema em que a pessoa está trabalhando: a oficina ou a locadora.
 *
 * Cada área tem menu, painel, caixa e contas a receber/pagar próprios
 * (trait SeparadoPorArea). A área fica na sessão e é definida pelo
 * middleware DefinirArea; fora de uma tela (comandos, portal do cliente)
 * não há área e nada é filtrado.
 */
class Area
{
    public const OFICINA = 'oficina';

    public const LOCADORA = 'locadora';

    public const ROTULOS = [self::OFICINA => 'Oficina', self::LOCADORA => 'Locadora'];

    private static ?string $atual = null;

    public static function usar(?string $area): void
    {
        self::$atual = $area;
    }

    public static function atual(): ?string
    {
        return self::$atual;
    }

    public static function locadora(): bool
    {
        return self::$atual === self::LOCADORA;
    }

    /** Executa um bloco em outra área (ou sem área) e restaura a anterior. */
    public static function com(?string $area, callable $fn): mixed
    {
        $anterior = self::$atual;
        self::$atual = $area;
        try {
            return $fn();
        } finally {
            self::$atual = $anterior;
        }
    }

    /** @return list<string> Áreas que o usuário pode abrir, na ordem de preferência. */
    public static function disponiveis(User $user): array
    {
        $areas = [];
        if ($user->perfil !== Perfil::Locadora) {
            $areas[] = self::OFICINA;
        }
        if ($user->pode('locacao.ver')) {
            $areas[] = self::LOCADORA;
        }

        return $areas ?: [self::OFICINA];
    }
}
