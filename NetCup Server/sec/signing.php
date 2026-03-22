<?php
return [
    'signing_secret' => getenv('NETCUP_SERVER_SIGNING_SECRET') ?: 'CHANGE_ME_SERVER_SIGNING_SECRET',
];
