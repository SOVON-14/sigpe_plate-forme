<?php

return [
    /*
    | Transport externe simule en v1 : les livraisons email/sms sont
    | marquees 'sent'. Forcer les echecs (tests) :
    */
    'force_failure' => env('NOTIFICATION_FORCE_FAILURE', false),
];
