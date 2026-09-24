<?php

return array(
    'id' =>             'osticket:internal-access',
    'version' =>        '1.0.0',
    'ost_version' =>    '1.18',
    'name' =>           'Internal Access Policy',
    'author' =>         'IT Infrastructure',
    'description' =>    'Restricts the helpdesk to company email domains (tickets and portal sign-in) and makes Microsoft Entra ID the only portal sign-in method.',
    'url' =>            'https://github.com/aftab1981/osTicket',
    'plugin' =>         'internalaccess.php:InternalAccessPlugin',
);
