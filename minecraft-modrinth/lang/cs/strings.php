<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Módy',
    'minecraft_plugins' => 'Minecraft Pluginy',

    'settings' => [
        'latest_minecraft_version' => 'Nejnovější verze Minecraftu',
        'settings_saved' => 'Nastavení uloženo',
    ],

    'page' => [
        'open_folder' => 'Otevřít složku :folder',
        'minecraft_version' => 'Verze Minecraftu',
        'loader' => 'Loader',
        'installed' => 'Nainstalováno :type',
        'unknown' => 'Neznámé',
        'view_all' => 'Vše',
        'view_installed' => 'Nainstalované',
        'mod_unavailable' => 'Tento mod/plugin už není na Modrinthu dostupný',
    ],

    'table' => [
        'columns' => [
            'title' => 'Název',
            'author' => 'Autor',
            'downloads' => 'Stažení',
            'date_modified' => 'Upraveno',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Stažení',
        'published' => 'Publikováno',
        'changelog' => 'Seznam změn',
        'no_file_found' => 'Soubor nenalezen',
    ],

    'actions' => [
        'install_latest' => 'Nainstalovat nejnovější verzi',
        'install' => 'Nainstalovat',
        'installed' => 'Nainstalováno',
        'update' => 'Aktualizovat',
        'uninstall' => 'Odinstalovat',
        'versions' => 'Výběr verze',
    ],

    'modals' => [
        'update_heading' => 'Aktualizovat mod/plugin',
        'update_description' => 'Tímto se verze :old_version nahradí verzí :new_version. Starý soubor bude smazán.',
        'uninstall_heading' => 'Odinstalovat mod/plugin',
        'uninstall_description' => 'Opravdu chcete odinstalovat :name? Tímto se soubor trvale smaže z vašeho serveru.',
    ],

    'notifications' => [
        'install_success' => 'Instalace dokončena',
        'install_success_body' => 'Úspěšně nainstalováno :name verze :version',
        'install_failed' => 'Instalace se nezdařila',
        'install_failed_body' => 'Při instalaci došlo k chybě. Zkuste to prosím znovu nebo kontaktujte podporu, pokud problém přetrvává.',
        'update_success' => 'Aktualizace dokončena',
        'update_success_body' => 'Úspěšně aktualizováno na verzi :version',
        'update_failed' => 'Aktualizace se nezdařila',
        'update_failed_body' => 'Při aktualizaci došlo k chybě. Zkuste to prosím znovu nebo kontaktujte podporu, pokud problém přetrvává.',
        'uninstall_success' => 'Odinstalace dokončena',
        'uninstall_success_body' => 'Úspěšně odinstalováno :name',
        'uninstall_partial' => 'Odinstalace neúplná',
        'uninstall_partial_body' => 'Soubor :name byl smazán, ale nepodařilo se ho odebrat ze seznamu nainstalovaných. Stále se může zobrazovat jako nainstalovaný.',
        'uninstall_failed' => 'Odinstalace se nezdařila',
        'uninstall_failed_body' => 'Při odinstalaci došlo k chybě. Zkuste to prosím znovu nebo kontaktujte podporu, pokud problém přetrvává.',
    ],
];
