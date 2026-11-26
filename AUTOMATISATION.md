# Billet du jour — automatisation GitHub Actions

Chaque matin, GitHub (et non plus le Mac) rédige le billet avec Claude, le sauvegarde dans ce dépôt, puis le publie sur renassia.com/news. Plus de veille du Mac, plus de session bloquée.

Fichiers: `.github/workflows/billet.yml` (le workflow) et `scripts/billet_tools.py` (contrôle du billet, publication, vérification).

## Ce que fait le workflow, dans l'ordre

1. Il se lance toutes les 20 minutes entre ~5h et ~9h30 (heure d'Israël), parce que GitHub retarde ou saute parfois les exécutions planifiées. Avant 5h il attend; dès que le billet du jour est en ligne, chaque passage s'arrête aussitôt.
2. Il regarde le billet en ligne: s'il est déjà celui du jour, il s'arrête.
3. Il installe Claude Code et lui demande d'écrire le billet (3 tentatives, 25 min maximum chacune), en suivant `CLAUDE.md`.
4. Il **refuse un billet invalide**: mauvaise date, version hébraïque ou anglaise manquante, nombre de paragraphes différent entre les 3 langues, structure cassée.
5. Il sauvegarde `billet.json` sur GitHub (commit « billet-bot »).
6. Il publie sur le site par HTTPS via `billet.php` (code d'accès du site), puis **vérifie en ligne**. Si cela échoue, il tente l'envoi FTP.
7. En cas d'échec, GitHub t'envoie un e-mail automatique (« Run failed »).

## Mise en route (une seule fois, environ 10 minutes)

Il faut 3 secrets GitHub. Je ne les saisis jamais moi-même: c'est toi qui les colles.

Va sur **github.com/Wazoolooow/renassia-news → Settings → Secrets and variables → Actions → New repository secret**.

| Nom du secret | Valeur | Obligatoire |
|---|---|---|
| `CLAUDE_CODE_OAUTH_TOKEN` | le jeton obtenu avec `claude setup-token` (voir ci-dessous) | oui (ou `ANTHROPIC_API_KEY`) |
| `BILLET_POST_CODE` | le code d'accès du site (celui de `billet.php`) | oui |
| `FTP_USERNAME` et `FTP_PASSWORD` | identifiant et mot de passe du FTP de `ftp.caesarea.tv` (déjà présents dans ce dépôt) | non (secours) |

### Obtenir le jeton Claude
Dans le Terminal du Mac:

```bash
claude setup-token
```

Une page de connexion s'ouvre dans le navigateur; à la fin, le Terminal affiche un jeton long. Copie-le et colle-le dans le secret `CLAUDE_CODE_OAUTH_TOKEN`. Ce jeton est valable environ un an (contrairement à la connexion normale qui expire).

Alternative: une clé d'API Anthropic dans un secret `ANTHROPIC_API_KEY` (facturée à l'usage).

## Test

1. Onglet **Actions → Billet du jour → Run workflow** (lance-le à la main).
2. Pour tester même si le billet du jour est déjà en ligne, coche « Réécrire le billet même s'il est déjà en ligne » (il sera remplacé par une nouvelle version).
3. Ouvre l'exécution et vérifie que les étapes sont vertes, puis que `https://renassia.com/news/billet.php` affiche la date du jour.

## Une fois que ça marche: couper l'ancienne automatisation

Pour éviter que deux systèmes s'écrasent:

1. Désactiver la tâche programmée `billet-dionysos-quotidien` dans l'application Claude.
2. Arrêter le script du Mac:

```bash
launchctl bootout gui/$(id -u) ~/Library/LaunchAgents/com.renassia.billetdujour.plist
```

## Points de vigilance

- **Blocage possible de l'hébergeur.** Le site a déjà renvoyé une erreur 468 « Access Denied » à certaines adresses. Si les adresses de GitHub sont bloquées, l'étape de publication échouera: le billet restera sauvegardé sur GitHub et il faudra une publication manuelle (FTP). Le test ci-dessus le révèle tout de suite.
- **Heure d'été.** GitHub utilise l'heure UTC: les deux déclenchements couvrent les deux saisons. GitHub peut aussi retarder une exécution de quelques minutes.
- **Qualité.** Le billet est écrit sans relecture humaine, comme avant. Les contrôles automatiques vérifient la structure, pas les faits.
- **Expiration du jeton.** Note dans ton agenda de relancer `claude setup-token` dans un an et de mettre à jour le secret.
