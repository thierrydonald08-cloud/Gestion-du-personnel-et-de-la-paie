<?php
require __DIR__ . '/_app.php';
$u = exiger(['rh']);
$uid = (int) $u['id'];
post([
    'generer' => fn($p) => 'Fiche #' . $fiches->generer((int) $p['employe_id'], $annee, $mois) . ' générée.',
    'generer_tous' => function () use ($fiches, $annee, $mois) {
        $n = 0;
        foreach ($fiches->employesSansFiche($annee, $mois) as $e) { try { $fiches->generer((int) $e['id'], $annee, $mois); $n++; } catch (Throwable) {} }
        return "$n fiche(s) générée(s) (employés sans contrat ignorés).";
    },
    'statut' => fn($p) => match ($p['etape']) {
        'verifier' => $fiches->verifier((int) $p['id']), 'valider' => $fiches->valider((int) $p['id'], $uid),
        'archiver' => $fiches->archiver((int) $p['id']), default => false } ? 'Statut mis à jour.' : 'Changement impossible.',
    'email'   => fn($p) => $fiches->envoyerParEmail((int) $p['id'], DOSSIER_PDF, $envoyerMail) ? 'Fiche envoyée par e-mail.' : '',
    'corriger' => fn($p) => $fiches->corrigerLigne((int) $p['id'], (int) $p['montant'], trim($p['justification'])) ? 'Ligne corrigée, totaux recalculés.' : '',
    'rubrique_creer' => fn($p) => 'Rubrique #' . $rub->creer(trim($p['code']), trim($p['libelle']), $p['nature'], $p['mode_calcul'], (float) $p['valeur']) . ' créée.',
    'rubrique_actif' => fn($p) => $rub->changerActif((int) $p['id'], (bool) (int) $p['actif']) ? 'Rubrique mise à jour.' : 'Aucun changement.',
    'poste_rub' => fn($p) => ok($posteRub->definir((int) $p['poste_id'], (int) $p['rubrique_id'], (float) $p['montant']), 'Rubrique rattachée au poste.'),
    'emp_rub'   => fn($p) => 'Rubrique attribuée (#' . $empRub->attribuer((int) $p['employe_id'], (int) $p['rubrique_id'], (float) $p['montant'], $p['debut'], $p['fin'] ?: null) . ').',
    'avance_accorder' => fn($p) => $avances->accorder((int) $p['id'], $uid) ? 'Avance accordée.' : 'Déjà traitée.',
    'avance_refuser'  => fn($p) => $avances->refuser((int) $p['id'], $uid) ? 'Avance refusée.' : 'Déjà traitée.',
]);
$opt = fn(array $rows, string $label) => implode('', array_map(fn($r) => '<option value="' . $r['id'] . '">' . h(sprintf($label, ...array_slice($r, 1))) . '</option>', $rows));
$optE = $opt($pdo->query("SELECT id, nom, prenom, matricule FROM employe WHERE statut='actif' ORDER BY nom")->fetchAll(), '%s %s (%s)');
$optP = $opt($pdo->query('SELECT id, intitule FROM poste ORDER BY intitule')->fetchAll(), '%s');
$optR = $opt($rub->lister(), '%s');
$sel = isset($_GET['fiche']) ? $fiches->trouverParId((int) $_GET['fiche']) : null;
$suite = ['brouillon' => ['verifier', 'Vérifier'], 'verifiee' => ['valider', 'Valider'], 'validee' => ['archiver', 'Archiver']];
entete('Paie – RH', $u); ?>
<div class="top"><h1>Gestion de la paie – <?= sprintf('%02d/%d', $mois, $annee) ?></h1><?= periode($annee, $mois) ?></div>

<h2>1. Génération des fiches</h2>
<div class="card"><?= f('generer', '<select name="employe_id" required>' . $optE . '</select><button>Générer pour cet employé</button>') ?>
  <div style="margin-top:8px"><?= f('generer_tous', '<button class="sec">Générer pour tous les employés sans fiche</button>') ?></div></div>

<h2>2. Contrôle, validation et envoi</h2>
<div class="card"><table><tr><th>Employé</th><th class="n">Brut</th><th class="n">Net</th><th>Statut</th><th>Anomalies</th><th></th></tr>
<?php foreach ($fiches->listerParPeriode($annee, $mois) as $x): $an = $fiches->controlerAnomalies((int) $x['id']); ?><tr>
  <td><?= h($x['nom'] . ' ' . $x['prenom']) ?></td><td class="n"><?= fcfa($x['salaire_brut']) ?></td><td class="n"><?= fcfa($x['salaire_net']) ?></td><td><?= badge($x['statut']) ?></td>
  <td class="warn"><?= $an ? '⚠ ' . h(implode(' ', $an)) : '<span class="muted">RAS</span>' ?></td>
  <td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= $x['id'] ?>">
    <a href="?fiche=<?= $x['id'] ?>&annee=<?= $annee ?>&mois=<?= $mois ?>">Lignes</a>
    <?php if (isset($suite[$x['statut']])): ?><input type="hidden" name="etape" value="<?= $suite[$x['statut']][0] ?>"><button name="action" value="statut"><?= $suite[$x['statut']][1] ?></button><?php endif; ?>
    <?php if (in_array($x['statut'], ['validee', 'archivee'])): ?><button name="action" value="email" class="sec">Envoyer par e-mail</button><?= $x['envoyee_le'] ? '✓' : '' ?><?php endif; ?></form></td></tr><?php endforeach; ?></table></div>

<?php if ($sel): ?><h2>Lignes de la fiche #<?= $sel['id'] ?> <?= badge($sel['statut']) ?></h2>
<div class="card"><table><tr><th>Libellé</th><th>Origine</th><th class="n">Montant</th><th>Correction (brouillon, justification obligatoire)</th></tr>
<?php foreach ($lignes->listerParFiche((int) $sel['id']) as $l): ?><tr><td><?= h($l['libelle']) ?> <small>(<?= h($l['nature']) ?>)</small></td><td><?= h($l['origine']) ?></td><td class="n"><?= fcfa($l['montant']) ?></td>
  <td><?php if ($sel['statut'] === 'brouillon'): ?><?= f('corriger', '<input type="hidden" name="id" value="' . $l['id'] . '"><input type="number" name="montant" min="0" value="' . (int) $l['montant'] . '" style="width:110px"><input name="justification" placeholder="Justification" required><button class="sec">Corriger</button>', 'inline') ?><?php endif; ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>

<h2>3. Heures sup validées par les chefs (intégrées à la génération)</h2>
<div class="card"><table><tr><th>Employé</th><th>Date</th><th class="n">Heures</th><th class="n">Montant</th></tr>
<?php foreach ($hs->listerARevoir() as $x): ?><tr><td><?= h($x['nom'] . ' ' . $x['prenom']) ?></td><td><?= h($x['date_realisation']) ?></td><td class="n"><?= h($x['nb_heures']) ?></td><td class="n"><?= fcfa($x['montant']) ?></td></tr><?php endforeach; ?></table></div>

<h2>4. Avances à décider</h2>
<div class="card"><table><tr><th>Employé</th><th class="n">Montant</th><th>Motif</th><th></th></tr>
<?php foreach ($avances->listerEnAttente() as $a): ?><tr><td><?= h($a['nom'] . ' ' . $a['prenom']) ?></td><td class="n"><?= fcfa($a['montant']) ?></td><td><?= h($a['motif']) ?></td>
  <td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>"><input type="hidden" name="id" value="<?= $a['id'] ?>">
    <button name="action" value="avance_accorder">Accorder</button><button name="action" value="avance_refuser" class="red">Refuser</button></form></td></tr><?php endforeach; ?></table></div>

<h2>5. Rubriques (primes et retenues)</h2>
<div class="card"><?= f('rubrique_creer', '<input name="code" placeholder="Code" required style="width:100px"><input name="libelle" placeholder="Libellé" required>'
  . '<select name="nature"><option value="prime">Prime</option><option value="retenue">Retenue</option></select><select name="mode_calcul"><option value="fixe">Fixe</option><option value="pourcentage">Pourcentage</option></select>'
  . '<input type="number" step="0.01" name="valeur" value="0" style="width:100px"><button>Créer</button>') ?>
<table><tr><th>Code</th><th>Libellé</th><th>Nature</th><th>Calcul</th><th>État</th><th></th></tr>
<?php foreach ($rub->lister(false) as $r): ?><tr><td><?= h($r['code']) ?></td><td><?= h($r['libelle']) ?></td><td><?= h($r['nature']) ?></td><td><?= h($r['mode_calcul']) ?></td><td><?= $r['actif'] ? 'Active' : 'Inactive' ?></td>
  <td><?= f('rubrique_actif', '<input type="hidden" name="id" value="' . $r['id'] . '"><input type="hidden" name="actif" value="' . ($r['actif'] ? 0 : 1) . '"><button class="sec">' . ($r['actif'] ? 'Désactiver' : 'Activer') . '</button>', 'inline') ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><b>Rubrique par poste</b> <small>(montant, ou taux % si la rubrique est en pourcentage)</small>
  <?= f('poste_rub', '<select name="poste_id">' . $optP . '</select><select name="rubrique_id">' . $optR . '</select><input type="number" step="0.01" name="montant" required style="width:110px"><button>Rattacher</button>') ?></div>
<div class="card"><b>Rubrique par employé</b>
  <?= f('emp_rub', '<select name="employe_id">' . $optE . '</select><select name="rubrique_id">' . $optR . '</select><input type="number" step="0.01" name="montant" required style="width:110px">'
    . '<input type="date" name="debut" value="' . date('Y-m-01') . '" required><input type="date" name="fin" title="Fin (facultatif)"><button>Attribuer</button>') ?></div>
<?php pied();
