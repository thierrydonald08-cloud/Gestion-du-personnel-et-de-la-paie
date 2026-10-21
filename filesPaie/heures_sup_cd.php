<?php
require __DIR__ . '/_app.php';
$u = exiger(['chef']);
$liste = $hs->listerPourChef((int) $u['id']);
$mesIds = array_column($liste, 'id');
$garde = fn($p) => in_array((int) $p['id'], array_map('intval', $mesIds)) ?: throw new RuntimeException('Cet employé n\'est pas dans votre département.');
post([
    'valider' => fn($p) => $garde($p) ? 'Validée : ' . fcfa($hs->valider((int) $p['id'], (int) $u['id'], (float) $p['taux'])) : '',
    'rejeter' => fn($p) => $garde($p) && $hs->rejeter((int) $p['id'], (int) $u['id']) ? 'Heures rejetées.' : 'Déjà traitée.',
]);
entete('Heures sup – équipe', $u); ?>
<div class="top"><h1>Heures supplémentaires de mon équipe</h1><a href="heures_sup_ajout_cd.php">+ Saisir des heures</a></div>
<div class="card"><table><tr><th>Employé</th><th>Date</th><th class="n">Heures</th><th>Motif</th><th>Statut</th><th class="n">Montant</th><th></th></tr>
<?php foreach ($liste as $x): ?><tr><td><?= h($x['nom'] . ' ' . $x['prenom']) ?></td><td><?= h($x['date_realisation']) ?></td><td class="n"><?= h($x['nb_heures']) ?></td>
  <td><?= h($x['motif']) ?></td><td><?= badge($x['statut']) ?></td><td class="n"><?= $x['montant'] !== null ? fcfa($x['montant']) : '—' ?></td>
  <td><?php if ($x['statut'] === 'saisie'): ?><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= $x['id'] ?>">
    <input type="number" name="taux" step="0.5" value="<?= \App\Paie\HeureSupplementaire::MAJORATION_DEFAUT ?>" style="width:70px" title="Majoration %">
    <button name="action" value="valider">Valider</button><button name="action" value="rejeter" class="red">Rejeter</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$liste): ?><tr><td colspan="7" class="muted">Aucune saisie.</td></tr><?php endif; ?></table></div>
<?php pied();
