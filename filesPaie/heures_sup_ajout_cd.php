<?php
require __DIR__ . '/_app.php';
$u = exiger(['chef']);
$equipe = $hs->employesDuChef((int) $u['id']);
post(['saisir' => fn($p) => in_array((int) $p['employe_id'], array_map('intval', array_column($equipe, 'id')))
    ? 'Heures enregistrées (#' . $hs->saisir((int) $p['employe_id'], $p['date'], (float) $p['nb_heures'], trim($p['motif']) ?: null) . ').'
    : throw new RuntimeException('Cet employé n\'est pas dans votre département.')], 'heures_sup_cd.php');
$opt = ''; foreach ($equipe as $e) $opt .= '<option value="' . $e['id'] . '">' . h("{$e['nom']} {$e['prenom']} ({$e['matricule']})") . '</option>';
entete('Saisir des heures sup', $u); ?>
<div class="top"><h1>Saisir des heures supplémentaires</h1><a href="heures_sup_cd.php">← Retour</a></div>
<div class="card"><?= f('saisir', '<select name="employe_id" required>' . $opt . '</select><input type="date" name="date" value="' . date('Y-m-d') . '" required>'
  . '<input type="number" name="nb_heures" step="0.25" min="0.25" placeholder="Heures" required style="width:100px"><input name="motif" placeholder="Motif"><button>Enregistrer</button>') ?></div>
<?php pied();
