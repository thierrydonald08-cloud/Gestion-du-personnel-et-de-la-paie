<?php
declare(strict_types=1);
session_start();

use App\Paie\{FichePaie, LignePaie, RubriquePaie, PosteRubrique, EmployeRubrique, HeureSupplementaire, AvanceSalaire};

spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'App\\Paie\\')) require __DIR__ . '/../../src/Paie/' . substr($c, 9) . '.php';
});
if (is_file(__DIR__ . '/../../vendor/autoload.php')) require __DIR__ . '/../../vendor/autoload.php'; // Dompdf

/* ===== À ADAPTER : connexion, session ($_SESSION['utilisateur_id']) et Mailer du domaine A ===== */
$pdo = new PDO('mysql:host=localhost;dbname=projet8_rh_paie;charset=utf8mb4', 'root', '');
const DOSSIER_PDF = __DIR__ . '/../../storage/fiches';
$envoyerMail = function (string $email, string $sujet, string $corps, string $pieceJointe): void {
    throw new RuntimeException('Mailer du domaine A non branché (public/paie/_app.php).');
};
/* ============================================================================================== */

$fiches = new FichePaie($pdo); $lignes = new LignePaie($pdo); $rub = new RubriquePaie($pdo);
$posteRub = new PosteRubrique($pdo); $empRub = new EmployeRubrique($pdo);
$hs = new HeureSupplementaire($pdo); $avances = new AvanceSalaire($pdo);
$annee = (int) ($_GET['annee'] ?? date('Y'));
$mois  = (int) ($_GET['mois'] ?? date('n'));
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

function h(mixed $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function fcfa(mixed $n): string { return number_format((float) $n, 0, ',', ' ') . ' FCFA'; }
function ok(mixed $_, string $msg): string { return $msg; }   // pour les méthodes qui ne renvoient rien
function badge(string $s): string { return '<span class="badge s-' . h($s) . '">' . h(str_replace('_', ' ', $s)) . '</span>'; }
function f(string $action, string $inner, string $cls = 'row'): string {
    return '<form method="post" class="' . $cls . '"><input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'
         . '<input type="hidden" name="action" value="' . $action . '">' . $inner . '</form>';
}
function periode(int $a, int $m): string {
    return '<form class="inline"><input type="number" name="mois" value="' . $m . '" min="1" max="12" style="width:70px">'
         . '<input type="number" name="annee" value="' . $a . '" style="width:90px"><button>Afficher</button></form>';
}

/** Vérifie la session et le rôle (utilisateur.role : employe, chef, rh, dg). */
function exiger(array $roles, bool $employeRequis = false): array {
    global $pdo;
    $id = (int) ($_SESSION['utilisateur_id'] ?? 0);
    $st = $pdo->prepare("SELECT u.id, u.role, e.id AS employe_id, e.nom, e.prenom
                           FROM utilisateur u LEFT JOIN employe e ON e.utilisateur_id = u.id
                          WHERE u.id = ? AND u.statut_compte = 'actif'");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u || !in_array($u['role'], $roles, true) || ($employeRequis && !$u['employe_id'])) {
        http_response_code(403);
        exit('Accès refusé : connectez-vous avec un compte autorisé.');
    }
    return $u;
}

/** Traite un POST : $actions = ['nom_action' => fn(array $p): string message]. */
function post(array $actions, ?string $retour = null): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    try {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) throw new RuntimeException('Session expirée, rechargez la page.');
        $fn = $actions[$_POST['action'] ?? ''] ?? throw new RuntimeException('Action inconnue.');
        $_SESSION['flash'] = ['ok', $fn($_POST)];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['err', $e->getMessage()];
    }
    header('Location: ' . ($retour ?? $_SERVER['REQUEST_URI'])); exit;
}

const CSS = <<<'CSS'
:root{--bg:#f5f7f6;--card:#fff;--tx:#1d2b25;--mut:#66756d;--bd:#dde5e0;--pri:#2e7d4f;--on:#fff;--err:#b3261e;--warn:#b26a00}
@media (prefers-color-scheme:dark){:root{--bg:#121816;--card:#1b2420;--tx:#e6eee9;--mut:#93a39a;--bd:#2c3a33;--pri:#4caf78;--on:#0b1410;--err:#f2928b;--warn:#f0b35a}}
*{box-sizing:border-box}body{margin:0;font:15px/1.5 system-ui,sans-serif;background:var(--bg);color:var(--tx)}
nav{background:var(--pri);color:var(--on);padding:0 16px;display:flex;gap:4px;flex-wrap:wrap;align-items:center}nav b{margin-right:12px}
nav a{padding:12px;color:var(--on);text-decoration:none;opacity:.8}nav a.on{opacity:1;font-weight:700;border-bottom:3px solid var(--on)}
main{max-width:1050px;margin:0 auto;padding:20px 16px}h1{font-size:21px;margin:0 0 14px}h2{font-size:16px;margin:22px 0 8px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:14px;margin-bottom:14px;overflow-x:auto}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px}.grid .card{margin:0}
small,.muted{color:var(--mut)}.kpi strong{display:block;font-size:19px}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:7px 9px;border-bottom:1px solid var(--bd);white-space:nowrap}th{color:var(--mut);font-size:13px}.n{text-align:right}
input,select,button{font:inherit;padding:6px 10px;border:1px solid var(--bd);border-radius:7px;background:var(--card);color:var(--tx)}
button{background:var(--pri);color:var(--on);border-color:var(--pri);cursor:pointer}button.sec{background:none;color:var(--tx)}button.red{background:none;color:var(--err);border-color:var(--err)}
form.row,form.inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0}
.badge{padding:1px 9px;border-radius:99px;font-size:12px;border:1px solid var(--bd);color:var(--mut)}
.s-validee,.s-archivee,.s-accordee,.s-integree,.s-validee_chef{color:var(--pri);border-color:var(--pri)}.s-refusee,.s-rejetee{color:var(--err);border-color:var(--err)}
.s-brouillon,.s-en_attente,.s-saisie,.s-verifiee{color:var(--warn);border-color:var(--warn)}
.flash{padding:9px 14px;border-radius:8px;margin-bottom:14px;border:1px solid}.flash.ok{color:var(--pri);border-color:var(--pri)}.flash.err{color:var(--err);border-color:var(--err)}
.top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px}.warn{color:var(--warn)}
CSS;

function entete(string $titre, array $u): void {
    $m = [];
    if ($u['employe_id']) $m = ['fiches_paie_employe.php' => 'Mes fiches', 'avances_employe.php' => 'Mes avances'];
    $m += match ($u['role']) {
        'chef' => ['heures_sup_cd.php' => 'Heures sup (équipe)'],
        'rh'   => ['paie_rh.php' => 'Paie RH', 'tableau_bord_rh.php' => 'Tableau de bord RH'],
        'dg'   => ['tableau_bord_dg.php' => 'Dashboard DG'],
        default => [],
    };
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
       . h($titre) . '</title><style>' . CSS . '</style></head><body><nav><b>Paie</b>';
    foreach ($m as $file => $label) echo '<a href="' . $file . '"' . ($file === $cur ? ' class="on"' : '') . '>' . h($label) . '</a>';
    echo '<span style="margin-left:auto">' . h(trim(($u['prenom'] ?? '') . ' · ' . $u['role'], ' ·')) . '</span></nav><main>';
    if ($flash) echo '<div class="flash ' . $flash[0] . '">' . h($flash[1]) . '</div>';
}
function pied(): void { echo '</main></body></html>'; }
