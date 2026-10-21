<?php
require __DIR__ . '/_app.php';
$u = exiger(['employe', 'chef', 'rh', 'dg'], true);
$liste = array_filter($fiches->listerParEmploye((int) $u['employe_id']), fn($x) => in_array($x['statut'], ['validee', 'archivee']));
entete('Mes fiches de paie', $u); ?>
<h1>Mes fiches de paie</h1>
<div class="card"><table><tr><th>Période</th><th class="n">Brut</th><th class="n">Net</th><th></th></tr>
<?php foreach ($liste as $x): ?><tr><td><?= sprintf('%02d/%d', $x['mois'], $x['annee']) ?></td><td class="n"><?= fcfa($x['salaire_brut']) ?></td>
  <td class="n"><?= fcfa($x['salaire_net']) ?></td><td><a href="fiche_paie_detail_employe.php?id=<?= $x['id'] ?>">Détail</a></td></tr><?php endforeach; ?>
<?php if (!$liste): ?><tr><td colspan="4" class="muted">Aucune fiche disponible.</td></tr><?php endif; ?></table></div>
<?php pied();
