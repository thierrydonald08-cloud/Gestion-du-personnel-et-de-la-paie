<?php
declare(strict_types=1);

namespace App\Paie;

/**
 * Table `fiche_paie` : génération, calcul, validation et tableaux de bord.
 * Cycle : brouillon -> verifiee -> validee -> archivee.
 *
 * Formules :
 *   salaire_brut = salaire_base + total_heures_sup
 *   salaire_net  = salaire_brut + total_primes - total_retenues
 */
final class FichePaie extends Modele
{
    private RubriquePaie $rubriques;
    private PosteRubrique $posteRubriques;
    private EmployeRubrique $employeRubriques;
    private HeureSupplementaire $heuresSup;
    private AvanceSalaire $avances;
    private LignePaie $lignes;

    public function __construct(\PDO $pdo)
    {
        parent::__construct($pdo);
        $this->rubriques        = new RubriquePaie($pdo);
        $this->posteRubriques   = new PosteRubrique($pdo);
        $this->employeRubriques = new EmployeRubrique($pdo);
        $this->heuresSup        = new HeureSupplementaire($pdo);
        $this->avances          = new AvanceSalaire($pdo);
        $this->lignes           = new LignePaie($pdo);
    }

    /** Génère la fiche (brouillon) d'un employé pour un mois. Retourne l'id de la fiche. */
    public function generer(int $employeId, int $annee, int $mois): int
    {
        if ($mois < 1 || $mois > 12) {
            throw new \InvalidArgumentException('Mois invalide.');
        }
        $debut = sprintf('%04d-%02d-01', $annee, $mois);
        $fin   = date('Y-m-t', strtotime($debut));

        $this->pdo->beginTransaction();
        try {
            if ($this->requete('SELECT 1 FROM fiche_paie WHERE employe_id = ? AND annee = ? AND mois = ?',
                               [$employeId, $annee, $mois])->fetchColumn()) {
                throw new \LogicException('Une fiche existe déjà pour cet employé et ce mois.');
            }
            $employe = $this->requete('SELECT id, poste_id FROM employe WHERE id = ?', [$employeId])->fetch();
            if (!$employe) {
                throw new \LogicException('Employé introuvable.');
            }
            $contrat = $this->requete(
                "SELECT id, salaire_base FROM contrat
                  WHERE employe_id = ? AND statut = 'en_cours'
                    AND date_debut <= ? AND (date_fin IS NULL OR date_fin >= ?)
                  ORDER BY date_debut DESC LIMIT 1",
                [$employeId, $fin, $debut]
            )->fetch();
            if (!$contrat) {
                throw new \LogicException('Aucun contrat en cours sur cette période.');
            }
            $base = (int) $contrat['salaire_base'];

            $this->requete(
                'INSERT INTO fiche_paie (employe_id, contrat_id, annee, mois, salaire_base) VALUES (?, ?, ?, ?, ?)',
                [$employeId, $contrat['id'], $annee, $mois, $base]
            );
            $ficheId = (int) $this->pdo->lastInsertId();

            // 1) Rubriques du poste, puis 2) rubriques de l'employé (elles écrasent celles du poste)
            $retenues = [];
            foreach ($this->posteRubriques->listerParPoste((int) $employe['poste_id']) as $r) {
                $retenues[$r['rubrique_id']] = $r + ['origine' => 'poste'];
            }
            foreach ($this->employeRubriques->listerPourPeriode($employeId, $debut, $fin) as $r) {
                $retenues[$r['rubrique_id']] = $r + ['origine' => 'employe'];
            }
            foreach ($retenues as $r) {
                if (!(int) $r['actif']) {
                    continue;
                }
                $pct = $r['mode_calcul'] === 'pourcentage';
                $montant = $pct ? (int) round($base * (float) $r['montant'] / 100) : (int) round((float) $r['montant']);
                $this->lignes->ajouter(
                    $ficheId, (int) $r['rubrique_id'], $r['libelle'], $r['nature'], $montant, $r['origine'],
                    $pct ? (float) $base : null, $pct ? (float) $r['montant'] : null
                );
            }

            // 3) Avances accordées -> retenue
            foreach ($this->avances->listerADeduire($employeId) as $a) {
                $this->lignes->ajouter(
                    $ficheId, null, 'Remboursement avance du ' . date('d/m/Y', strtotime($a['date_demande'])),
                    'retenue', (int) $a['montant'], 'avance'
                );
                $this->avances->rattacherAFiche((int) $a['id'], $ficheId);
            }

            // 3b) Absences non justifiées -> retenue automatique (corrigeable par la RH)
            $this->retenueAbsences($ficheId, $employeId, $base, $debut, $fin);

            // 4) Heures supplémentaires validées
            foreach ($this->heuresSup->listerAIntegrer($employeId, $debut, $fin) as $h) {
                $this->heuresSup->integrerDansFiche((int) $h['id'], $ficheId);
            }

            $this->recalculer($ficheId);
            $this->pdo->commit();
            return $ficheId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Recalcule les totaux à partir des lignes et des heures sup intégrées. */
    public function recalculer(int $id): void
    {
        $fiche = $this->trouverParId($id) ?? throw new \LogicException("Fiche #$id introuvable.");
        $t  = $this->lignes->totaux($id);
        $hs = (int) $this->requete(
            "SELECT COALESCE(SUM(montant),0) FROM heure_supplementaire
              WHERE fiche_paie_id = ? AND statut = 'integree'", [$id]
        )->fetchColumn();

        $brut = (int) $fiche['salaire_base'] + $hs;
        $net  = $brut + $t['primes'] - $t['retenues'];

        $this->requete(
            'UPDATE fiche_paie SET total_heures_sup = ?, total_primes = ?, total_retenues = ?,
                                   salaire_brut = ?, salaire_net = ? WHERE id = ?',
            [$hs, $t['primes'], $t['retenues'], $brut, $net, $id]
        );
    }

    /** Jours ouvrables du domaine C : lundi-vendredi hors jours fériés (table jour_ferie). */
    private function joursOuvrables(string $debut, string $fin): array
    {
        $feries = [];
        foreach ($this->requete('SELECT date_ferie, recurrent FROM jour_ferie')->fetchAll() as $j) {
            $feries[$j['recurrent'] ? substr($j['date_ferie'], 5) : $j['date_ferie']] = true;
        }
        $jours = [];
        for ($t = strtotime("$debut 12:00"); $t <= strtotime("$fin 12:00"); $t += 86400) {
            if ((int) date('N', $t) < 6 && !isset($feries[date('Y-m-d', $t)]) && !isset($feries[date('m-d', $t)])) {
                $jours[] = date('Y-m-d', $t);
            }
        }
        return $jours;
    }

    /** Retenue = salaire de base / jours ouvrables x nb d'absences non justifiées (jours ouvrables seulement). */
    private function retenueAbsences(int $ficheId, int $employeId, int $base, string $debut, string $fin): void
    {
        $ouvrables = $this->joursOuvrables($debut, $fin);
        $dates = $this->requete(
            'SELECT date_absence FROM absence WHERE employe_id = ? AND justifiee = 0 AND date_absence BETWEEN ? AND ?',
            [$employeId, $debut, $fin]
        )->fetchAll(\PDO::FETCH_COLUMN);
        $nb = count(array_intersect($dates, $ouvrables));
        if ($nb === 0) {
            return;
        }
        $montant = (int) round($base / count($ouvrables) * $nb);
        $this->lignes->ajouter($ficheId, null, "Absence non justifiée ($nb j)", 'retenue', $montant, 'auto',
            (float) $base, null, "$nb jour(s) sur " . count($ouvrables) . ' ouvrables');
    }

    /** Correction d'une ligne par la RH (fiche en brouillon, justification obligatoire). */
    public function corrigerLigne(int $ligneId, int $montant, string $justification): bool
    {
        $fid = (int) $this->requete('SELECT fiche_paie_id FROM ligne_paie WHERE id = ?', [$ligneId])->fetchColumn();
        $this->lignes->corrigerMontant($ligneId, $montant, $justification);
        $this->recalculer($fid);
        return true;
    }

    /** @return string[] anomalies détectées (liste vide = RAS). Seuils de 50 % = hypothèse à valider. */
    public function controlerAnomalies(int $id): array
    {
        $f = $this->trouverParId($id) ?? throw new \LogicException("Fiche #$id introuvable.");
        $a = [];
        $base = (int) $this->requete('SELECT salaire_base FROM contrat WHERE id = ?', [$f['contrat_id']])->fetchColumn();
        if ($base !== (int) $f['salaire_base']) $a[] = 'Salaire de base différent du contrat.';
        if ((int) $f['salaire_net'] <= 0) $a[] = 'Salaire net nul ou négatif.';
        if ((int) $f['total_retenues'] > (int) $f['salaire_brut'] / 2) $a[] = 'Retenues > 50 % du brut.';
        if ((int) $f['total_heures_sup'] > (int) $f['salaire_base'] / 2) $a[] = 'Heures sup > 50 % du salaire de base.';
        $t = $this->lignes->totaux($id);
        if ($t['primes'] !== (int) $f['total_primes'] || $t['retenues'] !== (int) $f['total_retenues']) $a[] = 'Totaux désynchronisés : recalcul nécessaire.';
        return $a;
    }

    public function employesSansFiche(int $annee, int $mois): array
    {
        return $this->requete(
            "SELECT e.id, e.matricule, e.nom, e.prenom FROM employe e
              WHERE e.statut = 'actif' AND NOT EXISTS
                    (SELECT 1 FROM fiche_paie f WHERE f.employe_id = e.id AND f.annee = ? AND f.mois = ?)
              ORDER BY e.nom", [$annee, $mois]
        )->fetchAll();
    }

    /** @return array<string,int> statut => nombre */
    public function compteParStatut(int $annee, int $mois): array
    {
        return $this->requete('SELECT statut, COUNT(*) FROM fiche_paie WHERE annee = ? AND mois = ? GROUP BY statut',
            [$annee, $mois])->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** Génère le PDF (prérequis : composer require dompdf/dompdf) et renvoie son chemin. */
    public function exporterPdf(int $id, string $dossier): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException('Dompdf non installé : composer require dompdf/dompdf');
        }
        $f = $this->trouverParId($id) ?? throw new \LogicException("Fiche #$id introuvable.");
        $e = $this->requete('SELECT matricule, nom, prenom FROM employe WHERE id = ?', [$f['employe_id']])->fetch();
        $n = fn($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = "<h2>Fiche de paie {$f['mois']}/{$f['annee']}</h2><p>{$h($e['nom'])} {$h($e['prenom'])} ({$h($e['matricule'])})</p>"
              . "<table width='100%' border='1' cellpadding='4' style='border-collapse:collapse'>"
              . "<tr><td>Salaire de base</td><td align='right'>{$n($f['salaire_base'])}</td></tr>"
              . "<tr><td>Heures supplémentaires</td><td align='right'>{$n($f['total_heures_sup'])}</td></tr>"
              . "<tr><td><b>Salaire brut</b></td><td align='right'><b>{$n($f['salaire_brut'])}</b></td></tr>";
        foreach ($this->lignes->listerParFiche($id) as $l) {
            $signe = $l['nature'] === 'retenue' ? '−' : '+';
            $html .= "<tr><td>{$h($l['libelle'])}</td><td align='right'>$signe {$n($l['montant'])}</td></tr>";
        }
        $html .= "<tr><td><b>Net à payer</b></td><td align='right'><b>{$n($f['salaire_net'])}</b></td></tr></table>";
        $pdf = new \Dompdf\Dompdf();
        $pdf->loadHtml($html);
        $pdf->render();
        if (!is_dir($dossier)) mkdir($dossier, 0750, true);
        $chemin = rtrim($dossier, '/') . "/fiche_{$f['employe_id']}_{$f['annee']}_{$f['mois']}.pdf";
        file_put_contents($chemin, $pdf->output());
        $this->requete('UPDATE fiche_paie SET pdf_chemin = ? WHERE id = ?', [$chemin, $id]);
        return $chemin;
    }

    /** $envoyer = fonction du Mailer (domaine A) : fn(string $email, string $sujet, string $corps, string $pieceJointe): void */
    public function envoyerParEmail(int $id, string $dossierPdf, callable $envoyer): bool
    {
        $f = $this->trouverParId($id) ?? throw new \LogicException("Fiche #$id introuvable.");
        if (!in_array($f['statut'], ['validee', 'archivee'], true)) {
            throw new \LogicException('Seule une fiche validée peut être envoyée.');
        }
        $chemin = $this->exporterPdf($id, $dossierPdf);
        $email = $this->requete('SELECT u.email FROM employe e JOIN utilisateur u ON u.id = e.utilisateur_id WHERE e.id = ?',
            [$f['employe_id']])->fetchColumn();
        $envoyer((string) $email, "Votre fiche de paie {$f['mois']}/{$f['annee']}", 'Veuillez trouver votre fiche de paie en pièce jointe.', $chemin);
        $this->requete('UPDATE fiche_paie SET envoyee_le = NOW() WHERE id = ?', [$id]);
        return true;
    }

    // ---- Cycle de vie -------------------------------------------------------

    public function verifier(int $id): bool
    {
        $this->recalculer($id);
        return $this->changerStatut($id, 'brouillon', 'verifiee');
    }

    public function valider(int $id, int $validateurId): bool
    {
        return $this->changerStatut($id, 'verifiee', 'validee', ', valide_par_id = ?, date_validation = NOW()', [$validateurId]);
    }

    public function archiver(int $id): bool
    {
        return $this->changerStatut($id, 'validee', 'archivee');
    }

    public function marquerEnvoyee(int $id, string $pdfChemin): bool
    {
        return $this->requete(
            "UPDATE fiche_paie SET pdf_chemin = ?, envoyee_le = NOW()
              WHERE id = ? AND statut IN ('validee','archivee')",
            [$pdfChemin, $id]
        )->rowCount() > 0;
    }

    private function changerStatut(int $id, string $de, string $vers, string $extraSql = '', array $extra = []): bool
    {
        return $this->requete(
            "UPDATE fiche_paie SET statut = ? $extraSql WHERE id = ? AND statut = ?",
            [$vers, ...$extra, $id, $de]
        )->rowCount() > 0;
    }

    // ---- Lecture ------------------------------------------------------------

    public function trouverParId(int $id): ?array
    {
        return $this->requete('SELECT * FROM fiche_paie WHERE id = ?', [$id])->fetch() ?: null;
    }

    public function listerParEmploye(int $employeId): array
    {
        return $this->requete(
            'SELECT * FROM fiche_paie WHERE employe_id = ? ORDER BY annee DESC, mois DESC',
            [$employeId]
        )->fetchAll();
    }

    public function listerParPeriode(int $annee, int $mois, ?string $statut = null): array
    {
        $sql = 'SELECT f.*, e.matricule, e.nom, e.prenom
                  FROM fiche_paie f JOIN employe e ON e.id = f.employe_id
                 WHERE f.annee = ? AND f.mois = ?';
        $params = [$annee, $mois];
        if ($statut !== null) {
            $sql .= ' AND f.statut = ?';
            $params[] = $statut;
        }
        return $this->requete($sql . ' ORDER BY e.nom, e.prenom', $params)->fetchAll();
    }

    // ---- Tableaux de bord ---------------------------------------------------

    /** Totaux du mois sur les fiches validées ou archivées. */
    public function masseSalariale(int $annee, int $mois): array
    {
        return $this->requete(
            "SELECT COUNT(*) AS nb_fiches,
                    COALESCE(SUM(salaire_brut),0)     AS total_brut,
                    COALESCE(SUM(total_primes),0)     AS total_primes,
                    COALESCE(SUM(total_retenues),0)   AS total_retenues,
                    COALESCE(SUM(total_heures_sup),0) AS total_heures_sup,
                    COALESCE(SUM(salaire_net),0)      AS total_net
               FROM fiche_paie
              WHERE annee = ? AND mois = ? AND statut IN ('validee','archivee')",
            [$annee, $mois]
        )->fetch();
    }

    public function masseSalarialeParDepartement(int $annee, int $mois): array
    {
        return $this->requete(
            "SELECT d.id AS departement_id, d.nom AS departement,
                    COUNT(*) AS nb_fiches, SUM(f.salaire_brut) AS total_brut, SUM(f.salaire_net) AS total_net
               FROM fiche_paie f
               JOIN employe e     ON e.id = f.employe_id
               JOIN departement d ON d.id = e.departement_id
              WHERE f.annee = ? AND f.mois = ? AND f.statut IN ('validee','archivee')
              GROUP BY d.id, d.nom ORDER BY total_brut DESC",
            [$annee, $mois]
        )->fetchAll();
    }

    /** Évolution mensuelle de la masse salariale sur une année (pour un graphique). */
    public function evolutionAnnuelle(int $annee): array
    {
        return $this->requete(
            "SELECT mois, SUM(salaire_brut) AS total_brut, SUM(salaire_net) AS total_net
               FROM fiche_paie
              WHERE annee = ? AND statut IN ('validee','archivee')
              GROUP BY mois ORDER BY mois",
            [$annee]
        )->fetchAll();
    }
}
