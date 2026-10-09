# Module Smtp

`includes/Modules/Smtp/` + `assets/admin/js/modules/smtp.js`. Envoi par un serveur SMTP authentifié ou par l'API HTTP de Brevo, mail de test et journal des mails (issues #20 et #65, remplace FluentSMTP). Six classes :

- `Mailer` : branche PHPMailer sur le serveur configuré (`phpmailer_init`) ou installe `BrevoMailer`, et impose l'expéditeur (`wp_mail_from`, `wp_mail_from_name`).
- `BrevoMailer` : sous-classe de PHPMailer qui envoie par l'API Brevo (voir [plus bas](#api-brevo)).
- `Crypto` : chiffrement du mot de passe au repos.
- `Logger` : capture de chaque appel à `wp_mail()` et écriture dans la table.
- `Providers` : préréglages SMTP des fournisseurs courants (Brevo, Mailgun US/UE, SendGrid, Postmark, SES, Mailjet, OVHcloud, Gmail, Microsoft 365).
- `Store` : la table `{prefix}lumia_mail_log`, sur le modèle du journal d'activité (voir [activity-log.md](activity-log.md#stockage) : `dbDelta`, `maybe_install()` à chaque chargement, recréation après suppression à la main, purge par lots).

## Envoi : `phpmailer_init`, pas de `wp_mail()` remplacé

FluentSMTP redéfinit la fonction enfichable `wp_mail()` tout entière (routage par adresse d'expédition, API des fournisseurs). Pour un seul relais SMTP, `phpmailer_init` suffit, et `wp_mail()` du cœur reste intact avec ses filtres et ses hooks `wp_mail_succeeded` / `wp_mail_failed`, dont dépend le journal. Si une autre extension redéfinit `wp_mail()`, `Mailer::wp_mail_override()` la détecte (par `ReflectionFunction`) et l'écran l'affiche.

- **Instance PHPMailer globale** : elle survit d'un envoi à l'autre, et `wp_mail()` ne remet à zéro que les destinataires, le corps et le transport (`isMail()`). `configure()` assigne donc **chaque** propriété de connexion à chaque appel, sans condition, `SMTPDebug = 0` compris. Sinon une valeur posée par l'envoi précédent reste en place (les identifiants, ou la trace d'un mail de test, qui partirait dans la réponse HTTP suivante).
- **Délai de connexion à 20 s** : le défaut de PHPMailer est de 300 s, et un hôte injoignable bloquait la page qui envoyait (un formulaire de contact, par exemple).
- SMTP branché seulement si l'interrupteur est actif **et** qu'un hôte est renseigné.

## Fournisseurs

Un préréglage **pré-remplit** l'hôte, le port, le chiffrement et, s'il y en a un d'imposé, l'identifiant (`apikey` pour SendGrid). Il affiche aussi une aide sur l'endroit où trouver les identifiants. Tout reste modifiable, et l'envoi passe par le même relais SMTP générique : le réglage `provider` ne sert qu'à l'affichage. Repasser sur « Serveur personnalisé » ne vide aucun champ.

## API Brevo

Réglage `transport` : `smtp` (défaut) ou `brevo`. L'interrupteur `smtp_enabled` reste l'interrupteur général (« Envoi personnalisé ») ; l'API n'est branchée qu'avec une clé enregistrée. Utile quand l'hébergeur bloque les ports SMTP, et les erreurs de l'API sont plus parlantes que celles d'un relais.

Architecture de WP Mail SMTP, pas celle de FluentSMTP : `BrevoMailer` étend PHPMailer, et seul `send()` est surchargé. `wp_mail()` construit donc toujours le message, et le journal comme les hooks de succès et d'échec restent inchangés. Un refus de l'API lève une `PHPMailer\Exception`, que `wp_mail()` transforme en `wp_mail_failed`.

- **Installation dans `pre_wp_mail`** (priorité maximale, la valeur du filtre est rendue telle quelle) : ce filtre tourne juste avant que `wp_mail()` ne crée son instance, et `wp_mail()` garde toute instance existante. On ne remplace qu'une instance du cœur (`PHPMailer` ou `WP_PHPMailer`) : celle d'une autre extension d'envoi est laissée en place, et `configure_brevo()` ne la touche pas.
- **`$Mailer = 'brevo'` à chaque envoi** (`phpmailer_init`) : `wp_mail()` repasse l'instance sur `isMail()` à chaque appel. Sans ce marqueur, `send()` retombe sur l'envoi normal de PHPMailer, ce qui couvre un changement de réglage en cours de requête.
- **Ne jamais toucher une constante de `BrevoMailer` hors d'un envoi.** La classe étend PHPMailer, que WordPress ne charge qu'au premier `wp_mail()`. Lire `BrevoMailer::MAILER` depuis `Mailer::TRANSPORTS` déclenchait l'autoload à chaque chargement de page, donc une erreur fatale sur tout le site. La constante du transport vit dans `Mailer::BREVO` ; `BrevoMailer` n'est nommé qu'après un `instanceof` (qui, lui, n'autocharge pas).
- **`WP_PHPMailer`** (WordPress 6.8+) ne fait que traduire les messages d'erreur de PHPMailer dans une propriété statique partagée. `BrevoMailer` étend donc `PHPMailer` et appelle `WP_PHPMailer::setLanguage()` s'il existe, ce qui garde la compatibilité avec WordPress 6.0.
- `preSend()` est conservé : il valide les adresses et lit les pièces jointes, avec les messages d'erreur traduits d'un envoi ordinaire (corps vide compris). Le MIME qu'il assemble n'est pas utilisé. **Mais il modifie le message** : dès qu'un `AltBody` existe (posé par une extension de modèles de mail dans `phpmailer_init`), il passe `ContentType` sur `multipart/alternative`. Le type HTML est donc lu **avant** `preSend()`. Sinon le HTML partait en `textContent`, et le destinataire voyait les balises.
- **Correspondances** : une seule adresse de réponse (la première), les en-têtes personnalisés passent dans `headers`, les images intégrées (`cid:`) partent comme pièces jointes ordinaires, faute d'équivalent. Le Return-Path ne s'applique pas : Brevo gère l'enveloppe lui-même.
- **Clé API** : même traitement que le mot de passe (voir ci-dessous) : option `lumia_smtp_brevo_key` chiffrée, hors export, champ vide = inchangée, constante `LUMIA_BREVO_API_KEY` prioritaire. Seuls lettres, chiffres, `-` et `_` sont conservés (format `xkeysib-…`).
- **Mail de test** : en cas d'échec, la requête, le code HTTP et le corps de la réponse remplacent la transcription SMTP. La clé ne part que dans l'en-tête `api-key` : elle n'apparaît pas.
- Pas encore faits : Mailgun (MIME brut), Postmark, SendGrid, SES (signature v4). Google et Microsoft (OAuth2) sont écartés.

## Expéditeur

- **Non forcé**, l'adresse configurée ne remplace que l'adresse par défaut de WordPress (`wordpress@` + hôte du réseau sans `www.`, calculée comme dans le cœur), et le nom seulement s'il vaut « WordPress ». Une extension qui choisit son expéditeur garde donc la main. **Forcé**, on remplace tout. Les filtres tournent à la priorité 9999, pour passer après ceux des extensions.
- **Return-Path** : il prend l'adresse **configurée**, pas le `From` du message. En non forcé, le `From` peut être celui d'un visiteur (formulaire de contact) : le mettre en enveloppe, c'est ce que faisait la première version, et le relais refuse une enveloppe hors de ses domaines (« Sender address rejected »), sans compter que SPF échoue.

## Mot de passe

- **Option à part** (`lumia_smtp_password`, non autochargée), jamais dans `lumia_module_smtp`. L'export de configuration écrit les options de module telles quelles dans le JSON (voir [core.md](../core.md#import-de-réglages)), et le journal d'activité liste les champs qu'elles changent. L'import ne transporte donc pas de mot de passe : il ne se déchiffrerait de toute façon pas sur un autre site.
- **Champ vide = inchangé** : le formulaire ne réaffiche jamais le mot de passe.
- **AES-256-GCM**, clé dérivée de `LOGGED_IN_KEY` + `LOGGED_IN_SALT` (`LUMIA_ENCRYPTION_KEY` la remplace si elle est définie), format `v1:` + base64(IV‖tag‖texte). FluentSMTP utilise AES-256-CTR, sans authentification, et détecte une mauvaise clé grâce à un sel concaténé au clair. GCM obtient le même résultat proprement. Le but : qu'une fuite de la base **seule** (dump, sauvegarde, onglet Base de données) ne livre pas le mot de passe. Contre qui lit `wp-config.php`, rien ne protège.
- **Clés régénérées** (migration, rotation des sels) : `Crypto::decrypt()` renvoie `null`, et l'écran demande de ressaisir le mot de passe au lieu d'échouer en silence à l'envoi.
- **Sans OpenSSL**, le mot de passe n'est pas enregistré du tout (on ne le stocke jamais en clair à l'insu de l'utilisateur), et l'écran le signale.
- `LUMIA_SMTP_USER` / `LUMIA_SMTP_PASSWORD` dans `wp-config.php` l'emportent sur la base, et les champs passent en lecture seule.
- Assainissement : pas de `sanitize_text_field()`, qui retire `<…>` et `%xx`, deux séquences légitimes dans un mot de passe. On retire seulement les caractères de contrôle.

## Journal

Trois hooks, parce qu'aucun ne voit tout :

- le filtre `wp_mail` (priorité maximale) capte la **demande**, telle que l'appelant l'a faite après les autres filtres (en-têtes et chemins de pièces jointes compris) : c'est ce qu'un renvoi rejoue ;
- `phpmailer_init` capte le **message final** (expéditeur retenu, type de contenu, transport) ;
- `wp_mail_succeeded` / `wp_mail_failed` donnent l'**issue**. Un échec peut arriver avant `phpmailer_init` (destinataire ou expéditeur invalide) : la ligne se contente alors de la demande.

Un `pre_wp_mail` qui court-circuite l'envoi ne déclenche aucun des deux hooks d'issue : rien n'est journalisé, et rien n'est parti.

- **Corps limité à 512 Kio** (`mb_strcut`, qui ne coupe pas un caractère UTF-8). Au-delà, la ligne est marquée `truncated` et le renvoi est refusé : renvoyer un message amputé serait pire que ne rien renvoyer.
- La **liste** ne lit ni le corps ni les en-têtes (`Store::LIST_COLUMNS`). Le détail les charge à l'ouverture (`lumia_smtp_log_detail`).
- **Aperçu HTML** dans un `<iframe sandbox="" srcdoc>` : pas de script, pas de formulaire, pas d'accès à la page d'administration. Le corps d'un mail peut venir de n'importe qui.
- **Renvoi** : on rejoue `wp_mail()` avec la demande d'origine. Les pièces jointes disparues depuis (fichiers temporaires des formulaires) sont retirées et signalées. La nouvelle ligne porte `resent_of`.
- **Renvoi, expéditeur et type de contenu** : les arguments de `wp_mail()` ne suffisent pas. WooCommerce et d'autres posent l'expéditeur et le HTML par des filtres (`wp_mail_from`, `wp_mail_content_type`) actifs seulement le temps de leur envoi. Au renvoi, ces filtres n'existent plus, et une commande repartait de l'expéditeur par défaut, en texte brut avec les balises visibles. Le renvoi réapplique donc l'expéditeur et le type **journalisés**, par des filtres temporaires à la priorité 9000, sous celle de l'expéditeur forcé (9999) qui garde le dernier mot.
- Le contenu complet est conservé, **liens de réinitialisation de mot de passe compris**. D'où une rétention par défaut plus courte que celle du journal d'activité (30 jours, 5 000 mails), le bouton « Vider le journal » (`TRUNCATE`) et un avertissement dans l'écran. Couper la journalisation n'efface pas ce qui existe déjà. La table n'est supprimée qu'à la désinstallation.

## Mail de test

- Il passe par `wp_mail()`, donc par les réglages **enregistrés**, et il est journalisé comme n'importe quel mail.
- En cas d'échec, la transcription SMTP (`SMTPDebug = 2`, hook à la priorité 1000, après `configure()`) est renvoyée. « Could not authenticate » ne dit pas ce qui cloche, la réponse du serveur si.
- **Masquage** (`Module::mask_transcript()`) : au niveau 2, PHPMailer écrit les commandes du client telles quelles, et `AUTH PLAIN <base64>` comme les réponses à `AUTH LOGIN` contiennent l'identifiant et le mot de passe en base64, donc lisibles. Chaque ligne client est masquée dès `AUTH`, tant que le serveur répond `334`.
- `wp_mail()` ne renvoie que `false`, sans détail. L'erreur se lit dans le `WP_Error` de `wp_mail_failed`, capté le temps de l'envoi (`send_capturing_error()`).

## Interface

Trois sous-onglets du composant `lumia-tabs` (voir [design-system.md](../design-system.md#onglets)) : **Réglages** (serveur, expéditeur), **Test**, **Journal** (liste et conservation). Tous les panneaux restent dans le formulaire : « Enregistrer » poste tous les champs, quel que soit l'onglet ouvert. Le journal ne se charge qu'à la première ouverture de son onglet (événement `lumia:tab`).

Même structure que le journal d'activité : les filtres et le champ de test n'ont **pas d'attribut `name`**, et Entrée y est interceptée, sinon elle soumet le formulaire de réglages. Le champ de test est en `type="text"` (`inputmode="email"`) et non `email` : le navigateur valide un champ email même sans `name`, et une adresse incomplète bloquait « Enregistrer ». L'avertissement « modifications non sauvegardées » d'`admin.js` ne compte que les champs nommés (voir [design-system.md](../design-system.md#formulaires)). Deux pièges CSS : `.lumia-input--sm` plafonne à 140 px (`.lumia-sm__wide` le lève pour l'hôte et les adresses), et l'attribut `hidden` perd face au `display:flex` de `.lumia-form__row` / `.lumia-option`, que `smtp.css` rétablit.
