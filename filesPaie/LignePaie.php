<?php
declare(strict_types=1);

namespace App\Paie;

/** Table `ligne_paie` : détail (primes / retenues) d'une fiche. Modifiable uniquement en brouillon. */
final class LignePaie extends Modele
{
    public const ORIGINES = ['poste', 'employe', 'exceptionnelle', 'auto', 'avance'];

    public function ajouter(int $fichePaieId, ?int $rubriqueId, string $libelle, string $nature,
                            int $montant, string $origine, ?float $baseCalcul = null,
                            ?float $taux = null, ?string $justification = null): int
    {
        $this->verifierEnum($nature, RubriquePaie::NATURES, 'nature');
        $this->verifierEnum($origine, self::ORIGINES, 'origine');
        if ($origine === 'exceptionnelle' && !$justification) {
            throw new \InvalidArgumentException('Une ligne exceptionnelle exige une justification.');
        }
        $this->exigerBrouillon($fichePaieId);

        $this->requete(
            'INSERT INTO ligne_paie
                (fiche_paie_id, rubrique_id, libelle, nature, base_calcul, taux, montant, origine, justification)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$fichePaieId, $rubriqueId, $libelle, $nature, $baseCalcul, $taux, $montant, $origine, $justification]
        );
        return (int) $this->pdo->lastInsertId();
    }

    public function supprimer(int $id): bool
    {
        $ligne = $this->requete('SELECT fiche_paie_id FROM ligne_paie WHERE id = ?', [$id])->fetch();
        if (!$ligne) {
            return false;
        }
        $this->exigerBrouillon((int) $ligne['fiche_paie_id']);
        return $this->requete('DELETE FROM ligne_paie WHERE id = ?', [$id])->rowCount() > 0;
    }

    public function listerParFiche(int $fichePaieId): array
    {
        return $this->requete(
            'SELECT * FROM ligne_paie WHERE fiche_paie_id = ? ORDER BY nature DESC, id',
            [$fichePaieId]
        )->fetchAll();
    }

    /** @return array{primes:int, retenues:int} */
    public function totaux(int $fichePaieId): array
    {
        $r = $this->requete(
            "SELECT COALESCE(SUM(CASE WHEN nature='prime'   THEN montant END),0) AS primes,
                    COALESCE(SUM(CASE WHEN nature='retenue' THEN montant END),0) AS retenues
               FROM ligne_paie WHERE fiche_paie_id = ?",
            [$fichePaieId]
        )->fetch();
        return ['primes' => (int) $r['primes'], 'retenues' => (int) $r['retenues']];
    }

    /** Correction du montant d'une ligne (fiche en brouillon). Passer par FichePaie::corrigerLigne pour recalculer. */
    public function corrigerMontant(int $id, int $montant, string $justification): bool
    {
        if ($montant < 0 || trim($justification) === '') {
            throw new \InvalidArgumentException('Montant >= 0 et justification obligatoires.');
        }
        $l = $this->requete('SELECT fiche_paie_id FROM ligne_paie WHERE id = ?', [$id])->fetch();
        if (!$l) {
            return false;
        }
        $this->exigerBrouillon((int) $l['fiche_paie_id']);
        return $this->requete('UPDATE ligne_paie SET montant = ?, justification = ? WHERE id = ?',
            [$montant, $justification, $id])->rowCount() > 0;
    }

    private function exigerBrouillon(int $fichePaieId): void
    {
        $statut = $this->requete('SELECT statut FROM fiche_paie WHERE id = ?', [$fichePaieId])->fetchColumn();
        if ($statut !== 'brouillon') {
            throw new \LogicException('La fiche doit être en brouillon pour modifier ses lignes.');
        }
    }
}
