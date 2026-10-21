<?php
require __DIR__ . '/_app.php';
$u = exiger(['employe', 'chef', 'rh', 'dg'], true);
$x = $fiches->trouverParId((int) ($_GET['id'] ?? 0));
if (!$x || (int) $x['employe_id'] !== (int) $u['employe_id'] || !in_array($x['statut'], ['validee', 'archivee'])) {
    http_response_code(404); exit('Fiche introuvable.');
}
if (isset($_GET['pdf'])) {   // téléchargement du PDF
    try {
        $c = $fiches->exporterPdf((int) $x['id'], DOSSIER_PDF);
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . basename($c) . '"');
        readfile($c); exit;
    } catch (Throwable $e) { $_SESSION['flash'] = ['err', $e->getMessage()]; header('Location: ?id=' . $x['id']); exit; }
}
entete('Fiche de paie', $u); ?>
<div class="top"><h1>Fiche de paie <?= sprintf('%02d/%d', $x['mois'], $x['annee']) ?></h1>
  <span><a href="?id=<?= $x['id'] ?>&pdf=1">Télécharger le PDF</a> · <a href="fiches_paie_employe.php">← Mes fiches</a></span></div>
<div class="grid"><?php foreach (['salaire_base'=>'Salaire de base','total_heures_sup'=>'Heures sup','salaire_brut'=>'Brut','total_primes'=>'Primes','total_retenues'=>'Retenues','salaire_net'=>'Net à payer'] as $k => $l): ?>
  <div class="card kpi"><small><?= $l ?></small><strong><?= fcfa($x[$k]) ?></strong></div><?php endforeach; ?></div>
<div class="card"><table><tr><th>Libellé</th><th>Nature</th><th class="n">Montant</th></tr>
<?php foreach ($lignes->listerParFiche((int) $x['id']) as $l): ?><tr><td><?= h($l['libelle']) ?></td><td><?= h($l['nature']) ?></td>
  <td class="n"><?= $l['nature'] === 'retenue' ? '−' : '+' ?> <?= fcfa($l['montant']) ?></td></tr><?php endforeach; ?></table></div>
<?php pied();
