<?php
declare(strict_types=1);

namespace App\Paie;

/**
 * Table `heure_supplementaire`.
 * Cycle : saisie -> validee_chef -> integree (ou rejetee).
 */
final class HeureSupplementaire extends Modele
{
    /** Majoration par défaut en % (ex. 25 = +25 %). À remplacer par un ParametreSysteme si votre équipe en définit un. */
    public const MAJORATION_DEFAUT = 25.0;

    public function saisir(int $employeId, string $dateRealisation, float $nbHeures, ?string $motif = null): int
    {
        if ($nbHeures <= 0) {
            throw new \InvalidArgumentException('nb_heures doit être > 0.');
        }
        $this->requete(
            'INSERT INTO heure_supplementaire (employe_id, date_realisation, nb_heures, motif)
             VALUES (?, ?, ?, ?)',
            [$employeId, $dateRealisation, $nbHeures, $motif]
        );
        return (int) $this->pdo->lastInsertId();
    }

    /** Valide la saisie et calcule le montant : nb_heures x taux horaire x (1 + majoration/100). */
    public function valider(int $id, int $validateurId, float $tauxMajoration = self::MAJORATION_DEFAUT): int
    {
        $hs = $this->trouverParId($id);
        if (!$hs || $hs['statut'] !== 'saisie') {
            throw new \LogicException("Heure supplémentaire #$id introuvable ou déjà traitée.");
        }
        $contrat = $this->requete(
            "SELECT salaire_base, heures_mensuelles FROM contrat
              WHERE employe_id = ? AND statut = 'en_cours'
              ORDER BY date_debut DESC LIMIT 1",
            [$hs['employe_id']]
        )->fetch();
        if (!$contrat) {
            throw new \LogicException('Aucun contrat en cours pour cet employé.');
        }
        $tauxHoraire = (float) $contrat['salaire_base'] / (float) $contrat['heures_mensuelles'];
        $montant = (int) round((float) $hs['nb_heures'] * $tauxHoraire * (1 + $tauxMajoration / 100));

        $this->requete(
            "UPDATE heure_supplementaire
                SET statut = 'validee_chef', taux_majoration = ?, montant = ?,
                    valide_par_id = ?, date_validation = NOW()
              WHERE id = ?",
            [$tauxMajoration, $montant, $validateurId, $id]
        );
        return $montant;
    }

    public function rejeter(int $id, int $validateurId): bool
    {
        return $this->requete(
            "UPDATE heure_supplementaire
                SET statut = 'rejetee', valide_par_id = ?, date_validation = NOW()
              WHERE id = ? AND statut = 'saisie'",
            [$validateurId, $id]
        )->rowCount() > 0;
    }

    public function trouverParId(int $id): ?array
    {
        return $this->requete('SELECT * FROM heure_supplementaire WHERE id = ?', [$id])->fetch() ?: null;
    }

    public function listerParEmploye(int $employeId, ?string $statut = null): array
    {
        $sql = 'SELECT * FROM heure_supplementaire WHERE employe_id = ?';
        $params = [$employeId];
        if ($statut !== null) {
            $sql .= ' AND statut = ?';
            $params[] = $statut;
        }
        return $this->requete($sql . ' ORDER BY date_realisation DESC', $params)->fetchAll();
    }

    /** Heures validées par le chef, pas encore intégrées, réalisées dans la période. */
    public function listerAIntegrer(int $employeId, string $debut, string $fin): array
    {
        return $this->requete(
            "SELECT * FROM heure_supplementaire
              WHERE employe_id = ? AND statut = 'validee_chef' AND fiche_paie_id IS NULL
                AND date_realisation BETWEEN ? AND ?",
            [$employeId, $debut, $fin]
        )->fetchAll();
    }

    /** Employés des départements dirigés par cet utilisateur (chef de département). */
    public function employesDuChef(int $utilisateurId): array
    {
        return $this->requete(
            "SELECT e.id, e.matricule, e.nom, e.prenom
               FROM employe e
               JOIN departement d ON d.id = e.departement_id
               JOIN employe c ON c.id = d.chef_id
              WHERE c.utilisateur_id = ? AND e.statut = 'actif' ORDER BY e.nom", [$utilisateurId]
        )->fetchAll();
    }

    public function listerPourChef(int $utilisateurId): array
    {
        return $this->requete(
            "SELECT h.*, e.nom, e.prenom
               FROM heure_supplementaire h
               JOIN employe e ON e.id = h.employe_id
               JOIN departement d ON d.id = e.departement_id
               JOIN employe c ON c.id = d.chef_id
              WHERE c.utilisateur_id = ?
              ORDER BY h.statut = 'saisie' DESC, h.date_realisation DESC LIMIT 100", [$utilisateurId]
        )->fetchAll();
    }

    /** Pour la RH : heures validées par les chefs, qui seront intégrées à la prochaine fiche. */
    public function listerARevoir(): array
    {
        return $this->requete(
            "SELECT h.*, e.nom, e.prenom FROM heure_supplementaire h
               JOIN employe e ON e.id = h.employe_id
              WHERE h.statut = 'validee_chef' AND h.fiche_paie_id IS NULL ORDER BY h.date_realisation"
        )->fetchAll();
    }

    public function integrerDansFiche(int $id, int $fichePaieId): bool
    {
        return $this->requete(
            "UPDATE heure_supplementaire SET statut = 'integree', fiche_paie_id = ?
              WHERE id = ? AND statut = 'validee_chef'",
            [$fichePaieId, $id]
        )->rowCount() > 0;
    }
}
