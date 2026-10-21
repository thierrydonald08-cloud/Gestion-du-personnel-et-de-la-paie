<?php
require __DIR__ . '/_app.php';
$u = exiger(['dg']);
$m = $fiches->masseSalariale($annee, $mois);
entete('Dashboard DG', $u); ?>
<div class="top"><h1>Dashboard global – <?= sprintf('%02d/%d', $mois, $annee) ?></h1><?= periode($annee, $mois) ?></div><br>
<div class="grid"><?php foreach (['nb_fiches'=>'Fiches validées','total_brut'=>'Masse brute','total_primes'=>'Primes','total_heures_sup'=>'Heures sup','total_retenues'=>'Retenues','total_net'=>'Net à payer'] as $k => $l): ?>
  <div class="card kpi"><small><?= $l ?></small><strong><?= $k === 'nb_fiches' ? (int) $m[$k] : fcfa($m[$k]) ?></strong></div><?php endforeach; ?></div>
<h2>Par département</h2>
<div class="card"><table><tr><th>Département</th><th class="n">Fiches</th><th class="n">Brut</th><th class="n">Net</th></tr>
<?php foreach ($fiches->masseSalarialeParDepartement($annee, $mois) as $d): ?><tr><td><?= h($d['departement']) ?></td><td class="n"><?= $d['nb_fiches'] ?></td>
  <td class="n"><?= fcfa($d['total_brut']) ?></td><td class="n"><?= fcfa($d['total_net']) ?></td></tr><?php endforeach; ?></table></div>
<h2>Évolution <?= $annee ?></h2>
<div class="card"><table><tr><th>Mois</th><th class="n">Brut</th><th class="n">Net</th></tr>
<?php foreach ($fiches->evolutionAnnuelle($annee) as $r): ?><tr><td><?= sprintf('%02d', $r['mois']) ?></td><td class="n"><?= fcfa($r['total_brut']) ?></td><td class="n"><?= fcfa($r['total_net']) ?></td></tr><?php endforeach; ?></table></div>
<?php pied();
