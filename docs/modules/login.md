# Module Login

`includes/Modules/Login/Module.php`. Réglages sous `lumia_module_login`.

Personnalise la page de connexion WordPress via les hooks `login_*` : `login_enqueue_scripts`, variables CSS dans `login_head`, logo via `login_headerurl`/`login_headertext`, panneau latéral et retouches DOM via `login_footer`. Chaque masquage/bascule optionnel (menu de langue, mot de passe oublié, retour au site) est enregistré conditionnellement.

L'URL de connexion personnalisée ne relève pas de ce module mais de [Security](security.md) (`LoginUrlHandler`).
