<?php
require __DIR__ . '/_app.php';
$u = exiger(['rh']);
$cnt = $fiches->compteParStatut($annee, $mois);
$nbAnomalies = 0;
foreach ($fiches->listerParPeriode($annee, $mois) as $x) if ($fiches->controlerAnomalies((int) $x['id'])) $nbAnomalies++;
$kpi = ['Brouillons' => $cnt['brouillon'] ?? 0, 'Vérifiées' => $cnt['verifiee'] ?? 0, 'Validées' => $cnt['validee'] ?? 0, 'Archivées' => $cnt['archivee'] ?? 0,
        'Employés sans fiche' => count($fiches->employesSansFiche($annee, $mois)), 'Fiches avec anomalies' => $nbAnomalies,
        'Heures sup à intégrer' => count($hs->listerARevoir()), 'Avances en attente' => count($avances->listerEnAttente())];
entete('Tableau de bord RH', $u); ?>
<div class="top"><h1>Tableau de bord RH – <?= sprintf('%02d/%d', $mois, $annee) ?></h1><?= periode($annee, $mois) ?></div><br>
<div class="grid"><?php foreach ($kpi as $l => $v): ?><div class="card kpi"><small><?= $l ?></small><strong><?= $v ?></strong></div><?php endforeach; ?></div>
<h2>Masse salariale par département (fiches validées)</h2>
<div class="card"><table><tr><th>Département</th><th class="n">Fiches</th><th class="n">Brut</th><th class="n">Net</th></tr>
<?php foreach ($fiches->masseSalarialeParDepartement($annee, $mois) as $d): ?><tr><td><?= h($d['departement']) ?></td><td class="n"><?= $d['nb_fiches'] ?></td>
  <td class="n"><?= fcfa($d['total_brut']) ?></td><td class="n"><?= fcfa($d['total_net']) ?></td></tr><?php endforeach; ?></table></div>
<?php pied();
