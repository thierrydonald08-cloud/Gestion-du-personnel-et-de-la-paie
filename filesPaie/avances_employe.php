<?php
require __DIR__ . '/_app.php';
$u = exiger(['employe', 'chef', 'rh', 'dg'], true);
post(['demander' => fn($p) => 'Demande #' . $avances->demander((int) $u['employe_id'], (int) $p['montant'], trim($p['motif']) ?: null) . ' envoyée à la RH.']);
entete('Mes avances', $u); ?>
<h1>Mes avances sur salaire</h1>
<div class="card"><?= f('demander', '<input type="number" name="montant" min="1" placeholder="Montant (FCFA)" required><input name="motif" placeholder="Motif"><button>Demander une avance</button>') ?></div>
<div class="card"><table><tr><th>Date</th><th class="n">Montant</th><th>Motif</th><th>Statut</th><th>Déduite sur fiche</th></tr>
<?php foreach ($avances->listerParEmploye((int) $u['employe_id']) as $a): ?><tr><td><?= h(substr($a['date_demande'], 0, 10)) ?></td><td class="n"><?= fcfa($a['montant']) ?></td>
  <td><?= h($a['motif']) ?></td><td><?= badge($a['statut']) ?></td><td><?= $a['fiche_paie_id'] ? 'Oui' : '—' ?></td></tr><?php endforeach; ?></table></div>
<?php pied();
