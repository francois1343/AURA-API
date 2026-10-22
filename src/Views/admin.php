<?php
declare(strict_types=1);

use Aura\Api\Http\AdminPage;

$escape = AdminPage::escape(...);
$user = $data['user'] ?? null;
$catalog = $data['catalog'] ?? null;
$error = $data['error'] ?? null;
$flash = $data['flash'] ?? [];
$title = ['login' => 'Connexion', 'dashboard' => 'Catalogue CSV', 'restore' => 'Confirmer le retour', 'error' => 'Action indisponible'][$page];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>AURA — <?= $escape($title) ?></title>
  <link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<a class="skip-link" href="#main">Aller au contenu</a>
<header class="header">
  <a class="brand" href="/admin" aria-label="Accueil de l’administration AURA">AURA<span class="brand-dot" aria-hidden="true">.</span></a>
  <span class="eyebrow">Gestion du catalogue</span>
  <?php if ($user): ?>
    <div class="account"><span><?= $escape($user['email']) ?></span><form action="/admin/logout" method="post"><input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><button class="action secondary" type="submit">Se déconnecter</button></form></div>
  <?php endif; ?>
</header>
<main id="main" class="main">
<?php if ($page === 'login'): ?>
  <section class="login" aria-labelledby="login-title">
    <p class="eyebrow">Espace personnel AURA</p>
    <h1 id="login-title">Le catalogue, simplement.</h1>
    <p>Connectez-vous pour remplacer le fichier utilisé par le site.</p>
    <?php if (!empty($flash['message'])): ?><p class="notice" role="status"><?= $escape($flash['message']) ?></p><?php endif; ?>
    <?php if ($error): ?><div class="error" role="alert" data-error-code="<?= $escape($error['code']) ?>"><p><?= $escape($error['message']) ?></p></div><?php endif; ?>
    <form action="/admin/login" method="post">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>">
      <label class="field" for="email">Adresse e-mail<input id="email" name="email" type="email" autocomplete="username" maxlength="190" value="<?= $escape($data['email'] ?? '') ?>" required></label>
      <label class="field" for="password">Mot de passe<input id="password" name="password" type="password" autocomplete="current-password" maxlength="200" required></label>
      <button class="action" type="submit">Se connecter</button>
    </form>
    <p class="muted">Accès réservé aux comptes AURA autorisés, distincts du compte interne Forem. Contactez l’administrateur du projet pour obtenir un accès.</p>
  </section>
<?php elseif ($page === 'dashboard'): ?>
  <section class="intro"><p class="eyebrow">Un fichier, un catalogue</p><h1>Gardez les formations à jour.</h1><p>Téléchargez le CSV actuel, modifiez-le dans votre tableur, puis remplacez-le. Une seule version précédente est conservée.</p></section>
  <?php if ($error): ?>
    <div class="error" role="alert" data-error-code="<?= $escape($error['code']) ?>"><p><?= $escape($error['message']) ?></p>
      <?php if ($error['details']): ?><ul><?php foreach ($error['details'] as $detail): ?><li><?= $escape($detail) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <p><a href="/admin">Recharger le dashboard</a>. Choisissez à nouveau votre fichier pour réessayer.</p>
    </div>
  <?php endif; ?>
  <?php if (!empty($flash['message'])): ?><p class="success" role="status"><?= $escape($flash['message']) ?></p><?php endif; ?>
  <?php if (!empty($flash['warnings'])): ?><details class="notice"><summary>Informations sur le fichier (<?= count($flash['warnings']) ?>)</summary><ul><?php foreach ($flash['warnings'] as $warning): ?><li><?= $escape($warning) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
  <div class="files-grid">
  <?php foreach (['active' => 'Catalogue actif', 'previous' => 'Version précédente'] as $key => $heading): $version = $catalog[$key]; ?>
    <section class="card <?= $key === 'previous' ? 'previous' : '' ?>" aria-labelledby="<?= $key ?>-title">
      <p class="eyebrow"><?= $key === 'active' ? 'Utilisé par le site' : 'Une seule sauvegarde' ?></p><h2 id="<?= $key ?>-title"><?= $heading ?></h2>
      <?php if (!$version): ?><p>Aucune version précédente pour le moment. Elle sera créée au premier remplacement.</p>
      <?php else: ?>
        <p class="filename"><?= $escape($version['uploadedName']) ?></p>
        <dl class="facts"><div><dt>Formations individuelles</dt><dd><?= $version['formations'] ?></dd></div><div><dt>Entrées du catalogue</dt><dd><?= $version['entries'] ?></dd></div><div><dt>Fichier enregistré</dt><dd><?= $escape(AdminPage::date($version['updatedAt'])) ?></dd></div></dl>
        <?php if ($key === 'active'): ?><a class="action secondary" href="/admin/export">Télécharger le CSV actuel</a>
        <?php else: ?><a class="action secondary" href="/admin/restore">Revenir à cette version</a><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <section class="card upload" aria-labelledby="upload-title">
    <p class="eyebrow">Mettre à jour les formations</p><h2 id="upload-title">Remplacer le catalogue CSV</h2>
    <p>Importez le fichier complet. Les formations absentes du nouveau fichier ne seront plus affichées.</p>
    <form action="/admin/import" method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="revision" value="<?= $catalog['revision'] ?>">
      <label class="field" for="catalogue">Nouveau fichier CSV<input id="catalogue" name="catalogue" type="file" accept=".csv,text/csv" aria-describedby="csv-help" required></label>
      <p id="csv-help" class="muted">CSV AURA · 1 Mo maximum. Les descriptions, horaires, prérequis et lieux peuvent rester vides.</p>
      <label class="check"><input type="checkbox" name="confirmation" value="REMPLACER" required>Je confirme le remplacement du catalogue complet. La version actuelle restera disponible pour un retour arrière.</label>
      <button class="action" type="submit">Importer et remplacer</button>
    </form>
    <p class="muted">Si le fichier est vide ou inutilisable, le catalogue actuel reste en ligne.</p>
  </section>
  <p class="muted">Révision <?= $catalog['revision'] ?>. Pour ajouter, modifier ou supprimer des formations, modifiez les lignes du CSV avant son import.</p>
<?php elseif ($page === 'restore'): ?>
  <section class="card confirmation" aria-labelledby="restore-title">
    <p class="eyebrow">Retour arrière</p><h1 id="restore-title">Revenir à la version précédente ?</h1>
    <p>Le catalogue <strong><?= $escape($catalog['previous']['uploadedName']) ?></strong> (<?= $catalog['previous']['formations'] ?> formations individuelles) sera remis en ligne.</p>
    <p>Le catalogue actuellement utilisé prendra sa place comme version précédente. Vous pourrez donc annuler ce retour.</p>
    <form action="/admin/restore" method="post">
      <input type="hidden" name="_csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="revision" value="<?= $catalog['revision'] ?>">
      <label class="check"><input type="checkbox" name="confirmation" value="REVENIR" required>Je confirme le retour à cette version du catalogue.</label>
      <div class="actions"><a class="action secondary" href="/admin">Annuler</a><button class="action" type="submit">Confirmer le retour</button></div>
    </form>
  </section>
<?php else: ?>
  <section class="card confirmation"><h1>Action indisponible</h1><div class="error" role="alert" data-error-code="<?= $escape($error['code'] ?? 'internal_error') ?>"><p><?= $escape($error['message'] ?? 'Veuillez réessayer plus tard.') ?></p></div><a class="action secondary" href="/admin">Revenir à l’administration</a></section>
<?php endif; ?>
</main>
<footer class="footer">Administration AURA · PHP · Catalogue CSV</footer>
</body>
</html>
