# Login module

`includes/Modules/Login/Module.php`. Settings stored under `lumia_module_login`.

Customizes the WordPress login page through the `login_*` hooks: `login_enqueue_scripts`, CSS variables in `login_head`, the logo via `login_headerurl`/`login_headertext`, the side panel and DOM tweaks via `login_footer`. Each optional hide/toggle (language menu, lost password, back to site) is registered conditionally.

The custom login URL is not handled by this module but by [Security](security.md) (`LoginUrlHandler`).
