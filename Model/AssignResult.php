<?php
// Resultat d'una assignació: nombre de source items creats i actualitzats.
declare(strict_types=1);

namespace Nacento\Roadie\Model;

final class AssignResult
{
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0
    ) {
    }
}
